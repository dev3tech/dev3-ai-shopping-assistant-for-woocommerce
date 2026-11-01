<?php
/**
 * Keyword product finder + cards (no AI).
 *
 * @package Dev3\AiShoppingAssistant
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiShoppingAssistant\Chat\Product_Search;

class ProductSearchTest extends Dev3sa_Base_Case {

	public function test_parse_prices_and_sorts() {
		$p = Product_Search::parse( 'blue mugs under $30' );
		$this->assertSame( 30.0, $p['max'] );
		$this->assertSame( array( 'blue', 'mug' ), $p['terms'] );
		$p = Product_Search::parse( 'teapot between 20 and 45.50' );
		$this->assertSame( array( 20.0, 45.5 ), array( $p['min'], $p['max'] ) );
		$this->assertSame( 'popular', Product_Search::parse( 'show me your best sellers' )['sort'] );
		$this->assertSame( array(), Product_Search::parse( 'show me your best sellers' )['terms'] );
		$this->assertTrue( Product_Search::parse( 'any deals on bowls?' )['on_sale'] );
		$this->assertSame( 'new', Product_Search::parse( 'new arrivals' )['sort'] );
	}

	public function test_search_by_title_category_and_price() {
		$mug    = $this->product( 'Stoneware Mug Sage', '24', array( 'category' => 'Drinkware' ) );
		$big    = $this->product( 'Large Stoneware Mug', '48', array( 'category' => 'Drinkware' ) );
		$bowl   = $this->product( 'Ramen Bowl', '35', array( 'category' => 'Tableware' ) );
		$hidden = $this->product( 'Secret Mug', '10', array( 'visibility' => 'hidden' ) );
		$cup    = $this->product( 'Espresso Cup', '12', array( 'category' => 'Drinkware' ) );

		$ids = Product_Search::search( 'stoneware mugs', 4 );
		$this->assertContains( $mug->get_id(), $ids );
		$this->assertContains( $big->get_id(), $ids );
		$this->assertNotContains( $hidden->get_id(), $ids, 'Hidden products never show' );
		$this->assertNotContains( $bowl->get_id(), $ids );

		$this->assertSame( array( $mug->get_id() ), Product_Search::search( 'mug under 30', 4 ) );
		$this->assertContains( $cup->get_id(), Product_Search::search( 'drinkware', 4 ), 'Category names match' );
		$this->assertSame( array(), Product_Search::search( 'helicopter', 4 ) );
	}

	public function test_best_sellers_on_sale_and_stock() {
		$a = $this->product( 'Plate', '20', array( 'sales' => 5 ) );
		$b = $this->product( 'Platter', '40', array( 'sales' => 50 ) );
		$c = $this->product( 'Saucer', '9', array( 'sale' => '6' ) );
		$o = $this->product( 'Plate Rack', '30', array( 'stock' => 'outofstock' ) );
		$top = Product_Search::search( 'best sellers', 3 );
		$this->assertSame( $b->get_id(), $top[0] );
		$this->assertContains( $c->get_id(), Product_Search::search( 'anything on sale', 4 ) );
		$this->assertContains( $o->get_id(), Product_Search::search( 'plate rack', 4 ) );
		$this->settings( array( 'hide_out_of_stock' => true ) );
		$this->assertNotContains( $o->get_id(), Product_Search::search( 'plate rack', 4 ) );
		$gift = Product_Search::search( 'a gift under 25', 4 );
		$this->assertContains( $a->get_id(), $gift, 'Price-only queries list popular products in range' );
		$this->assertNotContains( $b->get_id(), $gift );
	}

	public function test_card_shape() {
		$p    = $this->product( 'Teapot', '50', array( 'sale' => '40' ) );
		$card = Product_Search::card( $p );
		$this->assertSame( 'Teapot', $card['name'] );
		$this->assertTrue( $card['can_add'] );
		$this->assertTrue( $card['on_sale'] );
		$this->assertStringContainsString( '40', $card['price_html'] );
		$this->assertSame( 'Add to cart', $card['cta'] );
		$var = new WC_Product_Variable();
		$var->set_name( 'Sized tee' );
		$var->set_status( 'publish' );
		$var->save();
		$card = Product_Search::card( $var->get_id() );
		$this->assertFalse( $card['can_add'] );
		$this->assertSame( 'Choose options', $card['cta'] );
		$draft = $this->product( 'Draft', '5' );
		$draft->set_status( 'draft' );
		$draft->save();
		$this->assertNull( Product_Search::card( $draft->get_id() ) );
		$this->settings( array( 'add_to_cart' => false ) );
		$this->assertFalse( Product_Search::card( $p->get_id() )['can_add'] );
	}
}
