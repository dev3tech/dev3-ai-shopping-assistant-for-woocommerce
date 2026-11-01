<?php
/**
 * DEV3 AI chat (A1): retrieval-augmented answer with product picks and validated actions.
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant\Ai;

use Dev3\AiShoppingAssistant\Chat\Cart;
use Dev3\AiShoppingAssistant\Chat\Engine;
use Dev3\AiShoppingAssistant\Chat\Orders;
use Dev3\AiShoppingAssistant\Chat\Product_Search;
use Dev3\AiShoppingAssistant\Chat\Retriever;
use Dev3\AiShoppingAssistant\Chat\Rules;
use Dev3\AiShoppingAssistant\Chat\Text;
use Dev3\AiShoppingAssistant\Core3\Api\Prompts;
use Dev3\AiShoppingAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The model only chooses: it never renders prices (cards come from WooCommerce), its product ids
 * are intersected with the retrieved candidates, and cart actions are validated here.
 */
class Assistant {

	/**
	 * Answers a message.
	 *
	 * @param string $message Message.
	 * @param array  $ctx     history, product_id, recent_products, conversation, user_id, mode (storefront|admin), lang.
	 * @return array|\WP_Error Reply.
	 */
	public static function answer( $message, array $ctx ) {
		$mode    = isset( $ctx['mode'] ) && 'admin' === $ctx['mode'] ? 'admin' : 'storefront';
		$history = isset( $ctx['history'] ) ? array_slice( (array) $ctx['history'], -8 ) : array();

		// Follow-ups ("the blue one?") need the previous question for retrieval.
		$query = $message;
		foreach ( array_reverse( $history ) as $turn ) {
			if ( 'user' === $turn['role'] ) {
				$query = Text::cut( $turn['text'], 200 ) . ' ' . $message;
				break;
			}
		}
		Retriever::$debug = array();
		$candidates       = Settings::get( 'product_search', true ) ? Retriever::products( $query, 8, $mode ) : array();
		foreach ( array_merge( (array) ( $ctx['recent_products'] ?? array() ), ! empty( $ctx['product_id'] ) ? array( (int) $ctx['product_id'] ) : array() ) as $extra ) {
			if ( ! in_array( (int) $extra, $candidates, true ) ) {
				$candidates[] = (int) $extra;
			}
		}
		// Shoppers asking for "best sellers"/"deals" without other words.
		if ( ! $candidates && Settings::get( 'product_search', true ) ) {
			$parsed = Product_Search::parse( $message );
			if ( '' !== $parsed['sort'] || $parsed['on_sale'] || preg_match( '/\b(recommend|suggest|gift|ideas?)\b/i', $message ) ) {
				$candidates = Product_Search::listing( '' !== $parsed['sort'] ? $parsed['sort'] : 'popular', $parsed['on_sale'], 6 );
			}
		}
		$candidates = array_slice( array_values( array_unique( array_map( 'intval', $candidates ) ) ), 0, 12 );

		$products = array();
		foreach ( $candidates as $id ) {
			$product = wc_get_product( $id );
			if ( Product_Search::visible( $product ) ) {
				$facts = Product_Search::facts( $product, ! empty( $ctx['product_id'] ) && (int) $ctx['product_id'] === $id ? 600 : 200 );
				if ( ! empty( $ctx['product_id'] ) && (int) $ctx['product_id'] === $id ) {
					$facts['current_page'] = true;
				}
				$products[] = $facts;
			}
		}
		$allowed   = wp_list_pluck( $products, 'id' );
		$knowledge = array_map(
			static fn( $e ) => array(
				'title'  => $e['title'],
				'answer' => Text::cut( $e['content'], 700 ),
			),
			Retriever::knowledge( $query, 4, $mode )
		);

		$context = array(
			'products'  => $products,
			'knowledge' => $knowledge,
		);
		if ( Settings::get( 'add_to_cart', true ) && 'storefront' === $mode ) {
			$cart            = Cart::boot() ? Cart::summary() : null;
			$context['cart'] = $cart ? array(
				'items'    => $cart['items'],
				'subtotal' => html_entity_decode( wp_strip_all_tags( $cart['total_html'] ), ENT_QUOTES, 'UTF-8' ),
			) : null;
		}
		$user_id = isset( $ctx['user_id'] ) ? (int) $ctx['user_id'] : get_current_user_id();
		if ( $user_id && Settings::get( 'order_tracking', true ) ) {
			$user                = get_userdata( $user_id );
			$context['customer'] = array(
				'first_name'    => $user ? (string) $user->first_name : '',
				'recent_orders' => array_map(
					static fn( $o ) => array(
						'number' => $o->get_order_number(),
						'status' => wc_get_order_status_name( $o->get_status() ),
						'date'   => $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d' ) : '',
					),
					Orders::recent( $user_id, 3 )
				),
			);
		}
		$context['capabilities'] = array(
			'add_to_cart'    => (bool) Settings::get( 'add_to_cart', true ),
			'order_tracking' => (bool) Settings::get( 'order_tracking', true ),
			'handoff'        => (bool) Settings::get( 'handoff', true ),
		);

		$tones    = array(
			'friendly'     => 'friendly and helpful',
			'professional' => 'professional and precise',
			'playful'      => 'playful and upbeat',
			'luxury'       => 'elegant, refined and attentive',
			'concise'      => 'concise and to the point',
		);
		$vars     = array(
			'bot_name'     => (string) Settings::get( 'bot_name' ),
			'store'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'url'          => home_url( '/' ),
			'tone'         => $tones[ (string) Settings::get( 'ai_tone', 'friendly' ) ] ?? $tones['friendly'],
			'length'       => 'medium' === Settings::get( 'answer_length', 'short' ) ? 'up to 120 words' : 'one to three short sentences (max 60 words)',
			'max_products' => (int) Settings::get( 'max_products', 4 ),
			'cart_rule'    => Settings::get( 'add_to_cart', true ) ? '' : 'Adding to the cart from the chat is turned off: send the shopper to the product page instead.',
			'language'     => ! empty( $ctx['lang'] ) ? (string) $ctx['lang'] : get_locale(),
			'instructions' => '' !== trim( (string) Settings::get( 'ai_instructions', '' ) ) ? "Store owner's instructions:\n" . Settings::get( 'ai_instructions', '' ) : '',
			'context'      => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'message'      => $message,
		);
		$prompt   = Prompts::get( 'chat' );
		$contents = array();
		foreach ( $history as $turn ) {
			$contents[] = array(
				'role'  => 'user' === $turn['role'] ? 'user' : 'model',
				'parts' => array( array( 'text' => Text::cut( (string) $turn['text'], 800 ) ) ),
			);
		}
		$contents[] = array(
			'role'  => 'user',
			'parts' => array( array( 'text' => Prompts::fill( $prompt['template'], $vars ) ) ),
		);
		$extra      = array( 'contents' => $contents );
		// Cache first-turn questions of guests (identical context → identical answer).
		if ( ! $history && empty( $context['cart']['items'] ) && ! $user_id ) {
			$extra['cache'] = HOUR_IN_SECONDS;
		}
		/**
		 * Filters the chat AI arguments before the call.
		 *
		 * @param array  $extra   Extra Ai::run args (contents, cache…).
		 * @param array  $context Context sent to the model.
		 * @param string $message Shopper message.
		 */
		$extra  = apply_filters( 'dev3sa_chat_ai_args', $extra, $context, $message );
		$result = Prompts::run( 'chat', $vars, 'admin' === $mode ? 'admin' : 'storefront', $extra );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		$text = Text::output( (string) ( $data['reply'] ?? '' ), 1500 );
		if ( '' === $text ) {
			return new \WP_Error( 'dev3sa_empty_reply', 'Empty AI reply' );
		}

		$picked = array_values( array_intersect( array_map( 'intval', (array) ( $data['product_ids'] ?? array() ) ), $allowed ) );
		$picked = array_slice( $picked, 0, (int) Settings::get( 'max_products', 4 ) );
		$intent = in_array( $data['intent'] ?? '', Prompts_Library::INTENTS, true ) ? $data['intent'] : 'other';
		$chips  = array_slice( array_values( array_filter( array_map( static fn( $q ) => mb_substr( sanitize_text_field( (string) $q ), 0, 40 ), (array) ( $data['quick_replies'] ?? array() ) ) ) ), 0, 3 );
		$reply  = Engine::make(
			$text,
			$intent,
			array(
				'source'        => ! empty( $result['cached'] ) ? 'cache' : 'ai',
				'products'      => Product_Search::cards( $picked ),
				'quick_replies' => $chips,
				'unanswered'    => isset( $data['answered'] ) && false === $data['answered'],
				'tokens'        => (int) ( $result['usage']['total'] ?? 0 ),
				'lang'          => mb_substr( sanitize_key( (string) ( $data['language'] ?? '' ) ), 0, 12 ),
			)
		);
		$reply  = self::apply_action( $reply, (array) ( $data['action'] ?? array() ), $allowed, $message, $ctx );

		$reply['debug'] = array(
			'model'      => (string) ( $result['model'] ?? '' ),
			'cached'     => ! empty( $result['cached'] ),
			'tokens'     => (int) ( $result['usage']['total'] ?? 0 ),
			'candidates' => $allowed,
			'retrieval'  => Retriever::$debug,
			'knowledge'  => wp_list_pluck( $knowledge, 'title' ),
			'action'     => $data['action'] ?? null,
			'version'    => $result['prompt_version'] ?? '',
		);
		return $reply;
	}

	/**
	 * Validates and runs the model's action.
	 *
	 * @param array  $reply   Reply.
	 * @param array  $action  Action from the model.
	 * @param int[]  $allowed Candidate ids.
	 * @param string $message Shopper message.
	 * @param array  $ctx     Context.
	 * @return array Reply.
	 */
	public static function apply_action( array $reply, array $action, array $allowed, $message, array $ctx ) {
		$type = (string) ( $action['type'] ?? 'none' );
		$mode = $ctx['mode'] ?? 'storefront';
		if ( 'add_to_cart' === $type && Settings::get( 'add_to_cart', true ) ) {
			$pid = (int) ( $action['product_id'] ?? 0 );
			$qty = max( 1, min( 99, (int) ( $action['quantity'] ?? 1 ) ) );
			if ( ! in_array( $pid, $allowed, true ) ) {
				return $reply;
			}
			$card = Product_Search::card( $pid );
			if ( ! $card ) {
				return $reply;
			}
			if ( ! $card['can_add'] ) {
				$reply['products'] = array( $card );
				$reply['text']     = __( 'This product has options to choose (like size or colour). Pick them on the product page and add it from there.', 'dev3-ai-shopping-assistant-for-woocommerce' );
				return $reply;
			}
			if ( 'admin' === $mode || ! Settings::get( 'ai_auto_add', true ) ) {
				// Test console, or the store wants a tap first: show the card with its button.
				$reply['products'] = array( $card );
				if ( 'admin' === $mode ) {
					$reply['actions'][] = array(
						'type'     => 'would_add',
						'product'  => $card['name'],
						'quantity' => $qty,
					);
				}
				return $reply;
			}
			$added = Cart::add( $pid, $qty, $ctx['conversation'] ?? null );
			if ( is_wp_error( $added ) ) {
				$reply['text']     = $added->get_error_message();
				$reply['products'] = array( $card );
				return $reply;
			}
			$reply['actions'][] = array(
				'type'     => 'added',
				'key'      => $added['key'],
				'product'  => $added['product'],
				'quantity' => $added['quantity'],
				'cart'     => $added['cart'],
			);
			$reply['products']  = array();
			return $reply;
		}
		if ( 'track_order' === $type && Settings::get( 'order_tracking', true ) ) {
			$number           = sanitize_text_field( (string) ( $action['order_number'] ?? '' ) );
			$rules            = Rules::order( '' !== $number ? 'order #' . $number : $message, $ctx );
			$reply['actions'] = array_merge( $reply['actions'], $rules['actions'] );
			if ( ! empty( $rules['actions'][0]['type'] ) && 'order' === $rules['actions'][0]['type'] ) {
				// Status text comes from WooCommerce, not the model.
				$reply['text'] = $rules['text'];
			}
			return $reply;
		}
		if ( 'handoff' === $type && Settings::get( 'handoff', true ) ) {
			$rules            = Rules::handoff();
			$reply['actions'] = array_merge( $reply['actions'], $rules['actions'] );
		}
		return $reply;
	}
}
