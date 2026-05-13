<?php
/**
 * Phase 3c smoke test — Inline edit for core columns (Title, Date, Author).
 * Run with:
 *   localwp-wp --site="AI Experiments" eval-file tests/smoke/phase3c-core-inline-edit.php
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

// wp_die override so wp_send_json_* doesn't kill the script.
class BACAjaxDie extends Exception {}
add_filter( 'wp_die_ajax_handler', static function () {
	return static function () { throw new BACAjaxDie(); };
} );

function ajax_call( string $action ): array {
	$_POST['action']  = $action;
	$_REQUEST = array_merge( $_REQUEST ?? [], $_POST );
	if ( ! defined( 'DOING_AJAX' ) ) { define( 'DOING_AJAX', true ); }
	ob_start();
	try { do_action( 'wp_ajax_' . $action ); } catch ( BACAjaxDie $e ) {}
	$out  = ob_get_clean();
	$json = json_decode( $out, true );
	return is_array( $json ) ? $json : [ 'raw_output' => $out ];
}

// -----------------------------------------------------------------------------
// Setup
// -----------------------------------------------------------------------------
wp_set_current_user( 1 );
$admin_id = 1;

// Find or create a second user with edit_posts to test the author switch.
$editor = get_users( [ 'role' => 'editor', 'number' => 1 ] );
if ( ! $editor ) {
	$new_id = wp_create_user( 'ck_test_editor_' . uniqid(), wp_generate_password( 16, false ), 'ck_test_editor@example.com' );
	if ( is_wp_error( $new_id ) ) {
		echo "FAIL: could not create editor user: " . $new_id->get_error_message() . "\n";
		exit( 1 );
	}
	( new WP_User( $new_id ) )->set_role( 'editor' );
	$editor_id = (int) $new_id;
} else {
	$editor_id = (int) $editor[0]->ID;
}

// Clean prior test posts.
foreach ( get_posts( [ 'post_type' => 'post', 'meta_key' => '_ck_test_3c', 'meta_value' => '1', 'numberposts' => -1, 'fields' => 'ids' ] ) as $id ) {
	wp_delete_post( $id, true );
}
$post_id = wp_insert_post( [
	'post_title'  => 'Original Title',
	'post_status' => 'publish',
	'post_type'   => 'post',
	'post_date'   => '2024-01-15 12:30:00',
	'post_author' => $admin_id,
] );
update_post_meta( $post_id, '_ck_test_3c', '1' );

// -----------------------------------------------------------------------------
// 1. Config getters
// -----------------------------------------------------------------------------
$cfg = \ColumnKit\ListScreens\EditManager::js_core_columns_config();
check( 'core config has title/date/author', isset( $cfg['title'], $cfg['date'], $cfg['author'] ) );
check( 'core config: title is text', ( $cfg['title']['input'] ?? '' ) === 'text' );
check( 'core config: date is date',  ( $cfg['date']['input']  ?? '' ) === 'date' );
check( 'core config: author is select with options', ( $cfg['author']['input'] ?? '' ) === 'select' && is_array( $cfg['author']['options'] ?? null ) );

// -----------------------------------------------------------------------------
// 2. AJAX save: title — happy path
// -----------------------------------------------------------------------------
$_POST = [
	'_ajax_nonce' => wp_create_nonce( 'ck_inline_save' ),
	'post_id'     => $post_id,
	'col_id'      => 'core_title',
	'value'       => 'New Title',
];
$resp = ajax_call( 'ck_inline_save' );
check( 'title save returns success', ! empty( $resp['success'] ), 'resp: ' . wp_json_encode( $resp ) );
check( 'post_title updated', get_post( $post_id )->post_title === 'New Title' );
check( 'response html includes row-title anchor', is_string( $resp['data']['html'] ?? '' ) && str_contains( $resp['data']['html'], 'row-title' ) );

// Empty title rejected.
$_POST = [
	'_ajax_nonce' => wp_create_nonce( 'ck_inline_save' ),
	'post_id'     => $post_id,
	'col_id'      => 'core_title',
	'value'       => '   ',
];
$resp = ajax_call( 'ck_inline_save' );
check( 'empty/whitespace title rejected', empty( $resp['success'] ) );
check( 'post_title unchanged after rejection', get_post( $post_id )->post_title === 'New Title' );

// -----------------------------------------------------------------------------
// 3. AJAX save: date
// -----------------------------------------------------------------------------
$_POST = [
	'_ajax_nonce' => wp_create_nonce( 'ck_inline_save' ),
	'post_id'     => $post_id,
	'col_id'      => 'core_date',
	'value'       => '2024-06-30',
];
$resp = ajax_call( 'ck_inline_save' );
check( 'date save returns success', ! empty( $resp['success'] ) );
$p = get_post( $post_id );
check( 'post_date YYYY-MM-DD updated', str_starts_with( $p->post_date, '2024-06-30' ) );
check( 'post_date keeps original time-of-day', str_ends_with( $p->post_date, ' 12:30:00' ), 'got: ' . $p->post_date );

// Invalid date rejected.
$_POST = [
	'_ajax_nonce' => wp_create_nonce( 'ck_inline_save' ),
	'post_id'     => $post_id,
	'col_id'      => 'core_date',
	'value'       => 'not-a-date',
];
$resp = ajax_call( 'ck_inline_save' );
check( 'invalid date rejected', empty( $resp['success'] ) );

// -----------------------------------------------------------------------------
// 4. AJAX save: author — admin user has edit_others_posts
// -----------------------------------------------------------------------------
$_POST = [
	'_ajax_nonce' => wp_create_nonce( 'ck_inline_save' ),
	'post_id'     => $post_id,
	'col_id'      => 'core_author',
	'value'       => (string) $editor_id,
];
$resp = ajax_call( 'ck_inline_save' );
check( 'author save returns success', ! empty( $resp['success'] ) );
check( 'post_author updated to editor', (int) get_post( $post_id )->post_author === $editor_id );

// Invalid author ID rejected.
$_POST = [
	'_ajax_nonce' => wp_create_nonce( 'ck_inline_save' ),
	'post_id'     => $post_id,
	'col_id'      => 'core_author',
	'value'       => '999999',
];
$resp = ajax_call( 'ck_inline_save' );
check( 'invalid author ID rejected', empty( $resp['success'] ) );

// -----------------------------------------------------------------------------
// 5. Author switch requires edit_others_posts capability
// -----------------------------------------------------------------------------
// Create a contributor and try to change the author from their session.
$contrib_id = wp_create_user( 'ck_test_contrib_' . uniqid(), wp_generate_password( 16, false ), 'ck_test_contrib@example.com' );
if ( is_wp_error( $contrib_id ) ) {
	echo "FAIL: could not create contributor: " . $contrib_id->get_error_message() . "\n";
	exit( 1 );
}
( new WP_User( $contrib_id ) )->set_role( 'contributor' );

// Give contributor a post they OWN so the edit_post cap check passes.
$contrib_post = wp_insert_post( [
	'post_title'  => 'Contributor Post',
	'post_status' => 'draft',
	'post_type'   => 'post',
	'post_author' => $contrib_id,
] );
update_post_meta( $contrib_post, '_ck_test_3c', '1' );

wp_set_current_user( $contrib_id );
$_POST = [
	'_ajax_nonce' => wp_create_nonce( 'ck_inline_save' ),
	'post_id'     => $contrib_post,
	'col_id'      => 'core_author',
	'value'       => (string) $admin_id,
];
$resp = ajax_call( 'ck_inline_save' );
check( 'contributor cannot switch author (lacks edit_others_posts)', empty( $resp['success'] ) );
check( 'contributor: post_author unchanged', (int) get_post( $contrib_post )->post_author === $contrib_id );
wp_set_current_user( $admin_id );

// -----------------------------------------------------------------------------
// 6. Recursion safety: AJAX core save doesn't trigger our bulk handler
// -----------------------------------------------------------------------------
// Set up a Price column and Bulk-edit data in $_POST — then do a core_title AJAX save.
// If the bulk handler fired, it would clobber the price meta (test that doesn't happen).
update_post_meta( $post_id, '_ck_price', '99' );
$_POST = [
	'_ajax_nonce'    => wp_create_nonce( 'ck_inline_save' ),
	'post_id'        => $post_id,
	'col_id'         => 'core_title',
	'value'          => 'Recursion Test',
	'ck_bulk'       => [ 'price' => '777' ],
	'ck_bulk_apply' => [ 'price' => '1' ],
	'_wpnonce'       => wp_create_nonce( 'bulk-posts' ),
];
$resp = ajax_call( 'ck_inline_save' );
check( 'core save during AJAX does not trigger bulk write', get_post_meta( $post_id, '_ck_price', true ) === '99' );

// -----------------------------------------------------------------------------
// 7. Unknown core field rejected
// -----------------------------------------------------------------------------
$_POST = [
	'_ajax_nonce' => wp_create_nonce( 'ck_inline_save' ),
	'post_id'     => $post_id,
	'col_id'      => 'core_password', // not a configured field
	'value'       => 'whatever',
];
$resp = ajax_call( 'ck_inline_save' );
check( 'unknown core_* field rejected', empty( $resp['success'] ) );

// -----------------------------------------------------------------------------
// 8. collect_core_data returns expected map for current query
// -----------------------------------------------------------------------------
// Force a list-table-style main query.
$GLOBALS['wp_query'] = new WP_Query( [
	'post_type'  => 'post',
	'meta_key'   => '_ck_test_3c',
	'meta_value' => '1',
	'posts_per_page' => -1,
] );
$data = \ColumnKit\Plugin::instance()->list_screen_manager()->edit_manager()->collect_core_data();
check( 'collect_core_data returns map for visible posts', isset( $data[ $post_id ]['title'] ) );
check( 'collect_core_data: title key has post title', ( $data[ $post_id ]['title'] ?? '' ) === 'Recursion Test' );
check( 'collect_core_data: date key is YYYY-MM-DD', preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $data[ $post_id ]['date'] ?? '' ) ) === 1 );

// -----------------------------------------------------------------------------
// Cleanup
// -----------------------------------------------------------------------------
$_POST = [];
foreach ( get_posts( [ 'post_type' => 'post', 'meta_key' => '_ck_test_3c', 'meta_value' => '1', 'numberposts' => -1, 'fields' => 'ids' ] ) as $id ) {
	wp_delete_post( $id, true );
}
if ( isset( $new_id ) && ! is_wp_error( $new_id ) ) {
	wp_delete_user( $new_id );
}
if ( isset( $contrib_id ) && ! is_wp_error( $contrib_id ) ) {
	wp_delete_user( $contrib_id );
}

echo "\n" . ( $pass ? "ALL PHASE 3c SMOKE TESTS PASSED" : "PHASE 3c SMOKE TESTS FAILED" ) . "\n";
exit( $pass ? 0 : 1 );
