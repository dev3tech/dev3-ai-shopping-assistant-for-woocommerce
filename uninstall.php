<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiShoppingAssistant
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3sa_settings = get_option( 'dev3sa_settings', array() );
if ( empty( $dev3sa_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'dev3sa_usage', 'dev3sa_core_cache', 'dev3sa_core_jobs', 'dev3sa_core_embeddings', 'dev3sa_conversations', 'dev3sa_messages', 'dev3sa_knowledge' ) as $dev3sa_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3sa_table}" );
}
foreach ( array( 'dev3sa_settings', 'dev3sa_license', 'dev3sa_db_version', 'dev3sa_insights', 'dev3sa_index_dirty' ) as $dev3sa_option ) {
	delete_option( $dev3sa_option );
}
delete_transient( 'dev3sa_models' );
delete_site_transient( 'dev3sa_update_info' );
wp_unschedule_hook( 'dev3sa_run_job' );
wp_unschedule_hook( 'dev3sa_daily' );
wp_unschedule_hook( 'dev3sa_index_dirty_run' );
