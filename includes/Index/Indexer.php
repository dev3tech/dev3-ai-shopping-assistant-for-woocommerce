<?php
/**
 * Embeddings index of products and knowledge entries (core Jobs + Embeddings_Store).
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant\Index;

use Dev3\AiShoppingAssistant\Chat\Product_Search;
use Dev3\AiShoppingAssistant\Chat\Text;
use Dev3\AiShoppingAssistant\Core3\Api\Ai;
use Dev3\AiShoppingAssistant\Core3\Core\Jobs;
use Dev3\AiShoppingAssistant\Core3\Data\Embeddings_Store;
use Dev3\AiShoppingAssistant\Data\Knowledge;
use Dev3\AiShoppingAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Only stale items (content hash changed) are embedded again.
 */
class Indexer {

	const JOB   = 'dev3sa_index';
	const DIRTY = 'dev3sa_index_dirty';
	const EVENT = 'dev3sa_index_dirty_run';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'woocommerce_update_product', array( __CLASS__, 'mark_product' ) );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'mark_product' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'forget_post' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'forget_post' ) );
		add_action( 'dev3sa_knowledge_saved', array( __CLASS__, 'mark_kb' ) );
		add_action( 'dev3sa_knowledge_deleted', array( __CLASS__, 'forget_kb' ) );
		add_action( self::EVENT, array( __CLASS__, 'run_dirty' ) );
	}

	/**
	 * Registers the job handler (every request).
	 */
	public static function register_job() {
		Jobs::register( self::JOB, array( __CLASS__, 'run_chunk' ) );
	}

	/**
	 * Queues a product for re-embedding.
	 *
	 * @param int $id Product id.
	 */
	public static function mark_product( $id ) {
		self::mark( 'product:' . (int) $id );
	}

	/**
	 * Queues a knowledge entry.
	 *
	 * @param int $id Entry id.
	 */
	public static function mark_kb( $id ) {
		self::mark( 'kb:' . (int) $id );
	}

	/**
	 * Adds to the dirty list and schedules a run in a minute.
	 *
	 * @param string $item Item key.
	 */
	private static function mark( $item ) {
		if ( ! Settings::get( 'auto_index', true ) || ( ! Embeddings_Store::instance()->count( 'product' ) && ! Embeddings_Store::instance()->count( 'kb' ) ) ) {
			return; // Nothing indexed yet: the admin builds the first index by hand.
		}
		$dirty   = (array) get_option( self::DIRTY, array() );
		$dirty[] = $item;
		update_option( self::DIRTY, array_slice( array_values( array_unique( $dirty ) ), -2000 ), false );
		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::EVENT );
		}
	}

	/**
	 * Removes a deleted/trashed product from the index.
	 *
	 * @param int $post_id Post id.
	 */
	public static function forget_post( $post_id ) {
		if ( 'product' === get_post_type( $post_id ) ) {
			Embeddings_Store::instance()->delete( 'product', (int) $post_id );
		}
	}

	/**
	 * Removes a deleted entry.
	 *
	 * @param int $id Entry id.
	 */
	public static function forget_kb( $id ) {
		Embeddings_Store::instance()->delete( 'kb', (int) $id );
	}

	/**
	 * Cron: embeds dirty items through a job (also part of the daily catch-up).
	 *
	 * @return int|null Job id.
	 */
	public static function run_dirty() {
		$dirty = (array) get_option( self::DIRTY, array() );
		delete_option( self::DIRTY );
		if ( ! $dirty || ! Ai::enabled( 'cron' ) ) {
			return null;
		}
		$id = Jobs::create( self::JOB, array_values( $dirty ), array( 'auto' => true ), 20 );
		return is_wp_error( $id ) ? null : (int) $id;
	}

	/**
	 * Text embedded for a product.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public static function product_text( \WC_Product $product ) {
		$facts = Product_Search::facts( $product, 700 );
		$parts = array( $facts['name'] );
		foreach ( array( 'categories', 'sku' ) as $key ) {
			if ( ! empty( $facts[ $key ] ) ) {
				$parts[] = $facts[ $key ];
			}
		}
		if ( ! empty( $facts['attributes'] ) ) {
			foreach ( $facts['attributes'] as $label => $values ) {
				$parts[] = $label . ': ' . $values;
			}
		}
		$tags = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $tags ) && $tags ) {
			$parts[] = implode( ', ', $tags );
		}
		$parts[] = $facts['about'] ?? '';
		return Text::cut( implode( "\n", array_filter( $parts ) ), 2000 );
	}

	/**
	 * Live items with their hashes.
	 *
	 * @return array { product: [id => hash], kb: [id => hash] }
	 */
	public static function live() {
		$products = array();
		$ids      = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 20000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- ids only, for the index.
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( Product_Search::visible( $product ) ) {
				$products[ (int) $id ] = Embeddings_Store::hash( self::product_text( $product ) );
			}
		}
		$kb = array();
		foreach ( Knowledge::all( 'publish' ) as $entry ) {
			$kb[ $entry['id'] ] = Embeddings_Store::hash( Knowledge::embed_text( $entry ) );
		}
		return array(
			'product' => $products,
			'kb'      => $kb,
		);
	}

	/**
	 * Starts a (re)index job over stale items; removes orphans.
	 *
	 * @param bool $force Re-embed everything.
	 * @return array|\WP_Error Job, or { status: 'fresh' } when nothing is stale.
	 */
	public static function start( $force = false ) {
		if ( ! Ai::enabled( 'admin' ) ) {
			return new \WP_Error( 'dev3sa_ai_off', __( 'Connect DEV3 AI (add your Gemini API key) to build the smart search index.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$active = Jobs::active( self::JOB );
		if ( $active ) {
			return $active;
		}
		$store = Embeddings_Store::instance();
		$items = array();
		foreach ( self::live() as $type => $hashes ) {
			foreach ( $store->orphans( $type, array_keys( $hashes ) ) as $orphan ) {
				$store->delete( $type, (int) $orphan );
			}
			$stale = $force ? array_keys( $hashes ) : $store->stale( $type, $hashes );
			foreach ( $stale as $id ) {
				$items[] = $type . ':' . (int) $id;
			}
		}
		if ( ! $items ) {
			return array( 'status' => 'fresh' );
		}
		$id = Jobs::create( self::JOB, $items, array(), 20 );
		return is_wp_error( $id ) ? $id : Jobs::get( $id );
	}

	/**
	 * Job handler: embeds a chunk.
	 *
	 * @param array $items Items ("product:12", "kb:3").
	 * @param array $job   Job.
	 * @return array item => true|WP_Error|string
	 */
	public static function run_chunk( array $items, array $job ) {
		unset( $job );
		$out   = array();
		$texts = array();
		$meta  = array();
		foreach ( $items as $item ) {
			list( $type, $id ) = array_pad( explode( ':', (string) $item, 2 ), 2, 0 );
			$id                = (int) $id;
			$text              = '';
			if ( 'product' === $type ) {
				$product = wc_get_product( $id );
				if ( Product_Search::visible( $product ) ) {
					$text = self::product_text( $product );
				} else {
					Embeddings_Store::instance()->delete( 'product', $id );
				}
			} elseif ( 'kb' === $type ) {
				$entry = Knowledge::get( $id );
				if ( $entry && 'publish' === $entry['status'] ) {
					$text = Knowledge::embed_text( $entry );
				} else {
					Embeddings_Store::instance()->delete( 'kb', $id );
				}
			}
			if ( '' === $text ) {
				$out[ $item ] = true; // Removed or hidden: nothing to embed.
				continue;
			}
			$texts[] = $text;
			$meta[]  = array( $item, $type, $id, Embeddings_Store::hash( $text ) );
		}
		if ( ! $texts ) {
			return $out;
		}
		$result = Ai::embed( 'index', $texts, 'RETRIEVAL_DOCUMENT', 'cron' );
		foreach ( $meta as $i => $row ) {
			if ( is_wp_error( $result ) ) {
				$out[ $row[0] ] = $result;
				continue;
			}
			if ( empty( $result['vectors'][ $i ] ) ) {
				$out[ $row[0] ] = __( 'No vector returned.', 'dev3-ai-shopping-assistant-for-woocommerce' );
				continue;
			}
			Embeddings_Store::instance()->upsert( $row[1], $row[2], $result['vectors'][ $i ], $row[3] );
			$out[ $row[0] ] = true;
		}
		return $out;
	}

	/**
	 * Index status for the admin.
	 *
	 * @return array
	 */
	public static function status() {
		$store    = Embeddings_Store::instance();
		$products = (int) wp_count_posts( 'product' )->publish;
		return array(
			'products'         => $products,
			'products_indexed' => $store->count( 'product' ),
			'kb'               => Knowledge::count( 'publish' ),
			'kb_indexed'       => $store->count( 'kb' ),
			'job'              => Jobs::active( self::JOB ),
			'last'             => current( Jobs::recent( self::JOB, 1 ) ) ? current( Jobs::recent( self::JOB, 1 ) ) : null,
			'ai'               => Ai::enabled( 'admin' ),
		);
	}
}
