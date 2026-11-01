<?php
/**
 * REST: public chat flow (tokens, persistence, cart, orders, handoff, rating, limits) and admin routes.
 *
 * @package Dev3\AiShoppingAssistant
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiShoppingAssistant\Data\Conversations;
use Dev3\AiShoppingAssistant\Data\Knowledge;

class RestTest extends Dev3sa_Base_Case {

	/**
	 * Captured mails.
	 *
	 * @var array
	 */
	private $mails = array();

	public function set_up() {
		parent::set_up();
		do_action( 'rest_api_init' );
		wc_load_cart();
		WC()->cart->empty_cart();
		$this->mails = array();
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10, 2 );
	}

	public function tear_down() {
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail' ), 10 );
		parent::tear_down();
	}

	public function catch_mail( $pre, $atts ) {
		$this->mails[] = $atts;
		return true;
	}

	public function test_chat_creates_conversation_and_stores_both_messages() {
		$this->product( 'Indigo Scarf', '30' );
		$res = $this->rest( 'POST', 'public/chat', array( 'message' => 'indigo scarf', 'context' => array( 'url' => 'https://shop.test/p' ) ), false );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $data['token'] );
		$this->assertSame( 'Indigo Scarf', $data['messages'][0]['products'][0]['name'] );
		$conv = Conversations::by_token( $data['token'] );
		$this->assertSame( 2, $conv['messages'] );
		$this->assertSame( 'https://shop.test/p', $conv['source_url'] );

		$res2 = $this->rest( 'POST', 'public/chat', array( 'token' => $data['token'], 'message' => 'thanks' ), false );
		$this->assertSame( $data['token'], $res2->get_data()['token'], 'Same conversation' );
		$hist = $this->rest( 'GET', 'public/history', array( 'token' => $data['token'] ), false )->get_data();
		$this->assertSame( array( 'user', 'bot', 'user', 'bot' ), wp_list_pluck( $hist['messages'], 'role' ) );
		$this->assertSame( 'Indigo Scarf', $hist['messages'][1]['products'][0]['name'] );
		$this->assertSame( array(), $this->rest( 'GET', 'public/history', array( 'token' => 'nope' ), false )->get_data()['messages'] );
	}

	public function test_empty_message_and_cross_origin_are_rejected() {
		$this->assertSame( 400, $this->rest( 'POST', 'public/chat', array( 'message' => '   ' ), false )->get_status() );
		$req = new WP_REST_Request( 'POST', '/dev3sa/v1/public/chat' );
		$req->set_header( 'Origin', 'https://evil.test' );
		$req->set_param( 'message', 'hi' );
		$this->assertSame( 403, rest_do_request( $req )->get_status() );
	}

	public function test_rate_limit() {
		remove_filter( 'dev3sa_public_rate_limit', array( $this, 'no_limit' ) );
		$this->settings( array( 'chat_rate_limit' => 2 ) );
		$codes = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$codes[] = $this->rest( 'POST', 'public/chat', array( 'message' => 'hi' ), false )->get_status();
		}
		$this->assertSame( array( 200, 200, 429 ), $codes );
	}

	public function test_token_of_another_account_is_not_reused() {
		$a = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $a );
		$token = $this->rest( 'POST', 'public/chat', array( 'message' => 'hello' ) )->get_data()['token'];
		$this->assertSame( $a, Conversations::by_token( $token )['user_id'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$other = $this->rest( 'POST', 'public/chat', array( 'token' => $token, 'message' => 'hello' ) )->get_data()['token'];
		$this->assertNotSame( $token, $other );
	}

	public function test_cart_add_and_undo_count_on_the_conversation() {
		$p     = $this->product( 'Candle', '12' );
		$token = $this->rest( 'POST', 'public/chat', array( 'message' => 'candle' ), false )->get_data()['token'];
		$add   = $this->rest( 'POST', 'public/cart', array( 'token' => $token, 'product_id' => $p->get_id(), 'quantity' => 1 ), false );
		$this->assertSame( 200, $add->get_status() );
		$this->assertSame( 1, $add->get_data()['cart']['count'] );
		$this->assertSame( 1, Conversations::by_token( $token )['carts'] );
		$undo = $this->rest( 'POST', 'public/cart', array( 'token' => $token, 'key' => $add->get_data()['key'], 'remove' => true ), false );
		$this->assertSame( 0, $undo->get_data()['cart']['count'] );
		$this->assertSame( 0, Conversations::by_token( $token )['carts'] );
		$this->assertSame( 404, $this->rest( 'POST', 'public/cart', array( 'product_id' => 999999 ), false )->get_status() );
		$this->settings( array( 'add_to_cart' => false ) );
		$this->assertSame( 403, $this->rest( 'POST', 'public/cart', array( 'product_id' => $p->get_id() ), false )->get_status() );
	}

	public function test_order_lookup_route() {
		$order = wc_create_order();
		$order->add_product( $this->product( 'Bowl', '10' ), 1 );
		$order->set_billing_email( 'amy@example.com' );
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order->save();
		$bad = $this->rest( 'POST', 'public/order', array( 'order' => (string) $order->get_id(), 'email' => 'x@example.com' ), false );
		$this->assertSame( 404, $bad->get_status() );
		$ok = $this->rest( 'POST', 'public/order', array( 'order' => (string) $order->get_id(), 'email' => 'amy@example.com' ), false )->get_data();
		$this->assertSame( 'completed', $ok['messages'][0]['actions'][0]['order']['status'] );
		$this->assertSame( 'amy@example.com', Conversations::by_token( $ok['token'] )['email'] );
	}

	public function test_handoff_emails_the_store_with_the_transcript() {
		$this->settings( array( 'handoff_email' => 'team@example.com' ) );
		$token = $this->rest( 'POST', 'public/chat', array( 'message' => 'do you do custom engraving?' ), false )->get_data()['token'];
		$bad   = $this->rest( 'POST', 'public/handoff', array( 'token' => $token, 'email' => 'nope', 'message' => 'x' ), false );
		$this->assertSame( 400, $bad->get_status() );
		$res = $this->rest( 'POST', 'public/handoff', array( 'token' => $token, 'name' => 'Sam', 'email' => 'sam@example.com', 'message' => 'Please call me' ), false );
		$this->assertSame( 200, $res->get_status() );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'team@example.com', $this->mails[0]['to'] );
		$this->assertStringContainsString( 'custom engraving', $this->mails[0]['message'] );
		$this->assertStringContainsString( 'Reply-To: Sam <sam@example.com>', implode( "\n", (array) $this->mails[0]['headers'] ) );
		$conv = Conversations::by_token( $token );
		$this->assertSame( 'needs_reply', $conv['status'] );
		$this->assertSame( 'sam@example.com', $conv['email'] );
	}

	public function test_rating_moves_counters() {
		$data = $this->rest( 'POST', 'public/chat', array( 'message' => 'hi' ), false )->get_data();
		$mid  = $data['messages'][0]['id'];
		$this->assertSame( 200, $this->rest( 'POST', 'public/rate', array( 'token' => $data['token'], 'message_id' => $mid, 'rating' => 1 ), false )->get_status() );
		$this->rest( 'POST', 'public/rate', array( 'token' => $data['token'], 'message_id' => $mid, 'rating' => -1 ), false );
		$conv = Conversations::by_token( $data['token'] );
		$this->assertSame( array( 0, 1 ), array( $conv['rating_up'], $conv['rating_down'] ) );
		$other = $this->rest( 'POST', 'public/chat', array( 'message' => 'hello' ), false )->get_data()['token'];
		$this->assertSame( 404, $this->rest( 'POST', 'public/rate', array( 'token' => $other, 'message_id' => $mid, 'rating' => 1 ), false )->get_status(), 'Cannot rate another chat' );
	}

	public function test_admin_routes_need_manage_capability() {
		foreach ( array( array( 'GET', 'conversations' ), array( 'GET', 'knowledge' ), array( 'POST', 'test-chat' ), array( 'POST', 'ai/insights' ), array( 'POST', 'index' ) ) as $r ) {
			$this->assertSame( 403, $this->rest( $r[0], $r[1], array(), false )->get_status(), implode( ' ', $r ) );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$this->assertSame( 403, $this->rest( 'GET', 'conversations' )->get_status(), 'manage_options only by default' );
	}

	public function test_admin_conversation_reply_export_and_delete() {
		$data = $this->rest( 'POST', 'public/chat', array( 'message' => '=HYPERLINK("x")' ), false )->get_data();
		$this->rest( 'POST', 'public/handoff', array( 'token' => $data['token'], 'email' => 'lee@example.com', 'message' => 'help' ), false );
		$this->admin();
		$list = $this->rest( 'GET', 'conversations', array( 'status' => 'needs_reply' ) )->get_data();
		$this->assertSame( 1, $list['total'] );
		$this->assertSame( '=HYPERLINK("x")', $list['rows'][0]['first_question'] );
		$this->assertArrayNotHasKey( 'ip_hash', $list['rows'][0] );
		$id = $list['rows'][0]['id'];

		$this->mails = array();
		$reply       = $this->rest( 'POST', "conversations/{$id}/reply", array( 'reply' => 'We can help!' ) );
		$this->assertSame( 200, $reply->get_status() );
		$this->assertSame( 'lee@example.com', $this->mails[0]['to'] );
		$this->assertSame( 'resolved', $reply->get_data()['conversation']['status'] );
		$this->assertSame( 'agent', end( $reply->get_data()['messages'] )['role'] );

		$csv = $this->rest( 'GET', 'conversations/export' )->get_data()['csv'];
		$this->assertStringContainsString( "\"'=HYPERLINK(\"\"x\"\")\"", $csv, 'Formulas are neutralised' );

		$this->assertSame( 200, $this->rest( 'DELETE', "conversations/{$id}" )->get_status() );
		$this->assertNull( Conversations::get( $id ) );
	}

	public function test_admin_knowledge_routes_and_test_console() {
		$this->admin();
		$saved = $this->rest(
			'POST',
			'knowledge',
			array(
				'title'   => 'Do you ship abroad?',
				'content' => 'Yes, to 30 countries.',
				'type'    => 'shipping',
			)
		)->get_data();
		$this->assertSame( 'Do you ship abroad?', $saved['title'] );
		$bulk = $this->rest(
			'POST',
			'knowledge/bulk',
			array(
				'entries' => array(
					array(
						'title'   => 'Gift cards?',
						'content' => 'Yes.',
					),
					array(
						'title'   => '',
						'content' => 'bad',
					),
				),
			)
		)->get_data();
		$this->assertSame( 1, $bulk['saved'] );
		$this->assertCount( 1, $bulk['errors'] );
		$this->assertSame( 'ai', Knowledge::search( 'gift cards' )[0]['source'] );

		$test = $this->rest( 'POST', 'test-chat', array( 'message' => 'do you ship abroad?' ) )->get_data();
		$this->assertSame( 'kb', $test['source'] );
		$this->assertArrayHasKey( 'ms', $test['debug'] );
		$this->assertSame( 0, (int) Conversations::query()['total'], 'The console stores nothing' );

		$this->assertSame( 200, $this->rest( 'DELETE', 'knowledge/' . $saved['id'] )->get_status() );
		$this->assertNull( Knowledge::get( $saved['id'] ) );
	}
}
