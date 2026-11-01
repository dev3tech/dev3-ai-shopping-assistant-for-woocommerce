<?php
/**
 * Admin routes (manage capability + strict nonce).
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant\Rest;

use Dev3\AiShoppingAssistant\Ai\Admin_Ai;
use Dev3\AiShoppingAssistant\Chat\Engine;
use Dev3\AiShoppingAssistant\Chat\Handoff;
use Dev3\AiShoppingAssistant\Chat\Text;
use Dev3\AiShoppingAssistant\Core3\Api\Ai;
use Dev3\AiShoppingAssistant\Core3\Rest\Base_Controller;
use Dev3\AiShoppingAssistant\Data\Conversations;
use Dev3\AiShoppingAssistant\Data\Knowledge;
use Dev3\AiShoppingAssistant\Index\Indexer;
use Dev3\AiShoppingAssistant\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under dev3sa/v1.
 */
class Admin_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$manage = array( $this, 'can_manage' );
		$id     = '(?P<id>\d+)';
		$map    = array(
			array( '/stats', 'GET', 'stats' ),
			array( '/conversations', 'GET', 'conversations' ),
			array( '/conversations/export', 'GET', 'export' ),
			array( '/conversations/' . $id, 'GET', 'conversation' ),
			array( '/conversations/' . $id, 'POST', 'update_conversation' ),
			array( '/conversations/' . $id, 'DELETE', 'delete_conversation' ),
			array( '/conversations/' . $id . '/reply', 'POST', 'reply' ),
			array( '/conversations/' . $id . '/summary', 'POST', 'summary' ),
			array( '/knowledge', 'GET', 'knowledge' ),
			array( '/knowledge', 'POST', 'save_knowledge' ),
			array( '/knowledge/' . $id, 'DELETE', 'delete_knowledge' ),
			array( '/knowledge/import', 'POST', 'import_knowledge' ),
			array( '/knowledge/bulk', 'POST', 'bulk_knowledge' ),
			array( '/ai/knowledge', 'POST', 'ai_knowledge' ),
			array( '/ai/gaps', 'POST', 'ai_gaps' ),
			array( '/ai/insights', 'GET', 'last_insights' ),
			array( '/ai/insights', 'POST', 'ai_insights' ),
			array( '/ai/persona', 'POST', 'ai_persona' ),
			array( '/index', 'GET', 'index_status' ),
			array( '/index', 'POST', 'index_start' ),
			array( '/test-chat', 'POST', 'test_chat' ),
			array( '/lookup', 'GET', 'lookup' ),
		);
		foreach ( $map as $route ) {
			register_rest_route(
				self::NS,
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( $this, $route[2] ),
					'permission_callback' => $manage,
				)
			);
		}
	}

	/**
	 * GET /stats?days=30.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function stats( \WP_REST_Request $request ) {
		$days  = max( 1, min( 365, (int) ( $request->get_param( 'days' ) ? $request->get_param( 'days' ) : 30 ) ) );
		$index = Indexer::status();
		return $this->respond(
			array(
				'stats'      => Conversations::stats( $days ),
				'top'        => Conversations::top_questions( $days, 8 ),
				'unanswered' => Conversations::top_questions( $days, 8, 'unanswered' ),
				'index'      => $index,
				'currency'   => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
				'checklist'  => array(
					'widget'    => (bool) Settings::get( 'widget_enabled', true ),
					'knowledge' => Knowledge::count( 'publish' ) > 0,
					'ai'        => Ai::enabled( 'storefront' ),
					'index'     => $index['products_indexed'] > 0,
				),
				'online'     => Settings::is_online(),
			)
		);
	}

	/**
	 * GET /conversations.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function conversations( \WP_REST_Request $request ) {
		return $this->respond(
			Conversations::query(
				array(
					'status'   => sanitize_key( (string) $request->get_param( 'status' ) ),
					'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
					'rating'   => sanitize_key( (string) $request->get_param( 'rating' ) ),
					'carts'    => (bool) $request->get_param( 'carts' ),
					'days'     => absint( $request->get_param( 'days' ) ),
					'page'     => absint( $request->get_param( 'page' ) ),
					'per_page' => absint( $request->get_param( 'per_page' ) ),
				)
			)
		);
	}

	/**
	 * Conversation or 404.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	private function find( \WP_REST_Request $request ) {
		$conv = Conversations::get( (int) $request['id'] );
		return $conv ? $conv : new \WP_Error( 'dev3sa_not_found', __( 'Conversation not found.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 404 ) );
	}

	/**
	 * GET /conversations/<id>: conversation + transcript.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function conversation( \WP_REST_Request $request ) {
		$conv = $this->find( $request );
		if ( is_wp_error( $conv ) ) {
			return $this->respond( $conv );
		}
		$messages = array();
		foreach ( Conversations::messages( $conv['id'], 500 ) as $m ) {
			$m['product_cards'] = $m['products'] ? array_map(
				static fn( $id ) => array(
					'id'   => $id,
					'name' => get_the_title( $id ),
					'url'  => get_edit_post_link( $id, 'raw' ),
				),
				$m['products']
			) : array();
			$messages[]         = $m;
		}
		$conv['order_url'] = $conv['order_id'] && function_exists( 'wc_get_order' ) && wc_get_order( $conv['order_id'] ) ? wc_get_order( $conv['order_id'] )->get_edit_order_url() : '';
		return $this->respond(
			array(
				'conversation' => $conv,
				'messages'     => $messages,
			)
		);
	}

	/**
	 * POST /conversations/<id>: status / summary / sentiment.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_conversation( \WP_REST_Request $request ) {
		$conv = $this->find( $request );
		if ( is_wp_error( $conv ) ) {
			return $this->respond( $conv );
		}
		$fields = array();
		if ( null !== $request->get_param( 'status' ) ) {
			$fields['status'] = sanitize_key( (string) $request->get_param( 'status' ) );
		}
		if ( null !== $request->get_param( 'summary' ) ) {
			$fields['summary'] = mb_substr( sanitize_textarea_field( (string) $request->get_param( 'summary' ) ), 0, 600 );
		}
		if ( in_array( $request->get_param( 'sentiment' ), array( 'positive', 'neutral', 'negative', '' ), true ) ) {
			$fields['sentiment'] = (string) $request->get_param( 'sentiment' );
		}
		Conversations::update( $conv['id'], $fields );
		return $this->respond( Conversations::get( $conv['id'] ) );
	}

	/**
	 * DELETE /conversations/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_conversation( \WP_REST_Request $request ) {
		$conv = $this->find( $request );
		if ( is_wp_error( $conv ) ) {
			return $this->respond( $conv );
		}
		Conversations::delete( $conv['id'] );
		return $this->respond( array( 'deleted' => true ) );
	}

	/**
	 * POST /conversations/<id>/reply.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reply( \WP_REST_Request $request ) {
		$conv = $this->find( $request );
		if ( is_wp_error( $conv ) ) {
			return $this->respond( $conv );
		}
		$sent = Handoff::reply( $conv, (string) $request->get_param( 'reply' ), false !== $request->get_param( 'resolve' ) );
		return is_wp_error( $sent ) ? $this->respond( $sent ) : $this->conversation( $request );
	}

	/**
	 * POST /conversations/<id>/summary (DEV3 AI draft).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function summary( \WP_REST_Request $request ) {
		$conv = $this->find( $request );
		return $this->respond( is_wp_error( $conv ) ? $conv : Admin_Ai::summary( $conv ) );
	}

	/**
	 * GET /conversations/export → { filename, csv }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function export( \WP_REST_Request $request ) {
		$days = absint( $request->get_param( 'days' ) );
		$rows = Conversations::query(
			array(
				'days'     => $days,
				'per_page' => 100,
				'page'     => 1,
			)
		);
		$all  = $rows['rows'];
		$page = 1;
		$have = count( $all );
		while ( $have < $rows['total'] && $page < 50 ) {
			++$page;
			$more = Conversations::query(
				array(
					'days'     => $days,
					'per_page' => 100,
					'page'     => $page,
				)
			);
			$all  = array_merge( $all, $more['rows'] );
			$have = $more['rows'] ? count( $all ) : $rows['total'];
		}
		$lines = array( array( 'id', 'started', 'updated', 'status', 'name', 'email', 'messages', 'ai_messages', 'add_to_carts', 'order_id', 'revenue', 'thumbs_up', 'thumbs_down', 'first_question', 'summary' ) );
		foreach ( $all as $c ) {
			$lines[] = array( $c['id'], $c['created_at'], $c['updated_at'], $c['status'], $c['name'], $c['email'], $c['messages'], $c['ai_messages'], $c['carts'], $c['order_id'], $c['revenue'], $c['rating_up'], $c['rating_down'], (string) ( $c['first_question'] ?? '' ), $c['summary'] );
		}
		$csv = '';
		foreach ( $lines as $line ) {
			$csv .= implode(
				',',
				array_map(
					static function ( $v ) {
						$v = (string) $v;
						// Neutralise spreadsheet formulas.
						if ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@' ), true ) && ! is_numeric( $v ) ) {
							$v = "'" . $v;
						}
						return '"' . str_replace( '"', '""', $v ) . '"';
					},
					$line
				)
			) . "\n";
		}
		return $this->respond(
			array(
				'filename' => 'chat-conversations-' . gmdate( 'Y-m-d' ) . '.csv',
				'csv'      => $csv,
				'count'    => count( $all ),
			)
		);
	}

	/**
	 * GET /knowledge.
	 *
	 * @return \WP_REST_Response
	 */
	public function knowledge() {
		return $this->respond(
			array(
				'entries' => Knowledge::all(),
				'facts'   => count( Knowledge::store_facts() ),
			)
		);
	}

	/**
	 * POST /knowledge (id = update).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_knowledge( \WP_REST_Request $request ) {
		return $this->respond( Knowledge::save( (array) $request->get_params() ) );
	}

	/**
	 * DELETE /knowledge/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_knowledge( \WP_REST_Request $request ) {
		Knowledge::delete( (int) $request['id'] );
		return $this->respond( array( 'deleted' => true ) );
	}

	/**
	 * POST /knowledge/import.
	 *
	 * @return \WP_REST_Response
	 */
	public function import_knowledge() {
		$result            = Knowledge::import();
		$result['entries'] = Knowledge::all();
		return $this->respond( $result );
	}

	/**
	 * POST /knowledge/bulk: saves accepted AI drafts.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function bulk_knowledge( \WP_REST_Request $request ) {
		$saved  = 0;
		$errors = array();
		foreach ( array_slice( (array) $request->get_param( 'entries' ), 0, 50 ) as $entry ) {
			$entry           = (array) $entry;
			$entry['source'] = 'ai';
			unset( $entry['id'] );
			$row = Knowledge::save( $entry );
			if ( is_wp_error( $row ) ) {
				$errors[] = $row->get_error_message();
			} else {
				++$saved;
			}
		}
		return $this->respond(
			array(
				'saved'   => $saved,
				'errors'  => $errors,
				'entries' => Knowledge::all(),
			)
		);
	}

	/**
	 * POST /ai/knowledge.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_knowledge( \WP_REST_Request $request ) {
		return $this->respond( Admin_Ai::build_knowledge( (int) ( $request->get_param( 'count' ) ? $request->get_param( 'count' ) : 8 ) ) );
	}

	/**
	 * POST /ai/gaps.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_gaps( \WP_REST_Request $request ) {
		return $this->respond( Admin_Ai::gaps( max( 1, min( 365, (int) ( $request->get_param( 'days' ) ? $request->get_param( 'days' ) : 30 ) ) ) ) );
	}

	/**
	 * GET /ai/insights: last report.
	 *
	 * @return \WP_REST_Response
	 */
	public function last_insights() {
		$report = get_option( Admin_Ai::INSIGHTS_OPTION, null );
		return $this->respond( array( 'report' => is_array( $report ) ? $report : null ) );
	}

	/**
	 * POST /ai/insights.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_insights( \WP_REST_Request $request ) {
		$report = Admin_Ai::insights( max( 1, min( 365, (int) ( $request->get_param( 'days' ) ? $request->get_param( 'days' ) : 30 ) ) ) );
		return $this->respond( is_wp_error( $report ) ? $report : array( 'report' => $report ) );
	}

	/**
	 * POST /ai/persona.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ai_persona( \WP_REST_Request $request ) {
		return $this->respond( Admin_Ai::persona( (string) $request->get_param( 'brief' ) ) );
	}

	/**
	 * GET /index.
	 *
	 * @return \WP_REST_Response
	 */
	public function index_status() {
		return $this->respond( Indexer::status() );
	}

	/**
	 * POST /index { force }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function index_start( \WP_REST_Request $request ) {
		$job = Indexer::start( (bool) $request->get_param( 'force' ) );
		return $this->respond( $job, is_array( $job ) && isset( $job['id'] ) ? 201 : 200 );
	}

	/**
	 * POST /test-chat { message, history, product_id }: admin console with debug info; nothing stored.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function test_chat( \WP_REST_Request $request ) {
		$message = Text::input( (string) $request->get_param( 'message' ), 1000 );
		if ( '' === $message ) {
			return $this->respond( new \WP_Error( 'dev3sa_empty', __( 'Type a message first.', 'dev3-ai-shopping-assistant-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		$history = array();
		foreach ( array_slice( (array) $request->get_param( 'history' ), -8 ) as $turn ) {
			$turn = (array) $turn;
			$text = Text::input( (string) ( $turn['text'] ?? '' ), 1500 );
			if ( '' !== $text ) {
				$history[] = array(
					'role' => 'user' === ( $turn['role'] ?? '' ) ? 'user' : 'bot',
					'text' => $text,
				);
			}
		}
		$started              = microtime( true );
		$reply                = Engine::reply(
			$message,
			array(
				'history'         => $history,
				'product_id'      => absint( $request->get_param( 'product_id' ) ),
				'recent_products' => array_map( 'absint', (array) $request->get_param( 'recent_products' ) ),
				'mode'            => 'admin',
				'user_id'         => 0,
			)
		);
		$reply['debug']['ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		return $this->respond( $reply );
	}

	/**
	 * GET /lookup?type=pages|products&search=: options for selects.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function lookup( \WP_REST_Request $request ) {
		$type  = 'products' === $request->get_param( 'type' ) ? 'product' : 'page';
		$posts = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				's'              => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		return $this->respond(
			array_map(
				static fn( $p ) => array(
					'value' => (int) $p->ID,
					'label' => wp_strip_all_tags( get_the_title( $p ) ),
				),
				$posts
			)
		);
	}
}
