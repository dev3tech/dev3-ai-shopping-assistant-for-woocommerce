<?php
/**
 * Guided assistant without AI: intent rules, keyword product search and knowledge-base answers.
 * Also the fallback whenever the AI is off, capped, failing or slow.
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant\Chat;

use Dev3\AiShoppingAssistant\Data\Knowledge;
use Dev3\AiShoppingAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless; every method returns the reply shape documented in Engine::reply().
 */
class Rules {

	const POLICY_WORDS = '/\b(ship|shipping|shipped|deliver|delivery|postage|courier|return|returns|refund|refunds|exchange|warranty|guarantee|payment|pay|paypal|card|invoice|policy|policies|privacy|terms|cancel|cancellation|discount code|coupon|opening hours|contact|store hours|customs|duties|tax|taxes|international)\b/i';

	/**
	 * Detects an intent.
	 *
	 * @param string $message Message.
	 * @return array { intent, confidence }
	 */
	public static function detect( $message ) {
		$m      = mb_strtolower( trim( (string) $message ) );
		$m      = trim( preg_replace( '/[!?.]+$/', '', $m ) );
		$checks = array(
			array( 'handoff', 0.95, '/^(talk|speak|chat) (to|with) (a |an )?(person|human|agent|someone|representative|real person|team)|^(human|agent|operator|representative|customer service|support)$|\b(real person|live agent|human agent|speak to (a )?human|talk to (a )?human|contact (the )?(team|support))\b/u' ),
			array( 'order', 0.95, '/^(track|where is|where\'s|wheres|status of) (my )?(order|package|parcel|delivery)|^track my order$|^order status$|\btrack(ing)? (my )?(order|package|parcel)\b|\bwhere is my (order|package|parcel|stuff)\b/u' ),
			array( 'order', 0.9, '/\border\s*#?\s*\d{2,}\b|#\d{3,}/u' ),
			array( 'cart', 0.9, '/^(what\'?s|what is|show) (in )?my (cart|basket|bag)|^(my|view) (cart|basket)$|^checkout$/u' ),
			array( 'greeting', 0.9, '/^(hi|hello|hey|hola|hiya|yo|good (morning|afternoon|evening)|greetings|bonjour|hallo|ciao)( there| team)?$/u' ),
			array( 'thanks', 0.9, '/^(thanks|thank you|thx|ty|cheers|great|perfect|awesome|ok thanks|thanks a lot)$/u' ),
			array( 'add_to_cart', 0.6, '/\b(add|put)\b.+\b(to|in|into) (my |the )?(cart|basket|bag)\b/u' ),
			array( 'order', 0.6, '/\b(my order|order status|tracking|shipped yet|has it shipped|when will (it|my order) arrive)\b/u' ),
		);
		foreach ( $checks as $check ) {
			if ( preg_match( $check[2], $m ) ) {
				return array(
					'intent'     => $check[0],
					'confidence' => $check[1],
				);
			}
		}
		if ( preg_match( self::POLICY_WORDS, $m ) ) {
			return array(
				'intent'     => 'policy',
				'confidence' => 0.5,
			);
		}
		return array(
			'intent'     => 'product',
			'confidence' => 0.3,
		);
	}

	/**
	 * Answers with rules.
	 *
	 * @param string $message Message.
	 * @param array  $ctx     Context (user_id, product_id, intent?).
	 * @return array Reply.
	 */
	public static function respond( $message, array $ctx = array() ) {
		$intent = isset( $ctx['intent'] ) ? (string) $ctx['intent'] : self::detect( $message )['intent'];
		switch ( $intent ) {
			case 'greeting':
				return Engine::make(
					(string) Settings::get( 'greeting' ),
					'greeting',
					array( 'quick_replies' => (array) Settings::get( 'starters', array() ) )
				);
			case 'thanks':
				return Engine::make( __( 'You’re welcome! Anything else I can help you with?', 'dev3-ai-shopping-assistant-for-woocommerce' ), 'thanks' );
			case 'handoff':
				return self::handoff();
			case 'order':
				return self::order( $message, $ctx );
			case 'cart':
				return self::cart();
			case 'add_to_cart':
				return self::add_to_cart( $message, $ctx );
			case 'policy':
				$kb = self::knowledge( $message );
				return $kb ? $kb : self::products_or_kb( $message, $ctx );
		}
		return self::products_or_kb( $message, $ctx );
	}

	/**
	 * Handoff form (or contact info when the form is off).
	 *
	 * @return array
	 */
	public static function handoff() {
		if ( ! Settings::get( 'handoff', true ) ) {
			return Engine::make( __( 'You can reach our team through the contact page.', 'dev3-ai-shopping-assistant-for-woocommerce' ), 'handoff' );
		}
		$options = Handoff::options();
		$text    = $options['online']
			? __( 'Sure. Leave your email and a short message, and our team will get back to you.', 'dev3-ai-shopping-assistant-for-woocommerce' )
			: $options['away'];
		return Engine::make(
			$text,
			'handoff',
			array(
				'actions' => array(
					array(
						'type'    => 'handoff_form',
						'options' => $options,
					),
				),
			)
		);
	}

	/**
	 * Order tracking.
	 *
	 * @param string $message Message.
	 * @param array  $ctx     Context.
	 * @return array
	 */
	public static function order( $message, array $ctx ) {
		if ( ! Settings::get( 'order_tracking', true ) ) {
			$kb = self::knowledge( $message );
			return $kb ? $kb : self::handoff();
		}
		$number  = Orders::number_in( $message );
		$user_id = isset( $ctx['user_id'] ) ? (int) $ctx['user_id'] : get_current_user_id();
		if ( $user_id ) {
			$own = '' !== $number ? Orders::own( $number, $user_id ) : null;
			if ( $own ) {
				$summary = Orders::summary( $own, true );
				return Engine::make(
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
				);
			}
			$recent = Orders::recent( $user_id, 3 );
			if ( $recent && '' === $number ) {
				return Engine::make(
					_n( 'Here is your most recent order:', 'Here are your most recent orders:', count( $recent ), 'dev3-ai-shopping-assistant-for-woocommerce' ),
					'order',
					array(
						'actions' => array_map(
							static fn( $o ) => array(
								'type'  => 'order',
								'order' => Orders::summary( $o, true ),
							),
							$recent
						),
					)
				);
			}
		}
		return Engine::make(
			__( 'I can check that for you. Enter your order number and the email address you used at checkout.', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'order',
			array(
				'actions' => array(
					array(
						'type'   => 'order_form',
						'number' => $number,
					),
				),
			)
		);
	}

	/**
	 * What's in the cart.
	 *
	 * @return array
	 */
	public static function cart() {
		Cart::boot();
		$cart = Cart::summary();
		if ( ! $cart['count'] ) {
			return Engine::make(
				__( 'Your cart is empty. Tell me what you are looking for and I will find it.', 'dev3-ai-shopping-assistant-for-woocommerce' ),
				'cart',
				array( 'quick_replies' => array( __( 'Show me best sellers', 'dev3-ai-shopping-assistant-for-woocommerce' ) ) )
			);
		}
		$lines = array_map( static fn( $i ) => '- ' . $i['qty'] . ' × ' . $i['name'], $cart['items'] );
		/* translators: %s: cart subtotal */
		$text = __( 'Here is what is in your cart:', 'dev3-ai-shopping-assistant-for-woocommerce' ) . "\n" . implode( "\n", $lines ) . "\n" . sprintf( __( 'Subtotal: %s', 'dev3-ai-shopping-assistant-for-woocommerce' ), html_entity_decode( wp_strip_all_tags( $cart['total_html'] ), ENT_QUOTES, 'UTF-8' ) );
		return Engine::make(
			$text,
			'cart',
			array(
				'actions' => array(
					array(
						'type' => 'cart',
						'cart' => $cart,
					),
				),
			)
		);
	}

	/**
	 * "Add the X to my cart" without AI: show the best match with its Add button.
	 *
	 * @param string $message Message.
	 * @param array  $ctx     Context.
	 * @return array
	 */
	public static function add_to_cart( $message, array $ctx ) {
		$query = preg_replace( '/\b(add|put|please|to|in|into|my|the|cart|basket|bag)\b/i', ' ', (string) $message );
		$ids   = Settings::get( 'product_search', true ) ? Product_Search::search( $query, 2 ) : array();
		if ( ! $ids && ! empty( $ctx['product_id'] ) ) {
			$ids = array( (int) $ctx['product_id'] );
		}
		$cards = Product_Search::cards( $ids );
		if ( ! $cards ) {
			return self::not_found();
		}
		return Engine::make(
			__( 'Is this the one? Tap the button to add it to your cart.', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'add_to_cart',
			array( 'products' => array_slice( $cards, 0, 1 ) )
		);
	}

	/**
	 * Best knowledge-base answer, or null when nothing matches well.
	 *
	 * @param string $message Message.
	 * @param float  $min     Minimum score.
	 * @return array|null
	 */
	public static function knowledge( $message, $min = 0.8 ) {
		$hits = Knowledge::search( $message, 1 );
		if ( ! $hits || $hits[0]['score'] < $min ) {
			return null;
		}
		return Engine::make(
			$hits[0]['content'],
			'policy',
			array(
				'source'  => 'kb',
				'sources' => array(
					array(
						'type'  => 'kb',
						'id'    => $hits[0]['id'],
						'title' => $hits[0]['title'],
						'score' => $hits[0]['score'],
					),
				),
			)
		);
	}

	/**
	 * Product search, then the knowledge base, then "not found".
	 *
	 * @param string $message Message.
	 * @param array  $ctx     Context.
	 * @return array
	 */
	public static function products_or_kb( $message, array $ctx ) {
		$policy = (bool) preg_match( self::POLICY_WORDS, (string) $message );
		if ( $policy ) {
			$kb = self::knowledge( $message, 0.5 );
			if ( $kb ) {
				return $kb;
			}
		}
		if ( ! empty( $ctx['product_id'] ) && preg_match( '/\b(this|it|this one|this product|this item)\b/i', (string) $message ) ) {
			$about = self::about_product( (int) $ctx['product_id'] );
			if ( $about ) {
				return $about;
			}
		}
		if ( Settings::get( 'product_search', true ) ) {
			$ids = Product_Search::search( $message, (int) Settings::get( 'max_products', 4 ) );
			if ( $ids ) {
				$cards = Product_Search::cards( $ids );
				// A question ("are the bowls dishwasher safe?") with a strong answer: answer it and show the products.
				$kb = preg_match( '/\?\s*$|^(what|how|do|does|are|is|can|could|will|which|why|when)\b/i', trim( (string) $message ) ) ? self::knowledge( $message, 1.0 ) : null;
				if ( $kb ) {
					$kb['products'] = array_slice( $cards, 0, 2 );
					return $kb;
				}
				return Engine::make(
					_n( 'I found this product for you:', 'Here are some products that match:', count( $cards ), 'dev3-ai-shopping-assistant-for-woocommerce' ),
					'product',
					array( 'products' => $cards )
				);
			}
		}
		$kb = self::knowledge( $message, 0.5 );
		return $kb ? $kb : self::not_found();
	}

	/**
	 * Facts about the current product (no AI).
	 *
	 * @param int $product_id Product.
	 * @return array|null
	 */
	public static function about_product( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! Product_Search::visible( $product ) ) {
			return null;
		}
		$facts = Product_Search::facts( $product, 400 );
		$lines = array( '**' . $facts['name'] . '** · ' . $facts['price'] . ' · ' . $facts['stock'] );
		if ( ! empty( $facts['about'] ) ) {
			$lines[] = $facts['about'];
		}
		if ( ! empty( $facts['attributes'] ) ) {
			foreach ( $facts['attributes'] as $label => $values ) {
				$lines[] = '- ' . $label . ': ' . $values;
			}
		}
		return Engine::make(
			implode( "\n", $lines ),
			'product_question',
			array( 'products' => Product_Search::cards( array( $product_id ) ) )
		);
	}

	/**
	 * Nothing matched.
	 *
	 * @return array
	 */
	public static function not_found() {
		$chips = array( __( 'Show me best sellers', 'dev3-ai-shopping-assistant-for-woocommerce' ) );
		if ( Settings::get( 'handoff', true ) ) {
			$chips[] = __( 'Talk to a person', 'dev3-ai-shopping-assistant-for-woocommerce' );
		}
		return Engine::make(
			__( 'Sorry, I couldn’t find anything for that. Try other words (for example a product type, colour or price), or ask our team.', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'unanswered',
			array(
				'quick_replies' => $chips,
				'unanswered'    => true,
			)
		);
	}
}
