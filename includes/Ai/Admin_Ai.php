<?php
/**
 * Admin DEV3 AI features: knowledge builder (A3), knowledge gaps (A4), insights (A5), persona (A6)
 * and conversation summaries. Everything returns drafts; nothing is saved here except the
 * read-only insights report.
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant\Ai;

use Dev3\AiShoppingAssistant\Chat\Text;
use Dev3\AiShoppingAssistant\Core3\Api\Prompts;
use Dev3\AiShoppingAssistant\Core3\Core\Privacy;
use Dev3\AiShoppingAssistant\Data\Conversations;
use Dev3\AiShoppingAssistant\Data\Knowledge;
use Dev3\AiShoppingAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrappers around Prompts::run with sanitised output.
 */
class Admin_Ai {

	const INSIGHTS_OPTION = 'dev3sa_insights';

	/**
	 * Common prompt vars.
	 *
	 * @return array
	 */
	private static function base_vars() {
		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'fields'     => 'names',
				'number'     => 40,
			)
		);
		return array(
			'store'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'url'        => home_url( '/' ),
			'currency'   => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'country'    => function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_base_country() : '',
			'categories' => is_wp_error( $cats ) ? '' : implode( ', ', (array) $cats ),
			'existing'   => implode( ' | ', array_slice( wp_list_pluck( Knowledge::all(), 'title' ), 0, 80 ) ),
			'language'   => get_locale(),
		);
	}

	/**
	 * Store facts as prompt text.
	 *
	 * @return string
	 */
	private static function facts_text() {
		$out = array();
		foreach ( Knowledge::store_facts() as $fact ) {
			$out[] = '## ' . $fact['title'] . "\n" . Text::cut( $fact['content'], 1500 );
		}
		return $out ? implode( "\n\n", $out ) : '(no policy pages, shipping zones or gateways configured)';
	}

	/**
	 * Cleans AI entries into knowledge drafts.
	 *
	 * @param array $entries Raw entries.
	 * @param bool  $gaps    Keep asked/examples.
	 * @return array
	 */
	private static function clean_entries( array $entries, $gaps = false ) {
		$out = array();
		foreach ( $entries as $i => $e ) {
			$row = Knowledge::clean(
				array(
					'title'    => (string) ( $e['title'] ?? '' ),
					'content'  => (string) ( $e['content'] ?? '' ),
					'type'     => (string) ( $e['type'] ?? 'faq' ),
					'keywords' => (string) ( $e['keywords'] ?? '' ),
					'source'   => 'ai',
				)
			);
			if ( is_wp_error( $row ) ) {
				continue;
			}
			$draft = array(
				'key'      => 'd' . $i,
				'title'    => $row['title'],
				'content'  => $row['content'],
				'type'     => $row['type'],
				'keywords' => $row['keywords'],
				'confirm'  => false !== strpos( $row['content'], '[CONFIRM' ),
			);
			if ( $gaps ) {
				$draft['asked']    = max( 1, (int) ( $e['asked'] ?? 1 ) );
				$draft['examples'] = array_slice( array_map( 'sanitize_text_field', (array) ( $e['examples'] ?? array() ) ), 0, 3 );
			}
			$out[] = $draft;
		}
		return $out;
	}

	/**
	 * Meta block for responses.
	 *
	 * @param array $result AI result.
	 * @return array
	 */
	private static function meta( array $result ) {
		return array(
			'model'  => (string) ( $result['model'] ?? '' ),
			'tokens' => (int) ( $result['usage']['total'] ?? 0 ),
			'cached' => ! empty( $result['cached'] ),
		);
	}

	/**
	 * A3: knowledge entries from store facts.
	 *
	 * @param int $count Entries.
	 * @return array|\WP_Error { drafts, meta }
	 */
	public static function build_knowledge( $count = 8 ) {
		$vars          = self::base_vars();
		$vars['facts'] = self::facts_text();
		$vars['count'] = max( 3, min( 12, (int) $count ) );
		$result        = Prompts::run( 'kb_build', $vars, 'admin' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'drafts' => self::clean_entries( (array) ( $result['data']['entries'] ?? array() ) ),
			'meta'   => self::meta( $result ),
		);
	}

	/**
	 * A4: entries for unanswered / low-rated questions.
	 *
	 * @param int $days Days.
	 * @return array|\WP_Error { drafts, questions, meta }
	 */
	public static function gaps( $days = 30 ) {
		$questions = Conversations::top_questions( $days, 60, 'unanswered' );
		foreach ( Conversations::low_rated( $days, 20 ) as $row ) {
			$questions[] = array(
				'question' => mb_strtolower( Text::cut( $row['question'], 120 ) ),
				'count'    => 1,
			);
		}
		if ( ! $questions ) {
			return array(
				'drafts'    => array(),
				'questions' => array(),
				'meta'      => null,
			);
		}
		$lines = array();
		foreach ( $questions as $q ) {
			$lines[] = $q['count'] . ': ' . Privacy::mask( $q['question'] );
		}
		$vars              = self::base_vars();
		$vars['facts']     = self::facts_text();
		$vars['questions'] = implode( "\n", $lines );
		$result            = Prompts::run( 'kb_gaps', $vars, 'admin' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'drafts'    => self::clean_entries( (array) ( $result['data']['entries'] ?? array() ), true ),
			'questions' => $questions,
			'meta'      => self::meta( $result ),
		);
	}

	/**
	 * A5: insights report (saved as the latest report; read-only).
	 *
	 * @param int $days Days.
	 * @return array|\WP_Error Report.
	 */
	public static function insights( $days = 30 ) {
		$messages = Conversations::recent_user_messages( $days, 250 );
		if ( count( $messages ) < 3 ) {
			return new \WP_Error( 'dev3sa_not_enough', __( 'There are not enough conversations yet. Insights need at least a few shopper messages.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$stats = Conversations::stats( $days );
		unset( $stats['series'] );
		$vars             = self::base_vars();
		$vars['days']     = (int) $days;
		$vars['stats']    = wp_json_encode( $stats );
		$vars['top']      = wp_json_encode( wp_list_pluck( Conversations::top_questions( $days, 15 ), 'count', 'question' ) );
		$vars['messages'] = implode( "\n", array_map( static fn( $m ) => '- ' . Privacy::mask( Text::cut( $m, 200 ) ), $messages ) );
		$result           = Prompts::run( 'insights', $vars, 'admin' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$d      = (array) ( $result['data'] ?? array() );
		$report = array(
			'headline'         => sanitize_text_field( (string) ( $d['headline'] ?? '' ) ),
			'themes'           => array_map(
				static fn( $t ) => array(
					'title'      => sanitize_text_field( (string) ( $t['title'] ?? '' ) ),
					'share'      => max( 0, min( 100, (int) ( $t['share'] ?? 0 ) ) ),
					'summary'    => sanitize_text_field( (string) ( $t['summary'] ?? '' ) ),
					'suggestion' => sanitize_text_field( (string) ( $t['suggestion'] ?? '' ) ),
				),
				array_slice( (array) ( $d['themes'] ?? array() ), 0, 6 )
			),
			'missing_products' => array_map( 'sanitize_text_field', array_slice( (array) ( $d['missing_products'] ?? array() ), 0, 8 ) ),
			'sentiment'        => array(
				'positive' => (int) ( $d['sentiment']['positive'] ?? 0 ),
				'neutral'  => (int) ( $d['sentiment']['neutral'] ?? 0 ),
				'negative' => (int) ( $d['sentiment']['negative'] ?? 0 ),
			),
			'actions'          => array_map( 'sanitize_text_field', array_slice( (array) ( $d['actions'] ?? array() ), 0, 5 ) ),
			'days'             => (int) $days,
			'messages'         => count( $messages ),
			'generated_at'     => gmdate( 'c' ),
			'meta'             => self::meta( $result ),
		);
		update_option( self::INSIGHTS_OPTION, $report, false );
		return $report;
	}

	/**
	 * A6: persona copy (draft for settings).
	 *
	 * @param string $brief Owner's notes.
	 * @return array|\WP_Error { draft, meta }
	 */
	public static function persona( $brief ) {
		$vars          = self::base_vars();
		$vars['brief'] = '' !== trim( (string) $brief ) ? Text::input( $brief, 600 ) : '(none)';
		$vars['tone']  = (string) Settings::get( 'ai_tone', 'friendly' );
		$result        = Prompts::run( 'persona', $vars, 'admin' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$d = (array) ( $result['data'] ?? array() );
		return array(
			'draft' => array(
				'bot_name'        => mb_substr( sanitize_text_field( (string) ( $d['bot_name'] ?? '' ) ), 0, 40 ),
				'subtitle'        => mb_substr( sanitize_text_field( (string) ( $d['subtitle'] ?? '' ) ), 0, 70 ),
				'greeting'        => mb_substr( sanitize_textarea_field( (string) ( $d['greeting'] ?? '' ) ), 0, 400 ),
				'starters'        => implode( "\n", array_slice( array_map( static fn( $s ) => mb_substr( sanitize_text_field( (string) $s ), 0, 40 ), (array) ( $d['starters'] ?? array() ) ), 0, 6 ) ),
				'away_message'    => mb_substr( sanitize_textarea_field( (string) ( $d['away_message'] ?? '' ) ), 0, 300 ),
				'ai_instructions' => mb_substr( sanitize_textarea_field( (string) ( $d['ai_instructions'] ?? '' ) ), 0, 1500 ),
			),
			'meta'  => self::meta( $result ),
		);
	}

	/**
	 * Conversation summary (draft; saved only when the admin clicks Save).
	 *
	 * @param array $conversation Conversation.
	 * @return array|\WP_Error { summary, sentiment, needs_followup, meta }
	 */
	public static function summary( array $conversation ) {
		$lines = array();
		foreach ( Conversations::messages( $conversation['id'], 60 ) as $m ) {
			$lines[] = strtoupper( $m['role'] ) . ': ' . Privacy::mask( Text::cut( $m['content'], 400 ) );
		}
		if ( ! $lines ) {
			return new \WP_Error( 'dev3sa_empty_conversation', __( 'This conversation has no messages to summarise.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$result = Prompts::run(
			'summary',
			array(
				'transcript' => implode( "\n", $lines ),
				'language'   => get_locale(),
			),
			'admin'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$d = (array) ( $result['data'] ?? array() );
		return array(
			'summary'        => mb_substr( sanitize_textarea_field( (string) ( $d['summary'] ?? '' ) ), 0, 600 ),
			'sentiment'      => in_array( $d['sentiment'] ?? '', array( 'positive', 'neutral', 'negative' ), true ) ? $d['sentiment'] : 'neutral',
			'needs_followup' => ! empty( $d['needs_followup'] ),
			'meta'           => self::meta( $result ),
		);
	}
}
