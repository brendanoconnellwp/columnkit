<?php
/**
 * Phase 10 smoke test — GitHub self-updater.
 * Run with:
 *   localwp-wp --site="AI Experiments" eval-file tests/smoke/phase10-github-updater.php
 *
 * Fakes the GitHub API via pre_http_request so it runs without network access or a token,
 * then exercises the real update_plugins_github.com filter path end-to-end.
 */

global $pass;
$pass = true;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $pass;
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label;
	if ( $detail !== '' ) { echo "  ($detail)"; }
	echo "\n";
	if ( ! $ok ) { $pass = false; }
}

use ColumnKit\Updater\GitHubUpdater;

delete_transient( 'ck_github_update' );

// -----------------------------------------------------------------------------
// 0. Header + filter registration
// -----------------------------------------------------------------------------
$data = get_plugin_data( WP_PLUGIN_DIR . '/columnkit/columnkit.php', false, false );
check( 'Update URI header present', ( $data['UpdateURI'] ?? '' ) === 'https://github.com/brendanoconnellwp/columnkit' );
check( 'update_plugins_github.com filter registered', has_filter( 'update_plugins_github.com' ) !== false );

// -----------------------------------------------------------------------------
// 1. Fake a newer release and run the filter as core would
// -----------------------------------------------------------------------------
$fake_api = static function ( $pre, $args, $url ) {
	if ( ! str_starts_with( $url, 'https://api.github.com/repos/brendanoconnellwp/columnkit/releases/latest' ) ) {
		return $pre;
	}
	return [
		'headers'  => [],
		'response' => [ 'code' => 200, 'message' => 'OK' ],
		'body'     => (string) wp_json_encode( [
			'tag_name' => 'v99.0.0',
			'html_url' => 'https://github.com/brendanoconnellwp/columnkit/releases/tag/v99.0.0',
			'assets'   => [
				[ 'id' => 7, 'name' => 'columnkit-99.0.0.zip', 'browser_download_url' => 'https://github.com/brendanoconnellwp/columnkit/releases/download/v99.0.0/columnkit-99.0.0.zip' ],
			],
		] ),
	];
};
add_filter( 'pre_http_request', $fake_api, 10, 3 );

$update = apply_filters( 'update_plugins_github.com', false, [ 'Version' => CK_VERSION ], CK_BASENAME, [] );
check( 'newer release produces an update offer', is_array( $update ) );
check( 'offer has correct version', is_array( $update ) && ( $update['version'] ?? '' ) === '99.0.0' );
check( 'offer targets our basename', is_array( $update ) && ( $update['plugin'] ?? '' ) === CK_BASENAME );
check( 'offer has a package zip', is_array( $update ) && str_ends_with( (string) ( $update['package'] ?? '' ), '.zip' ) );

// Foreign plugin on the same host is never answered.
$foreign = apply_filters( 'update_plugins_github.com', false, [ 'Version' => '1.0' ], 'other/other.php', [] );
check( 'foreign plugin passthrough', $foreign === false );

// Cached — a second run must not re-fetch (fake removed, still answers from transient).
remove_filter( 'pre_http_request', $fake_api, 10 );
$cached = apply_filters( 'update_plugins_github.com', false, [ 'Version' => CK_VERSION ], CK_BASENAME, [] );
check( 'release cached in transient', is_array( $cached ) && ( $cached['version'] ?? '' ) === '99.0.0' );

// -----------------------------------------------------------------------------
// 2. Same-version release → no offer
// -----------------------------------------------------------------------------
delete_transient( 'ck_github_update' );
$fake_same = static function ( $pre, $args, $url ) {
	if ( ! str_starts_with( $url, 'https://api.github.com/repos/brendanoconnellwp/columnkit/releases/latest' ) ) {
		return $pre;
	}
	return [
		'headers'  => [],
		'response' => [ 'code' => 200, 'message' => 'OK' ],
		'body'     => (string) wp_json_encode( [ 'tag_name' => 'v' . CK_VERSION, 'html_url' => '', 'assets' => [] ] ),
	];
};
add_filter( 'pre_http_request', $fake_same, 10, 3 );
$none = apply_filters( 'update_plugins_github.com', false, [ 'Version' => CK_VERSION ], CK_BASENAME, [] );
check( 'same version yields no offer', $none === false );
remove_filter( 'pre_http_request', $fake_same, 10 );

// -----------------------------------------------------------------------------
// 3. API failure → no offer, failure cached
// -----------------------------------------------------------------------------
delete_transient( 'ck_github_update' );
$fake_fail = static fn( $pre, $args, $url ) => str_starts_with( $url, 'https://api.github.com/repos/brendanoconnellwp/columnkit/' )
	? [ 'headers' => [], 'response' => [ 'code' => 403, 'message' => 'rate limited' ], 'body' => '{}' ]
	: $pre;
add_filter( 'pre_http_request', $fake_fail, 10, 3 );
$fail = apply_filters( 'update_plugins_github.com', false, [ 'Version' => '0.0.1' ], CK_BASENAME, [] );
check( 'API failure yields no offer', $fail === false );
$t = get_transient( 'ck_github_update' );
check( 'failure is cached (backoff)', is_array( $t ) && ! empty( $t['ck_error'] ) );
remove_filter( 'pre_http_request', $fake_fail, 10 );

// Cleanup.
delete_transient( 'ck_github_update' );

echo "\n" . ( $pass ? 'ALL PASS' : 'SOME FAILED' ) . "\n";
