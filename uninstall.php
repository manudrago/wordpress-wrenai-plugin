<?php
/**
 * Uninstall: remove the plugin's own data.
 *
 * Saved dashboards are left alone unless the site owner asked for a full
 * cleanup by defining WWD_DELETE_DATA.
 *
 * @package WP_Wren_Dashboards
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'wwd_settings' );

$wpdb->query( // phpcs:ignore WordPress.DB
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wwd_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wwd_' ) . '%',
		$wpdb->esc_like( '_site_transient_wwd_' ) . '%',
		$wpdb->esc_like( '_site_transient_timeout_wwd_' ) . '%'
	)
);

if ( defined( 'WWD_DELETE_DATA' ) && WWD_DELETE_DATA ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wwd_query_log" ); // phpcs:ignore WordPress.DB

	$dashboards = get_posts(
		array(
			'post_type'      => 'wwd_dashboard',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);

	foreach ( $dashboards as $dashboard_id ) {
		wp_delete_post( $dashboard_id, true );
	}
}
