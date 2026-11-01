<?php
/**
 * Conversations + messages repository, analytics queries, retention and GDPR export/erase.
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant\Data;

use Dev3\AiShoppingAssistant\Core3\Core\Rate_Limiter;
use Dev3\AiShoppingAssistant\Settings;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables; names come from $wpdb->prefix.

/**
 * All SQL for the two chat tables lives here.
 */
class Conversations {

	const STATUSES = array( 'open', 'needs_reply', 'resolved' );

	/**
	 * Current UTC time.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * New random visitor token.
	 *
	 * @return string 32 hex chars.
	 */
	public static function new_token() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Whether a string looks like a token.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	public static function valid_token( $token ) {
		return is_string( $token ) && (bool) preg_match( '/^[a-f0-9]{32}$/', $token );
	}

	/**
	 * Creates a conversation.
	 *
	 * @param array $data Fields (source_url, lang, user_id, email, name).
	 * @return array Row.
	 */
	public static function create( array $data = array() ) {
		global $wpdb;
		$user = wp_get_current_user();
		$row  = array(
			'token'      => self::new_token(),
			'user_id'    => isset( $data['user_id'] ) ? (int) $data['user_id'] : get_current_user_id(),
			'email'      => isset( $data['email'] ) ? sanitize_email( $data['email'] ) : ( $user && $user->ID ? (string) $user->user_email : '' ),
			'name'       => isset( $data['name'] ) ? mb_substr( sanitize_text_field( $data['name'] ), 0, 120 ) : ( $user && $user->ID ? (string) $user->display_name : '' ),
			'status'     => 'open',
			'source_url' => isset( $data['source_url'] ) ? mb_substr( esc_url_raw( (string) $data['source_url'] ), 0, 255 ) : '',
			'lang'       => isset( $data['lang'] ) ? mb_substr( sanitize_key( (string) $data['lang'] ), 0, 12 ) : '',
			'summary'    => '',
			'ip_hash'    => sha1( wp_salt( 'auth' ) . Rate_Limiter::client_ip() ),
			'created_at' => isset( $data['created_at'] ) ? (string) $data['created_at'] : self::now(),
			'updated_at' => isset( $data['created_at'] ) ? (string) $data['created_at'] : self::now(),
		);
		$wpdb->insert( Tables::name( 'conversations' ), $row );
		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Row by id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::name( 'conversations' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? self::cast( $row ) : null;
	}

	/**
	 * Row by visitor token.
	 *
	 * @param string $token Token.
	 * @return array|null
	 */
	public static function by_token( $token ) {
		if ( ! self::valid_token( $token ) ) {
			return null;
		}
		global $wpdb;
		$table = Tables::name( 'conversations' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s", $token ), ARRAY_A );
		return $row ? self::cast( $row ) : null;
	}

	/**
	 * Updates fields.
	 *
	 * @param int   $id     Id.
	 * @param array $fields Fields.
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;
		$allowed = array( 'email', 'name', 'status', 'lang', 'order_id', 'revenue', 'summary', 'sentiment', 'user_id' );
		$fields  = array_intersect_key( $fields, array_flip( $allowed ) );
		if ( isset( $fields['status'] ) && ! in_array( $fields['status'], self::STATUSES, true ) ) {
			unset( $fields['status'] );
		}
		$fields['updated_at'] = self::now();
		$wpdb->update( Tables::name( 'conversations' ), $fields, array( 'id' => (int) $id ) );
	}

	/**
	 * Adds to a counter column.
	 *
	 * @param int    $id    Id.
	 * @param string $field messages|ai_messages|carts|rating_up|rating_down.
	 * @param int    $by    Amount (may be negative).
	 */
	public static function bump( $id, $field, $by = 1 ) {
		if ( ! in_array( $field, array( 'messages', 'ai_messages', 'carts', 'rating_up', 'rating_down' ), true ) ) {
			return;
		}
		global $wpdb;
		$table = Tables::name( 'conversations' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $field is whitelisted above.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$field} = GREATEST(0, CAST({$field} AS SIGNED) + %d), updated_at = %s WHERE id = %d", (int) $by, self::now(), (int) $id ) );
	}

	/**
	 * Stores a message.
	 *
	 * @param int    $conversation_id Conversation.
	 * @param string $role            user|bot|agent|system.
	 * @param string $content         Text.
	 * @param array  $extra           intent, source, products (ids), meta (array), created_at.
	 * @return int Message id.
	 */
	public static function add_message( $conversation_id, $role, $content, array $extra = array() ) {
		global $wpdb;
		$role = in_array( $role, array( 'user', 'bot', 'agent', 'system' ), true ) ? $role : 'user';
		$wpdb->insert(
			Tables::name( 'messages' ),
			array(
				'conversation_id' => (int) $conversation_id,
				'role'            => $role,
				'content'         => mb_substr( (string) $content, 0, 4000 ),
				'intent'          => isset( $extra['intent'] ) ? mb_substr( sanitize_key( $extra['intent'] ), 0, 30 ) : '',
				'source'          => isset( $extra['source'] ) ? mb_substr( sanitize_key( $extra['source'] ), 0, 20 ) : '',
				'products'        => isset( $extra['products'] ) ? mb_substr( implode( ',', array_map( 'absint', (array) $extra['products'] ) ), 0, 255 ) : '',
				'meta'            => isset( $extra['meta'] ) ? (string) wp_json_encode( $extra['meta'] ) : '{}',
				'created_at'      => isset( $extra['created_at'] ) ? (string) $extra['created_at'] : self::now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		self::bump( $conversation_id, 'messages' );
		if ( 'bot' === $role && isset( $extra['source'] ) && 'ai' === $extra['source'] ) {
			self::bump( $conversation_id, 'ai_messages' );
		}
		return $id;
	}

	/**
	 * Messages of a conversation (oldest first).
	 *
	 * @param int $conversation_id Conversation.
	 * @param int $limit           Max rows (latest ones).
	 * @return array
	 */
	public static function messages( $conversation_id, $limit = 200 ) {
		global $wpdb;
		$table = Tables::name( 'messages' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE conversation_id = %d ORDER BY id DESC LIMIT %d", (int) $conversation_id, max( 1, (int) $limit ) ), ARRAY_A );
		$rows  = array_reverse( (array) $rows );
		return array_map( array( __CLASS__, 'cast_message' ), $rows );
	}

	/**
	 * One message.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function message( $id ) {
		global $wpdb;
		$table = Tables::name( 'messages' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? self::cast_message( $row ) : null;
	}

	/**
	 * Recent turns as { role: user|bot, text } for the AI (newest last).
	 *
	 * @param int $conversation_id Conversation.
	 * @param int $limit           Turns.
	 * @return array
	 */
	public static function history( $conversation_id, $limit = 8 ) {
		$out = array();
		foreach ( self::messages( $conversation_id, $limit ) as $m ) {
			if ( in_array( $m['role'], array( 'user', 'bot', 'agent' ), true ) && '' !== $m['content'] ) {
				$out[] = array(
					'role' => 'user' === $m['role'] ? 'user' : 'bot',
					'text' => $m['content'],
				);
			}
		}
		return $out;
	}

	/**
	 * Thumbs up/down on a bot message (changing a vote moves the counters).
	 *
	 * @param array $conversation Conversation.
	 * @param int   $message_id   Message.
	 * @param int   $rating       -1, 0 or 1.
	 * @return bool
	 */
	public static function rate( array $conversation, $message_id, $rating ) {
		$message = self::message( $message_id );
		if ( ! $message || (int) $message['conversation_id'] !== (int) $conversation['id'] || 'bot' !== $message['role'] ) {
			return false;
		}
		$rating = max( -1, min( 1, (int) $rating ) );
		$old    = (int) $message['rating'];
		if ( $old === $rating ) {
			return true;
		}
		global $wpdb;
		$wpdb->update( Tables::name( 'messages' ), array( 'rating' => $rating ), array( 'id' => (int) $message_id ) );
		if ( 1 === $old ) {
			self::bump( $conversation['id'], 'rating_up', -1 );
		} elseif ( -1 === $old ) {
			self::bump( $conversation['id'], 'rating_down', -1 );
		}
		if ( 1 === $rating ) {
			self::bump( $conversation['id'], 'rating_up' );
		} elseif ( -1 === $rating ) {
			self::bump( $conversation['id'], 'rating_down' );
		}
		return true;
	}

	/**
	 * Admin list.
	 *
	 * @param array $args status, search, rating (up|down), carts (bool), days, page, per_page, orderby.
	 * @return array { rows, total }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table  = Tables::name( 'conversations' );
		$msgs   = Tables::name( 'messages' );
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'c.status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['rating'] ) ) {
			$where[] = 'up' === $args['rating'] ? 'c.rating_up > 0' : 'c.rating_down > 0';
		}
		if ( ! empty( $args['carts'] ) ) {
			$where[] = 'c.carts > 0';
		}
		if ( ! empty( $args['days'] ) ) {
			$where[]  = 'c.created_at >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * (int) $args['days'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = "(c.email LIKE %s OR c.name LIKE %s OR c.id IN (SELECT conversation_id FROM {$msgs} WHERE content LIKE %s))";
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, min( 100, isset( $args['per_page'] ) ? (int) $args['per_page'] : 20 ) );
		$page      = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$order     = isset( $args['orderby'] ) && in_array( $args['orderby'], array( 'messages', 'revenue', 'created_at' ), true ) ? $args['orderby'] : 'updated_at';
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above.
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} c WHERE {$where_sql}", $params ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$table} c WHERE {$where_sql}" ) );
		$sql   = "SELECT c.*, (SELECT content FROM {$msgs} m WHERE m.conversation_id = c.id AND m.role = 'user' ORDER BY m.id ASC LIMIT 1) AS first_question FROM {$table} c WHERE {$where_sql} ORDER BY c.{$order} DESC, c.id DESC LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.NotPrepared
		return array(
			'rows'  => array_map( array( __CLASS__, 'cast' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Deletes a conversation and its messages.
	 *
	 * @param int $id Id.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( Tables::name( 'messages' ), array( 'conversation_id' => (int) $id ) );
		$wpdb->delete( Tables::name( 'conversations' ), array( 'id' => (int) $id ) );
	}

	/**
	 * Dashboard numbers for the last N days.
	 *
	 * @param int $days Days.
	 * @return array
	 */
	public static function stats( $days = 30 ) {
		global $wpdb;
		$conv  = Tables::name( 'conversations' );
		$msgs  = Tables::name( 'messages' );
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * max( 1, (int) $days ) );
		$tot   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS conversations, COALESCE(SUM(messages),0) AS messages, COALESCE(SUM(ai_messages),0) AS ai_messages, COALESCE(SUM(carts),0) AS carts, COALESCE(SUM(CASE WHEN order_id > 0 THEN 1 ELSE 0 END),0) AS orders, COALESCE(SUM(revenue),0) AS revenue, COALESCE(SUM(rating_up),0) AS up, COALESCE(SUM(rating_down),0) AS down, COALESCE(SUM(CASE WHEN status = 'needs_reply' THEN 1 ELSE 0 END),0) AS needs_reply FROM {$conv} WHERE created_at >= %s", $since ), ARRAY_A );
		$bot   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$msgs} WHERE role = 'bot' AND created_at >= %s", $since ) );
		$miss  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$msgs} WHERE role = 'user' AND intent = 'unanswered' AND created_at >= %s", $since ) );
		$daily = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) AS day, COUNT(*) AS conversations, COALESCE(SUM(carts),0) AS carts FROM {$conv} WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY day ASC", $since ), ARRAY_A );
		$map   = array();
		foreach ( (array) $daily as $d ) {
			$map[ $d['day'] ] = $d;
		}
		$series = array();
		for ( $i = (int) $days - 1; $i >= 0; $i-- ) {
			$day      = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS * $i );
			$series[] = array(
				'day'           => $day,
				'conversations' => isset( $map[ $day ] ) ? (int) $map[ $day ]['conversations'] : 0,
				'carts'         => isset( $map[ $day ] ) ? (int) $map[ $day ]['carts'] : 0,
			);
		}
		$up    = (int) $tot['up'];
		$down  = (int) $tot['down'];
		$users = max( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$msgs} WHERE role = 'user' AND created_at >= %s", $since ) ) );
		return array(
			'days'          => (int) $days,
			'conversations' => (int) $tot['conversations'],
			'messages'      => (int) $tot['messages'],
			'ai_messages'   => (int) $tot['ai_messages'],
			'ai_rate'       => $bot ? round( 100 * (int) $tot['ai_messages'] / $bot, 1 ) : 0,
			'answered_rate' => round( 100 * ( 1 - min( 1, $miss / $users ) ), 1 ),
			'unanswered'    => $miss,
			'carts'         => (int) $tot['carts'],
			'orders'        => (int) $tot['orders'],
			'revenue'       => round( (float) $tot['revenue'], 2 ),
			'satisfaction'  => ( $up + $down ) ? round( 100 * $up / ( $up + $down ), 1 ) : null,
			'ratings'       => $up + $down,
			'needs_reply'   => (int) $tot['needs_reply'],
			'series'        => $series,
		);
	}

	/**
	 * Most asked shopper questions (normalised text).
	 *
	 * @param int    $days   Days.
	 * @param int    $limit  Rows.
	 * @param string $intent Only this intent ('' = any; 'unanswered' for gaps).
	 * @return array [ { question, count, last } ]
	 */
	public static function top_questions( $days = 30, $limit = 10, $intent = '' ) {
		global $wpdb;
		$msgs  = Tables::name( 'messages' );
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * max( 1, (int) $days ) );
		if ( '' !== $intent ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT LOWER(LEFT(TRIM(content),120)) AS question, COUNT(*) AS count, MAX(created_at) AS last FROM {$msgs} WHERE role = 'user' AND intent = %s AND created_at >= %s GROUP BY question ORDER BY count DESC, last DESC LIMIT %d", $intent, $since, (int) $limit ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT LOWER(LEFT(TRIM(content),120)) AS question, COUNT(*) AS count, MAX(created_at) AS last FROM {$msgs} WHERE role = 'user' AND created_at >= %s GROUP BY question ORDER BY count DESC, last DESC LIMIT %d", $since, (int) $limit ), ARRAY_A );
		}
		return array_map(
			static fn( $r ) => array(
				'question' => (string) $r['question'],
				'count'    => (int) $r['count'],
				'last'     => (string) $r['last'],
			),
			(array) $rows
		);
	}

	/**
	 * Bot answers that shoppers rated down, with the question before each.
	 *
	 * @param int $days  Days.
	 * @param int $limit Rows.
	 * @return array [ { question, answer } ]
	 */
	public static function low_rated( $days = 30, $limit = 20 ) {
		global $wpdb;
		$msgs  = Tables::name( 'messages' );
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * max( 1, (int) $days ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT b.content AS answer, (SELECT u.content FROM {$msgs} u WHERE u.conversation_id = b.conversation_id AND u.id < b.id AND u.role = 'user' ORDER BY u.id DESC LIMIT 1) AS question FROM {$msgs} b WHERE b.role = 'bot' AND b.rating < 0 AND b.created_at >= %s ORDER BY b.id DESC LIMIT %d", $since, (int) $limit ), ARRAY_A );
		return array_values( array_filter( (array) $rows, static fn( $r ) => ! empty( $r['question'] ) ) );
	}

	/**
	 * Recent shopper messages (for AI insights).
	 *
	 * @param int $days  Days.
	 * @param int $limit Rows.
	 * @return string[]
	 */
	public static function recent_user_messages( $days = 30, $limit = 200 ) {
		global $wpdb;
		$msgs  = Tables::name( 'messages' );
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * max( 1, (int) $days ) );
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT content FROM {$msgs} WHERE role = 'user' AND created_at >= %s ORDER BY id DESC LIMIT %d", $since, (int) $limit ) ) );
	}

	/**
	 * Retention: drops messages after the retention period (1 day when transcripts are off) and
	 * empty conversations older than the period.
	 *
	 * @return int Messages deleted.
	 */
	public static function purge() {
		global $wpdb;
		$days   = Settings::get( 'store_transcripts', true ) ? (int) Settings::get( 'retention_days', 90 ) : 1;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * max( 1, $days ) );
		$msgs   = Tables::name( 'messages' );
		$conv   = Tables::name( 'conversations' );
		$count  = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$msgs} WHERE created_at < %s", $cutoff ) );
		$keep   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS * max( $days, (int) Settings::get( 'retention_days', 90 ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$conv} WHERE updated_at < %s AND id NOT IN (SELECT DISTINCT conversation_id FROM {$msgs})", $keep ) );
		return $count;
	}

	/**
	 * Conversations for an email (GDPR).
	 *
	 * @param string $email Email.
	 * @return array Rows.
	 */
	public static function by_email( $email ) {
		global $wpdb;
		$table = Tables::name( 'conversations' );
		$user  = get_user_by( 'email', $email );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s OR (user_id > 0 AND user_id = %d) ORDER BY id ASC LIMIT 500", $email, $user ? (int) $user->ID : -1 ), ARRAY_A );
		return array_map( array( __CLASS__, 'cast' ), (array) $rows );
	}

	/**
	 * GDPR exporter.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export_personal_data( $email, $page = 1 ) {
		unset( $page );
		$items = array();
		foreach ( self::by_email( $email ) as $conv ) {
			$lines = array();
			foreach ( self::messages( $conv['id'], 500 ) as $m ) {
				$lines[] = '[' . $m['created_at'] . '] ' . $m['role'] . ': ' . $m['content'];
			}
			$items[] = array(
				'group_id'    => 'dev3sa-chats',
				'group_label' => __( 'Shopping assistant chats', 'dev3-ai-shopping-assistant-for-woocommerce' ),
				'item_id'     => 'dev3sa-chat-' . $conv['id'],
				'data'        => array(
					array(
						'name'  => __( 'Started', 'dev3-ai-shopping-assistant-for-woocommerce' ),
						'value' => $conv['created_at'],
					),
					array(
						'name'  => __( 'Transcript', 'dev3-ai-shopping-assistant-for-woocommerce' ),
						'value' => implode( "\n", $lines ),
					),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * GDPR eraser: deletes the shopper's conversations.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase_personal_data( $email, $page = 1 ) {
		unset( $page );
		$rows = self::by_email( $email );
		foreach ( $rows as $conv ) {
			self::delete( $conv['id'] );
		}
		return array(
			'items_removed'  => count( $rows ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Casts a conversation row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function cast( array $row ) {
		foreach ( array( 'id', 'user_id', 'messages', 'ai_messages', 'carts', 'order_id', 'rating_up', 'rating_down' ) as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$row[ $key ] = (int) $row[ $key ];
			}
		}
		if ( isset( $row['revenue'] ) ) {
			$row['revenue'] = (float) $row['revenue'];
		}
		unset( $row['ip_hash'] );
		return $row;
	}

	/**
	 * Casts a message row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function cast_message( array $row ) {
		$row['id']              = (int) $row['id'];
		$row['conversation_id'] = (int) $row['conversation_id'];
		$row['rating']          = (int) $row['rating'];
		$row['products']        = '' === (string) $row['products'] ? array() : array_map( 'intval', explode( ',', (string) $row['products'] ) );
		$meta                   = json_decode( (string) $row['meta'], true );
		$row['meta']            = is_array( $meta ) ? $meta : array();
		return $row;
	}
}
