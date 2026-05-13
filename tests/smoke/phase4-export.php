<?php
/**
 * Phase 4 smoke test — Data export (CSV/JSON) + Settings import/export.
 * Run with:
 *   localwp-wp --site="AI Experiments" eval-file tests/smoke/phase4-export.php
 *
 * Test-mode opt-out: with CK_TEST_MODE defined, DataExporter + SettingsExporter skip the
 * buffer-flush + exit so PHPUnit/wp-cli can capture the streamed output.
 */

if ( ! defined( 'CK_TEST_MODE' ) ) { define( 'CK_TEST_MODE', true ); }

global $pass;
$pass = true;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $pass;
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label;
	if ( $detail !== '' ) { echo "  ($detail)"; }
	echo "\n";
	if ( ! $ok ) { $pass = false; }
}

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------
wp_set_current_user( 1 );

foreach ( get_posts( [ 'post_type' => 'post', 'meta_key' => '_ck_test_phase4', 'meta_value' => '1', 'numberposts' => -1, 'fields' => 'ids' ] ) as $id ) {
	wp_delete_post( $id, true );
}

$post_a = wp_insert_post( [ 'post_title' => 'Alpha',   'post_status' => 'publish', 'post_type' => 'post' ] );
$post_b = wp_insert_post( [ 'post_title' => 'Bravo',   'post_status' => 'publish', 'post_type' => 'post' ] );
$post_c = wp_insert_post( [ 'post_title' => 'Charlie', 'post_status' => 'publish', 'post_type' => 'post' ] );
foreach ( [ $post_a, $post_b, $post_c ] as $i => $pid ) {
	update_post_meta( $pid, '_ck_test_phase4', '1' );
	update_post_meta( $pid, '_ck_price', (string) ( ( $i + 1 ) * 10 ) );
}
// One post with a formula-injection meta value to test CSV escaping.
update_post_meta( $post_a, '_ck_evil', '=SUM(A1:A9)' );
update_post_meta( $post_b, '_ck_evil', 'safe value' );

$columns = [
	[ 'id' => 'price', 'type' => 'post_meta', 'label' => 'Price',
	  'settings' => [ 'meta_key' => '_ck_price', 'value_type' => 'numeric' ], 'width' => '' ],
	[ 'id' => 'evil', 'type' => 'post_meta', 'label' => 'Formula',
	  'settings' => [ 'meta_key' => '_ck_evil', 'value_type' => 'string' ], 'width' => '' ],
];
\ColumnKit\Plugin::instance()->repository()->save( 'post_type:post', $columns );

// -----------------------------------------------------------------------------
// 1. CSV export — happy path
// -----------------------------------------------------------------------------
$_GET = [
	'action'    => 'ck_export',
	'_wpnonce'  => wp_create_nonce( 'ck_export' ),
	'post_type' => 'post',
	'format'    => 'csv',
];
$_REQUEST = array_merge( $_REQUEST ?? [], $_GET );

ob_start();
do_action( 'admin_post_ck_export' );
$csv = ob_get_clean();

check( 'CSV starts with UTF-8 BOM', str_starts_with( $csv, "\xEF\xBB\xBF" ), 'first bytes: ' . bin2hex( substr( $csv, 0, 3 ) ) );
$body  = substr( $csv, 3 );
$lines = preg_split( '/\R/', trim( $body ) );
check( 'CSV has header + at least 3 data rows (export includes all published posts)', count( $lines ) >= 4, 'lines: ' . count( $lines ) );
check( 'CSV header is exactly the column labels', $lines[0] === 'ID,Price,Formula', 'got: ' . $lines[0] );

// Find rows for our three fixture posts.
$ids_in_csv = [];
foreach ( array_slice( $lines, 1 ) as $line ) {
	if ( preg_match( '/^(\d+),/', $line, $m ) ) {
		$ids_in_csv[] = (int) $m[1];
	}
}
$missing = array_diff( [ $post_a, $post_b, $post_c ], $ids_in_csv );
check( 'CSV contains all 3 fixture post IDs', $missing === [], 'missing: ' . implode( ',', $missing ) );

$found_escape = false;
foreach ( array_slice( $lines, 1 ) as $line ) {
	if ( strpos( $line, "'=SUM(A1:A9)" ) !== false ) {
		$found_escape = true;
		break;
	}
}
check( 'CSV formula-injection value is escaped (prefix with single quote)', $found_escape, 'lines: ' . wp_json_encode( $lines ) );

// -----------------------------------------------------------------------------
// 2. JSON export
// -----------------------------------------------------------------------------
$_GET['format']   = 'json';
$_GET['_wpnonce'] = wp_create_nonce( 'ck_export' );
$_REQUEST = array_merge( $_REQUEST ?? [], $_GET );

ob_start();
do_action( 'admin_post_ck_export' );
$json_out = ob_get_clean();
$decoded  = json_decode( $json_out, true );
check( 'JSON export parses as array of objects (at least 3)', is_array( $decoded ) && count( $decoded ) >= 3, 'count: ' . ( is_array( $decoded ) ? count( $decoded ) : 'invalid' ) );
$first = is_array( $decoded ) ? $decoded[0] : [];
check( 'JSON row has ID, price, evil keys', isset( $first['ID'], $first['price'], $first['evil'] ) );
check( 'JSON row values: ID is numeric', is_int( $first['ID'] ) );

$fixture_in_json = array_filter( $decoded, static fn( $r ) => in_array( (int) ( $r['ID'] ?? 0 ), [ $post_a, $post_b, $post_c ], true ) );
check( 'JSON export contains all 3 fixture posts', count( $fixture_in_json ) === 3 );

// -----------------------------------------------------------------------------
// 3. Filter-aware export — apply a price range filter, expect fewer rows
// -----------------------------------------------------------------------------
$_GET = [
	'action'           => 'ck_export',
	'_wpnonce'         => wp_create_nonce( 'ck_export' ),
	'post_type'        => 'post',
	'format'           => 'json',
	'ck_f_price__min' => '15',
	'ck_f_price__max' => '25',
];
$_REQUEST = array_merge( $_REQUEST ?? [], $_GET );

ob_start();
do_action( 'admin_post_ck_export' );
$filtered_json = ob_get_clean();
$filtered      = json_decode( $filtered_json, true );
check(
	'filter-aware export: range [15..25] returns 1 post (Bravo)',
	is_array( $filtered ) && count( $filtered ) === 1 && (int) $filtered[0]['ID'] === (int) $post_b,
	'count: ' . ( is_array( $filtered ) ? count( $filtered ) : 'invalid' )
);

// -----------------------------------------------------------------------------
// 4. Settings export — JSON download
// -----------------------------------------------------------------------------
$_GET = [
	'action'   => 'ck_settings_export',
	'_wpnonce' => wp_create_nonce( 'ck_settings_export' ),
];
$_REQUEST = array_merge( $_REQUEST ?? [], $_GET );

ob_start();
do_action( 'admin_post_ck_settings_export' );
$settings_json = ob_get_clean();
$exported      = json_decode( $settings_json, true );

check( 'settings export returns valid JSON', is_array( $exported ) );
check( 'settings export has schema_version=1', ( $exported['schema_version'] ?? null ) === 1 );
check( 'settings export has post_type:post screen', isset( $exported['screens']['post_type:post'] ) );
check(
	'settings export preserves 2 columns',
	isset( $exported['screens']['post_type:post']['columns'] )
	&& count( $exported['screens']['post_type:post']['columns'] ) === 2
);

// -----------------------------------------------------------------------------
// 5. Settings import — round-trip via public method
// -----------------------------------------------------------------------------
delete_option( 'ck_screen_post_type_post' );
\ColumnKit\Settings\SettingsRepository::reset_cache();
check( 'settings deleted before import', \ColumnKit\Plugin::instance()->repository()->get_columns( 'post_type:post' ) === [] );

$exporter = new \ColumnKit\Admin\SettingsExporter(
	\ColumnKit\Plugin::instance()->registry(),
	\ColumnKit\Plugin::instance()->repository()
);
$imported = $exporter->import_from_json( $settings_json );
check( 'import returned 1 screen imported', $imported === 1 );

$restored = \ColumnKit\Plugin::instance()->repository()->get_columns( 'post_type:post' );
check( 'after import: 2 columns restored', count( $restored ) === 2 );
check( 'after import: price column intact',
	( $restored[0]['type'] ?? '' ) === 'post_meta'
	&& ( $restored[0]['settings']['meta_key'] ?? '' ) === '_ck_price'
);

// Junk JSON returns -1
check( 'invalid JSON structure returns -1', $exporter->import_from_json( '{"foo":"bar"}' ) === -1 );
check( 'non-JSON returns -1', $exporter->import_from_json( 'not json at all' ) === -1 );

// Screen-key allowlist: attempt to write to "evil_option" → rejected.
$evil = wp_json_encode( [
	'schema_version' => 1,
	'screens' => [
		'evil:rm-rf' => [ 'columns' => [ [ 'id' => 'x', 'type' => 'post_id', 'label' => 'X' ] ] ],
		'post_type:page' => [ 'columns' => [ [ 'id' => 'y', 'type' => 'post_id', 'label' => 'Y' ] ] ],
	],
] );
$got = $exporter->import_from_json( $evil );
check( 'import skips non-post_type screen keys', $got === 1, 'imported: ' . $got );
check( 'evil screen key NOT written to DB', get_option( 'ck_screen_evil_rm_rf', '__none__' ) === '__none__' );

// -----------------------------------------------------------------------------
// 6. Column type whitelist on import
// -----------------------------------------------------------------------------
$with_unknown = wp_json_encode( [
	'schema_version' => 1,
	'screens' => [
		'post_type:post' => [
			'columns' => [
				[ 'id' => 'good', 'type' => 'post_id', 'label' => 'OK' ],
				[ 'id' => 'bad',  'type' => 'arbitrary_attacker_type', 'label' => 'EVIL' ],
			],
		],
	],
] );
$exporter->import_from_json( $with_unknown );
$post_cols = \ColumnKit\Plugin::instance()->repository()->get_columns( 'post_type:post' );
check( 'import drops unknown column types', count( $post_cols ) === 1, 'cols: ' . wp_json_encode( $post_cols ) );

// -----------------------------------------------------------------------------
// Cleanup
// -----------------------------------------------------------------------------
$_GET = $_POST = []; $_FILES = [];
delete_option( 'ck_screen_post_type_page' );
// Restore the original column config so other tests don't break.
update_option( 'ck_screen_post_type_post', [ 'schema_version' => 1, 'columns' => $columns ], false );

foreach ( get_posts( [ 'post_type' => 'post', 'meta_key' => '_ck_test_phase4', 'meta_value' => '1', 'numberposts' => -1, 'fields' => 'ids' ] ) as $id ) {
	wp_delete_post( $id, true );
}

echo "\n" . ( $pass ? "ALL PHASE 4 SMOKE TESTS PASSED" : "PHASE 4 SMOKE TESTS FAILED" ) . "\n";
exit( $pass ? 0 : 1 );
