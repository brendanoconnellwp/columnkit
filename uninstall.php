<?php
/**
 * Uninstall — remove all plugin options.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ColumnKit stores everything under the ck_ prefix (ck_version, ck_screen_*). The previous
// 'bac_%' pattern matched none of it, leaving every per-screen column configuration behind
// after uninstall.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ck\\_%'" );

if ( is_multisite() ) {
	$wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE 'ck\\_%'" );
}
