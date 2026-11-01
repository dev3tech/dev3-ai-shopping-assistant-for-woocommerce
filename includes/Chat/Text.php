<?php
/**
 * Text helpers: tokenising, stop words, light stemming and plain-text extraction.
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant\Chat;

defined( 'ABSPATH' ) || exit;

/**
 * Language-agnostic where possible (Unicode letters); stop words and stemming are English.
 */
class Text {

	/**
	 * English stop words plus chat filler words that never describe a product.
	 */
	const STOP = array( 'a', 'an', 'the', 'and', 'or', 'but', 'for', 'to', 'of', 'in', 'on', 'at', 'by', 'from', 'with', 'without', 'is', 'are', 'was', 'were', 'be', 'been', 'am', 'do', 'does', 'did', 'have', 'has', 'had', 'i', 'im', 'me', 'my', 'mine', 'we', 'our', 'us', 'you', 'your', 'it', 'its', 'this', 'that', 'these', 'those', 'there', 'here', 'what', 'which', 'who', 'how', 'when', 'where', 'why', 'can', 'could', 'would', 'should', 'will', 'shall', 'may', 'might', 'please', 'pls', 'thanks', 'thank', 'hi', 'hello', 'hey', 'any', 'some', 'something', 'anything', 'show', 'find', 'looking', 'look', 'search', 'want', 'wanna', 'need', 'get', 'buy', 'like', 'love', 'got', 'about', 'tell', 'give', 'one', 'ones', 'sell', 'selling', 'shop', 'store', 'item', 'items', 'product', 'products', 'also', 'just', 'very', 'really', 'so', 'too', 'than', 'then', 'if', 'not', 'no', 'yes', 'ok', 'okay', 'more', 'most', 'other', 'under', 'below', 'over', 'above', 'less', 'cheaper', 'between', 'up', 'around', 'price', 'priced', 'cost', 'usd', 'eur', 'gbp', 'dollars', 'euro', 'euros' );

	/**
	 * Normalised terms of a text.
	 *
	 * @param string $text   Text.
	 * @param bool   $unique Unique terms (queries) or every occurrence (documents).
	 * @return string[]
	 */
	public static function terms( $text, $unique = true ) {
		$text  = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text ) : strtolower( (string) $text );
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( (array) $parts as $word ) {
			if ( in_array( $word, self::STOP, true ) || ( mb_strlen( $word ) < 2 && ! is_numeric( $word ) ) ) {
				continue;
			}
			$out[] = self::stem( $word );
		}
		return $unique ? array_values( array_unique( $out ) ) : $out;
	}

	/**
	 * Very light English stemmer (plurals only), so "mugs" finds "mug".
	 *
	 * @param string $word Word.
	 * @return string
	 */
	public static function stem( $word ) {
		$len = strlen( $word );
		if ( $len > 4 && preg_match( '/ies$/', $word ) ) {
			return substr( $word, 0, -3 ) . 'y';
		}
		if ( $len > 4 && preg_match( '/(ss|x|ch|sh)es$/', $word ) ) {
			return substr( $word, 0, -2 );
		}
		if ( $len > 3 && 's' === substr( $word, -1 ) && ! preg_match( '/(ss|us|is)$/', $word ) ) {
			return substr( $word, 0, -1 );
		}
		return $word;
	}

	/**
	 * Plain text from post content (blocks, shortcodes and tags removed), truncated.
	 *
	 * @param string $html Content.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	public static function plain( $html, $max = 600 ) {
		$text = strip_shortcodes( (string) $html );
		$text = preg_replace( '/<!--.*?-->/s', ' ', $text );
		$text = preg_replace( '/<(br|\/p|\/li|\/h[1-6])[^>]*>/i', "\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = trim( preg_replace( "/\n\s*\n+/", "\n", $text ) );
		return self::cut( $text, $max );
	}

	/**
	 * Truncates on a word boundary with an ellipsis.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	public static function cut( $text, $max ) {
		$text = (string) $text;
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $max );
		$space = mb_strrpos( $cut, ' ' );
		return rtrim( false !== $space && $space > $max * 0.6 ? mb_substr( $cut, 0, $space ) : $cut, " ,.;:-\n" ) . '…';
	}

	/**
	 * Cleans shopper input: plain text, single spaces, max length.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	public static function input( $text, $max = 1000 ) {
		$text = sanitize_textarea_field( wp_unslash( (string) $text ) );
		$text = trim( preg_replace( '/[ \t]+/', ' ', $text ) );
		return mb_substr( $text, 0, $max );
	}

	/**
	 * Cleans assistant output: plain text with the light markdown the widget renders.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	public static function output( $text, $max = 1500 ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = trim( preg_replace( "/\n{3,}/", "\n\n", str_replace( "\r", '', $text ) ) );
		return mb_substr( $text, 0, $max );
	}
}
