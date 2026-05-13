<?php
/**
 * Phase 3 smoke test — Click-to-edit popover (AJAX) + Bulk Edit (WP's native panel).
 * Run with:
 *   localwp-wp --site="AI Experiments" eval-file tests/smoke/phase3-inline-bulk-edit.php
 */

global $pass;
$pass = true;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $pass;
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label;
	if ( $detail !== '' ) {
		echo "  ($detail)";
	}
	echo "\n";
	if ( ! $ok ) {
		$pass = false;
	}
}

// Capture wp_send_json_* output without the script exiting.
// wp_send_json echoes JSON to STDOUT then calls wp_die — we replace wp_die's ajax handler with
// one that throws, so we can ob_get_clean the JSON afterwards.
class BACAjaxDie extends Exception {}
add_filter( 'wp_die_ajax_handler', static function () {
	return static function ( $message = '', $title = '', $args = [] ) {
		throw new BACAjaxDie();
	};
} );

function ajax_call( string $action ): array {
	$_POST['action'] = $action;
	// check_ajax_referer + most WP nonce helpers read $_REQUEST, which PHP normally merges from
	// $_POST/$_GET at request start. In wp-cli that auto-merge hasn't happened, so we mirror.
	$_REQUEST = array_merge( $_REQUEST ?? [], $_POST );
	if ( ! defined( 'DOING_AJAX' ) ) {
		define( 'DOING_AJAX', true );
	}
	ob_start();
	try {
		do_action( 'wp_ajax_' . $action );
	} catch ( BACAjaxDie $e ) {
		// expected
	}
	$out  = ob_get_clean();
	$json = json_decode( $out, true );
	return is_array( $json ) ? $json : [ 'raw_output' => $out ];
}

// -----------------------------------------------------------------------------
// 1. Setup
// -----------------------------------------------------------------------------
wp_set_current_user( 1 );
check( 'admin user has edit_posts cap', current_user_can( 'edit_posts' ) );

$prior = get_posts( [
	'post_type'  => 'post',
	'meta_key'   => '_ck_test_phase3',
	'meta_value' => '1',
	'numberposts'=> -1,
	'fields'     => 'ids',
] );
foreach ( $prior as $id ) { wp_delete_post( $id, true ); }

$post_a = wp_insert_post( [ 'post_title' => 'Phase3 Post A', 'post_status' => 'publish', 'post_type' => 'post' ] );
$post_b = wp_insert_post( [ 'post_title' => 'Phase3 Post B', 'post_status' => 'publish', 'post_type' => 'post' ] );
update_post_meta( $post_a, '_ck_test_phase3', '1' );
update_post_meta( $post_b, '_ck_test_phase3', '1' );
update_post_meta( $post_a, '_ck_price', '10' );
update_post_meta( $post_b, '_ck_price', '20' );

$columns = [
	[ 'id' => 'price', 'type' => 'post_meta', 'label' => 'Price',
	  'settings' => [ 'meta_key' => '_ck_price', 'value_type' => 'numeric' ], 'width' => '' ],
	[ 'id' => 'tag',   'type' => 'post_meta', 'label' => 'Tag',
	  'settings' => [ 'meta_key' => '_ck_tag', 'value_type' => 'string' ], 'width' => '' ],
];
update_option( 'ck_screen_post_type_post', [ 'schema_version' => 1, 'columns' => $columns ], false );
\ColumnKit\Settings\SettingsRepository::reset_cache();
set_current_screen( 'edit-post' );

// -----------------------------------------------------------------------------
// 2. Cell wrap now includes ck-editable + data-ck-input
// -----------------------------------------------------------------------------
ob_start();
do_action( 'manage_post_posts_custom_column', 'ck_price', $post_a );
$cell_html = ob_get_clean();
check(
	'cell carries .ck-cell.ck-editable',
	str_contains( $cell_html, 'class="ck-cell ck-editable"' ),
	'html: ' . $cell_html
);
check(
	'cell carries data-ck-input for the input type',
	str_contains( $cell_html, 'data-ck-input="number"' )
);
check(
	'cell carries data-ck-raw with the raw value',
	str_contains( $cell_html, 'data-ck-raw="10"' )
);

// String column → data-ck-input="text"
ob_start();
update_post_meta( $post_a, '_ck_tag', 'before' );
do_action( 'manage_post_posts_custom_column', 'ck_tag', $post_a );
$tag_cell = ob_get_clean();
check( 'string column gets data-ck-input="text"', str_contains( $tag_cell, 'data-ck-input="text"' ) );

// -----------------------------------------------------------------------------
// 3. AJAX save — happy path
// -----------------------------------------------------------------------------
$_POST = [
	'_ajax_nonce' => wp_create_nonce( 'ck_inline_save' ),
	'post_id'     => $post_a,
	'col_id'      => 'price',
	'value'       => '99',
];
$resp = ajax_call( 'ck_inline_save' );
check( 'AJAX returns success', ! empty( $resp['success'] ), 'resp: ' . wp_json_encode( $resp ) );
check( 'AJAX updates meta to 99', get_post_meta( $post_a, '_ck_price', true ) === '99' );
check( 'AJAX response includes re-rendered html', isset( $resp['data']['html'] ) && str_contains( (string) $resp['data']['html'], '99' ) );
check( 'AJAX response includes new raw value', isset( $resp['data']['raw'] ) && $resp['data']['raw'] === '99' );

// -----------------------------------------------------------------------------
// 4. AJAX save — invalid nonce → 403
// -----------------------------------------------------------------------------
update_post_meta( $post_a, '_ck_price', '10' );
$_POST = [
	'_ajax_nonce' => 'bogus',
	'post_id'     => $post_a,
	'col_id'      => 'price',
	'value'       => '77',
];
$resp = ajax_call( 'ck_inline_save' );
check( 'invalid nonce returns success=false', empty( $resp['success'] ) );
check( 'invalid nonce: meta unchanged', get_post_meta( $post_a, '_ck_price', true ) === '10' );

// -----------------------------------------------------------------------------
// 5. AJAX save — anonymous user → permission denied
// -----------------------------------------------------------------------------
wp_set_current_user( 0 );
$_POST = [
	'_ajax_nonce' => wp_create_nonce( 'ck_inline_save' ),
	'post_id'     => $post_a,
	'col_id'      => 'price',
	'value'       => '88',
];
$resp = ajax_call( 'ck_inline_save' );
check( 'anonymous user is rejected', empty( $resp['success'] ) );
check( 'anonymous user: meta unchanged', get_post_meta( $post_a, '_ck_price', true ) === '10' );
wp_set_current_user( 1 );

// -----------------------------------------------------------------------------
// 6. AJAX save — non-numeric input on numeric column → save_value rejects
// -----------------------------------------------------------------------------
update_post_meta( $post_a, '_ck_price', '10' );
$_POST = [
	'_ajax_nonce' => wp_create_nonce( 'ck_inline_save' ),
	'post_id'     => $post_a,
	'col_id'      => 'price',
	'value'       => 'banana',
];
$resp = ajax_call( 'ck_inline_save' );
check( 'non-numeric input does not change meta', get_post_meta( $post_a, '_ck_price', true ) === '10' );
// (Server returns success because save_value is a no-op write-rejection; this is acceptable v1
//  behaviour — client could check resp.data.raw if it wants to detect.)

// -----------------------------------------------------------------------------
// 7. AJAX save — XSS payload is escaped in returned html
// -----------------------------------------------------------------------------
$_POST = [
	'_ajax_nonce' => wp_create_nonce( 'ck_inline_save' ),
	'post_id'     => $post_a,
	'col_id'      => 'tag',
	'value'       => '"><script>alert(1)</script>',
];
$resp = ajax_call( 'ck_inline_save' );
check( 'XSS payload save returns success', ! empty( $resp['success'] ) );
$html     = $resp['data']['html'] ?? '';
$raw_back = $resp['data']['raw'] ?? '';
// sanitize_text_field strips <script>/<style> content + remaining tags at the EditManager
// boundary, so the saved value is just '">'. (Even better security than naive XSS-on-render.)
check(
	'sanitize_text_field neutralises XSS at save time',
	$raw_back === '">',
	'raw: ' . var_export( $raw_back, true )
);
check(
	'returned html escapes the surviving characters',
	is_string( $html ) && ! str_contains( $html, '<script>' ) && str_contains( $html, '&quot;&gt;' ),
	'html: ' . substr( $html, 0, 120 )
);
// Verify it actually stored on the post.
check(
	'stored meta value matches the sanitised value',
	get_post_meta( $post_a, '_ck_tag', true ) === '">'
);

// -----------------------------------------------------------------------------
// 8. AJAX save — unknown col_id is rejected
// -----------------------------------------------------------------------------
$_POST = [
	'_ajax_nonce' => wp_create_nonce( 'ck_inline_save' ),
	'post_id'     => $post_a,
	'col_id'      => 'nonexistent',
	'value'       => 'x',
];
$resp = ajax_call( 'ck_inline_save' );
check( 'unknown col_id rejected', empty( $resp['success'] ) );

// -----------------------------------------------------------------------------
// 9. Bulk edit still works — only ticked apply checkboxes write
// -----------------------------------------------------------------------------
$_POST = [];
update_post_meta( $post_a, '_ck_price', '10' );
update_post_meta( $post_a, '_ck_tag',   'before' );
update_post_meta( $post_b, '_ck_price', '20' );
update_post_meta( $post_b, '_ck_tag',   'before' );

$_POST = [
	'_wpnonce'        => wp_create_nonce( 'bulk-posts' ),
	'ck_bulk'        => [ 'price' => '555', 'tag' => 'after' ],
	'ck_bulk_apply'  => [ 'price' => '1' ],
];
do_action( 'save_post', $post_a, get_post( $post_a ), true );
do_action( 'save_post', $post_b, get_post( $post_b ), true );
check( 'bulk: ticked column updates A', get_post_meta( $post_a, '_ck_price', true ) === '555' );
check( 'bulk: ticked column updates B', get_post_meta( $post_b, '_ck_price', true ) === '555' );
check( 'bulk: un-ticked column unchanged A', get_post_meta( $post_a, '_ck_tag', true ) === 'before' );
check( 'bulk: un-ticked column unchanged B', get_post_meta( $post_b, '_ck_tag', true ) === 'before' );

// Bulk with invalid nonce
update_post_meta( $post_a, '_ck_price', '10' );
$_POST = [
	'_wpnonce'       => 'bogus',
	'ck_bulk'       => [ 'price' => '111' ],
	'ck_bulk_apply' => [ 'price' => '1' ],
];
do_action( 'save_post', $post_a, get_post( $post_a ), true );
check( 'bulk: invalid nonce blocks save', get_post_meta( $post_a, '_ck_price', true ) === '10' );

// quick_edit_custom_box should NOT produce any of our fieldsets anymore (we removed that hook).
ob_start();
do_action( 'quick_edit_custom_box', 'ck_price', 'post' );
$qe = ob_get_clean();
check(
	'quick_edit_custom_box no longer renders our fieldset (replaced by click-to-edit popover)',
	! str_contains( $qe, 'ck-quick-edit' )
);

// -----------------------------------------------------------------------------
// Cleanup
// -----------------------------------------------------------------------------
$_POST = [];
$cleanup = get_posts( [
	'post_type'  => 'post',
	'meta_key'   => '_ck_test_phase3',
	'meta_value' => '1',
	'numberposts'=> -1,
	'fields'     => 'ids',
] );
foreach ( $cleanup as $id ) { wp_delete_post( $id, true ); }

echo "\n" . ( $pass ? "ALL PHASE 3 SMOKE TESTS PASSED" : "PHASE 3 SMOKE TESTS FAILED" ) . "\n";
exit( $pass ? 0 : 1 );
