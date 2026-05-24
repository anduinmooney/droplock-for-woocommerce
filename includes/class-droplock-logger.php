<?php
/**
 * Blocked attempt logger.
 *
 * @package DropLock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DropLock_Logger {

	const TABLE = 'droplock_blocked_log';

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create the log table.
	 */
	public static function create_table() {
		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			product_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			product_name VARCHAR(255) NOT NULL DEFAULT '',
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			billing_email VARCHAR(190) NOT NULL DEFAULT '',
			reason VARCHAR(64) NOT NULL DEFAULT '',
			purchased_qty INT NOT NULL DEFAULT 0,
			cart_qty INT NOT NULL DEFAULT 0,
			max_limit INT NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY product_id (product_id),
			KEY billing_email (billing_email),
			KEY user_id (user_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert a log entry.
	 *
	 * @param array $args
	 */
	public function log( $args ) {
		global $wpdb;

		$defaults = array(
			'product_id'    => 0,
			'product_name'  => '',
			'user_id'       => 0,
			'billing_email' => '',
			'reason'        => '',
			'purchased_qty' => 0,
			'cart_qty'      => 0,
			'max_limit'     => 0,
		);
		$args = array_merge( $defaults, $args );

		$wpdb->insert(
			self::table_name(),
			array(
				'created_at'    => current_time( 'mysql' ),
				'product_id'    => (int) $args['product_id'],
				'product_name'  => substr( (string) $args['product_name'], 0, 255 ),
				'user_id'       => (int) $args['user_id'],
				'billing_email' => substr( (string) $args['billing_email'], 0, 190 ),
				'reason'        => substr( (string) $args['reason'], 0, 64 ),
				'purchased_qty' => (int) $args['purchased_qty'],
				'cart_qty'      => (int) $args['cart_qty'],
				'max_limit'     => (int) $args['max_limit'],
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%d', '%d', '%d' )
		);
	}

	/**
	 * Fetch recent log rows.
	 *
	 * @param int $limit
	 * @return array
	 */
	public function get_recent( $limit = 50 ) {
		global $wpdb;
		$limit = max( 1, min( 500, (int) $limit ) );
		$table = self::table_name();
		// $table comes from $wpdb->prefix + constant; safe to interpolate.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	public function count_total() {
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	public function clear_all() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}
}
