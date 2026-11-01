<?php
/**
 * Guided assistant (rules) and safe order lookup.
 *
 * @package Dev3\AiShoppingAssistant
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiShoppingAssistant\Chat\Orders;
use Dev3\AiShoppingAssistant\Chat\Rules;
use Dev3\AiShoppingAssistant\Data\Knowledge;

class RulesOrdersTest extends Dev3sa_Base_Case {

	/**
	 * Order helper.
	 *
	 * @param string $email  Billing email.
	 * @param string $status Status.
	 * @param int    $user   Customer.
	 * @return WC_Order
	 */
	private function order( $email = 'jo@example.com', $status = 'processing', $user = 0 ) {
		$product = $this->product( 'Mug', '20' );
		$order   = wc_create_order( array( 'customer_id' => $user ) );
		$order->add_product( $product, 2 );
		$order->set_billing_email( $email );
		$order->set_billing_address_1( '1 Secret Street' );
		$order->set_billing_phone( '555-0100' );
		$order->calculate_totals();
		$order->set_status( $status );
		$order->save();
		return $order;
	}

	public function test_detect_intents() {
		$cases = array(
			'Hi'                              => 'greeting',
			'Track my order'                  => 'order',
			'where is my package?'            => 'order',
			'order #1234 status'              => 'order',
			'Talk to a person'                => 'handoff',
			'can I speak to a human please'   => 'handoff',
			'what is in my cart'              => 'cart',
			'add the blue mug to my cart'     => 'add_to_cart',
			'how much is shipping to Canada?' => 'policy',
			'blue ceramic vase'               => 'product',
			'thanks'                          => 'thanks',
		);
		foreach ( $cases as $text => $intent ) {
			$this->assertSame( $intent, Rules::detect( $text )['intent'], $text );
		}
		$this->assertGreaterThanOrEqual( 0.9, Rules::detect( 'Track my order' )['confidence'] );
		$this->assertLessThan( 0.9, Rules::detect( 'blue vase' )['confidence'] );
	}

	public function test_rule_answers() {
		$this->assertSame( 'handoff_form', Rules::respond( 'talk to a person' )['actions'][0]['type'] );
		$order = Rules::respond( 'where is my order #55' );
		$this->assertSame( 'order_form', $order['actions'][0]['type'] );
		$this->assertSame( '55', $order['actions'][0]['number'] );
		$greet = Rules::respond( 'hello' );
		$this->assertNotEmpty( $greet['quick_replies'] );

		Knowledge::save(
			array(
				'title'    => 'Shipping times',
				'content'  => 'We ship in 2 days.',
				'type'     => 'shipping',
				'keywords' => 'shipping, delivery',
			)
		);
		$kb = Rules::respond( 'how long does shipping take?' );
		$this->assertSame( 'kb', $kb['source'] );
		$this->assertSame( 'We ship in 2 days.', $kb['text'] );

		$this->product( 'Ceramic Vase Blue', '30' );
		$found = Rules::respond( 'blue vase' );
		$this->assertSame( 'product', $found['intent'] );
		$this->assertSame( 'Ceramic Vase Blue', $found['products'][0]['name'] );

		$miss = Rules::respond( 'helicopter parts' );
		$this->assertTrue( $miss['unanswered'] );
		$this->assertSame( 'unanswered', $miss['intent'] );
	}

	public function test_handoff_off_and_tracking_off() {
		$this->settings(
			array(
				'handoff'        => false,
				'order_tracking' => false,
			)
		);
		$this->assertSame( array(), Rules::respond( 'talk to a person' )['actions'] );
		$this->assertNotSame( 'order_form', Rules::respond( 'track my order' )['actions'][0]['type'] ?? '' );
	}

	public function test_guest_lookup_needs_matching_email_and_hides_addresses() {
		$order = $this->order();
		$this->assertWPError( Orders::lookup( (string) $order->get_id(), 'other@example.com' ) );
		$this->assertWPError( Orders::lookup( '999999', 'jo@example.com' ) );
		$this->assertWPError( Orders::lookup( (string) $order->get_id(), '' ) );
		$found = Orders::lookup( '#' . $order->get_id(), 'JO@example.com' );
		$this->assertInstanceOf( WC_Order::class, $found );
		$summary = Orders::summary( $found );
		$json    = wp_json_encode( $summary );
		$this->assertStringNotContainsString( 'Secret Street', $json );
		$this->assertStringNotContainsString( '555-0100', $json );
		$this->assertStringNotContainsString( 'jo@example.com', $json );
		$this->assertSame( '', $summary['url'], 'Guests get no account link' );
		$this->assertSame( 'processing', $summary['status'] );
		$this->assertSame( 2, $summary['items'][0]['qty'] );
		$this->assertTrue( $summary['steps'][1]['done'] );
		$this->assertFalse( $summary['steps'][2]['done'] );
	}

	public function test_tracking_numbers_and_customer_orders() {
		$user  = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order = $this->order( 'c@example.com', 'processing', $user );
		$order->update_meta_data(
			'_wc_shipment_tracking_items',
			array(
				array(
					'tracking_provider'    => 'UPS',
					'tracking_number'      => '1Z999',
					'custom_tracking_link' => 'https://ups.test/1Z999',
				),
			)
		);
		$order->save();
		$summary = Orders::summary( $order, true );
		$this->assertSame( '1Z999', $summary['tracking'][0]['number'] );
		$this->assertTrue( $summary['steps'][2]['done'], 'Tracking marks it shipped' );
		$this->assertNotSame( '', $summary['url'] );

		$this->assertNull( Orders::own( (string) $order->get_id(), $user + 1 ), 'Not another customer' );
		$this->assertSame( $order->get_id(), Orders::own( (string) $order->get_id(), $user )->get_id() );
		$reply = Rules::order( 'where is my order', array( 'user_id' => $user ) );
		$this->assertSame( 'order', $reply['actions'][0]['type'] );
		$this->assertSame( (string) $order->get_order_number(), $reply['actions'][0]['order']['number'] );
		$this->assertSame( '1234', Orders::number_in( 'order number 1234 please' ) );
		$this->assertSame( '', Orders::number_in( 'I ordered a mug' ) );
	}
}
