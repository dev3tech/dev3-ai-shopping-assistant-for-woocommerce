<?php
/**
 * Revenue attribution: products added from the chat are remembered in the WooCommerce session;
 * orders containing them get order meta (HPOS-safe CRUD), and paid orders add revenue to the chat.
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant\Woo;

use Dev3\AiShoppingAssistant\Data\Conversations;

defined( 'ABSPATH' ) || exit;

/**
 * Session key dev3sa_assisted = [ product_id => conversation_id ].
 */
class Attribution {

	const SESSION = 'dev3sa_assisted';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'attribute' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'attribute' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'count_revenue' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'count_revenue' ) );
	}

	/**
	 * Remembers a product added from a conversation.
	 *
	 * @param int $product_id      Product.
	 * @param int $conversation_id Conversation.
	 */
	public static function remember( $product_id, $conversation_id ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$map                      = (array) WC()->session->get( self::SESSION, array() );
		$map[ (int) $product_id ] = (int) $conversation_id;
		WC()->session->set( self::SESSION, $map );
	}

	/**
	 * Tags a new order (classic and block checkout).
	 *
	 * @param \WC_Order  $order Order.
	 * @param array|null $map   Product => conversation map (default: session).
	 */
	public static function attribute( $order, $map = null ) {
		if ( ! $order instanceof \WC_Order || $order->get_meta( '_dev3sa_conversation' ) ) {
			return;
		}
		if ( null === $map ) {
			$map = function_exists( 'WC' ) && WC()->session ? (array) WC()->session->get( self::SESSION, array() ) : array();
		}
		if ( ! $map ) {
			return;
		}
		$total = 0.0;
		$conv  = 0;
		$ids   = array();
		foreach ( $order->get_items() as $item ) {
			$pid = (int) $item->get_product_id();
			if ( isset( $map[ $pid ] ) ) {
				$total += (float) $item->get_total();
				$conv   = (int) $map[ $pid ];
				$ids[]  = $pid;
			}
		}
		if ( ! $conv ) {
			return;
		}
		$order->update_meta_data( '_dev3sa_conversation', $conv );
		$order->update_meta_data( '_dev3sa_assisted_items', implode( ',', $ids ) );
		$order->update_meta_data( '_dev3sa_assisted_total', wc_format_decimal( $total, 2 ) );
		$order->save();
		Conversations::update( $conv, array( 'order_id' => $order->get_id() ) );
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION, array() );
		}
		if ( $order->is_paid() ) {
			self::count_revenue( $order->get_id() );
		}
	}

	/**
	 * Adds the assisted total to the conversation once the order is paid.
	 *
	 * @param int $order_id Order id.
	 */
	public static function count_revenue( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$conv = (int) $order->get_meta( '_dev3sa_conversation' );
		if ( ! $conv || $order->get_meta( '_dev3sa_revenue_counted' ) ) {
			return;
		}
		$row = Conversations::get( $conv );
		if ( ! $row ) {
			return;
		}
		$order->update_meta_data( '_dev3sa_revenue_counted', 1 );
		$order->save();
		Conversations::update(
			$conv,
			array(
				'revenue'  => round( (float) $row['revenue'] + (float) $order->get_meta( '_dev3sa_assisted_total' ), 4 ),
				'order_id' => $order->get_id(),
			)
		);
	}
}
