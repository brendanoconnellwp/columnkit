<?php
/**
 * Phase 9 smoke test — Users + Taxonomies parity (sort, inline-edit, export).
 * Run with:
 *   localwp-wp --site="AI Experiments" eval-file tests/smoke/phase9-users-terms-parity.php
 *
 * Test-mode: define CK_TEST_MODE so the exporters return-instead-of-exit.
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

wp_set_current_user( 1 );

use ColumnKit\Columns\MetaSortable;
use ColumnKit\Columns\EditableColumn;

$plugin   = \ColumnKit\Plugin::instance();
$registry = $plugin->registry();
$repo     = $plugin->repository();

// -----------------------------------------------------------------------------
// 1. Column capabilities
// -----------------------------------------------------------------------------
$user_meta = $registry->get( 'user_meta' );
$term_meta = $registry->get( 'term_meta' );
check( 'user_meta is MetaSortable + Editable', $user_meta instanceof MetaSortable && $user_meta instanceof EditableColumn );
check( 'term_meta is MetaSortable + Editable', $term_meta instanceof MetaSortable && $term_meta instanceof EditableColumn );
check( 'user_meta sort key from settings', $user_meta->sort_meta_key( [ 'meta_key' => 'ck_rank' ] ) === 'ck_rank' );

// -----------------------------------------------------------------------------
// 2. Inline edit round-trip on a real user
// -----------------------------------------------------------------------------
$uid = wp_insert_user( [ 'user_login' => 'ck_phase9_' . wp_rand( 1000, 9999 ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
if ( is_wp_error( $uid ) ) {
	check( 'created test user', false, $uid->get_error_message() );
} else {
	$user_meta->save_value( $uid, 'gold', [ 'meta_key' => 'ck_badge' ] );
	check( 'user meta saved via column', get_user_meta( $uid, 'ck_badge', true ) === 'gold' );
	check( 'user raw value reads back', $user_meta->get_raw_value( $uid, [ 'meta_key' => 'ck_badge' ] ) === 'gold' );
	$user_meta->save_value( $uid, '', [ 'meta_key' => 'ck_badge' ] );
	check( 'empty value clears user meta', get_user_meta( $uid, 'ck_badge', true ) === '' );
	wp_delete_user( $uid );
}

// -----------------------------------------------------------------------------
// 3. Inline edit round-trip on a real term
// -----------------------------------------------------------------------------
$t = wp_insert_term( 'CK Phase9 ' . wp_rand( 1000, 9999 ), 'category' );
if ( is_wp_error( $t ) ) {
	check( 'created test term', false, $t->get_error_message() );
} else {
	$tid = (int) $t['term_id'];
	$term_meta->save_value( $tid, 'star', [ 'meta_key' => 'ck_icon' ] );
	check( 'term meta saved via column', get_term_meta( $tid, 'ck_icon', true ) === 'star' );
	$term_meta->save_value( $tid, '', [ 'meta_key' => 'ck_icon' ] );
	check( 'empty value clears term meta', get_term_meta( $tid, 'ck_icon', true ) === '' );
	wp_delete_term( $tid, 'category' );
}

// -----------------------------------------------------------------------------
// 4. Meta sort wiring (WP_User_Query)
// -----------------------------------------------------------------------------
$repo->save_set( 'users', 'default', 'Default', [
	[ 'id' => 'rank', 'type' => 'user_meta', 'label' => 'Rank', 'settings' => [ 'meta_key' => 'ck_rank' ], 'format' => [] ],
] );
$ulm = new \ColumnKit\ListScreens\UserListManager( $registry, $repo );
$ulm->activate( 'users', $repo->get_columns( 'users', 'default' ) );

$uq = new WP_User_Query( [ 'fields' => 'ID' ] );
$uq->set( 'orderby', 'ck_rank' );
$uq->set( 'order', 'asc' );
// pre_get_users would normally fire; call apply_sort directly to verify the rewrite.
$ulm->apply_sort( $uq );
check( 'user sort sets meta_key', $uq->get( 'meta_key' ) === 'ck_rank' );
check( 'user sort sets orderby=meta_value', $uq->get( 'orderby' ) === 'meta_value' );
check( 'user sort order whitelisted to ASC', $uq->get( 'order' ) === 'ASC' );

// -----------------------------------------------------------------------------
// 5. Meta sort wiring (WP_Term_Query via get_terms_args)
// -----------------------------------------------------------------------------
$repo->save_set( 'taxonomy:category', 'default', 'Default', [
	[ 'id' => 'icon', 'type' => 'term_meta', 'label' => 'Icon', 'settings' => [ 'meta_key' => 'ck_icon' ], 'format' => [] ],
] );
$tlm = new \ColumnKit\ListScreens\TermListManager( $registry, $repo );
$tlm->activate( 'taxonomy:category', 'category', $repo->get_columns( 'taxonomy:category', 'default' ) );

$_GET['orderby'] = 'ck_icon';
$_GET['order']   = 'desc';
$args = $tlm->apply_sort( [ 'taxonomy' => [ 'category' ] ], [ 'category' ] );
check( 'term sort sets meta_key', ( $args['meta_key'] ?? '' ) === 'ck_icon' );
check( 'term sort sets orderby=meta_value', ( $args['orderby'] ?? '' ) === 'meta_value' );
check( 'term sort order=DESC', ( $args['order'] ?? '' ) === 'DESC' );

// Non-ck orderby is left alone.
$_GET['orderby'] = 'name';
$args2 = $tlm->apply_sort( [ 'taxonomy' => [ 'category' ] ], [ 'category' ] );
check( 'term sort ignores non-ck orderby', ! isset( $args2['meta_key'] ) );

// Cleanup.
$_GET = [];
$repo->delete( 'users' );
$repo->delete( 'taxonomy:category' );

echo "\n" . ( $pass ? 'ALL PASS' : 'SOME FAILED' ) . "\n";
