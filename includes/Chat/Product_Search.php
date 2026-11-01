<?php
/**
 * Keyword product finder (works without AI) and product cards for the widget.
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant\Chat;

use Dev3\AiShoppingAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Scores products by how many query terms hit their title, SKU, content, categories, tags and
 * attribute terms; understands "under 50", "between 20 and 40", "on sale", "best sellers", "new".
 */
class Product_Search {

	/**
	 * Parses price limits, sort hints and terms out of a shopper query.
	 *
	 * @param string $query Query.
	 * @return array { terms, min, max, on_sale, in_stock, sort }
	 */
	public static function parse( $query ) {
		$q   = mb_strtolower( (string) $query );
		$out = array(
			'terms'    => array(),
			'min'      => null,
			'max'      => null,
			'on_sale'  => (bool) preg_match( '/\b(on sale|sale|deals?|discount(ed|s)?|offers?|promo(tion)?s?|clearance)\b/u', $q ),
			'in_stock' => (bool) preg_match( '/\b(in stock|available now|available)\b/u', $q ),
			'sort'     => '',
		);
		$num = '(\d+(?:[.,]\d{1,2})?)';
		if ( preg_match( '/between\s*\D{0,3}' . $num . '\s*(?:and|-|to)\s*\D{0,3}' . $num . '/u', $q, $m ) ) {
			$out['min'] = (float) str_replace( ',', '.', $m[1] );
			$out['max'] = (float) str_replace( ',', '.', $m[2] );
			$q          = str_replace( $m[0], ' ', $q );
		} else {
			if ( preg_match( '/(?:under|below|less than|cheaper than|max(?:imum)?|up to|no more than|<)\s*\D{0,3}' . $num . '/u', $q, $m ) ) {
				$out['max'] = (float) str_replace( ',', '.', $m[1] );
				$q          = str_replace( $m[0], ' ', $q );
			}
			if ( preg_match( '/(?:over|above|more than|at least|min(?:imum)?|from|>)\s*\D{0,3}' . $num . '/u', $q, $m ) ) {
				$out['min'] = (float) str_replace( ',', '.', $m[1] );
				$q          = str_replace( $m[0], ' ', $q );
			}
		}
		if ( preg_match( '/\b(best ?sell(ers?|ing)|popular|top selling|most sold|trending)\b/u', $q ) ) {
			$out['sort'] = 'popular';
		} elseif ( preg_match( '/\b(new|newest|latest|new arrivals?|just in)\b/u', $q ) ) {
			$out['sort'] = 'new';
		} elseif ( preg_match( '/\b(top rated|best rated|highest rated|best reviewed)\b/u', $q ) ) {
			$out['sort'] = 'rating';
		} elseif ( preg_match( '/\b(cheap|cheapest|budget|affordable|inexpensive)\b/u', $q ) ) {
			$out['sort'] = 'price';
		}
		$noise        = array( 'sale', 'deal', 'discount', 'discounted', 'offer', 'promo', 'promotion', 'clearance', 'best', 'seller', 'selling', 'sellers', 'bestseller', 'bestsellers', 'popular', 'top', 'sold', 'trending', 'new', 'newest', 'latest', 'arrival', 'just', 'rated', 'highest', 'reviewed', 'cheap', 'cheapest', 'budget', 'affordable', 'inexpensive', 'stock', 'available', 'now', 'recommend', 'recommendation', 'suggest', 'suggestion', 'gift', 'idea' );
		$out['terms'] = array_values( array_diff( Text::terms( preg_replace( '/[$€£¥]?\d+(?:[.,]\d+)?/u', ' ', $q ) ), array_map( array( Text::class, 'stem' ), $noise ) ) );
		return $out;
	}

	/**
	 * Searches the catalog.
	 *
	 * @param string $query Query.
	 * @param int    $limit Max results.
	 * @param array  $opts  exclude (ids).
	 * @return int[] Product ids, best first.
	 */
	public static function search( $query, $limit = 4, array $opts = array() ) {
		$parsed  = self::parse( $query );
		$terms   = array_slice( $parsed['terms'], 0, 6 );
		$exclude = isset( $opts['exclude'] ) ? array_map( 'intval', (array) $opts['exclude'] ) : array();
		$scores  = array();

		if ( $terms ) {
			$store = \WC_Data_Store::load( 'product' );
			foreach ( $terms as $term ) {
				foreach ( (array) $store->search_products( $term, '', false, false, 200 ) as $id ) {
					$id = (int) $id;
					if ( $id ) {
						$scores[ $id ] = ( $scores[ $id ] ?? 0 ) + 1;
					}
				}
				foreach ( self::term_matches( $term ) as $id ) {
					$scores[ $id ] = ( $scores[ $id ] ?? 0 ) + 1.5;
				}
			}
			if ( ! $scores ) {
				return array();
			}
			// Title hits matter most.
			arsort( $scores );
			foreach ( array_slice( array_keys( $scores ), 0, 120 ) as $id ) {
				$title = Text::terms( get_the_title( $id ) );
				$hits  = count( array_intersect( $terms, $title ) );
				if ( $hits ) {
					$scores[ $id ] += 2 * $hits;
				}
			}
		} elseif ( '' !== $parsed['sort'] || $parsed['on_sale'] || null !== $parsed['min'] || null !== $parsed['max'] || preg_match( '/\b(gift|present|recommend|suggest|ideas?)\b/i', (string) $query ) ) {
			// No product words ("a gift under 40", "best sellers"): popular products within the limits.
			foreach ( self::listing( '' !== $parsed['sort'] ? $parsed['sort'] : 'popular', $parsed['on_sale'], 60 ) as $rank => $id ) {
				$scores[ $id ] = 100 - $rank;
			}
		} else {
			return array();
		}

		$hide_oos = (bool) Settings::get( 'hide_out_of_stock', false ) || $parsed['in_stock'] || 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' );
		$on_sale  = $parsed['on_sale'] ? array_map( 'intval', wc_get_product_ids_on_sale() ) : array();
		$rows     = array();
		arsort( $scores );
		foreach ( array_slice( array_keys( $scores ), 0, 200 ) as $id ) {
			if ( in_array( $id, $exclude, true ) ) {
				continue;
			}
			$product = wc_get_product( $id );
			if ( ! self::visible( $product ) ) {
				continue;
			}
			if ( $hide_oos && ! $product->is_in_stock() ) {
				continue;
			}
			if ( $parsed['on_sale'] && $terms && ! in_array( $id, $on_sale, true ) && ! $product->is_on_sale() ) {
				continue;
			}
			$price = (float) $product->get_price();
			if ( ( null !== $parsed['min'] && $price < $parsed['min'] ) || ( null !== $parsed['max'] && $price > $parsed['max'] ) ) {
				continue;
			}
			$score = $scores[ $id ];
			if ( 'popular' === $parsed['sort'] && $terms ) {
				$score += min( 2, log( 1 + (int) $product->get_total_sales() ) / 5 );
			} elseif ( 'price' === $parsed['sort'] ) {
				$score -= $price / 100000;
			}
			// Prefer what can be bought now.
			$score      += $product->is_in_stock() ? 0.25 : 0;
			$rows[ $id ] = $score;
			if ( count( $rows ) >= $limit * 4 ) {
				break;
			}
		}
		arsort( $rows );
		if ( 'price' === $parsed['sort'] ) {
			$ids = array_keys( $rows );
			usort( $ids, static fn( $a, $b ) => (float) wc_get_product( $a )->get_price() <=> (float) wc_get_product( $b )->get_price() );
			return array_slice( $ids, 0, $limit );
		}
		/**
		 * Filters keyword search results.
		 *
		 * @param int[]  $ids   Product ids.
		 * @param string $query Query.
		 * @param array  $parsed Parsed query.
		 */
		return array_slice( (array) apply_filters( 'dev3sa_product_search_results', array_keys( $rows ), $query, $parsed ), 0, $limit );
	}

	/**
	 * Product ids in categories/tags/attribute terms whose name matches a term.
	 *
	 * @param string $term Term.
	 * @return int[]
	 */
	private static function term_matches( $term ) {
		if ( mb_strlen( $term ) < 3 ) {
			return array();
		}
		$taxonomies = array( 'product_cat', 'product_tag' );
		if ( function_exists( 'wc_get_attribute_taxonomy_names' ) ) {
			$taxonomies = array_merge( $taxonomies, wc_get_attribute_taxonomy_names() );
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomies,
				'name__like' => $term,
				'hide_empty' => true,
				'fields'     => 'all',
				'number'     => 20,
			)
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			return array();
		}
		$ids = array();
		foreach ( $terms as $t ) {
			$objects = get_objects_in_term( (int) $t->term_id, $t->taxonomy );
			if ( ! is_wp_error( $objects ) ) {
				$ids = array_merge( $ids, array_map( 'intval', (array) $objects ) );
			}
		}
		return array_slice( array_values( array_unique( $ids ) ), 0, 300 );
	}

	/**
	 * Listing without terms: best sellers, newest, top rated, cheapest or on sale.
	 *
	 * @param string $sort    popular|new|rating|price|''.
	 * @param bool   $on_sale Only products on sale.
	 * @param int    $limit   Max.
	 * @return int[]
	 */
	public static function listing( $sort, $on_sale = false, $limit = 20 ) {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'name',
					'terms'    => array( 'exclude-from-catalog' ),
					'operator' => 'NOT IN',
				),
			),
		);
		if ( 'popular' === $sort ) {
			$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['orderby']  = array(
				'meta_value_num' => 'DESC',
				'date'           => 'DESC',
			);
		} elseif ( 'rating' === $sort ) {
			$args['meta_key'] = '_wc_average_rating'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['orderby']  = array(
				'meta_value_num' => 'DESC',
				'date'           => 'DESC',
			);
		} elseif ( 'price' === $sort ) {
			$args['meta_key'] = '_price'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['orderby']  = 'meta_value_num';
			$args['order']    = 'ASC';
		} else {
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		}
		if ( $on_sale ) {
			$args['post__in'] = array_merge( array( 0 ), array_map( 'intval', wc_get_product_ids_on_sale() ) );
		}
		$query = new \WP_Query( $args );
		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Whether a product may be shown to shoppers.
	 *
	 * @param mixed $product Product.
	 * @return bool
	 */
	public static function visible( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return false;
		}
		if ( 'publish' !== $product->get_status() || '' !== (string) $product->get_post_password() ) {
			return false;
		}
		return in_array( $product->get_catalog_visibility(), array( 'visible', 'catalog', 'search' ), true );
	}

	/**
	 * Widget card for a product.
	 *
	 * @param \WC_Product|int $product Product.
	 * @return array|null
	 */
	public static function card( $product ) {
		$product = $product instanceof \WC_Product ? $product : wc_get_product( (int) $product );
		if ( ! self::visible( $product ) ) {
			return null;
		}
		$image_id = (int) $product->get_image_id();
		$image    = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
		$type     = $product->get_type();
		$can_add  = 'simple' === $type && $product->is_purchasable() && $product->is_in_stock() && Settings::get( 'add_to_cart', true );
		$card     = array(
			'id'          => $product->get_id(),
			'name'        => wp_strip_all_tags( $product->get_name() ),
			'url'         => get_permalink( $product->get_id() ),
			'image'       => $image ? $image : wc_placeholder_img_src( 'woocommerce_thumbnail' ),
			'price_html'  => wp_kses_post( $product->get_price_html() ),
			'price'       => (float) $product->get_price(),
			'on_sale'     => $product->is_on_sale(),
			'rating'      => round( (float) $product->get_average_rating(), 1 ),
			'reviews'     => (int) $product->get_review_count(),
			'in_stock'    => $product->is_in_stock(),
			'stock_label' => $product->is_in_stock() ? ( $product->managing_stock() && $product->get_stock_quantity() > 0 && $product->get_stock_quantity() <= (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ) ? __( 'Low stock', 'dev3-ai-shopping-assistant-for-woocommerce' ) : __( 'In stock', 'dev3-ai-shopping-assistant-for-woocommerce' ) ) : __( 'Out of stock', 'dev3-ai-shopping-assistant-for-woocommerce' ),
			'type'        => $type,
			'can_add'     => $can_add,
			'cta'         => $can_add ? __( 'Add to cart', 'dev3-ai-shopping-assistant-for-woocommerce' ) : ( 'external' === $type ? wp_strip_all_tags( $product->single_add_to_cart_text() ) : __( 'Choose options', 'dev3-ai-shopping-assistant-for-woocommerce' ) ),
			'cta_url'     => 'external' === $type && method_exists( $product, 'get_product_url' ) ? $product->get_product_url() : get_permalink( $product->get_id() ),
		);
		/**
		 * Filters the product card sent to the chat widget.
		 *
		 * @param array       $card    Card.
		 * @param \WC_Product $product Product.
		 */
		return apply_filters( 'dev3sa_product_card', $card, $product );
	}

	/**
	 * Cards for ids (invisible products dropped).
	 *
	 * @param int[] $ids Ids.
	 * @return array
	 */
	public static function cards( array $ids ) {
		return array_values( array_filter( array_map( array( __CLASS__, 'card' ), array_values( array_unique( array_map( 'intval', $ids ) ) ) ) ) );
	}

	/**
	 * Compact product facts for the AI prompt (no HTML).
	 *
	 * @param \WC_Product $product Product.
	 * @param int         $desc    Description length.
	 * @return array
	 */
	public static function facts( \WC_Product $product, $desc = 220 ) {
		$attrs = array();
		foreach ( $product->get_attributes() as $attr ) {
			if ( ! $attr instanceof \WC_Product_Attribute || ! $attr->get_visible() ) {
				continue;
			}
			$values = $attr->is_taxonomy() ? wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'names' ) ) : $attr->get_options();
			$attrs[ wc_attribute_label( $attr->get_name() ) ] = implode( ', ', array_slice( array_map( 'strval', (array) $values ), 0, 12 ) );
		}
		$cats = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		$text = $product->get_short_description() ? $product->get_short_description() : $product->get_description();
		return array_filter(
			array(
				'id'         => $product->get_id(),
				'name'       => wp_strip_all_tags( $product->get_name() ),
				'price'      => html_entity_decode( wp_strip_all_tags( $product->get_price_html() ), ENT_QUOTES, 'UTF-8' ),
				'on_sale'    => $product->is_on_sale() ? 'yes' : null,
				'stock'      => $product->is_in_stock() ? 'in stock' : 'out of stock',
				'type'       => $product->get_type(),
				'rating'     => $product->get_review_count() ? round( (float) $product->get_average_rating(), 1 ) . '/5 (' . $product->get_review_count() . ')' : null,
				'categories' => is_wp_error( $cats ) ? null : implode( ', ', (array) $cats ),
				'attributes' => $attrs ? $attrs : null,
				'sku'        => $product->get_sku() ? $product->get_sku() : null,
				'about'      => Text::plain( $text, $desc ),
			)
		);
	}
}
