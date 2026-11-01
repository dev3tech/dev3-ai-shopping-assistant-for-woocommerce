<?php
/**
 * Plugin tables (conversations, messages, knowledge base), added to the core dbDelta run.
 *
 * @package Dev3\AiShoppingAssistant
 */

namespace Dev3\AiShoppingAssistant\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Table names + schema.
 */
class Tables {

	/**
	 * Table name.
	 *
	 * @param string $name conversations|messages|knowledge.
	 * @return string
	 */
	public static function name( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'dev3sa_' . $name;
	}

	/**
	 * Adds CREATE TABLE statements (dev3sa_schema_sql).
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset collate.
	 * @return array
	 */
	public static function schema( $sql, $charset ) {
		$conv  = self::name( 'conversations' );
		$msg   = self::name( 'messages' );
		$kb    = self::name( 'knowledge' );
		$sql[] = "CREATE TABLE {$conv} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token char(32) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(190) NOT NULL DEFAULT '',
			name varchar(120) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'open',
			source_url varchar(255) NOT NULL DEFAULT '',
			lang varchar(12) NOT NULL DEFAULT '',
			messages int(10) unsigned NOT NULL DEFAULT 0,
			ai_messages int(10) unsigned NOT NULL DEFAULT 0,
			carts int(10) unsigned NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			revenue decimal(19,4) NOT NULL DEFAULT 0,
			rating_up int(10) unsigned NOT NULL DEFAULT 0,
			rating_down int(10) unsigned NOT NULL DEFAULT 0,
			summary text NOT NULL,
			sentiment varchar(20) NOT NULL DEFAULT '',
			ip_hash char(40) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY status_updated (status,updated_at),
			KEY created (created_at),
			KEY email (email)
		) {$charset};";
		$sql[] = "CREATE TABLE {$msg} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			role varchar(10) NOT NULL DEFAULT 'user',
			content text NOT NULL,
			intent varchar(30) NOT NULL DEFAULT '',
			source varchar(20) NOT NULL DEFAULT '',
			products varchar(255) NOT NULL DEFAULT '',
			meta longtext NOT NULL,
			rating tinyint(4) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY conversation (conversation_id,id),
			KEY created (created_at),
			KEY intent (intent)
		) {$charset};";
		$sql[] = "CREATE TABLE {$kb} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(200) NOT NULL DEFAULT '',
			content text NOT NULL,
			type varchar(20) NOT NULL DEFAULT 'faq',
			keywords varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'publish',
			source varchar(20) NOT NULL DEFAULT 'manual',
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_type (status,type)
		) {$charset};";
		return $sql;
	}

	/**
	 * Drops plugin tables (uninstall).
	 */
	public static function drop() {
		global $wpdb;
		foreach ( array( 'conversations', 'messages', 'knowledge' ) as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::name( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
	}
}
