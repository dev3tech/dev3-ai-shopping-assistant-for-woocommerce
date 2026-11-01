<?php
/**
 * DEV3 AI chat (mocked Gemini): candidates, validated actions, fallbacks, caching, caps.
 *
 * @package Dev3\AiShoppingAssistant
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiShoppingAssistant\Chat\Cart;
use Dev3\AiShoppingAssistant\Chat\Engine;
use Dev3\AiShoppingAssistant\Data\Knowledge;

class EngineAiTest extends Dev3sa_Base_Case {

	public function set_up() {
		parent::set_up();
		wc_load_cart();
		WC()->cart->empty_cart();
	}

	/**
	 * Fake chat reply.
	 *
	 * @param array $data Structured output.
	 */
	private function reply_with( array $data ) {
		$this->http[':generateContent'] = $this->gemini(
			$data + array(
				'reply'         => 'Here you go',
				'intent'        => 'product_search',
				'product_ids'   => array(),
				'action'        => array( 'type' => 'none' ),
				'quick_replies' => array(),
				'answered'      => true,
			)
		);
	}

	public function test_without_key_rules_answer() {
		$this->product( 'Linen Apron', '25' );
		$reply = Engine::reply( 'linen apron' );
		$this->assertSame( 'rules', $reply['source'] );
		$this->assertSame( 'ai_unavailable', $reply['debug']['reason'] );
		$this->assertSame( 'Linen Apron', $reply['products'][0]['name'] );
		$this->assertCount( 0, $this->requests );
	}

	public function test_ai_answer_uses_only_candidate_products() {
		$this->ai_on();
		$apron = $this->product( 'Linen Apron', '25' );
		$other = $this->product( 'Unrelated Lamp', '90' );
		Knowledge::save(
			array(
				'title'   => 'Do you gift wrap?',
				'content' => 'Yes, gift wrap is free.',
				'type'    => 'faq',
			)
		);
		$this->reply_with(
			array(
				'reply'         => 'This **apron** is great for gifts.',
				'product_ids'   => array( $apron->get_id(), $other->get_id(), 999999 ),
				'quick_replies' => array( 'Gift wrap?' ),
			)
		);
		$reply = Engine::reply( 'a linen apron as a gift, can you gift wrap?' );
		$this->assertSame( 'ai', $reply['source'] );
		$this->assertSame( array( $apron->get_id() ), wp_list_pluck( $reply['products'], 'id' ), 'Ids outside the retrieved set are dropped' );
		$this->assertSame( array( 'Gift wrap?' ), $reply['quick_replies'] );
		$prompt = $this->sent( ':generateContent' )[0]['body'];
		$last   = end( $prompt['contents'] );
		$this->assertStringContainsString( 'Linen Apron', $last['parts'][0]['text'] );
		$this->assertStringContainsString( 'gift wrap is free', $last['parts'][0]['text'] );
		$this->assertStringNotContainsString( 'Unrelated Lamp', $last['parts'][0]['text'] );
		$this->assertSame( 'application/json', $prompt['generationConfig']['responseMimeType'] );
		$this->assertStringContainsString( 'Never invent', $prompt['systemInstruction']['parts'][0]['text'] );
	}

	public function test_add_to_cart_action_adds_validated_product() {
		$this->ai_on();
		$mug = $this->product( 'Blue Mug', '15' );
		$this->reply_with(
			array(
				'reply'  => 'Added!',
				'intent' => 'add_to_cart',
				'action' => array(
					'type'       => 'add_to_cart',
					'product_id' => $mug->get_id(),
					'quantity'   => 2,
				),
			)
		);
		$reply = Engine::reply( 'add two blue mugs to my cart' );
		$this->assertSame( 'added', $reply['actions'][0]['type'] );
		$this->assertSame( 2, WC()->cart->get_cart_contents_count() );

		// Product the model invents (not retrieved) is ignored.
		WC()->cart->empty_cart();
		$this->reply_with(
			array(
				'intent' => 'add_to_cart',
				'action' => array(
					'type'       => 'add_to_cart',
					'product_id' => 424242,
				),
			)
		);
		$reply = Engine::reply( 'add the blue mug to my cart please now' );
		$this->assertSame( array(), $reply['actions'] );
		$this->assertSame( 0, WC()->cart->get_cart_contents_count() );
	}

	public function test_auto_add_off_shows_card_instead() {
		$this->ai_on( array( 'ai_auto_add' => false ) );
		$mug = $this->product( 'Green Mug', '15' );
		$this->reply_with(
			array(
				'action' => array(
					'type'       => 'add_to_cart',
					'product_id' => $mug->get_id(),
				),
			)
		);
		$reply = Engine::reply( 'put the green mug in my basket' );
		$this->assertSame( 0, WC()->cart->get_cart_contents_count() );
		$this->assertSame( $mug->get_id(), $reply['products'][0]['id'] );
	}

	public function test_track_order_action_opens_the_form_for_guests() {
		$this->ai_on();
		$this->reply_with(
			array(
				'intent' => 'order_status',
				'action' => array(
					'type'         => 'track_order',
					'order_number' => '77',
				),
			)
		);
		$reply = Engine::reply( 'has my parcel left yet? it was 77' );
		$this->assertSame( 'order_form', $reply['actions'][0]['type'] );
		$this->assertSame( '77', $reply['actions'][0]['number'] );
	}

	public function test_ai_errors_fall_back_to_rules() {
		$this->ai_on();
		$this->product( 'Walnut Board', '60' );
		$this->http[':generateContent'] = array( 500, array( 'error' => array( 'message' => 'boom' ) ) );
		$reply                          = Engine::reply( 'walnut board' );
		$this->assertSame( 'fallback', $reply['source'] );
		$this->assertSame( 'Walnut Board', $reply['products'][0]['name'] );
		$this->assertCount( 1, $this->sent( ':generateContent' ), 'Storefront calls are not retried' );
	}

	public function test_storefront_switch_and_ai_chat_toggle() {
		$this->ai_on( array( 'public_ai' => false ) );
		$this->assertSame( 'ai_unavailable', Engine::reply( 'walnut board' )['debug']['reason'] );
		$this->settings(
			array(
				'public_ai' => true,
				'ai_chat'   => false,
			)
		);
		$this->assertSame( 'ai_chat_off', Engine::reply( 'walnut board' )['debug']['reason'] );
		$this->assertCount( 0, $this->sent( ':generateContent' ) );
	}

	public function test_starter_chips_skip_ai_and_turn_cap() {
		$this->ai_on( array( 'max_ai_turns' => 2 ) );
		$this->reply_with( array() );
		$this->assertSame( 'rule_match', Engine::reply( 'Track my order' )['debug']['reason'] );
		$capped = Engine::reply( 'bowls', array( 'conversation' => array( 'ai_messages' => 2 ) ) );
		$this->assertSame( 'turn_cap', $capped['debug']['reason'] );
		$this->assertCount( 0, $this->sent( ':generateContent' ) );
	}

	public function test_first_turn_answers_are_cached_for_guests() {
		$this->ai_on();
		$this->product( 'Oak Spoon', '8' );
		$this->reply_with( array( 'reply' => 'Spoons!' ) );
		Engine::reply( 'oak spoon' );
		\Dev3\AiShoppingAssistant\Core3\Core\Cache::reset_memo();
		$again = Engine::reply( 'oak spoon' );
		$this->assertSame( 'cache', $again['source'] );
		$this->assertCount( 1, $this->sent( ':generateContent' ) );
		// With history the answer is never cached.
		Engine::reply(
			'oak spoon',
			array(
				'history' => array(
					array(
						'role' => 'user',
						'text' => 'hi',
					),
				),
			)
		);
		$this->assertCount( 2, $this->sent( ':generateContent' ) );
	}

	public function test_history_is_sent_as_turns() {
		$this->ai_on();
		$this->reply_with( array() );
		Engine::reply(
			'the cheaper one?',
			array(
				'history' => array(
					array(
						'role' => 'user',
						'text' => 'show me teapots',
					),
					array(
						'role' => 'bot',
						'text' => 'Here are two teapots',
					),
				),
			)
		);
		$body = $this->sent( ':generateContent' )[0]['body'];
		$this->assertSame( array( 'user', 'model', 'user' ), wp_list_pluck( $body['contents'], 'role' ) );
	}

	public function test_cart_add_rejects_variable_and_out_of_stock() {
		$oos = $this->product( 'Gone', '5', array( 'stock' => 'outofstock' ) );
		$this->assertSame( 'dev3sa_unavailable', Cart::add( $oos->get_id() )->get_error_code() );
		$var = new WC_Product_Variable();
		$var->set_name( 'Tee' );
		$var->set_status( 'publish' );
		$var->save();
		$this->assertSame( 'dev3sa_needs_options', Cart::add( $var->get_id() )->get_error_code() );
		$ok = Cart::add( $this->product( 'Ok', '5' )->get_id(), 3 );
		$this->assertSame( 3, $ok['cart']['count'] );
		$this->assertSame( 0, Cart::remove( $ok['key'] )['cart']['count'] );
	}
}
