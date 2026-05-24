<?php
/**
 * Main plugin bootstrap.
 *
 * @package DropLock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DropLock_Plugin {

	/**
	 * @var DropLock_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * @var DropLock_Admin
	 */
	public $admin;

	/**
	 * @var DropLock_Validator
	 */
	public $validator;

	/**
	 * @var DropLock_Logger
	 */
	public $logger;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	protected function __construct() {
		$this->logger    = new DropLock_Logger();
		$this->validator = new DropLock_Validator( $this->logger );
		$this->admin     = new DropLock_Admin( $this->logger );

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_product_badge' ), 11 );

		add_filter(
			'plugin_action_links_' . DROPLOCK_PLUGIN_BASENAME,
			array( $this, 'plugin_action_links' )
		);

		$this->validator->register_hooks();
		$this->admin->register_hooks();
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'droplock', false, dirname( DROPLOCK_PLUGIN_BASENAME ) . '/languages' );
	}

	public function enqueue_frontend_assets() {
		wp_enqueue_style(
			'droplock-frontend',
			DROPLOCK_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			DROPLOCK_VERSION
		);
	}

	public function enqueue_admin_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_product_screen = $screen && in_array( $screen->id, array( 'product', 'edit-product' ), true );
		$is_droplock_page  = isset( $_GET['page'] ) && 'droplock' === $_GET['page'];

		if ( $is_product_screen || $is_droplock_page ) {
			wp_enqueue_style(
				'droplock-admin',
				DROPLOCK_PLUGIN_URL . 'assets/css/admin.css',
				array(),
				DROPLOCK_VERSION
			);
		}
	}

	public function render_product_badge() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		if ( ! DropLock_Helper::is_enabled( $product_id ) ) {
			return;
		}

		if ( ! DropLock_Helper::should_show_badge( $product_id ) ) {
			return;
		}

		$limit = DropLock_Helper::get_max_qty( $product_id );
		if ( $limit < 1 ) {
			return;
		}

		$badge_text_raw = DropLock_Helper::get_badge_text( $product_id );
		$badge_text     = DropLock_Helper::format_message(
			$badge_text_raw,
			array(
				'product_name' => $product->get_name(),
				'limit'        => $limit,
			)
		);

		echo '<div class="droplock-badge" role="status" aria-live="polite">'
			. esc_html( $badge_text )
			. '</div>';
	}

	public function plugin_action_links( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=droplock' ) ) . '">'
			. esc_html__( 'Dashboard', 'droplock' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	public static function on_activate() {
		require_once DROPLOCK_PLUGIN_DIR . 'includes/class-droplock-logger.php';
		DropLock_Logger::create_table();
		update_option( 'droplock_db_version', DROPLOCK_DB_VERSION );
	}

	public static function on_deactivate() {
		// Keep data on deactivation. Uninstall.php would handle cleanup if needed.
	}
}
