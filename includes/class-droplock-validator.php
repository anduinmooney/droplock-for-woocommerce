<?php
/**
 * Cart and checkout validation.
 *
 * @package DropLock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DropLock_Validator {

	/**
	 * @var DropLock_Logger
	 */
	protected $logger;

	public function __construct( DropLock_Logger $logger ) {
		$this->logger = $logger;
	}

	public function register_hooks() {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 5 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_cart_update' ), 10, 4 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_items' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout' ), 10, 2 );
	}

	/**
	 * Validate when a product is being added to the cart.
	 *
	 * @param bool $passed
	 * @param int  $product_id
	 * @param int  $quantity
	 * @param int  $variation_id
	 * @param array $variations
	 * @return bool
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
		if ( ! $passed ) {
			return $passed;
		}
		if ( DropLock_Helper::current_user_can_bypass() ) {
			return $passed;
		}

		$effective_id = DropLock_Helper::get_effective_product_id( $product_id );

		if ( ! DropLock_Helper::is_enabled( $effective_id ) ) {
			return $passed;
		}

		$limit         = DropLock_Helper::get_max_qty( $effective_id );
		$statuses      = DropLock_Helper::get_order_statuses( $effective_id );
		$user_id       = get_current_user_id();
		$billing_email = $this->resolve_billing_email_from_request();

		$purchased = DropLock_Order_Query::get_customer_purchased_quantity(
			$effective_id,
			$user_id,
			$billing_email,
			$statuses
		);

		$in_cart   = DropLock_Order_Query::get_cart_quantity_for_product( $effective_id );
		$attempted = (int) $quantity;
		$total_if_added = $purchased + $in_cart + $attempted;

		if ( $total_if_added > $limit ) {
			$remaining = max( 0, $limit - $purchased - $in_cart );

			$msg = DropLock_Helper::format_message(
				DropLock_Helper::get_limit_message( $effective_id ),
				array(
					'product_name'  => $this->get_product_name( $effective_id ),
					'limit'         => $limit,
					'purchased_qty' => $purchased,
					'cart_qty'      => $in_cart,
					'remaining_qty' => $remaining,
				)
			);

			wc_add_notice( $msg, 'error' );

			$this->logger->log(
				array(
					'product_id'    => $effective_id,
					'product_name'  => $this->get_product_name( $effective_id ),
					'user_id'       => $user_id,
					'billing_email' => $billing_email,
					'reason'        => 'add_to_cart_exceeded',
					'purchased_qty' => $purchased,
					'cart_qty'      => $in_cart + $attempted,
					'max_limit'     => $limit,
				)
			);

			return false;
		}

		return $passed;
	}

	/**
	 * Validate when the customer updates quantity in the cart page.
	 *
	 * @param bool   $passed
	 * @param string $cart_item_key
	 * @param array  $values
	 * @param int    $quantity
	 * @return bool
	 */
	public function validate_cart_update( $passed, $cart_item_key, $values, $quantity ) {
		if ( ! $passed ) {
			return $passed;
		}
		if ( DropLock_Helper::current_user_can_bypass() ) {
			return $passed;
		}

		$product_id   = isset( $values['product_id'] ) ? (int) $values['product_id'] : 0;
		$variation_id = isset( $values['variation_id'] ) ? (int) $values['variation_id'] : 0;
		$effective_id = DropLock_Helper::get_effective_product_id( $variation_id ?: $product_id );

		if ( ! DropLock_Helper::is_enabled( $effective_id ) ) {
			return $passed;
		}

		$limit         = DropLock_Helper::get_max_qty( $effective_id );
		$statuses      = DropLock_Helper::get_order_statuses( $effective_id );
		$user_id       = get_current_user_id();
		$billing_email = $this->resolve_billing_email_from_request();

		$purchased = DropLock_Order_Query::get_customer_purchased_quantity(
			$effective_id,
			$user_id,
			$billing_email,
			$statuses
		);

		// Cart total for this product, but treat the updated row as the new quantity.
		$existing_cart_total = DropLock_Order_Query::get_cart_quantity_for_product( $effective_id );
		$current_row_qty     = DropLock_Order_Query::get_cart_quantity_for_key( $cart_item_key );
		$new_cart_total      = $existing_cart_total - $current_row_qty + (int) $quantity;

		if ( ( $purchased + $new_cart_total ) > $limit ) {
			$remaining = max( 0, $limit - $purchased );

			$msg = DropLock_Helper::format_message(
				DropLock_Helper::get_limit_message( $effective_id ),
				array(
					'product_name'  => $this->get_product_name( $effective_id ),
					'limit'         => $limit,
					'purchased_qty' => $purchased,
					'cart_qty'      => $new_cart_total,
					'remaining_qty' => $remaining,
				)
			);

			wc_add_notice( $msg, 'error' );

			$this->logger->log(
				array(
					'product_id'    => $effective_id,
					'product_name'  => $this->get_product_name( $effective_id ),
					'user_id'       => $user_id,
					'billing_email' => $billing_email,
					'reason'        => 'cart_update_exceeded',
					'purchased_qty' => $purchased,
					'cart_qty'      => $new_cart_total,
					'max_limit'     => $limit,
				)
			);

			return false;
		}

		return $passed;
	}

	/**
	 * Re-check the entire cart on cart/checkout view.
	 */
	public function check_cart_items() {
		if ( DropLock_Helper::current_user_can_bypass() ) {
			return;
		}
		if ( ! WC()->cart ) {
			return;
		}

		$user_id       = get_current_user_id();
		$billing_email = $this->resolve_billing_email_from_request();

		// Aggregate cart quantities by effective product id.
		$seen = array();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product_id   = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			$variation_id = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
			$effective_id = DropLock_Helper::get_effective_product_id( $variation_id ?: $product_id );

			if ( ! $effective_id || isset( $seen[ $effective_id ] ) ) {
				continue;
			}
			$seen[ $effective_id ] = true;

			if ( ! DropLock_Helper::is_enabled( $effective_id ) ) {
				continue;
			}

			$limit    = DropLock_Helper::get_max_qty( $effective_id );
			$statuses = DropLock_Helper::get_order_statuses( $effective_id );

			$purchased = DropLock_Order_Query::get_customer_purchased_quantity(
				$effective_id,
				$user_id,
				$billing_email,
				$statuses
			);
			$cart_qty = DropLock_Order_Query::get_cart_quantity_for_product( $effective_id );

			if ( ( $purchased + $cart_qty ) > $limit ) {
				$remaining = max( 0, $limit - $purchased );
				$msg = DropLock_Helper::format_message(
					DropLock_Helper::get_limit_message( $effective_id ),
					array(
						'product_name'  => $this->get_product_name( $effective_id ),
						'limit'         => $limit,
						'purchased_qty' => $purchased,
						'cart_qty'      => $cart_qty,
						'remaining_qty' => $remaining,
					)
				);
				wc_add_notice( $msg, 'error' );

				$this->logger->log(
					array(
						'product_id'    => $effective_id,
						'product_name'  => $this->get_product_name( $effective_id ),
						'user_id'       => $user_id,
						'billing_email' => $billing_email,
						'reason'        => 'cart_check_exceeded',
						'purchased_qty' => $purchased,
						'cart_qty'      => $cart_qty,
						'max_limit'     => $limit,
					)
				);
			}
		}
	}

	/**
	 * Validate at checkout using the submitted billing email.
	 *
	 * @param array     $data
	 * @param WP_Error  $errors
	 */
	public function validate_checkout( $data, $errors ) {
		if ( DropLock_Helper::current_user_can_bypass() ) {
			return;
		}
		if ( ! WC()->cart ) {
			return;
		}

		$user_id       = get_current_user_id();
		$billing_email = isset( $data['billing_email'] ) ? sanitize_email( $data['billing_email'] ) : '';
		if ( '' === $billing_email && $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user && $user->user_email ) {
				$billing_email = sanitize_email( $user->user_email );
			}
		}

		$seen = array();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product_id   = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			$variation_id = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
			$effective_id = DropLock_Helper::get_effective_product_id( $variation_id ?: $product_id );

			if ( ! $effective_id || isset( $seen[ $effective_id ] ) ) {
				continue;
			}
			$seen[ $effective_id ] = true;

			if ( ! DropLock_Helper::is_enabled( $effective_id ) ) {
				continue;
			}

			$limit    = DropLock_Helper::get_max_qty( $effective_id );
			$statuses = DropLock_Helper::get_order_statuses( $effective_id );

			$purchased = DropLock_Order_Query::get_customer_purchased_quantity(
				$effective_id,
				$user_id,
				$billing_email,
				$statuses
			);
			$cart_qty = DropLock_Order_Query::get_cart_quantity_for_product( $effective_id );

			if ( ( $purchased + $cart_qty ) > $limit ) {
				$remaining = max( 0, $limit - $purchased );
				$msg = DropLock_Helper::format_message(
					DropLock_Helper::get_limit_message( $effective_id ),
					array(
						'product_name'  => $this->get_product_name( $effective_id ),
						'limit'         => $limit,
						'purchased_qty' => $purchased,
						'cart_qty'      => $cart_qty,
						'remaining_qty' => $remaining,
					)
				);

				if ( is_wp_error( $errors ) ) {
					$errors->add( 'droplock_limit_exceeded', $msg );
				} else {
					wc_add_notice( $msg, 'error' );
				}

				$this->logger->log(
					array(
						'product_id'    => $effective_id,
						'product_name'  => $this->get_product_name( $effective_id ),
						'user_id'       => $user_id,
						'billing_email' => $billing_email,
						'reason'        => 'checkout_exceeded',
						'purchased_qty' => $purchased,
						'cart_qty'      => $cart_qty,
						'max_limit'     => $limit,
					)
				);
			}
		}
	}

	/**
	 * Try to find a billing email outside of explicit checkout submission
	 * (used during add-to-cart and cart updates).
	 */
	protected function resolve_billing_email_from_request() {
		$email = '';

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( $user && $user->user_email ) {
				$email = sanitize_email( $user->user_email );
			}
		}

		if ( '' === $email && WC()->customer ) {
			$customer_email = WC()->customer->get_billing_email();
			if ( $customer_email ) {
				$email = sanitize_email( $customer_email );
			}
		}

		return $email;
	}

	protected function get_product_name( $product_id ) {
		$product = wc_get_product( $product_id );
		return $product ? $product->get_name() : '#' . $product_id;
	}
}
