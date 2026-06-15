<?php
/**
 * Phase 7 smoke test — Column Sets (saved views).
 * Run with:
 *   localwp-wp --site="AI Experiments" eval-file tests/smoke/phase7-column-sets.php
 *
 * Exercises the v1→v2 migration, per-set CRUD, set-scoped column resolution, and the
 * SetResolver request/user-meta precedence — against the live DB.
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

wp_set_current_user( 1 );

use ColumnKit\Settings\SettingsRepository;
use ColumnKit\Support\SetResolver;

$repo   = \ColumnKit\Plugin::instance()->repository();
$screen = 'post_type:post';
$option = 'ck_screen_post_type_post';

// -----------------------------------------------------------------------------
// 1. v1 → v2 migration on read
// -----------------------------------------------------------------------------
delete_option( $option );
SettingsRepository::reset_cache();
update_option( $option, [
	'schema_version' => 1,
	'screen_key'     => $screen,
	'columns'        => [ [ 'id' => 'col_legacy', 'type' => 'post_id', 'label' => 'ID', 'settings' => [] ] ],
], false );
SettingsRepository::reset_cache();

$payload = $repo->get( $screen );
check( 'legacy v1 option reads back as schema v2', ( $payload['schema_version'] ?? 0 ) === 2 );
check( 'legacy columns land in default set', ( $payload['sets']['default']['columns'][0]['id'] ?? '' ) === 'col_legacy' );
check( 'get_columns(default) returns migrated columns', $repo->get_columns( $screen, 'default' )[0]['id'] === 'col_legacy' );

// -----------------------------------------------------------------------------
// 2. Create / duplicate / rename / delete sets
// -----------------------------------------------------------------------------
$seo_id = $repo->generate_set_id( $screen );
$repo->save_set( $screen, $seo_id, 'SEO view', [ [ 'id' => 'col_seo', 'type' => 'post_meta', 'label' => 'Focus', 'settings' => [ 'meta_key' => '_yoast', 'value_type' => 'string' ] ] ] );
SettingsRepository::reset_cache();

$sets = $repo->get_sets( $screen );
check( 'two sets after create', count( $sets ) === 2, implode( ',', array_keys( $sets ) ) );
check( 'new set has its own columns', $repo->get_columns( $screen, $seo_id )[0]['id'] === 'col_seo' );
check( 'default set untouched by new set', $repo->get_columns( $screen, 'default' )[0]['id'] === 'col_legacy' );

$repo->save_set( $screen, $seo_id, 'SEO view (renamed)', $repo->get_columns( $screen, $seo_id ) );
SettingsRepository::reset_cache();
check( 'rename keeps columns + new label', $repo->get_sets( $screen )[ $seo_id ] === 'SEO view (renamed)' && count( $repo->get_columns( $screen, $seo_id ) ) === 1 );

$repo->delete_set( $screen, $seo_id );
SettingsRepository::reset_cache();
check( 'delete removes the set', ! $repo->set_exists( $screen, $seo_id ) );
check( 'default survives sibling delete', $repo->set_exists( $screen, 'default' ) );

// Default set can't be deleted — only emptied.
$repo->delete_set( $screen, 'default' );
SettingsRepository::reset_cache();
check( 'default set still exists after delete', $repo->set_exists( $screen, 'default' ) );
check( 'default set emptied by delete', $repo->get_columns( $screen, 'default' ) === [] );

// -----------------------------------------------------------------------------
// 3. SetResolver precedence: ?ck_set → user meta → default
// -----------------------------------------------------------------------------
$repo->save_set( $screen, 'default', 'Default', [ [ 'id' => 'd', 'type' => 'post_id' ] ] );
$alt = $repo->generate_set_id( $screen );
$repo->save_set( $screen, $alt, 'Alt', [ [ 'id' => 'a', 'type' => 'post_id' ] ] );
SettingsRepository::reset_cache();

$uid = get_current_user_id();
delete_user_meta( $uid, 'ck_active_set_post_type_post' );

$_GET = [];
check( 'no param, no meta → default', SetResolver::resolve( $repo, $screen ) === 'default' );

$_GET = [ 'ck_set' => $alt ];
check( 'explicit ?ck_set is honoured', SetResolver::resolve( $repo, $screen ) === $alt );
check( 'explicit ?ck_set is remembered in user meta', get_user_meta( $uid, 'ck_active_set_post_type_post', true ) === $alt );

$_GET = [];
check( 'remembered set reused without param', SetResolver::resolve( $repo, $screen ) === $alt );

$_GET = [ 'ck_set' => 'does_not_exist' ];
check( 'invalid ?ck_set falls back to remembered', SetResolver::resolve( $repo, $screen ) === $alt );

// Cleanup.
$_GET = [];
delete_user_meta( $uid, 'ck_active_set_post_type_post' );
delete_option( $option );
SettingsRepository::reset_cache();

echo "\n" . ( $pass ? 'ALL PASS' : 'SOME FAILED' ) . "\n";
