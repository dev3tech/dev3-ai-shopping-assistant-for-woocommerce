<?php
/**
 * Decides who answers (rules or DEV3 AI) and falls back to rules on any AI problem.
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant\Chat;

use Dev3\AiShoppingAssistant\Ai\Assistant;
use Dev3\AiShoppingAssistant\Core3\Api\Ai;
use Dev3\AiShoppingAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reply shape: { text, intent, source (ai|cache|rules|kb|fallback), products [cards], actions [],
 * quick_replies [], unanswered (bool), tokens, lang, debug }.
 */
class Engine {

	/**
	 * Builds a reply.
	 *
	 * @param string $text   Text.
	 * @param string $intent Intent.
	 * @param array  $extra  Other keys.
	 * @return array
	 */
	public static function make( $text, $intent, array $extra = array() ) {
		return array_merge(
			array(
				'text'          => (string) $text,
				'intent'        => (string) $intent,
				'source'        => 'rules',
				'products'      => array(),
				'actions'       => array(),
				'quick_replies' => array(),
				'unanswered'    => false,
				'tokens'        => 0,
				'lang'          => '',
				'debug'         => array(),
			),
			$extra
		);
	}

	/**
	 * Whether the AI may answer in this mode.
	 *
	 * @param string $mode storefront|admin.
	 * @return bool
	 */
	public static function ai_available( $mode = 'storefront' ) {
		return (bool) Settings::get( 'ai_chat', true ) && Ai::enabled( 'admin' === $mode ? 'admin' : 'storefront' );
	}

	/**
	 * Answers a shopper message.
	 *
	 * @param string $message Message.
	 * @param array  $ctx     history, product_id, recent_products, conversation, user_id, mode, lang.
	 * @return array Reply.
	 */
	public static function reply( $message, array $ctx = array() ) {
		$message  = Text::input( $message, 1000 );
		$mode     = isset( $ctx['mode'] ) && 'admin' === $ctx['mode'] ? 'admin' : 'storefront';
		$detected = Rules::detect( $message );
		$why      = '';

		$over_cap = ! empty( $ctx['conversation'] ) && (int) $ctx['conversation']['ai_messages'] >= (int) Settings::get( 'max_ai_turns', 40 );
		if ( $detected['confidence'] >= 0.9 ) {
			$why = 'rule_match';
		} elseif ( ! self::ai_available( $mode ) ) {
			$why = Settings::get( 'ai_chat', true ) ? 'ai_unavailable' : 'ai_chat_off';
		} elseif ( $over_cap && 'storefront' === $mode ) {
			$why = 'turn_cap';
		} else {
			$reply = Assistant::answer( $message, $ctx + array( 'mode' => $mode ) );
			if ( ! is_wp_error( $reply ) ) {
				return self::finish( $reply, $message, $ctx );
			}
			$why = $reply->get_error_code();
		}

		$reply = Rules::respond( $message, $ctx + array( 'intent' => $detected['intent'] ) );
		if ( ! in_array( $why, array( 'rule_match', 'ai_unavailable', 'ai_chat_off', 'turn_cap' ), true ) ) {
			$reply['source'] = 'fallback'; // The AI failed: the shopper still gets a rules answer.
		}
		$reply['debug'] = array(
			'reason'    => $why,
			'detected'  => $detected,
			'retrieval' => Retriever::$debug,
		);
		return self::finish( $reply, $message, $ctx );
	}

	/**
	 * Final touches shared by both paths.
	 *
	 * @param array  $reply   Reply.
	 * @param string $message Message.
	 * @param array  $ctx     Context.
	 * @return array
	 */
	private static function finish( array $reply, $message, array $ctx ) {
		if ( 'unanswered' === $reply['intent'] ) {
			$reply['unanswered'] = true;
		}
		/**
		 * Filters every assistant reply before it is stored and sent.
		 *
		 * @param array  $reply   Reply.
		 * @param string $message Shopper message.
		 * @param array  $ctx     Context.
		 */
		return (array) apply_filters( 'dev3sa_chat_reply', $reply, $message, $ctx );
	}
}
