<?php
/**
 * Shared set-up: clean tables and settings, a fake Gemini (pre_http_request) and catalog helpers.
 *
 * @package Dev3\AiShoppingAssistant
 */

use Dev3\AiShoppingAssistant\Ai\Prompts_Library;
use Dev3\AiShoppingAssistant\Chat\Retriever;
use Dev3\AiShoppingAssistant\Core3\Api\Ai;
use Dev3\AiShoppingAssistant\Core3\Core\Cache;
use Dev3\AiShoppingAssistant\Core3\Core\Schema;
use Dev3\AiShoppingAssistant\Core3\Core\Settings_Store;
use Dev3\AiShoppingAssistant\Core3\Data\Usage_Repository;
use Dev3\AiShoppingAssistant\Data\Knowledge;
use Dev3\AiShoppingAssistant\Data\Tables;

abstract class Dev3sa_Base_Case extends WP_UnitTestCase {

	/**
	 * Captured HTTP requests: [ url, body ].
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Fake replies: url substring => [ status, body ] or callable( url, body ) → [ status, body ].
	 *
	 * @var array
	 */
	protected $http = array();

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3SA_DB_VERSION );
		global $wpdb;
		foreach ( array( 'conversations', 'messages', 'knowledge' ) as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Tables::name( $t ) ); // phpcs:ignore
		}
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}dev3sa_core_embeddings" ); // phpcs:ignore
		update_option( Settings_Store::OPTION, array() );
		Settings_Store::flush();
		Usage_Repository::flush();
		Cache::reset_memo();
		Cache::flush();
		Knowledge::reset_memo();
		Retriever::reset();
		Prompts_Library::register();
		Ai::$client     = null;
		$this->requests = array();
		$this->http     = array();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		add_filter( 'dev3sa_retry_delay_ms', '__return_zero' );
		add_filter( 'dev3sa_public_rate_limit', array( $this, 'no_limit' ) );
		add_filter( 'dev3sa_jobs_use_action_scheduler', '__return_false' );
		update_option( 'woocommerce_currency', 'USD' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'dev3sa_public_rate_limit', array( $this, 'no_limit' ) );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Very high public rate limit (tests that check limits remove this filter).
	 *
	 * @return int
	 */
	public function no_limit() {
		return 10000;
	}

	/**
	 * Saves a (fake) API key so AI is enabled.
	 *
	 * @param array $settings Extra settings.
	 */
	protected function ai_on( array $settings = array() ) {
		Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000' ) + $settings );
		Settings_Store::flush();
	}

	/**
	 * Updates plugin settings.
	 *
	 * @param array $settings Settings.
	 */
	protected function settings( array $settings ) {
		Settings_Store::update( $settings );
		Settings_Store::flush();
	}

	/**
	 * Gemini generateContent reply carrying structured JSON.
	 *
	 * @param array $data Structured output.
	 * @return array
	 */
	protected function gemini( array $data ) {
		return array(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array( 'parts' => array( array( 'text' => wp_json_encode( $data ) ) ) ),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 300,
					'candidatesTokenCount' => 60,
					'totalTokenCount'      => 360,
				),
			),
		);
	}

	/**
	 * Embedding reply: one vector per requested text, from a callback text → vector.
	 *
	 * @param callable $vector fn( string $text ): float[].
	 * @return callable
	 */
	protected function embeddings( callable $vector ) {
		return static function ( $url, $body ) use ( $vector ) {
			$out = array();
			foreach ( (array) ( $body['requests'] ?? array() ) as $r ) {
				$out[] = array( 'values' => $vector( (string) $r['content']['parts'][0]['text'] ) );
			}
			return array( 200, array( 'embeddings' => $out ) );
		};
	}

	/**
	 * pre_http_request handler.
	 *
	 * @param mixed  $pre  Pre.
	 * @param array  $args Args.
	 * @param string $url  URL.
	 * @return array|\WP_Error
	 */
	public function fake_http( $pre, $args, $url ) {
		$body             = isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null;
		$this->requests[] = array(
			'url'  => $url,
			'body' => $body,
		);
		foreach ( $this->http as $needle => $reply ) {
			if ( false !== strpos( $url, $needle ) ) {
				if ( is_callable( $reply ) ) {
					$reply = $reply( $url, $body );
				}
				return array(
					'headers'  => array(),
					'body'     => is_string( $reply[1] ) ? $reply[1] : wp_json_encode( $reply[1] ),
					'response' => array(
						'code'    => $reply[0],
						'message' => 'x',
					),
					'cookies'  => array(),
				);
			}
		}
		return new WP_Error( 'http_blocked', 'No fake for ' . $url );
	}

	/**
	 * Requests sent to a URL fragment.
	 *
	 * @param string $needle Fragment.
	 * @return array
	 */
	protected function sent( $needle ) {
		return array_values( array_filter( $this->requests, static fn( $r ) => false !== strpos( $r['url'], $needle ) ) );
	}

	/**
	 * Simple product.
	 *
	 * @param string $name  Name.
	 * @param string $price Price.
	 * @param array  $opts  category, sale, stock (instock|outofstock), visibility, description, sales.
	 * @return WC_Product_Simple
	 */
	protected function product( $name, $price, array $opts = array() ) {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_status( 'publish' );
		$p->set_regular_price( $price );
		if ( ! empty( $opts['sale'] ) ) {
			$p->set_sale_price( $opts['sale'] );
		}
		$p->set_stock_status( $opts['stock'] ?? 'instock' );
		$p->set_catalog_visibility( $opts['visibility'] ?? 'visible' );
		$p->set_short_description( $opts['description'] ?? '' );
		if ( ! empty( $opts['category'] ) ) {
			$term = term_exists( $opts['category'], 'product_cat' );
			if ( ! $term ) {
				$term = wp_insert_term( $opts['category'], 'product_cat' );
			}
			$p->set_category_ids( array( (int) $term['term_id'] ) );
		}
		if ( isset( $opts['sales'] ) ) {
			$p->set_total_sales( (int) $opts['sales'] );
		}
		$p->save();
		return wc_get_product( $p->get_id() );
	}

	/**
	 * REST request helper.
	 *
	 * @param string $method Method.
	 * @param string $route  Route under dev3sa/v1.
	 * @param array  $params Params (JSON body).
	 * @param bool   $nonce  Send the REST nonce.
	 * @return WP_REST_Response
	 */
	protected function rest( $method, $route, array $params = array(), $nonce = true ) {
		$req = new WP_REST_Request( $method, '/dev3sa/v1/' . ltrim( $route, '/' ) );
		if ( 'GET' === $method ) {
			$req->set_query_params( $params );
		} else {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
		}
		if ( $nonce ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		return rest_do_request( $req );
	}

	/**
	 * Logs in an administrator.
	 *
	 * @return int User id.
	 */
	protected function admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $id );
		return $id;
	}
}
