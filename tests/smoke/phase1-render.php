<?php
/**
 * Phase 1 smoke test — run with:
 *   localwp-wp --site="AI Experiments" eval-file tests/smoke/phase1-render.php
 *
 * Seeds a post-meta value, writes a column config, simulates the Posts list
 * screen, and verifies the column header + cell render correctly.
 */

// 1. Seed a meta value on an existing post.
$post_id = (int) ( get_posts( [ 'numberposts' => 1, 'fields' => 'ids' ] )[0] ?? 0 );
if ( $post_id === 0 ) {
	echo "FAIL: no posts to test against\n";
	exit( 1 );
}
update_post_meta( $post_id, '_ck_test_price', '19.99' );

// 2. Write a column config for the Posts list screen.
$option = [
	'schema_version' => 1,
	'columns'        => [
		[
			'id'       => 'col_price',
			'type'     => 'post_meta',
			'label'    => 'Price',
			'settings' => [ 'meta_key' => '_ck_test_price', 'value_type' => 'numeric' ],
			'width'    => '',
		],
		[
			'id'       => 'col_id',
			'type'     => 'post_id',
			'label'    => 'ID',
			'settings' => [],
			'width'    => '',
		],
	],
];
update_option( 'ck_screen_post_type_post', $option, false );

// Clear our static repository cache so the new option is re-read.
\ColumnKit\Settings\SettingsRepository::reset_cache();

// 3. Simulate the Posts admin list-table screen (this fires the `current_screen` action,
//    which is what our ListScreenManager hooks).
set_current_screen( 'edit-post' );

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

// 4. Verify our filter adds the columns.
$defaults = [
	'cb'         => '<input type="checkbox">',
	'title'      => 'Title',
	'author'     => 'Author',
	'categories' => 'Categories',
	'tags'       => 'Tags',
	'comments'   => 'Comments',
	'date'       => 'Date',
];
$filtered = apply_filters( 'manage_post_posts_columns', $defaults );

check(
	'manage_post_posts_columns adds ck_col_price',
	isset( $filtered['ck_col_price'] ) && $filtered['ck_col_price'] === 'Price'
);
check(
	'manage_post_posts_columns adds ck_col_id',
	isset( $filtered['ck_col_id'] ) && $filtered['ck_col_id'] === 'ID'
);
check(
	'core columns are preserved (additive)',
	isset( $filtered['title'], $filtered['author'], $filtered['date'] )
);

// 5. Render the cells.
ob_start();
do_action( 'manage_post_posts_custom_column', 'ck_col_price', $post_id );
$price_out = ob_get_clean();
check(
	'price cell renders formatted numeric value',
	str_contains( $price_out, '19.99' ),
	"got: '$price_out'"
);

ob_start();
do_action( 'manage_post_posts_custom_column', 'ck_col_id', $post_id );
$id_out = ob_get_clean();
check(
	'id cell renders the post ID',
	$id_out === (string) $post_id,
	"got: '$id_out', expected: '$post_id'"
);

// 6. XSS smoke: stuff a malicious value into the meta, render, confirm escaped.
update_post_meta( $post_id, '_ck_test_price', '<script>alert(1)</script>boom' );
ob_start();
do_action( 'manage_post_posts_custom_column', 'ck_col_price', $post_id );
$xss_out = ob_get_clean();
check(
	'XSS payload in meta value is escaped on render',
	! str_contains( $xss_out, '<script>' ) && str_contains( $xss_out, '&lt;' ),
	"got: '$xss_out'"
);

// 7. Unknown column key is a no-op (our action handler must not swallow non-ck_ columns).
ob_start();
do_action( 'manage_post_posts_custom_column', 'title', $post_id );
$noop = ob_get_clean();
check(
	'non-bac column keys are ignored by our renderer',
	$noop === ''
);

// 8. Cleanup test meta.
delete_post_meta( $post_id, '_ck_test_price' );

echo "\n" . ( $pass ? "ALL SMOKE TESTS PASSED" : "SMOKE TESTS FAILED" ) . "\n";
exit( $pass ? 0 : 1 );
