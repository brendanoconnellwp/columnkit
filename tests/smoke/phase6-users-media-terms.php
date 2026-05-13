<?php
/**
 * Phase 6 smoke test — Users, Media, Taxonomies, i18n, multisite.
 * Run with:
 *   localwp-wp --site="AI Experiments" eval-file tests/smoke/phase6-users-media-terms.php
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
$registry   = \ColumnKit\Plugin::instance()->registry();
$repository = \ColumnKit\Plugin::instance()->repository();

// -----------------------------------------------------------------------------
// 1. ScreenIdentifier handles all four screen kinds
// -----------------------------------------------------------------------------
$screens = \ColumnKit\Support\ScreenIdentifier::available_screens();
check( 'available_screens includes post_type:post', isset( $screens['post_type:post'] ) );
check( 'available_screens includes media',         isset( $screens['media'] ) );
check( 'available_screens includes users',         isset( $screens['users'] ) );
check( 'available_screens includes a taxonomy',    isset( $screens['taxonomy:category'] ) || isset( $screens['taxonomy:post_tag'] ) );

check( 'attachment post type is excluded (uses media instead)',
	! isset( $screens['post_type:attachment'] )
);

// -----------------------------------------------------------------------------
// 2. New user/term columns are registered
// -----------------------------------------------------------------------------
check( 'user_meta column registered',       $registry->has( 'user_meta' ) );
check( 'user_role column registered',       $registry->has( 'user_role' ) );
check( 'user_post_count column registered', $registry->has( 'user_post_count' ) );
check( 'term_meta column registered',       $registry->has( 'term_meta' ) );

// -----------------------------------------------------------------------------
// 3. applies_to_screen — user columns only on users; post columns NOT on users
// -----------------------------------------------------------------------------
$user_meta = $registry->get( 'user_meta' );
check( 'UserMetaColumn applies to users',          $user_meta->applies_to_screen( 'users' ) === true );
check( 'UserMetaColumn does NOT apply to posts',   $user_meta->applies_to_screen( 'post_type:post' ) === false );
check( 'UserMetaColumn does NOT apply to media',   $user_meta->applies_to_screen( 'media' ) === false );
check( 'UserMetaColumn does NOT apply to terms',   $user_meta->applies_to_screen( 'taxonomy:category' ) === false );

$post_meta = $registry->get( 'post_meta' );
check( 'PostMetaColumn applies to posts + media',
	$post_meta->applies_to_screen( 'post_type:post' ) === true
	&& $post_meta->applies_to_screen( 'media' ) === true
);
check( 'PostMetaColumn does NOT apply to users',  $post_meta->applies_to_screen( 'users' ) === false );

$term_meta = $registry->get( 'term_meta' );
check( 'TermMetaColumn applies to a taxonomy',     $term_meta->applies_to_screen( 'taxonomy:category' ) === true );
check( 'TermMetaColumn does NOT apply to posts',   $term_meta->applies_to_screen( 'post_type:post' ) === false );

$featured = $registry->get( 'featured_image' );
check( 'FeaturedImageColumn does NOT apply to media (media items ARE images)',
	$featured->applies_to_screen( 'media' ) === false
);

// -----------------------------------------------------------------------------
// 4. UserMetaColumn render — read user meta value
// -----------------------------------------------------------------------------
update_user_meta( 1, '_ck_test_user_meta', 'hello world' );
check( 'UserMetaColumn renders user meta value',
	$user_meta->render( 1, [ 'meta_key' => '_ck_test_user_meta' ] ) === 'hello world'
);
check( 'UserMetaColumn empty when key missing',
	$user_meta->render( 1, [ 'meta_key' => '__nonexistent__' ] ) === ''
);

// XSS smoke: meta with HTML/script payload is escaped.
update_user_meta( 1, '_ck_xss', '<script>alert(1)</script>' );
$out = $user_meta->render( 1, [ 'meta_key' => '_ck_xss' ] );
check( 'UserMetaColumn escapes XSS in meta',
	! str_contains( $out, '<script>' ) && str_contains( $out, '&lt;script&gt;' )
);

// -----------------------------------------------------------------------------
// 5. UserRoleColumn renders the role display name
// -----------------------------------------------------------------------------
$user_role = $registry->get( 'user_role' );
$out = $user_role->render( 1, [] );
check( 'UserRoleColumn renders the role of user 1',
	str_contains( $out, 'Administrator' ) || str_contains( $out, 'administrator' ),
	'got: ' . $out
);

// -----------------------------------------------------------------------------
// 6. UserPostCountColumn returns a numeric count
// -----------------------------------------------------------------------------
$count_col = $registry->get( 'user_post_count' );
$out       = $count_col->render( 1, [ 'post_type' => 'post' ] );
check( 'UserPostCountColumn returns numeric string',
	ctype_digit( $out ),
	'got: ' . $out
);

// Setting sanitiser whitelists post type.
$sanitised = $count_col->sanitize_settings( [ 'post_type' => 'evil_type' ] );
check( 'UserPostCountColumn rejects unknown post type',
	( $sanitised['post_type'] ?? '' ) === 'post'
);

// -----------------------------------------------------------------------------
// 7. TermMetaColumn renders term meta
// -----------------------------------------------------------------------------
$cat = wp_create_category( 'BAC Phase6 Test Category' );
if ( is_wp_error( $cat ) || $cat === 0 ) {
	echo "FAIL: could not create test category\n";
	exit( 1 );
}
update_term_meta( $cat, '_ck_term_test', 'term value' );
check( 'TermMetaColumn renders term meta value',
	$term_meta->render( (int) $cat, [ 'meta_key' => '_ck_term_test' ] ) === 'term value'
);

// -----------------------------------------------------------------------------
// 8. ListScreenManager dispatches user cell render correctly
// -----------------------------------------------------------------------------
$user_cols = [
	[ 'id' => 'role', 'type' => 'user_role', 'label' => 'Role', 'settings' => [], 'width' => '' ],
	[ 'id' => 'meta', 'type' => 'user_meta', 'label' => 'Meta',
	  'settings' => [ 'meta_key' => '_ck_test_user_meta' ], 'width' => '' ],
];
$repository->save( 'users', $user_cols );

set_current_screen( 'users' );

// User cell renderer is a FILTER returning HTML (not echo).
$out = apply_filters( 'manage_users_custom_column', 'INITIAL', 'ck_role', 1 );
check( 'manage_users_custom_column returns role HTML (filter, not echo)',
	is_string( $out ) && ( str_contains( $out, 'Administrator' ) || str_contains( $out, 'administrator' ) ),
	'got: ' . $out
);
$out = apply_filters( 'manage_users_custom_column', 'INITIAL', 'ck_meta', 1 );
check( 'manage_users_custom_column returns user meta value',
	$out === 'hello world',
	'got: ' . $out
);
// Non-bac columns pass through unchanged.
$out = apply_filters( 'manage_users_custom_column', 'INITIAL', 'username', 1 );
check( 'non-bac user column passes through unchanged', $out === 'INITIAL' );

// User column headers are added.
$cols = apply_filters( 'manage_users_columns', [ 'username' => 'Username', 'role' => 'Role' ] );
check( 'manage_users_columns adds ck_role + ck_meta headers',
	isset( $cols['ck_role'], $cols['ck_meta'] )
);

// -----------------------------------------------------------------------------
// 9. ListScreenManager dispatches taxonomy cell render correctly
// -----------------------------------------------------------------------------
$repository->delete( 'users' );

$term_cols = [
	[ 'id' => 'tm', 'type' => 'term_meta', 'label' => 'TM',
	  'settings' => [ 'meta_key' => '_ck_term_test' ], 'width' => '' ],
];
$repository->save( 'taxonomy:category', $term_cols );
set_current_screen( 'edit-category' );

$out = apply_filters( 'manage_category_custom_column', 'INITIAL', 'ck_tm', (int) $cat );
check( 'manage_{tax}_custom_column returns term meta',
	$out === 'term value',
	'got: ' . $out
);

// Headers added.
$cols = apply_filters( 'manage_edit-category_columns', [ 'name' => 'Name' ] );
check( 'manage_edit-{tax}_columns adds ck_tm header', isset( $cols['ck_tm'] ) );

// -----------------------------------------------------------------------------
// 10. ListScreenManager dispatches media (echo-style action) correctly
// -----------------------------------------------------------------------------
$repository->delete( 'taxonomy:category' );

$media_cols = [
	[ 'id' => 'id', 'type' => 'post_id', 'label' => 'ID', 'settings' => [], 'width' => '' ],
];
$repository->save( 'media', $media_cols );
set_current_screen( 'upload' );

// Find any attachment to test against (or create one).
$attachments = get_posts( [ 'post_type' => 'attachment', 'numberposts' => 1, 'post_status' => 'any' ] );
if ( $attachments ) {
	$att_id = (int) $attachments[0]->ID;
	ob_start();
	do_action( 'manage_media_custom_column', 'ck_id', $att_id );
	$cell = ob_get_clean();
	check( 'manage_media_custom_column echoes the post ID',
		$cell === (string) $att_id,
		'got: ' . $cell
	);
} else {
	echo "INFO: no attachments on site, skipping media render test\n";
}

$cols = apply_filters( 'manage_media_columns', [ 'title' => 'File' ] );
check( 'manage_media_columns adds ck_id header', isset( $cols['ck_id'] ) );

// -----------------------------------------------------------------------------
// 11. i18n .pot file generated and contains plugin strings
// -----------------------------------------------------------------------------
$pot_file = CK_DIR . 'languages/columnkit.pot';
check( '.pot file exists', is_file( $pot_file ) );
$pot = is_file( $pot_file ) ? file_get_contents( $pot_file ) : '';
check( '.pot file declares text domain', str_contains( (string) $pot, 'X-Domain: columnkit' ) );
check( '.pot includes plugin name string', str_contains( (string) $pot, 'msgid "ColumnKit"' ) );
check( '.pot includes a key UI string', str_contains( (string) $pot, 'Admin Columns' ) );

// -----------------------------------------------------------------------------
// 12. Multisite — verify storage is per-site (no surprises)
// -----------------------------------------------------------------------------
// Our SettingsRepository uses get_option / update_option, which on multisite scope to the
// current site. We document and verify here that we never call get_site_option.
$repo_source = file_get_contents( CK_DIR . 'src/Settings/SettingsRepository.php' );
check( 'SettingsRepository never calls get_site_option (network-wide)',
	! str_contains( (string) $repo_source, 'get_site_option' )
);
check( 'SettingsRepository uses per-site update_option',
	str_contains( (string) $repo_source, 'update_option' )
);

// -----------------------------------------------------------------------------
// Cleanup
// -----------------------------------------------------------------------------
delete_user_meta( 1, '_ck_test_user_meta' );
delete_user_meta( 1, '_ck_xss' );
wp_delete_term( (int) $cat, 'category' );
$repository->delete( 'users' );
$repository->delete( 'taxonomy:category' );
$repository->delete( 'media' );

echo "\n" . ( $pass ? "ALL PHASE 6 SMOKE TESTS PASSED" : "PHASE 6 SMOKE TESTS FAILED" ) . "\n";
exit( $pass ? 0 : 1 );
