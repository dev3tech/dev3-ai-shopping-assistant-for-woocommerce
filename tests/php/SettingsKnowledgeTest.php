<?php
/**
 * Settings sanitising, business hours and the knowledge base (CRUD, BM25 search, import).
 *
 * @package Dev3\AiShoppingAssistant
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiShoppingAssistant\Chat\Text;
use Dev3\AiShoppingAssistant\Core3\Core\Settings_Store;
use Dev3\AiShoppingAssistant\Data\Knowledge;
use Dev3\AiShoppingAssistant\Settings;

class SettingsKnowledgeTest extends Dev3sa_Base_Case {

	public function test_defaults_and_sanitising() {
		$this->assertSame( '#4f46e5', Settings::get( 'accent_color' ) );
		$this->assertCount( 4, Settings::get( 'starters' ) );
		$this->settings(
			array(
				'accent_color'  => 'red; background:url(x)',
				'position'      => 'top',
				'max_products'  => 99,
				'starters'      => "  One \n\nTwo\nOne\n<b>Three</b>\nFour\nFive\nSix\nSeven",
				'pages'         => '3, 5, abc, 3',
				'hours_start'   => '25:00',
				'hours_days'    => array( 1, 9, 2 ),
				'handoff_email' => 'not-an-email',
				'whatsapp'      => '+1 (555) 123-4567',
				'bot_name'      => '<script>x</script>Mia',
			)
		);
		$this->assertSame( '#4f46e5', Settings::get( 'accent_color' ), 'Bad colours are ignored' );
		$this->assertSame( 'right', Settings::get( 'position' ) );
		$this->assertSame( 8, Settings::get( 'max_products' ) );
		$this->assertSame( array( 'One', 'Two', 'Three', 'Four', 'Five', 'Six' ), Settings::get( 'starters' ) );
		$this->assertSame( array( 3, 5 ), Settings::get( 'pages' ) );
		$this->assertSame( '09:00', Settings::get( 'hours_start' ) );
		$this->assertSame( array( 1, 2 ), Settings::get( 'hours_days' ) );
		$this->assertSame( '', Settings::get( 'handoff_email' ) );
		$this->assertSame( '+15551234567', Settings::get( 'whatsapp' ) );
		$this->assertSame( 'Mia', Settings::get( 'bot_name' ) );
		$this->assertSame( get_option( 'admin_email' ), Settings::handoff_email() );
	}

	public function test_business_hours() {
		update_option( 'timezone_string', 'UTC' );
		$this->assertTrue( Settings::is_online( strtotime( '2026-10-25 03:00:00 UTC' ) ), 'Hours off = always online' );
		$this->settings(
			array(
				'hours_enabled' => true,
				'hours_start'   => '09:00',
				'hours_end'     => '17:00',
				'hours_days'    => array( 1, 2, 3, 4, 5 ),
			)
		);
		$this->assertTrue( Settings::is_online( strtotime( '2026-10-26 10:30:00 UTC' ) ), 'Monday 10:30' );
		$this->assertFalse( Settings::is_online( strtotime( '2026-10-26 17:00:00 UTC' ) ), 'Closing time' );
		$this->assertFalse( Settings::is_online( strtotime( '2026-10-25 10:30:00 UTC' ) ), 'Sunday' );
	}

	public function test_text_terms_and_stemming() {
		$this->assertSame( array( 'blue', 'mug', 'coffee' ), Text::terms( 'Do you have any blue mugs for coffee?' ) );
		$this->assertSame( 'box', Text::stem( 'boxes' ) );
		$this->assertSame( 'accessory', Text::stem( 'accessories' ) );
		$this->assertSame( 'glass', Text::stem( 'glass' ) );
		$this->assertSame( 'Hello world…', Text::cut( 'Hello world this is long', 14 ) );
		$this->assertSame( "Title\nBody", Text::plain( '<!-- wp:p --><h2>Title</h2><p>Body [gallery]</p>' ) );
	}

	public function test_knowledge_crud_and_search_ranking() {
		$this->assertWPError( Knowledge::save( array( 'title' => '', 'content' => 'x' ) ) );
		$ship = Knowledge::save(
			array(
				'title'    => 'How long does delivery take?',
				'content'  => 'Orders ship in 1-2 days and arrive in 3-5 working days.',
				'type'     => 'shipping',
				'keywords' => 'shipping, delivery, courier',
			)
		);
		Knowledge::save(
			array(
				'title'   => 'What is your return policy?',
				'content' => 'Return unused items within 30 days for a refund.',
				'type'    => 'returns',
			)
		);
		Knowledge::save(
			array(
				'title'   => 'Hidden draft about shipping',
				'content' => 'Not live',
				'status'  => 'draft',
			)
		);
		$this->assertSame( 2, Knowledge::count() );
		$hits = Knowledge::search( 'how long is shipping to Canada?' );
		$this->assertSame( $ship['id'], $hits[0]['id'] );
		$this->assertSame( 'What is your return policy?', Knowledge::search( 'can I get a refund' )[0]['title'] );
		$this->assertSame( array(), Knowledge::search( 'zebra' ) );
		Knowledge::delete( $ship['id'] );
		$this->assertNull( Knowledge::get( $ship['id'] ) );
	}

	public function test_import_reads_refund_page_and_payment_methods_once() {
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Refunds',
				'post_content' => '<p>You can return items within 60 days.</p>',
				'post_status'  => 'publish',
			)
		);
		update_option( 'woocommerce_refund_returns_page_id', $page );
		update_option( 'woocommerce_bacs_settings', array( 'enabled' => 'yes', 'title' => 'Bank transfer' ) );
		WC()->payment_gateways()->init();
		$first = Knowledge::import();
		$this->assertGreaterThanOrEqual( 1, $first['added'] );
		$titles = wp_list_pluck( Knowledge::all(), 'title' );
		$this->assertContains( 'What is your return and refund policy?', $titles );
		$entry = Knowledge::search( 'return policy' )[0];
		$this->assertStringContainsString( '60 days', $entry['content'] );
		$this->assertSame( 'import', $entry['source'] );
		$again = Knowledge::import();
		$this->assertSame( 0, $again['added'], 'Import is idempotent' );
	}

	public function test_settings_rest_requires_admin() {
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->assertSame( 403, $this->rest( 'GET', 'stats' )->get_status() );
		$this->admin();
		$this->assertSame( 403, $this->rest( 'GET', 'stats', array(), false )->get_status(), 'Nonce required' );
		$res = $this->rest( 'GET', 'stats' );
		$this->assertSame( 200, $res->get_status() );
		$this->assertArrayHasKey( 'checklist', $res->get_data() );
		Settings_Store::flush();
	}
}
