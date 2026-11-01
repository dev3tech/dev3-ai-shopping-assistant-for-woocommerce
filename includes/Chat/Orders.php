<?php
/**
 * "Where is my order": safe order lookup and a shopper-facing status summary (HPOS-safe CRUD only).
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Guests need the order number AND the billing email; customers see only their own orders.
 * Addresses, phone numbers and payment details are never returned.
 */
class Orders {

	/**
	 * Finds an order id from what the shopper typed ("#1234", "1234", custom order numbers).
	 *
	 * @param string $number Order number.
	 * @return int
	 */
	public static function find_id( $number ) {
		$number = trim( preg_replace( '/^(order\s*)?#?/i', '', (string) $number ) );
		if ( '' === $number || strlen( $number ) > 30 ) {
			return 0;
		}
		$id = ctype_digit( $number ) ? (int) $number : 0;
		/**
		 * Maps a shopper-visible order number to an order id (sequential order number plugins).
		 *
		 * @param int    $id     Order id guess (0 when not numeric).
		 * @param string $number What the shopper typed.
		 */
		return (int) apply_filters( 'dev3sa_find_order_id', $id, $number );
	}

	/**
	 * Guest lookup by number + billing email.
	 *
	 * @param string $number Order number.
	 * @param string $email  Billing email.
	 * @return \WC_Order|\WP_Error
	 */
	public static function lookup( $number, $email ) {
		$not_found = new \WP_Error( 'dev3sa_order_not_found', __( 'We could not find an order with that number and email address. Check both and try again.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 404 ) );
		$id        = self::find_id( $number );
		$email     = strtolower( trim( sanitize_email( (string) $email ) ) );
		if ( ! $id || '' === $email ) {
			return $not_found;
		}
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() ) {
			return $not_found;
		}
		if ( ! hash_equals( strtolower( (string) $order->get_billing_email() ), $email ) ) {
			return $not_found;
		}
		return $order;
	}

	/**
	 * Order of the logged-in customer by number.
	 *
	 * @param string $number  Number.
	 * @param int    $user_id User.
	 * @return \WC_Order|null
	 */
	public static function own( $number, $user_id ) {
		$id    = self::find_id( $number );
		$order = $id ? wc_get_order( $id ) : null;
		if ( ! $order instanceof \WC_Order || ! $user_id || (int) $order->get_customer_id() !== (int) $user_id ) {
			return null;
		}
		return $order;
	}

	/**
	 * Recent orders of a customer.
	 *
	 * @param int $user_id User.
	 * @param int $limit   Max.
	 * @return \WC_Order[]
	 */
	public static function recent( $user_id, $limit = 3 ) {
		if ( ! $user_id ) {
			return array();
		}
		return (array) wc_get_orders(
			array(
				'customer_id' => (int) $user_id,
				'limit'       => $limit,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'type'        => 'shop_order',
			)
		);
	}

	/**
	 * Tracking numbers from common shipment-tracking plugins.
	 *
	 * @param \WC_Order $order Order.
	 * @return array [ { provider, number, url } ]
	 */
	public static function tracking( \WC_Order $order ) {
		$out   = array();
		$items = $order->get_meta( '_wc_shipment_tracking_items' );
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( empty( $item['tracking_number'] ) ) {
					continue;
				}
				$out[] = array(
					'provider' => (string) ( ! empty( $item['custom_tracking_provider'] ) ? $item['custom_tracking_provider'] : ( $item['tracking_provider'] ?? '' ) ),
					'number'   => (string) $item['tracking_number'],
					'url'      => (string) ( $item['custom_tracking_link'] ?? '' ),
				);
			}
		}
		$number = (string) $order->get_meta( '_tracking_number' );
		if ( '' !== $number ) {
			$out[] = array(
				'provider' => (string) $order->get_meta( '_tracking_provider' ),
				'number'   => $number,
				'url'      => (string) $order->get_meta( '_tracking_url' ),
			);
		}
		/**
		 * Filters tracking numbers shown in the chat.
		 *
		 * @param array     $out   [ { provider, number, url } ].
		 * @param \WC_Order $order Order.
		 */
		$out = (array) apply_filters( 'dev3sa_order_tracking', $out, $order );
		return array_map(
			static fn( $t ) => array(
				'provider' => sanitize_text_field( (string) ( $t['provider'] ?? '' ) ),
				'number'   => sanitize_text_field( (string) ( $t['number'] ?? '' ) ),
				'url'      => esc_url_raw( (string) ( $t['url'] ?? '' ) ),
			),
			$out
		);
	}

	/**
	 * Shopper-facing summary.
	 *
	 * @param \WC_Order $order Order.
	 * @param bool      $owner Whether the viewer is the logged-in owner (adds the account link).
	 * @return array
	 */
	public static function summary( \WC_Order $order, $owner = false ) {
		$status = $order->get_status();
		$items  = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name' => wp_strip_all_tags( $item->get_name() ),
				'qty'  => (int) $item->get_quantity(),
			);
		}
		$tracking = self::tracking( $order );
		$created  = $order->get_date_created();
		$summary  = array(
			'number'       => (string) $order->get_order_number(),
			'status'       => $status,
			'status_label' => wc_get_order_status_name( $status ),
			'date'         => $created ? wc_format_datetime( $created ) : '',
			'total_html'   => wp_kses_post( $order->get_formatted_order_total() ),
			'items'        => array_slice( $items, 0, 10 ),
			'more_items'   => max( 0, count( $items ) - 10 ),
			'shipping'     => wp_strip_all_tags( $order->get_shipping_method() ),
			'payment'      => wp_strip_all_tags( $order->get_payment_method_title() ),
			'tracking'     => $tracking,
			'steps'        => self::steps( $status, ! empty( $tracking ) ),
			'message'      => self::status_message( $status, ! empty( $tracking ) ),
			'url'          => $owner ? $order->get_view_order_url() : '',
		);
		/**
		 * Filters the order summary shown in the chat.
		 *
		 * @param array     $summary Summary.
		 * @param \WC_Order $order   Order.
		 */
		return apply_filters( 'dev3sa_order_summary', $summary, $order );
	}

	/**
	 * Status timeline.
	 *
	 * @param string $status       Status.
	 * @param bool   $has_tracking Tracking added.
	 * @return array [ { label, done } ]
	 */
	public static function steps( $status, $has_tracking = false ) {
		$rank = array(
			'pending'    => 1,
			'on-hold'    => 1,
			'failed'     => 1,
			'processing' => 2,
			'completed'  => 4,
		);
		$at   = $rank[ $status ] ?? 1;
		if ( 'processing' === $status && $has_tracking ) {
			$at = 3;
		}
		$labels = array(
			__( 'Order placed', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			__( 'Payment received', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			__( 'Shipped', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			__( 'Completed', 'dev3-ai-shopping-assistant-for-woocommerce' ),
		);
		if ( in_array( $status, array( 'cancelled', 'refunded' ), true ) ) {
			return array(
				array(
					'label' => $labels[0],
					'done'  => true,
				),
				array(
					'label' => wc_get_order_status_name( $status ),
					'done'  => true,
				),
			);
		}
		$out = array();
		foreach ( $labels as $i => $label ) {
			$out[] = array(
				'label' => $label,
				'done'  => $i < $at,
			);
		}
		return $out;
	}

	/**
	 * One-line explanation of a status.
	 *
	 * @param string $status       Status.
	 * @param bool   $has_tracking Tracking added.
	 * @return string
	 */
	public static function status_message( $status, $has_tracking = false ) {
		switch ( $status ) {
			case 'pending':
				return __( 'We are waiting for the payment to complete.', 'dev3-ai-shopping-assistant-for-woocommerce' );
			case 'on-hold':
				return __( 'Your order is on hold while we confirm the payment.', 'dev3-ai-shopping-assistant-for-woocommerce' );
			case 'processing':
				return $has_tracking ? __( 'Your order is on its way. Use the tracking number below.', 'dev3-ai-shopping-assistant-for-woocommerce' ) : __( 'Payment received. We are preparing your order for shipping.', 'dev3-ai-shopping-assistant-for-woocommerce' );
			case 'completed':
				return __( 'Your order has been fulfilled and shipped.', 'dev3-ai-shopping-assistant-for-woocommerce' );
			case 'cancelled':
				return __( 'This order was cancelled.', 'dev3-ai-shopping-assistant-for-woocommerce' );
			case 'refunded':
				return __( 'This order was refunded.', 'dev3-ai-shopping-assistant-for-woocommerce' );
			case 'failed':
				return __( 'The payment for this order failed. You can try again from your account.', 'dev3-ai-shopping-assistant-for-woocommerce' );
		}
		/* translators: %s: order status */
		return sprintf( __( 'Current status: %s.', 'dev3-ai-shopping-assistant-for-woocommerce' ), wc_get_order_status_name( $status ) );
	}

	/**
	 * Order number mentioned in a message, if any ("order 1234", "#1234").
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function number_in( $text ) {
		if ( preg_match( '/#\s?([A-Za-z0-9-]{2,20})/', (string) $text, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/\border\s*(?:number|no\.?|num)?\s*:?\s*([0-9][A-Za-z0-9-]{1,19})\b/i', (string) $text, $m ) ) {
			return $m[1];
		}
		return '';
	}
}
