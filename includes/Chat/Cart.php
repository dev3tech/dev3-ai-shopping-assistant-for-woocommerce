<?php
/**
 * Cart actions from the chat (REST requests load the WooCommerce session + cart themselves).
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant\Chat;

use Dev3\AiShoppingAssistant\Data\Conversations;
use Dev3\AiShoppingAssistant\Woo\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over WC()->cart with validation and chat attribution.
 */
class Cart {

	/**
	 * Loads the session and cart (REST requests do not by default).
	 *
	 * @return bool
	 */
	public static function boot() {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}
		if ( defined( 'WC_ABSPATH' ) ) {
			foreach ( array( 'wc-cart-functions.php', 'wc-notice-functions.php' ) as $file ) {
				if ( is_readable( WC_ABSPATH . 'includes/' . $file ) ) {
					include_once WC_ABSPATH . 'includes/' . $file;
				}
			}
		}
		if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		return null !== WC()->cart;
	}

	/**
	 * Adds a product.
	 *
	 * @param int        $product_id   Product.
	 * @param int        $quantity     Quantity.
	 * @param array|null $conversation Conversation (attribution).
	 * @return array|\WP_Error { key, product, cart }
	 */
	public static function add( $product_id, $quantity = 1, $conversation = null ) {
		if ( ! self::boot() ) {
			return new \WP_Error( 'dev3sa_no_cart', __( 'The cart is not available right now.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 503 ) );
		}
		$product = wc_get_product( (int) $product_id );
		if ( ! Product_Search::visible( $product ) ) {
			return new \WP_Error( 'dev3sa_bad_product', __( 'That product is not available.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 404 ) );
		}
		if ( 'simple' !== $product->get_type() ) {
			return new \WP_Error( 'dev3sa_needs_options', __( 'This product has options. Choose them on the product page.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 409 ) );
		}
		if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return new \WP_Error( 'dev3sa_unavailable', __( 'Sorry, this product is out of stock.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 409 ) );
		}
		$quantity = max( 1, min( 99, (int) $quantity ) );
		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}
		$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), $quantity ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core WooCommerce hook.
		$key    = $passed ? WC()->cart->add_to_cart( $product->get_id(), $quantity ) : false;
		if ( ! $key ) {
			$message = __( 'Sorry, we could not add this product to your cart.', 'dev3-ai-shopping-assistant-for-woocommerce' );
			if ( function_exists( 'wc_get_notices' ) ) {
				$errors = wc_get_notices( 'error' );
				if ( $errors ) {
					$first   = reset( $errors );
					$message = wp_strip_all_tags( is_array( $first ) ? $first['notice'] : (string) $first );
				}
				wc_clear_notices();
			}
			return new \WP_Error( 'dev3sa_add_failed', $message, array( 'status' => 409 ) );
		}
		if ( $conversation ) {
			Attribution::remember( $product->get_id(), (int) $conversation['id'] );
			Conversations::bump( $conversation['id'], 'carts' );
		}
		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}
		WC()->cart->calculate_totals();
		return array(
			'key'      => $key,
			'product'  => wp_strip_all_tags( $product->get_name() ),
			'quantity' => $quantity,
			'cart'     => self::summary(),
		);
	}

	/**
	 * Removes a cart line (undo).
	 *
	 * @param string     $key          Cart item key.
	 * @param array|null $conversation Conversation.
	 * @return array|\WP_Error Cart summary.
	 */
	public static function remove( $key, $conversation = null ) {
		if ( ! self::boot() ) {
			return new \WP_Error( 'dev3sa_no_cart', __( 'The cart is not available right now.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 503 ) );
		}
		$key = preg_replace( '/[^a-f0-9]/', '', (string) $key );
		if ( '' === $key || ! WC()->cart->get_cart_item( $key ) ) {
			return new \WP_Error( 'dev3sa_no_line', __( 'That item is no longer in your cart.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 404 ) );
		}
		WC()->cart->remove_cart_item( $key );
		WC()->cart->calculate_totals();
		if ( $conversation ) {
			Conversations::bump( $conversation['id'], 'carts', -1 );
		}
		return array( 'cart' => self::summary() );
	}

	/**
	 * Cart summary for the widget.
	 *
	 * @return array { count, total_html, items, cart_url, checkout_url }
	 */
	public static function summary() {
		$out = array(
			'count'        => 0,
			'total_html'   => '',
			'items'        => array(),
			'cart_url'     => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '',
			'checkout_url' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
		);
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return $out;
		}
		$out['count']      = (int) WC()->cart->get_cart_contents_count();
		$out['total_html'] = wp_kses_post( WC()->cart->get_cart_subtotal() );
		foreach ( WC()->cart->get_cart() as $line ) {
			if ( empty( $line['data'] ) || ! $line['data'] instanceof \WC_Product ) {
				continue;
			}
			$out['items'][] = array(
				'name' => wp_strip_all_tags( $line['data']->get_name() ),
				'qty'  => (int) $line['quantity'],
				'id'   => (int) $line['product_id'],
			);
		}
		return $out;
	}
}
