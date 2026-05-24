# v2.0 — License activation + auto-updates

This is the design doc for the v2.0 milestone that adds license activation and an automatic update channel for **Pro customers**. The Free (wordpress.org) plugin does not get this — wordpress.org handles its updates.

The goal: when a customer enters their Lemon Squeezy license key in **WooCommerce → DropLock → License**, the plugin (a) activates the key against the Lemon Squeezy License API, (b) starts receiving automatic update notifications when a new version is published, and (c) shows "Update available" in **Plugins** just like any plugin.

Total new code: ~400 lines. No external dependencies. No update server to host yourself.

---

## How the pieces fit together

```
┌────────────────────┐   1. customer pastes key      ┌────────────────────────┐
│ WP Admin           │ ───────────────────────────▶  │ Lemon Squeezy          │
│ License screen     │                               │ License API            │
│ (DropLock_License) │ ◀───────────────────────────  │ /v1/licenses/activate  │
└──────┬─────────────┘   2. activated + instance_id  └────────────────────────┘
       │                                                          │
       │  3. store key + instance_id in option                    │
       ▼                                                          │
┌────────────────────┐                                            │
│ WordPress updates  │   4. on update check, hit our checker      │
│ subsystem          │ ──────────────────────────────────────┐    │
└────────────────────┘                                       ▼    │
                                                ┌─────────────────────────┐
                                                │ GitHub Releases JSON    │
                                                │ (or your own update     │
                                                │  endpoint if you prefer)│
                                                └────────┬────────────────┘
                                                         │
                                                         ▼
                                                ┌─────────────────────────┐
                                                │ 5. validate license     │
                                                │    is still active      │
                                                │    via Lemon Squeezy    │
                                                └─────────────────────────┘
```

### Pick one: GitHub Releases vs. your own update endpoint

| | GitHub Releases | Your own endpoint |
|---|---|---|
| Hosting cost | Free | Free–$5/mo |
| Setup | Zero (already in place via your `release.yml`) | Build a tiny PHP / Cloudflare Worker |
| Privacy | Public zip URL | You control distribution |
| Best for | v2.0 launch | If you ever ship a paid-only build |

**Recommendation:** Ship v2.0 with GitHub Releases. Migrate to a private endpoint only if you start gating downloads on license check (which the Lemon Squeezy License API can already enforce client-side — see the License Validation step).

---

## File plan

Add these to the Pro plugin:

```
droplock-for-woocommerce/
├── includes/
│   ├── class-droplock-license.php       ← NEW: activate / deactivate / validate
│   ├── class-droplock-updater.php       ← NEW: hooks into the WP updates subsystem
│   └── class-droplock-license-admin.php ← NEW: the License settings page
├── docs/
│   └── licensing.md                     ← NEW: customer-facing how-to
```

Bump `DROPLOCK_PRO_VERSION` to `2.0.0`. The Free plugin needs no changes.

---

## Step 1: License activation

### Lemon Squeezy License API endpoints you'll use

| Method + path | Purpose |
|---|---|
| `POST https://api.lemonsqueezy.com/v1/licenses/activate` | activate a key, get back `instance_id` |
| `POST https://api.lemonsqueezy.com/v1/licenses/deactivate` | deactivate an instance (e.g. when moving sites) |
| `POST https://api.lemonsqueezy.com/v1/licenses/validate` | check if a key is still valid |

All POST forms. No API key needed on these (the license key authenticates). Docs: <https://docs.lemonsqueezy.com/api/license-api>.

### Skeleton: `class-droplock-license.php`

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DropLock_License {

	const OPTION_KEY         = 'droplock_license';
	const TRANSIENT_VALIDATE = 'droplock_license_valid';
	const VALIDATE_INTERVAL  = DAY_IN_SECONDS;

	/**
	 * @return array{key:string,instance_id:string,site_url:string,status:string,checked_at:int}
	 */
	public static function get_state() {
		$state = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $state, array(
			'key'         => '',
			'instance_id' => '',
			'site_url'    => '',
			'status'      => 'inactive',
			'checked_at'  => 0,
		) );
	}

	public static function is_active() {
		$state = self::get_state();
		return 'active' === $state['status'] && ! empty( $state['instance_id'] );
	}

	/** Activate a license key against the LS License API. */
	public static function activate( $license_key ) {
		$license_key = sanitize_text_field( $license_key );
		if ( empty( $license_key ) ) {
			return new WP_Error( 'empty_key', __( 'License key is required.', 'droplock' ) );
		}

		$response = wp_remote_post( 'https://api.lemonsqueezy.com/v1/licenses/activate', array(
			'timeout' => 15,
			'headers' => array( 'Accept' => 'application/json' ),
			'body'    => array(
				'license_key'   => $license_key,
				'instance_name' => parse_url( home_url(), PHP_URL_HOST ),
			),
		) );

		if ( is_wp_error( $response ) ) return $response;

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['activated'] ) || $body['activated'] !== true ) {
			$msg = $body['error'] ?? __( 'Activation failed.', 'droplock' );
			return new WP_Error( 'ls_activate_failed', $msg, $body );
		}

		update_option( self::OPTION_KEY, array(
			'key'         => $license_key,
			'instance_id' => $body['instance']['id'],
			'site_url'    => home_url(),
			'status'      => 'active',
			'checked_at'  => time(),
		), false );

		delete_transient( self::TRANSIENT_VALIDATE );
		return true;
	}

	/** Deactivate at LS and clear the local state. */
	public static function deactivate() {
		$state = self::get_state();
		if ( empty( $state['key'] ) || empty( $state['instance_id'] ) ) {
			delete_option( self::OPTION_KEY );
			return true;
		}

		$response = wp_remote_post( 'https://api.lemonsqueezy.com/v1/licenses/deactivate', array(
			'timeout' => 15,
			'headers' => array( 'Accept' => 'application/json' ),
			'body'    => array(
				'license_key' => $state['key'],
				'instance_id' => $state['instance_id'],
			),
		) );

		// We don't fail hard if the remote deactivation fails — clear local state anyway,
		// so the customer can re-activate on another site or with a new key.
		delete_option( self::OPTION_KEY );
		delete_transient( self::TRANSIENT_VALIDATE );
		return true;
	}

	/**
	 * Daily revalidation (called from updater).
	 * Returns true if license is still valid; false otherwise.
	 */
	public static function validate_periodic() {
		$cached = get_transient( self::TRANSIENT_VALIDATE );
		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$state = self::get_state();
		if ( empty( $state['key'] ) ) {
			set_transient( self::TRANSIENT_VALIDATE, 0, self::VALIDATE_INTERVAL );
			return false;
		}

		$response = wp_remote_post( 'https://api.lemonsqueezy.com/v1/licenses/validate', array(
			'timeout' => 15,
			'body'    => array(
				'license_key' => $state['key'],
				'instance_id' => $state['instance_id'],
			),
		) );

		if ( is_wp_error( $response ) ) {
			// Network blip — fail OPEN. Don't punish the customer.
			set_transient( self::TRANSIENT_VALIDATE, 1, HOUR_IN_SECONDS );
			return true;
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$valid = ! empty( $body['valid'] ) && $body['valid'] === true;

		// Update stored status.
		$state['status']     = $valid ? 'active' : 'invalid';
		$state['checked_at'] = time();
		update_option( self::OPTION_KEY, $state, false );

		set_transient( self::TRANSIENT_VALIDATE, $valid ? 1 : 0, self::VALIDATE_INTERVAL );
		return $valid;
	}
}
```

**Important:**
- `validate_periodic()` fails **open** on network errors — never block paid customers because Lemon Squeezy has a 30-second hiccup.
- The transient throttles validation to once per day.
- Pro features should *not* gate-check `is_active()` on hot paths (cart/checkout). Doing so risks customers being silently locked out if the API ever has an outage. Check it at update-time only.

---

## Step 2: License admin page

### Skeleton: `class-droplock-license-admin.php`

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DropLock_License_Admin {

	const MENU_SLUG    = 'droplock-license';
	const NONCE_ACTION = 'droplock_license_action';

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_droplock_activate_license',   array( $this, 'handle_activate' ) );
		add_action( 'admin_post_droplock_deactivate_license', array( $this, 'handle_deactivate' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'DropLock License', 'droplock' ),
			__( 'DropLock License', 'droplock' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		$state = DropLock_License::get_state();
		$active = DropLock_License::is_active();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DropLock License', 'droplock' ); ?></h1>

			<?php if ( $active ) : ?>
				<p>
					<?php esc_html_e( 'Status:', 'droplock' ); ?>
					<strong style="color:#46b450;">● <?php esc_html_e( 'Active', 'droplock' ); ?></strong>
				</p>
				<p><?php printf( esc_html__( 'Key: %s', 'droplock' ), '<code>' . esc_html( substr( $state['key'], 0, 8 ) . '••••' ) . '</code>' ); ?></p>
				<p><?php printf( esc_html__( 'Activated on: %s', 'droplock' ), esc_html( $state['site_url'] ) ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="droplock_deactivate_license">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<button type="submit" class="button"
						onclick="return confirm('<?php echo esc_js( __( 'Deactivate this license? You can re-activate on another site.', 'droplock' ) ); ?>');">
						<?php esc_html_e( 'Deactivate', 'droplock' ); ?>
					</button>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'Paste your DropLock Pro license key to enable automatic updates.', 'droplock' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="droplock_activate_license">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<p>
						<input type="text" name="license_key" class="regular-text"
							placeholder="XXXX-XXXX-XXXX-XXXX-XXXX" required>
					</p>
					<p>
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Activate', 'droplock' ); ?>
						</button>
					</p>
				</form>
				<p><a href="https://droplockwp.com/docs/license" target="_blank" rel="noopener">
					<?php esc_html_e( 'Where do I find my license key?', 'droplock' ); ?>
				</a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_activate() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'No permission.', 'droplock' ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$key    = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		$result = DropLock_License::activate( $key );

		if ( is_wp_error( $result ) ) {
			set_transient( 'droplock_license_notice', $result->get_error_message(), 60 );
		} else {
			set_transient( 'droplock_license_notice', __( 'License activated.', 'droplock' ), 60 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	public function handle_deactivate() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'No permission.', 'droplock' ) );
		}
		check_admin_referer( self::NONCE_ACTION );
		DropLock_License::deactivate();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}
}
```

---

## Step 3: Auto-update integration

WordPress polls a transient called `update_plugins` periodically. To inject your own update info, hook the `pre_set_site_transient_update_plugins` filter and add an entry whenever a newer version is available. To deliver the actual zip, hook `plugins_api` and `upgrader_pre_download`.

### Skeleton: `class-droplock-updater.php`

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DropLock_Updater {

	const TRANSIENT_REMOTE = 'droplock_remote_release';
	const REMOTE_TTL       = 6 * HOUR_IN_SECONDS;

	/** Where to read the latest release manifest. */
	const RELEASE_URL = 'https://api.github.com/repos/REPLACE-ME-USER/droplock-for-woocommerce/releases/latest';

	public function register_hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
	}

	/** Cache the latest GitHub release JSON. */
	protected function get_remote() {
		$cached = get_transient( self::TRANSIENT_REMOTE );
		if ( false !== $cached ) return $cached;

		$response = wp_remote_get( self::RELEASE_URL, array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'application/vnd.github+json' ),
		) );
		if ( is_wp_error( $response ) ) return null;

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['tag_name'] ) ) return null;

		$asset = null;
		foreach ( ( $body['assets'] ?? array() ) as $a ) {
			if ( ! empty( $a['name'] ) && 'droplock-for-woocommerce.zip' === $a['name'] ) {
				$asset = $a;
				break;
			}
		}
		if ( ! $asset ) return null;

		$payload = array(
			'version' => ltrim( $body['tag_name'], 'v' ),
			'zip_url' => $asset['browser_download_url'],
			'notes'   => $body['body'] ?? '',
		);
		set_transient( self::TRANSIENT_REMOTE, $payload, self::REMOTE_TTL );
		return $payload;
	}

	public function inject_update( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) return $transient;

		// Require an active license to receive updates.
		if ( ! DropLock_License::validate_periodic() ) {
			return $transient;
		}

		$remote = $this->get_remote();
		if ( ! $remote ) return $transient;

		if ( version_compare( $remote['version'], DROPLOCK_VERSION, '<=' ) ) {
			return $transient;
		}

		$obj = new stdClass();
		$obj->slug         = 'droplock-for-woocommerce';
		$obj->plugin       = DROPLOCK_PLUGIN_BASENAME;
		$obj->new_version  = $remote['version'];
		$obj->url          = 'https://droplockwp.com';
		$obj->package      = $remote['zip_url'];
		$obj->tested       = '6.7';
		$obj->requires_php = '8.0';
		$obj->icons        = array();
		$obj->banners      = array();
		$obj->compatibility = new stdClass();

		$transient->response[ DROPLOCK_PLUGIN_BASENAME ] = $obj;
		return $transient;
	}

	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) return $result;
		if ( empty( $args->slug ) || 'droplock-for-woocommerce' !== $args->slug ) return $result;

		$remote = $this->get_remote();
		if ( ! $remote ) return $result;

		$info = new stdClass();
		$info->name          = 'DropLock for WooCommerce';
		$info->slug          = 'droplock-for-woocommerce';
		$info->version       = $remote['version'];
		$info->author        = '<a href="https://droplockwp.com">DropLock</a>';
		$info->homepage      = 'https://droplockwp.com';
		$info->requires      = '6.5';
		$info->tested        = '6.7';
		$info->requires_php  = '8.0';
		$info->download_link = $remote['zip_url'];
		$info->sections      = array(
			'changelog' => wpautop( esc_html( $remote['notes'] ) ),
		);
		return $info;
	}
}
```

### Wire it up in `class-droplock-plugin.php`

In `DropLock_Plugin::__construct()` add:

```php
require_once DROPLOCK_PLUGIN_DIR . 'includes/class-droplock-license.php';
require_once DROPLOCK_PLUGIN_DIR . 'includes/class-droplock-license-admin.php';
require_once DROPLOCK_PLUGIN_DIR . 'includes/class-droplock-updater.php';

$this->license_admin = new DropLock_License_Admin();
$this->updater       = new DropLock_Updater();
$this->license_admin->register_hooks();
$this->updater->register_hooks();
```

---

## Edge cases to handle (and how)

| Edge case | Decision |
|---|---|
| Network down at LS | `validate_periodic()` returns true (fail open). Customer keeps working. |
| Customer migrates to new domain | Lemon Squeezy ties `instance_id` to the activation, not the URL. New activation on new domain consumes a new "seat" on multi-site plans. UI should offer Deactivate-Then-Reactivate workflow. |
| License expires (annual renewal lapsed) | LS returns `valid: false`. Plugin keeps working (GPL); only updates stop. Show a yellow notice on the License page. |
| License revoked (refund / chargeback) | Same as expired. |
| GitHub rate limit | Cache release JSON for 6 hours. GitHub allows 60 unauthenticated requests per hour per IP — plenty. |
| Multiple sites under one license | LS instance IDs handle this automatically — one activation per site, up to the variant's quota. |
| Customer downloads zip directly, never enters key | Plugin works fine without a license. They just never see "Update available" — they have to manually re-upload. That's fine. |

---

## Release flow once v2.0 is shipped

```powershell
# 1. Bump + commit + tag
powershell -File C:\Users\andui\WordpressPlugins\droplock-launch\scripts\release.ps1 -Version 2.0.1

# 2. Push
cd C:\Users\andui\WordpressPlugins\droplock-for-woocommerce
git push origin main --tags

# 3. GitHub Actions builds the zip and creates the Release.
# 4. Within 6 hours, every customer's wp-admin shows "Update available".
# 5. They click Update. WP downloads the zip from GitHub and replaces the plugin.
```

---

## What this design deliberately doesn't include

- **No phone-home telemetry.** Only the activate/deactivate/validate calls — all customer-initiated or update-triggered.
- **No license-server self-hosting.** Lemon Squeezy is the source of truth. One less thing to maintain.
- **No remote feature flags.** Pro features are determined by which plugin is installed, not by the license server.
- **No silent feature disablement when license expires.** Disabling features customers built workflows around invites support fires and chargebacks. Updates stop; features stay.

---

## Cost / time estimate

- Coding: ~1 day for a competent WP dev.
- Testing: ~half a day across activation, deactivation, multi-site, expired, and revoked scenarios.
- Lemon Squeezy License API setup in the LS dashboard: 30 minutes.
- Documentation update: a `docs/licensing.md` for customers, ~1 hour.

Total: **2–3 days end to end** if you're focused.
