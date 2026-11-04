<?php
/**
 * Smoke test — ColumnKit\Api v1 against the live DB.
 * Run with:
 *   localwp-wp --site="AI Experiments" eval-file wp-content/plugins/columnkit/tests/smoke/api-v1.php
 *
 * Uses a temporary Posts screen config (a Status column) and a throwaway Contributor; restores
 * the original config and deletes everything it created.
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

use ColumnKit\Api;
use ColumnKit\Settings\SettingsRepository;

$option   = 'ck_screen_post_type_post';
$backup   = get_option( $option, null );
$screen   = 'post_type:post';
$created  = [ 'posts' => [], 'users' => [] ];

try {
	wp_set_current_user( 1 );

	check( 'columnkit/loaded fired', did_action( 'columnkit/loaded' ) > 0 );
	check( 'Api::VERSION is 1', Api::VERSION === 1 );

	update_option( $option, [
		'schema_version' => 2,
		'screen_key'     => $screen,
		'sets'           => [
			'default'   => [ 'label' => 'Default', 'columns' => [ [ 'id' => 'st', 'type' => 'post_status', 'settings' => [] ] ] ],
			'editorial' => [ 'label' => 'Editorial', 'roles' => [ 'editor' ], 'columns' => [ [ 'id' => 'st', 'type' => 'post_status', 'settings' => [] ] ] ],
		],
	], false );
	SettingsRepository::reset_cache();

	$cols = Api::columns( $screen );
	check( 'columns() returns the configured column', count( $cols ) === 1 && $cols[0]['id'] === 'st' );
	$choices = Api::lane_choices( $cols[0] );
	check( 'post_status column offers lane choices', is_array( $choices ) && isset( $choices['draft'], $choices['pending'] ) );

	// Admin moves a draft to pending.
	$admin_post         = wp_insert_post( [ 'post_title' => 'CK API smoke (admin)', 'post_status' => 'draft', 'post_author' => 1 ] );
	$created['posts'][] = $admin_post;
	$r = Api::save( $screen, 'default', 'st', $admin_post, 'pending' );
	clean_post_cache( $admin_post );
	check( 'admin save succeeds', $r === true, is_wp_error( $r ) ? $r->get_error_code() : '' );
	check( 'status actually changed', get_post_status( $admin_post ) === 'pending' );
	check( 'raw_value reads it back', Api::raw_value( $cols[0], $admin_post ) === 'pending' );

	// Contributor.
	$contrib             = wp_insert_user( [ 'user_login' => 'ck_smoke_contrib_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'contributor' ] );
	$created['users'][]  = $contrib;
	$own                 = wp_insert_post( [ 'post_title' => 'CK API smoke (contrib)', 'post_status' => 'draft', 'post_author' => $contrib ] );
	$created['posts'][]  = $own;
	wp_set_current_user( $contrib );
	SettingsRepository::reset_cache();

	$r = Api::save( $screen, 'default', 'st', $own, 'publish' );
	clean_post_cache( $own );
	check( 'contributor publish is reported as not saved', is_wp_error( $r ) && $r->get_error_code() === 'ck_not_saved', is_wp_error( $r ) ? $r->get_error_code() : var_export( $r, true ) );
	check( 'contributor post is still a draft', get_post_status( $own ) === 'draft' );

	$r = Api::save( $screen, 'default', 'st', $admin_post, 'draft' );
	check( "contributor can't save someone else's post (403)", is_wp_error( $r ) && ( $r->get_error_data()['status'] ?? 0 ) === 403 );

	check( 'contributor does not see the editor-only view', ! array_key_exists( 'editorial', Api::sets( $screen ) ) );
	check( 'editor-only view has no columns for contributor', Api::columns( $screen, 'editorial' ) === [] );
	$r = Api::save( $screen, 'editorial', 'st', $own, 'pending' );
	check( 'saving through an invisible view is 403', is_wp_error( $r ) && $r->get_error_code() === 'ck_set_forbidden' );

	$r = Api::save( 'post_type:page', 'default', 'st', $own, 'pending' );
	check( 'screen/post type mismatch is 400', is_wp_error( $r ) && $r->get_error_code() === 'ck_screen_mismatch' );
} finally {
	wp_set_current_user( 1 );
	foreach ( $created['posts'] as $id ) {
		wp_delete_post( $id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $created['users'] as $id ) {
		wp_delete_user( $id );
	}
	if ( $backup === null ) {
		delete_option( $option );
	} else {
		update_option( $option, $backup, false );
	}
	SettingsRepository::reset_cache();
}

echo $pass ? "\nALL PASS\n" : "\nSOME FAILED\n";
