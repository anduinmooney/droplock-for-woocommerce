<?php
/**
 * Helper utilities for DropLock.
 *
 * @package DropLock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DropLock_Helper {

	const META_ENABLED         = '_droplock_enabled';
	const META_MAX_QTY         = '_droplock_max_qty';
	const META_ORDER_STATUSES  = '_droplock_order_statuses';
	const META_LIMIT_MESSAGE   = '_droplock_limit_message';
	const META_BADGE_TEXT      = '_droplock_badge_text';
	const META_SHOW_BADGE      = '_droplock_show_badge';

	/**
	 * Default counted order statuses (without wc- prefix).
	 *
	 * @return string[]
	 */
	public static function default_statuses() {
		return array( 'completed', 'processing', 'on-hold' );
	}

	/**
	 * Default limit message.
	 */
	public static function default_limit_message() {
		return __(
			'You have already reached the purchase limit for {product_name}. This limited drop is restricted to {limit} per customer.',
			'droplock'
		);
	}

	/**
	 * Default badge text.
	 */
	public static function default_badge_text() {
		return __( 'Limited Drop: Limit {limit} per customer', 'droplock' );
	}

	public static function is_enabled( $product_id ) {
		return 'yes' === get_post_meta( (int) $product_id, self::META_ENABLED, true );
	}

	public static function get_max_qty( $product_id ) {
		$raw = get_post_meta( (int) $product_id, self::META_MAX_QTY, true );
		$qty = absint( $raw );
		return $qty > 0 ? $qty : 1;
	}

	public static function get_order_statuses( $product_id ) {
		$saved = get_post_meta( (int) $product_id, self::META_ORDER_STATUSES, true );
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			return self::default_statuses();
		}
		return array_values(
			array_filter(
				array_map( 'sanitize_key', $saved )
			)
		);
	}

	public static function get_limit_message( $product_id ) {
		$msg = get_post_meta( (int) $product_id, self::META_LIMIT_MESSAGE, true );
		if ( ! is_string( $msg ) || '' === trim( $msg ) ) {
			$msg = self::default_limit_message();
		}
		return $msg;
	}

	public static function get_badge_text( $product_id ) {
		$txt = get_post_meta( (int) $product_id, self::META_BADGE_TEXT, true );
		if ( ! is_string( $txt ) || '' === trim( $txt ) ) {
			$txt = self::default_badge_text();
		}
		return $txt;
	}

	public static function should_show_badge( $product_id ) {
		$val = get_post_meta( (int) $product_id, self::META_SHOW_BADGE, true );
		if ( '' === $val ) {
			// Default checked.
			return true;
		}
		return 'yes' === $val;
	}

	/**
	 * Replace {variables} in a message.
	 *
	 * Supported keys: product_name, limit, purchased_qty, cart_qty, remaining_qty.
	 *
	 * @param string $template
	 * @param array  $vars
	 * @return string
	 */
	public static function format_message( $template, $vars = array() ) {
		$defaults = array(
			'product_name'  => '',
			'limit'         => '',
			'purchased_qty' => '',
			'cart_qty'      => '',
			'remaining_qty' => '',
		);
		$vars = array_merge( $defaults, $vars );

		$replacements = array();
		foreach ( $vars as $key => $val ) {
			$replacements[ '{' . $key . '}' ] = (string) $val;
		}
		return strtr( $template, $replacements );
	}

	/**
	 * Whether the current user should bypass DropLock checks.
	 */
	public static function current_user_can_bypass() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		/**
		 * Filter whether the current request should bypass DropLock validation.
		 *
		 * @param bool $bypass
		 */
		return (bool) apply_filters( 'droplock_bypass_validation', false );
	}

	/**
	 * Get the "effective" product id used for limit grouping.
	 * Variations roll up to their parent product id.
	 *
	 * @param int $product_id
	 * @return int
	 */
	public static function get_effective_product_id( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( $product && $product->is_type( 'variation' ) ) {
			return $product->get_parent_id();
		}
		return (int) $product_id;
	}

	/**
	 * Sanitize a list of order statuses against WooCommerce-known statuses.
	 *
	 * @param array $statuses
	 * @return string[]
	 */
	public static function sanitize_statuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			return self::default_statuses();
		}
		$valid = array_keys( wc_get_order_statuses() );
		$valid = array_map(
			static function ( $k ) {
				return preg_replace( '/^wc-/', '', $k );
			},
			$valid
		);
		$clean = array();
		foreach ( $statuses as $s ) {
			$s = sanitize_key( $s );
			$s = preg_replace( '/^wc-/', '', $s );
			if ( in_array( $s, $valid, true ) ) {
				$clean[] = $s;
			}
		}
		return $clean ? array_values( array_unique( $clean ) ) : self::default_statuses();
	}
}
