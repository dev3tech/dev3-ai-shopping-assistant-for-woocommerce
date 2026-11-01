<?php
/**
 * Revenue attribution (HPOS-safe order meta), analytics, retention and GDPR tools.
 *
 * @package Dev3\AiShoppingAssistant
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiShoppingAssistant\Chat\Cart;
use Dev3\AiShoppingAssistant\Data\Conversations;
use Dev3\AiShoppingAssistant\Data\Tables;
use Dev3\AiShoppingAssistant\Woo\Attribution;

class AttributionConversationsTest extends Dev3sa_Base_Case {

	public function test_hpos_and_blocks_compatibility_declared() {
		$util = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
		if ( ! class_exists( $util ) ) {
			$this->markTestSkipped( 'FeaturesUtil missing' );
		}
		$plugin = plugin_basename( DEV3SA_FILE );
		$this->assertContains( $plugin, $util::get_compatible_plugins_for_feature( 'custom_order_tables' )['compatible'] );
		$this->assertContains( $plugin, $util::get_compatible_plugins_for_feature( 'cart_checkout_blocks' )['compatible'] );
	}

	public function test_chat_added_items_are_attributed_and_revenue_counted_once_paid() {
		wc_load_cart();
		WC()->cart->empty_cart();
		$conv   = Conversations::create();
		$chat   = $this->product( 'Chat Mug', '20' );
		$normal = $this->product( 'Shelf Mug', '10' );
		Cart::add( $chat->get_id(), 2, $conv );

		$order = wc_create_order();
		$order->add_product( $chat, 2 );
		$order->add_product( $normal, 1 );
		$order->calculate_totals();
		$order->save();
		Attribution::attribute( $order );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( (string) $conv['id'], (string) $order->get_meta( '_dev3sa_conversation' ) );
		$this->assertSame( '40.00', (string) $order->get_meta( '_dev3sa_assisted_total' ) );
		$this->assertSame( array(), WC()->session->get( Attribution::SESSION ), 'Session map is cleared' );
		$this->assertSame( 0.0, Conversations::get( $conv['id'] )['revenue'], 'Not paid yet' );

		$order->set_status( 'processing' );
		$order->save();
		$order->set_status( 'completed' );
		$order->save();
		$row = Conversations::get( $conv['id'] );
		$this->assertSame( 40.0, $row['revenue'], 'Counted once' );
		$this->assertSame( $order->get_id(), $row['order_id'] );
	}

	public function test_orders_without_chat_items_are_untouched() {
		$order = wc_create_order();
		$order->add_product( $this->product( 'Plain', '5' ), 1 );
		$order->save();
		Attribution::attribute( $order, array( 999 => 1 ) );
		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( '_dev3sa_conversation' ) );
	}

	public function test_stats_top_questions_and_unanswered() {
		$a = Conversations::create();
		$b = Conversations::create();
		Conversations::add_message( $a['id'], 'user', 'Do you ship to Canada?', array( 'intent' => 'policy' ) );
		Conversations::add_message( $a['id'], 'bot', 'Yes', array( 'source' => 'ai' ) );
		Conversations::add_message( $b['id'], 'user', 'do you ship to canada?', array( 'intent' => 'policy' ) );
		Conversations::add_message( $b['id'], 'bot', 'Yes', array( 'source' => 'rules' ) );
		Conversations::add_message( $b['id'], 'user', 'gift cards?', array( 'intent' => 'unanswered' ) );
		Conversations::bump( $a['id'], 'carts' );
		$s = Conversations::stats( 30 );
		$this->assertSame( 2, $s['conversations'] );
		$this->assertSame( 50.0, $s['ai_rate'] );
		$this->assertSame( 1, $s['carts'] );
		$this->assertSame( 1, $s['unanswered'] );
		$this->assertCount( 30, $s['series'] );
		$top = Conversations::top_questions( 30, 5 );
		$this->assertSame( 'do you ship to canada?', $top[0]['question'] );
		$this->assertSame( 2, $top[0]['count'] );
		$this->assertSame( 'gift cards?', Conversations::top_questions( 30, 5, 'unanswered' )[0]['question'] );
	}

	public function test_retention_purge() {
		global $wpdb;
		$conv = Conversations::create();
		Conversations::add_message( $conv['id'], 'user', 'old', array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ) ) );
		Conversations::add_message( $conv['id'], 'user', 'new' );
		$this->assertSame( 1, Conversations::purge() );
		$this->settings( array( 'store_transcripts' => false ) );
		$wpdb->update( Tables::name( 'messages' ), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ), array( 'conversation_id' => $conv['id'] ) ); // phpcs:ignore
		$this->assertSame( 1, Conversations::purge(), 'Transcripts off: messages last one day' );
		$this->assertNotNull( Conversations::get( $conv['id'] ), 'Totals stay until the retention period' );
	}

	public function test_gdpr_export_and_erase() {
		$conv = Conversations::create( array( 'email' => 'gdpr@example.com' ) );
		Conversations::add_message( $conv['id'], 'user', 'my question' );
		$export = Conversations::export_personal_data( 'gdpr@example.com' );
		$this->assertCount( 1, $export['data'] );
		$this->assertStringContainsString( 'my question', $export['data'][0]['data'][1]['value'] );
		$erase = Conversations::erase_personal_data( 'gdpr@example.com' );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertNull( Conversations::get( $conv['id'] ) );
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$this->assertNotEmpty( array_filter( array_keys( $exporters ), static fn( $k ) => false !== strpos( $k, 'shopping-assistant' ) && false !== strpos( $k, 'chats' ) ) );
	}
}
