<?php
/**
 * Uninstall — remove all plugin options.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ColumnKit stores exactly two option shapes: ck_version and ck_screen_<key>. Match those
// precisely — a broad 'ck_%' sweep could destroy options belonging to OTHER plugins that
// happen to share the ck_ prefix.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = 'ck_version' OR option_name LIKE 'ck\\_screen\\_%'" );

if ( is_multisite() ) {
	$wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key = 'ck_version' OR meta_key LIKE 'ck\\_screen\\_%'" );
}
