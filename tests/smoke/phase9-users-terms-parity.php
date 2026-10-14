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
// 4. Meta sort (WP_User_Query) — LEFT JOIN: users WITHOUT the meta must stay in the list.
// -----------------------------------------------------------------------------
// is_admin() gates the sort hooks; give the CLI request an admin screen.
require_once ABSPATH . 'wp-admin/includes/admin.php';
set_current_screen( 'dashboard' );

// Back up any real config on these screens so the smoke run doesn't wipe it.
$backup_users = get_option( 'ck_screen_users' );
$backup_cat   = get_option( 'ck_screen_taxonomy_category' );

$repo->save_set( 'users', 'default', 'Default', [
	[ 'id' => 'rank', 'type' => 'user_meta', 'label' => 'Rank', 'settings' => [ 'meta_key' => 'ck_rank' ], 'format' => [] ],
] );
$ulm = new \ColumnKit\ListScreens\UserListManager( $registry, $repo );
$ulm->activate( 'users', $repo->get_columns( 'users', 'default' ) );

$u_ids = [];
foreach ( [ 'ck_smoke_b' => 'b', 'ck_smoke_a' => 'a', 'ck_smoke_none' => '' ] as $login => $rank ) {
	$id = username_exists( $login ) ?: wp_insert_user( [ 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.test' ] );
	delete_user_meta( (int) $id, 'ck_rank' );
	if ( $rank !== '' ) {
		update_user_meta( (int) $id, 'ck_rank', $rank );
	}
	$u_ids[ $login ] = (int) $id;
}
$uq  = new WP_User_Query( [ 'include' => array_values( $u_ids ), 'orderby' => 'ck_rank', 'order' => 'asc', 'fields' => 'ID' ] );
$got = array_map( 'intval', $uq->get_results() );
check( 'user sort keeps users without the meta', count( $got ) === 3, implode( ',', $got ) );
check( 'user sort ASC: missing, a, b', $got === [ $u_ids['ck_smoke_none'], $u_ids['ck_smoke_a'], $u_ids['ck_smoke_b'] ], implode( ',', $got ) );

// -----------------------------------------------------------------------------
// 5. Meta sort (WP_Term_Query via get_terms_args + terms_clauses) — same LEFT JOIN rule.
// -----------------------------------------------------------------------------
$repo->save_set( 'taxonomy:category', 'default', 'Default', [
	[ 'id' => 'icon', 'type' => 'term_meta', 'label' => 'Icon', 'settings' => [ 'meta_key' => 'ck_icon' ], 'format' => [] ],
] );
$tlm = new \ColumnKit\ListScreens\TermListManager( $registry, $repo );
$tlm->activate( 'taxonomy:category', 'category', $repo->get_columns( 'taxonomy:category', 'default' ) );

$t_ids = [];
foreach ( [ 'ck-smoke-z' => 'zz', 'ck-smoke-y' => 'yy', 'ck-smoke-none' => '' ] as $slug => $icon ) {
	$existing = get_term_by( 'slug', $slug, 'category' );
	$tid      = $existing ? (int) $existing->term_id : (int) wp_insert_term( $slug, 'category', [ 'slug' => $slug ] )['term_id'];
	delete_term_meta( $tid, 'ck_icon' );
	if ( $icon !== '' ) {
		update_term_meta( $tid, 'ck_icon', $icon );
	}
	$t_ids[ $slug ] = $tid;
}
$_GET['orderby'] = 'ck_icon';
$_GET['order']   = 'desc';
$terms = array_map( 'intval', (array) get_terms( [ 'taxonomy' => 'category', 'include' => array_values( $t_ids ), 'hide_empty' => false, 'fields' => 'ids' ] ) );
check( 'term sort keeps terms without the meta', count( $terms ) === 3, implode( ',', $terms ) );
check( 'term sort DESC: zz, yy, missing', $terms === [ $t_ids['ck-smoke-z'], $t_ids['ck-smoke-y'], $t_ids['ck-smoke-none'] ], implode( ',', $terms ) );

// Non-ck orderby is left alone.
$_GET['orderby'] = 'name';
$args2 = $tlm->apply_sort( [ 'taxonomy' => [ 'category' ] ], [ 'category' ] );
check( 'term sort ignores non-ck orderby', ! isset( $args2['ck_sort_mark'] ) );

// Cleanup — remove fixtures, restore the real config.
$_GET = [];
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $u_ids as $id ) {
	wp_delete_user( $id );
}
foreach ( $t_ids as $tid ) {
	wp_delete_term( $tid, 'category' );
}
\ColumnKit\Settings\SettingsRepository::reset_cache();
false === $backup_users ? delete_option( 'ck_screen_users' ) : update_option( 'ck_screen_users', $backup_users, false );
false === $backup_cat ? delete_option( 'ck_screen_taxonomy_category' ) : update_option( 'ck_screen_taxonomy_category', $backup_cat, false );

echo "\n" . ( $pass ? 'ALL PASS' : 'SOME FAILED' ) . "\n";
