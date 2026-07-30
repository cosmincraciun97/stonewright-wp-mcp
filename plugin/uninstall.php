<?php
/**
 * Stonewright uninstall handler.
 *
 * Removes plugin-owned options, custom tables, and transients.
 *
 * @package Stonewright\WpMcp
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$options = [
	'stonewright_mode',
	'stonewright_feature_flags',
	'stonewright_settings',
	'stonewright_version',
];

foreach ( $options as $option ) {
	delete_option( $option );
	delete_site_option( $option );
}

$tables = [
	$wpdb->prefix . 'stonewright_audit_log',
	$wpdb->prefix . 'stonewright_memory',
];

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS " . esc_sql( $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

wp_cache_flush();
