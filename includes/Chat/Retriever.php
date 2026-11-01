<?php
/**
 * Hybrid retrieval: keyword search + semantic search (embeddings), merged by reciprocal rank fusion.
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant\Chat;

use Dev3\AiShoppingAssistant\Core3\Api\Ai;
use Dev3\AiShoppingAssistant\Core3\Data\Embeddings_Store;
use Dev3\AiShoppingAssistant\Data\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Semantic search is used only when vectors exist and AI may run in the context; any embedding
 * error silently leaves keyword results.
 */
class Retriever {

	/**
	 * Debug info of the last call.
	 *
	 * @var array
	 */
	public static $debug = array();

	/**
	 * Query vectors of this request.
	 *
	 * @var array
	 */
	private static $memo = array();

	/**
	 * Clears per-request state (tests, long-running CLI).
	 */
	public static function reset() {
		self::$memo  = array();
		self::$debug = array();
	}

	/**
	 * Query vector (one embed call per request is enough).
	 *
	 * @param string $query   Query.
	 * @param string $context Context.
	 * @return array|null
	 */
	private static function vector( $query, $context ) {
		$key = md5( $query . '|' . $context );
		if ( array_key_exists( $key, self::$memo ) ) {
			return self::$memo[ $key ];
		}
		$store = Embeddings_Store::instance();
		if ( ( ! $store->count( 'product' ) && ! $store->count( 'kb' ) ) || ! Ai::enabled( $context ) ) {
			self::$memo[ $key ] = null;
			return null;
		}
		$result = Ai::embed( 'chat_query', array( $query ), 'RETRIEVAL_QUERY', $context, array( 'cache' => DAY_IN_SECONDS ) );
		if ( is_wp_error( $result ) || empty( $result['vectors'][0] ) ) {
			self::$debug['embed_error'] = is_wp_error( $result ) ? $result->get_error_code() : 'empty';
			self::$memo[ $key ]         = null;
			return null;
		}
		self::$memo[ $key ] = $result['vectors'][0];
		return self::$memo[ $key ];
	}

	/**
	 * Candidate products.
	 *
	 * @param string $query   Query.
	 * @param int    $limit   Max.
	 * @param string $context AI context.
	 * @return int[]
	 */
	public static function products( $query, $limit = 8, $context = 'storefront' ) {
		$keyword  = Product_Search::search( $query, $limit );
		$semantic = array();
		$vector   = self::vector( $query, $context );
		if ( $vector ) {
			/**
			 * Minimum cosine score for semantic product matches.
			 *
			 * @param float $min Score.
			 */
			$min = (float) apply_filters( 'dev3sa_semantic_min_score', 0.5 );
			foreach ( Embeddings_Store::instance()->search( $vector, 'product', $limit, array( 'min_score' => $min ) ) as $hit ) {
				$product = wc_get_product( $hit['object_id'] );
				if ( Product_Search::visible( $product ) ) {
					$semantic[] = (int) $hit['object_id'];
				}
			}
		}
		self::$debug['products'] = array(
			'keyword'  => $keyword,
			'semantic' => $semantic,
		);
		return array_slice( self::fuse( array( $semantic, $keyword ) ), 0, $limit );
	}

	/**
	 * Candidate knowledge entries.
	 *
	 * @param string $query   Query.
	 * @param int    $limit   Max.
	 * @param string $context AI context.
	 * @return array Entries.
	 */
	public static function knowledge( $query, $limit = 4, $context = 'storefront' ) {
		$keyword = array();
		foreach ( Knowledge::search( $query, $limit ) as $hit ) {
			if ( $hit['score'] >= 0.3 ) {
				$keyword[] = (int) $hit['id'];
			}
		}
		$semantic = array();
		$vector   = self::vector( $query, $context );
		if ( $vector ) {
			foreach ( Embeddings_Store::instance()->search( $vector, 'kb', $limit, array( 'min_score' => 0.45 ) ) as $hit ) {
				$semantic[] = (int) $hit['object_id'];
			}
		}
		self::$debug['knowledge'] = array(
			'keyword'  => $keyword,
			'semantic' => $semantic,
		);
		$out                      = array();
		foreach ( array_slice( self::fuse( array( $semantic, $keyword ) ), 0, $limit ) as $id ) {
			$entry = Knowledge::get( $id );
			if ( $entry && 'publish' === $entry['status'] ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Reciprocal rank fusion of ranked id lists.
	 *
	 * @param array $lists Lists of ids, best first.
	 * @return int[]
	 */
	public static function fuse( array $lists ) {
		$scores = array();
		foreach ( $lists as $list ) {
			foreach ( array_values( $list ) as $rank => $id ) {
				$scores[ (int) $id ] = ( $scores[ (int) $id ] ?? 0 ) + 1 / ( 60 + $rank );
			}
		}
		arsort( $scores );
		return array_keys( $scores );
	}
}
