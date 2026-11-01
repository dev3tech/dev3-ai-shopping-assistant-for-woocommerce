<?php
/**
 * Storefront routes (no login needed): chat, history, cart, order lookup, handoff, rating.
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant\Rest;

use Dev3\AiShoppingAssistant\Chat\Cart;
use Dev3\AiShoppingAssistant\Chat\Engine;
use Dev3\AiShoppingAssistant\Chat\Handoff;
use Dev3\AiShoppingAssistant\Chat\Orders;
use Dev3\AiShoppingAssistant\Chat\Product_Search;
use Dev3\AiShoppingAssistant\Chat\Text;
use Dev3\AiShoppingAssistant\Core3\Rest\Base_Controller;
use Dev3\AiShoppingAssistant\Data\Conversations;
use Dev3\AiShoppingAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Same-origin + per-IP rate limit (Base_Controller::public_permission). A conversation is bound to
 * its random token, and to the account when the shopper is logged in (they also send the REST nonce).
 */
class Public_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$routes = array(
			'chat'    => array( 'POST', 'chat', 'dev3sa_chat', 0 ),
			'history' => array( 'GET', 'history', 'dev3sa_history', 30 ),
			'cart'    => array( 'POST', 'cart', 'dev3sa_cart', 20 ),
			'order'   => array( 'POST', 'order', 'dev3sa_order', 6 ),
			'handoff' => array( 'POST', 'handoff', 'dev3sa_handoff', 4 ),
			'rate'    => array( 'POST', 'rate', 'dev3sa_rate', 30 ),
		);
		foreach ( $routes as $path => $route ) {
			register_rest_route(
				self::NS,
				'/public/' . $path,
				array(
					'methods'             => $route[0],
					'callback'            => array( $this, $route[1] ),
					'permission_callback' => function ( $request ) use ( $route ) {
						$limit = $route[3] ? $route[3] : (int) Settings::get( 'chat_rate_limit', 12 );
						return $this->public_permission( $request, $route[2], $limit );
					},
				)
			);
		}
	}

	/**
	 * Conversation for the request's token (or a new one).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $create  Create when missing.
	 * @return array|null
	 */
	public static function conversation( \WP_REST_Request $request, $create = false ) {
		$token = (string) $request->get_param( 'token' );
		$conv  = Conversations::by_token( $token );
		$user  = get_current_user_id();
		if ( $conv && $conv['user_id'] && $conv['user_id'] !== $user ) {
			$conv = null; // A token from another account (shared device): start fresh.
		}
		if ( $conv && ! $conv['user_id'] && $user ) {
			Conversations::update( $conv['id'], array( 'user_id' => $user ) );
			$conv['user_id'] = $user;
		}
		if ( ! $conv && $create ) {
			$context = (array) $request->get_param( 'context' );
			$conv    = Conversations::create(
				array(
					'source_url' => isset( $context['url'] ) ? (string) $context['url'] : '',
					'lang'       => (string) $request->get_param( 'lang' ),
				)
			);
		}
		return $conv;
	}

	/**
	 * Widget shape of a stored or fresh bot message.
	 *
	 * @param int   $id    Message id.
	 * @param array $reply Reply (text, products cards, actions, quick_replies, source).
	 * @param int   $rating Rating.
	 * @return array
	 */
	public static function shape( $id, array $reply, $rating = 0 ) {
		return array(
			'id'            => (int) $id,
			'role'          => 'bot',
			'text'          => (string) $reply['text'],
			'products'      => array_values( (array) $reply['products'] ),
			'actions'       => array_values( (array) $reply['actions'] ),
			'quick_replies' => array_values( (array) $reply['quick_replies'] ),
			'source'        => (string) $reply['source'],
			'rating'        => (int) $rating,
		);
	}

	/**
	 * Stores the shopper message + the reply, returns the widget message.
	 *
	 * @param array  $conv    Conversation.
	 * @param string $message Shopper text.
	 * @param array  $reply   Reply.
	 * @return array
	 */
	public static function persist( array $conv, $message, array $reply ) {
		Conversations::add_message(
			$conv['id'],
			'user',
			$message,
			array(
				'intent' => ! empty( $reply['unanswered'] ) ? 'unanswered' : $reply['intent'],
				'source' => 'shopper',
			)
		);
		$actions = array_map(
			static function ( $a ) {
				unset( $a['cart'] );
				return $a;
			},
			(array) $reply['actions']
		);
		$id      = Conversations::add_message(
			$conv['id'],
			'bot',
			$reply['text'],
			array(
				'intent'   => $reply['intent'],
				'source'   => $reply['source'],
				'products' => wp_list_pluck( (array) $reply['products'], 'id' ),
				'meta'     => array(
					'actions'       => $actions,
					'quick_replies' => $reply['quick_replies'],
					'tokens'        => (int) $reply['tokens'],
					'reason'        => (string) ( $reply['debug']['reason'] ?? '' ),
				),
			)
		);
		if ( '' !== $reply['lang'] && '' === (string) $conv['lang'] ) {
			Conversations::update( $conv['id'], array( 'lang' => $reply['lang'] ) );
		}
		if ( 'needs_reply' !== $conv['status'] && 'open' !== $conv['status'] ) {
			Conversations::update( $conv['id'], array( 'status' => 'open' ) );
		}
		return self::shape( $id, $reply );
	}

	/**
	 * POST /public/chat.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function chat( \WP_REST_Request $request ) {
		$message = Text::input( (string) $request->get_param( 'message' ), 1000 );
		if ( '' === $message ) {
			return $this->respond( new \WP_Error( 'dev3sa_empty', __( 'Type a message first.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		$conv    = self::conversation( $request, true );
		$context = (array) $request->get_param( 'context' );
		$recent  = array();
		foreach ( array_reverse( Conversations::messages( $conv['id'], 6 ) ) as $m ) {
			if ( 'bot' === $m['role'] && $m['products'] ) {
				$recent = $m['products'];
				break;
			}
		}
		$product_id = isset( $context['product_id'] ) ? absint( $context['product_id'] ) : 0;
		$reply      = Engine::reply(
			$message,
			array(
				'history'         => Conversations::history( $conv['id'], 8 ),
				'product_id'      => $product_id && Product_Search::visible( wc_get_product( $product_id ) ) ? $product_id : 0,
				'recent_products' => $recent,
				'conversation'    => $conv,
				'user_id'         => get_current_user_id(),
				'mode'            => 'storefront',
				'lang'            => sanitize_text_field( (string) $request->get_param( 'lang' ) ),
			)
		);
		$out        = self::persist( $conv, $message, $reply );
		return $this->respond(
			array(
				'token'    => $conv['token'],
				'messages' => array( $out ),
			)
		);
	}

	/**
	 * GET /public/history?token=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function history( \WP_REST_Request $request ) {
		$conv = self::conversation( $request );
		if ( ! $conv ) {
			return $this->respond(
				array(
					'token'    => '',
					'messages' => array(),
				)
			);
		}
		$out = array();
		foreach ( Conversations::messages( $conv['id'], 60 ) as $m ) {
			if ( 'system' === $m['role'] ) {
				continue;
			}
			if ( 'bot' !== $m['role'] ) {
				$out[] = array(
					'id'   => $m['id'],
					'role' => $m['role'],
					'text' => $m['content'],
				);
				continue;
			}
			$out[] = self::shape(
				$m['id'],
				array(
					'text'          => $m['content'],
					'products'      => Product_Search::cards( $m['products'] ),
					'actions'       => (array) ( $m['meta']['actions'] ?? array() ),
					'quick_replies' => array(),
					'source'        => $m['source'],
				),
				$m['rating']
			);
		}
		return $this->respond(
			array(
				'token'    => $conv['token'],
				'status'   => $conv['status'],
				'messages' => $out,
			)
		);
	}

	/**
	 * POST /public/cart: add ({ product_id, quantity }) or undo ({ key, remove: true }).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function cart( \WP_REST_Request $request ) {
		if ( ! Settings::get( 'add_to_cart', true ) ) {
			return $this->respond( new \WP_Error( 'dev3sa_cart_off', __( 'Adding to the cart from the chat is turned off.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 403 ) ) );
		}
		$conv = self::conversation( $request );
		if ( $request->get_param( 'remove' ) ) {
			return $this->respond( Cart::remove( (string) $request->get_param( 'key' ), $conv ) );
		}
		return $this->respond( Cart::add( absint( $request->get_param( 'product_id' ) ), (int) $request->get_param( 'quantity' ), $conv ) );
	}

	/**
	 * POST /public/order: guest lookup ({ order, email }) or the customer's own orders.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function order( \WP_REST_Request $request ) {
		if ( ! Settings::get( 'order_tracking', true ) ) {
			return $this->respond( new \WP_Error( 'dev3sa_tracking_off', __( 'Order tracking is not available in the chat.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 403 ) ) );
		}
		$number = sanitize_text_field( (string) $request->get_param( 'order' ) );
		$email  = sanitize_email( (string) $request->get_param( 'email' ) );
		$user   = get_current_user_id();
		$order  = $user && '' !== $number ? Orders::own( $number, $user ) : null;
		if ( ! $order ) {
			$order = Orders::lookup( $number, $email );
		}
		$conv = self::conversation( $request, true );
		if ( is_wp_error( $order ) ) {
			Conversations::add_message(
				$conv['id'],
				'system',
				'Order lookup failed for #' . mb_substr( $number, 0, 30 ),
				array( 'intent' => 'order' )
			);
			return $this->respond( $order );
		}
		$owner   = $user && (int) $order->get_customer_id() === $user;
		$summary = Orders::summary( $order, $owner );
		if ( ! $conv['email'] && $email ) {
			Conversations::update( $conv['id'], array( 'email' => $email ) );
		}
		/* translators: %s: order number */
		$asked = sprintf( __( 'Order #%s', 'dev3-ai-shopping-assistant-for-woocommerce' ), $summary['number'] );
		$out   = self::persist(
			$conv,
			$asked,
			Engine::make(
				$summary['message'],
				'order',
				array(
					'actions' => array(
						array(
							'type'  => 'order',
							'order' => $summary,
						),
					),
				)
			)
		);
		return $this->respond(
			array(
				'token'    => $conv['token'],
				'messages' => array( $out ),
			)
		);
	}

	/**
	 * POST /public/handoff.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handoff( \WP_REST_Request $request ) {
		if ( ! Settings::get( 'handoff', true ) ) {
			return $this->respond( new \WP_Error( 'dev3sa_handoff_off', __( 'Messages to the team are turned off.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 403 ) ) );
		}
		$conv = self::conversation( $request, true );
		$sent = Handoff::send( $conv, (string) $request->get_param( 'name' ), (string) $request->get_param( 'email' ), (string) $request->get_param( 'message' ) );
		if ( is_wp_error( $sent ) ) {
			return $this->respond( $sent );
		}
		$text = __( 'Thanks! Your message is with our team. We will reply by email as soon as we can.', 'dev3-ai-shopping-assistant-for-woocommerce' );
		$id   = Conversations::add_message(
			$conv['id'],
			'bot',
			$text,
			array(
				'intent' => 'handoff',
				'source' => 'rules',
			)
		);
		return $this->respond(
			array(
				'token'    => $conv['token'],
				'messages' => array( self::shape( $id, Engine::make( $text, 'handoff' ) ) ),
			)
		);
	}

	/**
	 * POST /public/rate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rate( \WP_REST_Request $request ) {
		$conv = self::conversation( $request );
		if ( ! $conv || ! Conversations::rate( $conv, absint( $request->get_param( 'message_id' ) ), (int) $request->get_param( 'rating' ) ) ) {
			return $this->respond( new \WP_Error( 'dev3sa_bad_rating', __( 'That message could not be rated.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 404 ) ) );
		}
		return $this->respond( array( 'ok' => true ) );
	}
}
