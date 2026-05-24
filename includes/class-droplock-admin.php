<?php
/**
 * Admin: product fields + WooCommerce > DropLock dashboard.
 *
 * @package DropLock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DropLock_Admin {

	const NONCE_FIELD  = 'droplock_product_nonce';
	const NONCE_ACTION = 'droplock_save_product';
	const MENU_SLUG    = 'droplock';

	/**
	 * @var DropLock_Logger
	 */
	protected $logger;

	public function __construct( DropLock_Logger $logger ) {
		$this->logger = $logger;
	}

	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );

		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_fields' ), 10, 1 );

		// Handle "clear log" admin-post action.
		add_action( 'admin_post_droplock_clear_log', array( $this, 'handle_clear_log' ) );
	}

	/* -------------------------------------------------------------------
	 * Product edit screen fields
	 * ------------------------------------------------------------------- */

	public function render_product_fields() {
		global $post;
		if ( ! $post ) {
			return;
		}

		echo '<div class="options_group droplock-product-options">';

		echo '<h4 style="padding-left:12px;margin:12px 0 4px;">' . esc_html__( 'DropLock', 'droplock' ) . '</h4>';

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		woocommerce_wp_checkbox(
			array(
				'id'          => DropLock_Helper::META_ENABLED,
				'label'       => __( 'Enable DropLock for this product', 'droplock' ),
				'description' => __( 'Use this for limited drops, memberships, event exclusives, and one-per-customer products.', 'droplock' ),
				'desc_tip'    => true,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => DropLock_Helper::META_MAX_QTY,
				'label'             => __( 'Maximum quantity per customer', 'droplock' ),
				'description'       => __( 'The total quantity a customer can purchase across all previous orders and their current cart.', 'droplock' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '1',
					'step' => '1',
				),
				'value'             => get_post_meta( $post->ID, DropLock_Helper::META_MAX_QTY, true ) ?: '1',
			)
		);

		// Order statuses (multi-checkbox group).
		$current_statuses = get_post_meta( $post->ID, DropLock_Helper::META_ORDER_STATUSES, true );
		if ( ! is_array( $current_statuses ) || empty( $current_statuses ) ) {
			$current_statuses = DropLock_Helper::default_statuses();
		}
		$all_statuses = wc_get_order_statuses();
		?>
		<p class="form-field droplock-order-statuses">
			<label><?php esc_html_e( 'Order statuses to count', 'droplock' ); ?></label>
			<span class="droplock-checkbox-group">
				<?php
				foreach ( $all_statuses as $status_key => $status_label ) {
					$slug    = preg_replace( '/^wc-/', '', $status_key );
					$checked = in_array( $slug, $current_statuses, true ) ? 'checked' : '';
					echo '<label style="display:block;margin:2px 0;">';
					echo '<input type="checkbox" name="' . esc_attr( DropLock_Helper::META_ORDER_STATUSES ) . '[]" value="' . esc_attr( $slug ) . '" ' . esc_attr( $checked ) . '> ';
					echo esc_html( $status_label );
					echo '</label>';
				}
				?>
			</span>
			<span class="description">
				<?php esc_html_e( 'Orders with these statuses count toward the customer’s lifetime limit.', 'droplock' ); ?>
			</span>
		</p>
		<?php

		woocommerce_wp_textarea_input(
			array(
				'id'          => DropLock_Helper::META_LIMIT_MESSAGE,
				'label'       => __( 'Custom limit message', 'droplock' ),
				'placeholder' => DropLock_Helper::default_limit_message(),
				'description' => __( 'Variables: {product_name}, {limit}, {purchased_qty}, {cart_qty}, {remaining_qty}', 'droplock' ),
				'desc_tip'    => false,
				'rows'        => 3,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => DropLock_Helper::META_BADGE_TEXT,
				'label'       => __( 'Product badge text', 'droplock' ),
				'placeholder' => DropLock_Helper::default_badge_text(),
				'description' => __( 'Variables: {product_name}, {limit}', 'droplock' ),
				'desc_tip'    => false,
			)
		);

		$show_badge = get_post_meta( $post->ID, DropLock_Helper::META_SHOW_BADGE, true );
		if ( '' === $show_badge ) {
			$show_badge = 'yes';
		}
		woocommerce_wp_checkbox(
			array(
				'id'    => DropLock_Helper::META_SHOW_BADGE,
				'label' => __( 'Show badge on product page', 'droplock' ),
				'value' => $show_badge,
			)
		);

		echo '</div>';
	}

	public function save_product_fields( $post_id ) {
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}
		if ( empty( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		// Enabled.
		update_post_meta(
			$post_id,
			DropLock_Helper::META_ENABLED,
			isset( $_POST[ DropLock_Helper::META_ENABLED ] ) ? 'yes' : 'no'
		);

		// Max qty.
		$max_qty = isset( $_POST[ DropLock_Helper::META_MAX_QTY ] )
			? absint( wp_unslash( $_POST[ DropLock_Helper::META_MAX_QTY ] ) )
			: 1;
		if ( $max_qty < 1 ) {
			$max_qty = 1;
		}
		update_post_meta( $post_id, DropLock_Helper::META_MAX_QTY, $max_qty );

		// Statuses.
		$statuses = isset( $_POST[ DropLock_Helper::META_ORDER_STATUSES ] )
			? (array) wp_unslash( $_POST[ DropLock_Helper::META_ORDER_STATUSES ] )
			: array();
		$statuses = DropLock_Helper::sanitize_statuses( $statuses );
		update_post_meta( $post_id, DropLock_Helper::META_ORDER_STATUSES, $statuses );

		// Limit message.
		$msg = isset( $_POST[ DropLock_Helper::META_LIMIT_MESSAGE ] )
			? wp_kses_post( wp_unslash( $_POST[ DropLock_Helper::META_LIMIT_MESSAGE ] ) )
			: '';
		update_post_meta( $post_id, DropLock_Helper::META_LIMIT_MESSAGE, $msg );

		// Badge text.
		$badge = isset( $_POST[ DropLock_Helper::META_BADGE_TEXT ] )
			? sanitize_text_field( wp_unslash( $_POST[ DropLock_Helper::META_BADGE_TEXT ] ) )
			: '';
		update_post_meta( $post_id, DropLock_Helper::META_BADGE_TEXT, $badge );

		// Show badge.
		update_post_meta(
			$post_id,
			DropLock_Helper::META_SHOW_BADGE,
			isset( $_POST[ DropLock_Helper::META_SHOW_BADGE ] ) ? 'yes' : 'no'
		);
	}

	/* -------------------------------------------------------------------
	 * Admin dashboard page
	 * ------------------------------------------------------------------- */

	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'DropLock', 'droplock' ),
			__( 'DropLock', 'droplock' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'droplock' ) );
		}

		$rows  = $this->logger->get_recent( 50 );
		$total = $this->logger->count_total();

		$cleared = isset( $_GET['cleared'] ) && '1' === $_GET['cleared'];

		?>
		<div class="wrap droplock-wrap">
			<h1><?php esc_html_e( 'DropLock for WooCommerce', 'droplock' ); ?></h1>

			<?php if ( $cleared ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Log cleared.', 'droplock' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="droplock-card">
				<h2><?php esc_html_e( 'Overview', 'droplock' ); ?></h2>
				<p>
					<?php esc_html_e( 'DropLock enforces lifetime purchase limits for limited WooCommerce products. Edit any product, scroll to the "General" tab, and enable DropLock to start restricting purchases.', 'droplock' ); ?>
				</p>
				<ol>
					<li><?php esc_html_e( 'Edit a product.', 'droplock' ); ?></li>
					<li><?php esc_html_e( 'Enable DropLock and set the maximum quantity per customer.', 'droplock' ); ?></li>
					<li><?php esc_html_e( 'Choose which order statuses count toward the limit.', 'droplock' ); ?></li>
					<li><?php esc_html_e( 'Customize the badge and limit message if desired.', 'droplock' ); ?></li>
					<li><?php esc_html_e( 'Save the product.', 'droplock' ); ?></li>
				</ol>
			</div>

			<div class="droplock-card">
				<h2><?php esc_html_e( 'Blocked Attempts', 'droplock' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %d total blocked attempts */
						esc_html__( 'Total blocked attempts: %d', 'droplock' ),
						(int) $total
					);
					?>
				</p>

				<?php if ( empty( $rows ) ) : ?>
					<p><em><?php esc_html_e( 'No blocked attempts yet.', 'droplock' ); ?></em></p>
				<?php else : ?>
					<table class="widefat striped droplock-log-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'droplock' ); ?></th>
								<th><?php esc_html_e( 'Product', 'droplock' ); ?></th>
								<th><?php esc_html_e( 'User', 'droplock' ); ?></th>
								<th><?php esc_html_e( 'Email', 'droplock' ); ?></th>
								<th><?php esc_html_e( 'Reason', 'droplock' ); ?></th>
								<th><?php esc_html_e( 'Purchased', 'droplock' ); ?></th>
								<th><?php esc_html_e( 'Cart', 'droplock' ); ?></th>
								<th><?php esc_html_e( 'Limit', 'droplock' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['created_at'] ); ?></td>
									<td>
										<?php
										$pid   = (int) $row['product_id'];
										$pname = $row['product_name'] ? $row['product_name'] : ( '#' . $pid );
										if ( $pid ) {
											$edit = get_edit_post_link( $pid );
											if ( $edit ) {
												echo '<a href="' . esc_url( $edit ) . '">' . esc_html( $pname ) . '</a>';
											} else {
												echo esc_html( $pname );
											}
										} else {
											echo esc_html( $pname );
										}
										?>
									</td>
									<td>
										<?php
										$uid = (int) $row['user_id'];
										if ( $uid ) {
											$u = get_userdata( $uid );
											echo esc_html( $u ? $u->user_login : ( '#' . $uid ) );
										} else {
											echo '&mdash;';
										}
										?>
									</td>
									<td><?php echo esc_html( $row['billing_email'] ?: '—' ); ?></td>
									<td><?php echo esc_html( $row['reason'] ); ?></td>
									<td><?php echo (int) $row['purchased_qty']; ?></td>
									<td><?php echo (int) $row['cart_qty']; ?></td>
									<td><?php echo (int) $row['max_limit']; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
						<input type="hidden" name="action" value="droplock_clear_log" />
						<?php wp_nonce_field( 'droplock_clear_log' ); ?>
						<button type="submit" class="button"
							onclick="return confirm('<?php echo esc_js( __( 'Clear all blocked attempt logs?', 'droplock' ) ); ?>');">
							<?php esc_html_e( 'Clear log', 'droplock' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</div>

			<div class="droplock-card">
				<h2><?php esc_html_e( 'Variables you can use', 'droplock' ); ?></h2>
				<ul>
					<li><code>{product_name}</code></li>
					<li><code>{limit}</code></li>
					<li><code>{purchased_qty}</code></li>
					<li><code>{cart_qty}</code></li>
					<li><code>{remaining_qty}</code></li>
				</ul>
			</div>
		</div>
		<?php
	}

	public function handle_clear_log() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'droplock' ) );
		}
		check_admin_referer( 'droplock_clear_log' );
		$this->logger->clear_all();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&cleared=1' ) );
		exit;
	}
}
