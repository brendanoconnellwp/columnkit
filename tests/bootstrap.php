<?php
declare( strict_types=1 );

$autoload = __DIR__ . '/../vendor/autoload.php';
if ( ! is_file( $autoload ) ) {
	fwrite( STDERR, "Run `composer install` first.\n" );
	exit( 1 );
}
require $autoload;

// Make plugin constants available to source files that reference them.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'CK_VERSION' ) ) {
	define( 'CK_VERSION', '0.1.0-test' );
}
if ( ! defined( 'CK_FILE' ) ) {
	define( 'CK_FILE', __DIR__ . '/../columnkit.php' );
}
if ( ! defined( 'CK_DIR' ) ) {
	define( 'CK_DIR', __DIR__ . '/../' );
}
if ( ! defined( 'CK_URL' ) ) {
	define( 'CK_URL', 'http://example.test/wp-content/plugins/columnkit/' );
}
if ( ! defined( 'CK_BASENAME' ) ) {
	define( 'CK_BASENAME', 'columnkit/columnkit.php' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 ); // WP core time constant, used by GitHubUpdater.
}

// Manual PSR-4 autoloader matching the bootstrap, so tests don't need composer dumpautoload.
spl_autoload_register( static function ( string $class ): void {
	foreach ( [
		'ColumnKit\\Tests\\' => __DIR__ . '/',
		'ColumnKit\\'        => __DIR__ . '/../src/',
	] as $prefix => $base ) {
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) === 0 ) {
			$rel  = substr( $class, strlen( $prefix ) );
			$path = $base . str_replace( '\\', '/', $rel ) . '.php';
			if ( is_file( $path ) ) {
				require_once $path;
			}
			return;
		}
	}
} );

// Minimal global WP_Post stand-in for unit tests that exercise code type-hinting/branching on
// it (e.g. EditManager::collect_core_data). Real WP isn't loaded in the unit suite.
if ( ! class_exists( 'WP_Post' ) ) {
	#[\AllowDynamicProperties]
	class WP_Post {
		public int $ID = 0;
		public string $post_title = '';
		public string $post_date = '';
		public string $post_author = '';
	}
}
