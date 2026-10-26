<?php
/**
 * Remove all plugin data (per site on multisite): options and the order meta this plugin wrote.
 *
 * @package CodRisk
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean one site.
 */
function codrisk_uninstall_site(): void {
	global $wpdb;
	delete_option( 'codrisk_threshold' );
	delete_option( 'codrisk_blocklist' );

	$keys = array( '_codrisk_score', '_codrisk_reasons', '_codrisk_phone', '_codrisk_ip', '_codrisk_addr' );
	foreach ( $keys as $key ) {
		delete_post_meta_by_key( $key ); // Legacy order storage.
	}
	// High-Performance Order Storage keeps order meta in its own table.
	$table = $wpdb->prefix . 'wc_orders_meta';
	if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $keys as $key ) {
			$wpdb->delete( $table, array( 'meta_key' => $key ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a column name in our own DELETE, not a meta query.
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $codrisk_blog_id ) {
		switch_to_blog( (int) $codrisk_blog_id );
		codrisk_uninstall_site();
		restore_current_blog();
	}
} else {
	codrisk_uninstall_site();
}
