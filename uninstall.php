<?php
/**
 * Uninstall — remove all plugin options.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bac\\_%'" );

if ( is_multisite() ) {
	$wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE 'bac\\_%'" );
}
