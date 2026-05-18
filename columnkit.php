<?php
/**
 * Plugin Name:       ColumnKit
 * Description:       Customise WordPress admin list tables — add, remove, reorder columns; filter, sort, inline edit, bulk edit, export.
 * Version:           0.5.3
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Brendan
 * License:           GPL-2.0-or-later
 * Text Domain:       columnkit
 * Domain Path:       /languages
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CK_VERSION', '0.5.3' );
define( 'CK_FILE', __FILE__ );
define( 'CK_DIR', plugin_dir_path( __FILE__ ) );
define( 'CK_URL', plugin_dir_url( __FILE__ ) );
define( 'CK_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'ColumnKit\\';
	if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$path     = CK_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $path ) ) {
		require_once $path;
	}
} );

register_activation_hook( __FILE__, static function (): void {
	add_option( 'ck_version', CK_VERSION, '', 'no' );
} );

add_action( 'plugins_loaded', static function (): void {
	load_plugin_textdomain(
		'columnkit',
		false,
		dirname( CK_BASENAME ) . '/languages'
	);
	\ColumnKit\Plugin::instance()->boot();
} );
