<?php
/**
 * Knowledge base (FAQ / policy entries): CRUD, keyword search and import from store settings.
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant\Data;

use Dev3\AiShoppingAssistant\Chat\Text;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; name from $wpdb->prefix.

/**
 * Entries are short answers the assistant can quote.
 */
class Knowledge {

	const TYPES = array( 'faq', 'shipping', 'returns', 'payment', 'policy', 'product', 'other' );

	/**
	 * In-request memo of published entries.
	 *
	 * @var array|null
	 */
	private static $memo = null;

	/**
	 * All entries.
	 *
	 * @param string $status publish|draft|'' (all).
	 * @return array
	 */
	public static function all( $status = '' ) {
		global $wpdb;
		$table = Tables::name( 'knowledge' );
		if ( 'publish' === $status && null !== self::$memo ) {
			return self::$memo;
		}
		$rows = '' === $status
			? $wpdb->get_results( "SELECT * FROM {$table} ORDER BY type ASC, title ASC LIMIT 2000", ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY type ASC, title ASC LIMIT 2000", $status ), ARRAY_A );
		$rows = array_map( array( __CLASS__, 'cast' ), (array) $rows );
		if ( 'publish' === $status ) {
			self::$memo = $rows;
		}
		return $rows;
	}

	/**
	 * One entry.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::name( 'knowledge' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? self::cast( $row ) : null;
	}

	/**
	 * Sanitizes input into a row.
	 *
	 * @param array $data Raw.
	 * @return array|\WP_Error
	 */
	public static function clean( array $data ) {
		$title   = mb_substr( sanitize_text_field( wp_unslash( (string) ( $data['title'] ?? '' ) ) ), 0, 200 );
		$content = mb_substr( sanitize_textarea_field( wp_unslash( (string) ( $data['content'] ?? '' ) ) ), 0, 4000 );
		if ( '' === trim( $title ) || '' === trim( $content ) ) {
			return new \WP_Error( 'dev3sa_kb_invalid', __( 'Add a question/title and an answer.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$type     = in_array( $data['type'] ?? '', self::TYPES, true ) ? $data['type'] : 'faq';
		$keywords = is_array( $data['keywords'] ?? '' ) ? implode( ', ', $data['keywords'] ) : (string) ( $data['keywords'] ?? '' );
		return array(
			'title'      => $title,
			'content'    => $content,
			'type'       => $type,
			'keywords'   => mb_substr( sanitize_text_field( wp_unslash( $keywords ) ), 0, 255 ),
			'status'     => in_array( $data['status'] ?? 'publish', array( 'publish', 'draft' ), true ) ? ( $data['status'] ?? 'publish' ) : 'publish',
			'source'     => in_array( $data['source'] ?? 'manual', array( 'manual', 'import', 'ai' ), true ) ? ( $data['source'] ?? 'manual' ) : 'manual',
			'updated_at' => Conversations::now(),
		);
	}

	/**
	 * Inserts or updates.
	 *
	 * @param array $data Raw (id = update).
	 * @return array|\WP_Error Row.
	 */
	public static function save( array $data ) {
		global $wpdb;
		$row = self::clean( $data );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		if ( $id && self::get( $id ) ) {
			$wpdb->update( Tables::name( 'knowledge' ), $row, array( 'id' => $id ) );
		} else {
			$wpdb->insert( Tables::name( 'knowledge' ), $row );
			$id = (int) $wpdb->insert_id;
		}
		self::$memo = null;
		/**
		 * Fires after a knowledge entry is saved (the indexer re-embeds it).
		 *
		 * @param int $id Entry id.
		 */
		do_action( 'dev3sa_knowledge_saved', $id );
		return self::get( $id );
	}

	/**
	 * Deletes.
	 *
	 * @param int $id Id.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( Tables::name( 'knowledge' ), array( 'id' => (int) $id ) );
		self::$memo = null;
		do_action( 'dev3sa_knowledge_deleted', (int) $id );
	}

	/**
	 * Count.
	 *
	 * @param string $status Status ('' all).
	 * @return int
	 */
	public static function count( $status = 'publish' ) {
		return count( self::all( $status ) );
	}

	/**
	 * Keyword search (BM25-style over title, keywords and answer).
	 *
	 * @param string $query Query.
	 * @param int    $limit Results.
	 * @return array [ entry + score ]
	 */
	public static function search( $query, $limit = 3 ) {
		$terms = Text::terms( $query );
		if ( ! $terms ) {
			return array();
		}
		$entries = self::all( 'publish' );
		$n       = count( $entries );
		if ( ! $n ) {
			return array();
		}
		$docs = array();
		$df   = array();
		$len  = 0;
		foreach ( $entries as $i => $e ) {
			$title    = Text::terms( $e['title'], false );
			$keywords = Text::terms( $e['keywords'] . ' ' . $e['type'], false );
			$body     = Text::terms( $e['content'], false );
			// Title and keywords count three times: they say what the entry is about.
			$all        = array_merge( $title, $title, $title, $keywords, $keywords, $keywords, $body );
			$docs[ $i ] = array_count_values( $all );
			$len       += count( $all );
			foreach ( array_keys( $docs[ $i ] ) as $t ) {
				$df[ $t ] = ( $df[ $t ] ?? 0 ) + 1;
			}
		}
		$avg    = max( 1, $len / $n );
		$scores = array();
		foreach ( $docs as $i => $tf ) {
			$dl    = array_sum( $tf );
			$score = 0.0;
			$hits  = 0;
			foreach ( $terms as $t ) {
				if ( empty( $tf[ $t ] ) ) {
					continue;
				}
				++$hits;
				$idf    = log( 1 + $n / $df[ $t ] ); // Stays positive in small knowledge bases.
				$score += $idf * ( $tf[ $t ] * 2.2 ) / ( $tf[ $t ] + 1.2 * ( 0.25 + 0.75 * $dl / $avg ) );
			}
			if ( $hits ) {
				// Reward covering more of the question.
				$scores[ $i ] = $score * ( 0.5 + 0.5 * $hits / count( $terms ) );
			}
		}
		arsort( $scores );
		$out = array();
		foreach ( array_slice( $scores, 0, $limit, true ) as $i => $score ) {
			$out[] = $entries[ $i ] + array( 'score' => round( $score, 3 ) );
		}
		return $out;
	}

	/**
	 * Store facts used by the importer and the AI knowledge builder.
	 *
	 * @return array [ { title, content, type, keywords, source_ref } ]
	 */
	public static function store_facts() {
		$facts = array();
		$pages = array(
			'returns' => array( (int) get_option( 'woocommerce_refund_returns_page_id' ), __( 'What is your return and refund policy?', 'dev3-ai-shopping-assistant-for-woocommerce' ), 'returns, refund, exchange, return policy' ),
			'policy'  => array( (int) get_option( 'woocommerce_terms_page_id' ), __( 'Terms and conditions', 'dev3-ai-shopping-assistant-for-woocommerce' ), 'terms, conditions, legal' ),
			'privacy' => array( (int) get_option( 'wp_page_for_privacy_policy' ), __( 'How do you use my personal data?', 'dev3-ai-shopping-assistant-for-woocommerce' ), 'privacy, data, gdpr, cookies' ),
		);
		foreach ( $pages as $type => $page ) {
			$post = $page[0] ? get_post( $page[0] ) : null;
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$text = Text::plain( $post->post_content, 1800 );
			if ( '' === $text ) {
				continue;
			}
			$facts[] = array(
				'title'      => $page[1],
				'content'    => $text . "\n\n" . get_permalink( $post ),
				'type'       => 'privacy' === $type ? 'policy' : $type,
				'keywords'   => $page[2],
				'source_ref' => 'page:' . $post->ID,
			);
		}

		if ( class_exists( '\WC_Shipping_Zones' ) ) {
			$lines = array();
			$zones = \WC_Shipping_Zones::get_zones();
			foreach ( $zones as $zone ) {
				$methods = array();
				foreach ( (array) $zone['shipping_methods'] as $method ) {
					if ( 'yes' !== $method->enabled ) {
						continue;
					}
					$label = $method->get_title();
					$cost  = isset( $method->instance_settings['cost'] ) ? (string) $method->instance_settings['cost'] : '';
					$min   = isset( $method->instance_settings['min_amount'] ) ? (string) $method->instance_settings['min_amount'] : '';
					if ( '' !== $cost && is_numeric( $cost ) ) {
						$label .= ' (' . html_entity_decode( wp_strip_all_tags( wc_price( (float) $cost ) ), ENT_QUOTES, 'UTF-8' ) . ')';
					} elseif ( '' !== $min && is_numeric( $min ) ) {
						/* translators: %s: amount */
						$label .= ' (' . sprintf( __( 'orders over %s', 'dev3-ai-shopping-assistant-for-woocommerce' ), html_entity_decode( wp_strip_all_tags( wc_price( (float) $min ) ), ENT_QUOTES, 'UTF-8' ) ) . ')';
					}
					$methods[] = $label;
				}
				if ( $methods ) {
					$lines[] = $zone['zone_name'] . ': ' . implode( ', ', $methods );
				}
			}
			if ( $lines ) {
				$facts[] = array(
					'title'      => __( 'Where do you ship and how much does shipping cost?', 'dev3-ai-shopping-assistant-for-woocommerce' ),
					'content'    => implode( "\n", $lines ),
					'type'       => 'shipping',
					'keywords'   => 'shipping, delivery, postage, ship, countries, cost',
					'source_ref' => 'shipping',
				);
			}
		}

		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$titles = array();
			foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
				if ( 'yes' === $gateway->enabled ) {
					$titles[] = wp_strip_all_tags( $gateway->get_title() );
				}
			}
			if ( $titles ) {
				$facts[] = array(
					'title'      => __( 'Which payment methods do you accept?', 'dev3-ai-shopping-assistant-for-woocommerce' ),
					/* translators: %s: list of payment methods */
					'content'    => sprintf( __( 'You can pay with: %s.', 'dev3-ai-shopping-assistant-for-woocommerce' ), implode( ', ', $titles ) ),
					'type'       => 'payment',
					'keywords'   => 'payment, pay, card, paypal, methods, checkout',
					'source_ref' => 'payment',
				);
			}
		}
		/**
		 * Facts the knowledge importer and DEV3 AI builder read from the store.
		 *
		 * @param array $facts Facts.
		 */
		return (array) apply_filters( 'dev3sa_store_facts', $facts );
	}

	/**
	 * Imports store facts as entries (skips titles that already exist).
	 *
	 * @return array { added, skipped }
	 */
	public static function import() {
		$existing = array_map( static fn( $e ) => strtolower( $e['title'] ), self::all() );
		$added    = 0;
		$skipped  = 0;
		foreach ( self::store_facts() as $fact ) {
			if ( in_array( strtolower( $fact['title'] ), $existing, true ) ) {
				++$skipped;
				continue;
			}
			$fact['source'] = 'import';
			if ( ! is_wp_error( self::save( $fact ) ) ) {
				++$added;
			}
		}
		return array(
			'added'   => $added,
			'skipped' => $skipped,
		);
	}

	/**
	 * Text that gets embedded for an entry.
	 *
	 * @param array $entry Entry.
	 * @return string
	 */
	public static function embed_text( array $entry ) {
		return $entry['title'] . "\n" . $entry['keywords'] . "\n" . $entry['content'];
	}

	/**
	 * Clears the memo (tests).
	 */
	public static function reset_memo() {
		self::$memo = null;
	}

	/**
	 * Casts a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function cast( array $row ) {
		$row['id'] = (int) $row['id'];
		return $row;
	}
}
