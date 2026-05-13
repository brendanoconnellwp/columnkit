<?php
/**
 * Phase 5 smoke test — Integrations (ACF, WooCommerce, Yoast).
 * Run with:
 *   localwp-wp --site="AI Experiments" eval-file tests/smoke/phase5-integrations.php
 *
 * Expected on ai-experiments dev site: ACF active, WC + Yoast NOT installed.
 * Tests verify conditional registration: ACF column registered, WC + Yoast NOT registered.
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
$registry = \ColumnKit\Plugin::instance()->registry();

// -----------------------------------------------------------------------------
// 1. ACF detection + registration
// -----------------------------------------------------------------------------
$acf_active = \ColumnKit\Integrations\ACF\Loader::is_active();
check( 'ACF is detected as active on this site', $acf_active );

check( 'acf_field column type is registered when ACF is active',
	$registry->has( 'acf_field' ) === $acf_active
);

if ( $acf_active ) {
	$col = $registry->get( 'acf_field' );
	check( 'ACFFieldColumn class is correct',
		$col instanceof \ColumnKit\Integrations\ACF\ACFFieldColumn
	);

	// settings_fields() should produce the dropdown spec.
	$fields = $col->settings_fields();
	check( 'ACFFieldColumn settings has field_name dropdown',
		isset( $fields[0]['key'] ) && $fields[0]['key'] === 'field_name'
		&& isset( $fields[0]['type'] ) && $fields[0]['type'] === 'select'
	);

	// 2. Render path with a non-existent field name → falls back to raw meta.
	// Seed a meta value and render via the column.
	$prior = get_posts( [ 'post_type' => 'post', 'meta_key' => '_ck_test_phase5', 'meta_value' => '1', 'numberposts' => -1, 'fields' => 'ids' ] );
	foreach ( $prior as $id ) { wp_delete_post( $id, true ); }

	$post_id = wp_insert_post( [ 'post_title' => 'Phase5 ACF Test', 'post_status' => 'publish', 'post_type' => 'post' ] );
	update_post_meta( $post_id, '_ck_test_phase5', '1' );
	update_post_meta( $post_id, 'unregistered_acf_field', 'fallback value' );

	$rendered = $col->render( $post_id, [ 'field_name' => 'unregistered_acf_field' ] );
	check( 'ACF column falls back to raw meta when field is not registered in ACF',
		$rendered === 'fallback value',
		'got: ' . $rendered
	);

	// 3. Sort/filter sanity — generate the SQL clauses via direct method call.
	$query = new WP_Query();
	$col->apply_sort( $query, [ 'field_name' => 'unregistered_acf_field' ], 'DESC' );
	// We don't run the query (would need fixtures matched on ACF terms). Just check no fatal.
	check( 'ACF apply_sort registers posts_clauses without error', has_filter( 'posts_clauses' ) > 0 );

	// Filter: apply with a value, verify meta_query was added.
	$query2 = new WP_Query();
	$col->apply_filter( $query2, [ 'field_name' => 'unregistered_acf_field' ], [ '' => 'fall' ] );
	$mq = (array) $query2->get( 'meta_query' );
	check( 'ACF apply_filter adds a LIKE meta_query clause',
		count( $mq ) > 0 && ( $mq[0]['key'] ?? '' ) === 'unregistered_acf_field' && ( $mq[0]['compare'] ?? '' ) === 'LIKE'
	);

	// 4. End-to-end via list-table simulation.
	$columns = [
		[ 'id' => 'acf', 'type' => 'acf_field', 'label' => 'ACF',
		  'settings' => [ 'field_name' => 'unregistered_acf_field' ], 'width' => '' ],
	];
	\ColumnKit\Plugin::instance()->repository()->save( 'post_type:post', $columns );
	set_current_screen( 'edit-post' );
	ob_start();
	do_action( 'manage_post_posts_custom_column', 'ck_acf', $post_id );
	$cell = ob_get_clean();
	// ACFFieldColumn is NOT EditableColumn (editing deferred to ACF's own UIs), so the cell
	// renders as plain content with no .ck-cell wrap — that's intentional.
	check( 'ACF column renders the field value in the list-table cell',
		str_contains( $cell, 'fallback value' ) && ! str_contains( $cell, 'ck-editable' ),
		'cell: ' . $cell
	);

	// Cleanup
	wp_delete_post( $post_id, true );
}

// -----------------------------------------------------------------------------
// 5. WooCommerce — not installed on this site → loader inactive, columns NOT registered
// -----------------------------------------------------------------------------
$wc_active = \ColumnKit\Integrations\WooCommerce\Loader::is_active();
check( 'WooCommerce is NOT active on this dev site', ! $wc_active, 'wc detected: ' . ( $wc_active ? 'yes' : 'no' ) );

check( 'WC product price column NOT registered when WC absent',
	$registry->has( 'wc_product_price' ) === $wc_active
);
check( 'WC product stock column NOT registered when WC absent',
	$registry->has( 'wc_product_stock' ) === $wc_active
);
check( 'WC product SKU column NOT registered when WC absent',
	$registry->has( 'wc_product_sku' ) === $wc_active
);

// 6. WC column classes still parse/instantiate without WC loaded.
$price = new \ColumnKit\Integrations\WooCommerce\ProductPriceColumn();
check( 'ProductPriceColumn instantiates without WC', $price instanceof \ColumnKit\Integrations\WooCommerce\ProductPriceColumn );
check( 'ProductPriceColumn::render returns empty when wc_get_product unavailable',
	$price->render( 1, [] ) === ''
);
check( 'ProductPriceColumn::applies_to_screen restricts to post_type:product',
	$price->applies_to_screen( 'post_type:product' ) === true
	&& $price->applies_to_screen( 'post_type:post' ) === false
);

// -----------------------------------------------------------------------------
// 7. Yoast — same expectation: not installed → columns NOT registered
// -----------------------------------------------------------------------------
$yoast_active = \ColumnKit\Integrations\Yoast\Loader::is_active();
check( 'Yoast SEO is NOT active on this dev site', ! $yoast_active );

check( 'Yoast SEO score column NOT registered when Yoast absent',
	$registry->has( 'yoast_seo_score' ) === $yoast_active
);
check( 'Yoast readability column NOT registered when Yoast absent',
	$registry->has( 'yoast_readability' ) === $yoast_active
);
check( 'Yoast focus keyword column NOT registered when Yoast absent',
	$registry->has( 'yoast_focus_keyword' ) === $yoast_active
);

// 8. Yoast column classes still instantiate + render without Yoast loaded.
$seo = new \ColumnKit\Integrations\Yoast\SEOScoreColumn();
check( 'SEOScoreColumn instantiates without Yoast', $seo instanceof \ColumnKit\Integrations\Yoast\SEOScoreColumn );

$post_id = wp_insert_post( [ 'post_title' => 'Yoast test', 'post_status' => 'publish', 'post_type' => 'post' ] );

// No score meta → renders as "—" badge.
$out = $seo->render( $post_id, [] );
check( 'Yoast SEOScoreColumn renders dash when meta missing', str_contains( $out, 'ck-yoast-none' ) && str_contains( $out, '&mdash;' ) || str_contains( $out, '—' ) );

// With a score → coloured badge.
update_post_meta( $post_id, '_yoast_wpseo_linkdex', '85' );
$out = $seo->render( $post_id, [] );
check( 'Yoast score 85 → "good" bucket', str_contains( $out, 'ck-yoast-good' ) && str_contains( $out, '85' ) );

update_post_meta( $post_id, '_yoast_wpseo_linkdex', '50' );
$out = $seo->render( $post_id, [] );
check( 'Yoast score 50 → "ok" bucket', str_contains( $out, 'ck-yoast-ok' ) );

update_post_meta( $post_id, '_yoast_wpseo_linkdex', '20' );
$out = $seo->render( $post_id, [] );
check( 'Yoast score 20 → "bad" bucket', str_contains( $out, 'ck-yoast-bad' ) );

wp_delete_post( $post_id, true );

// -----------------------------------------------------------------------------
// 9. All three loaders + columns participate in the registry's all() listing
// -----------------------------------------------------------------------------
$all_types = $registry->type_slugs();
$expected_present = [ 'post_id', 'post_meta', 'taxonomy', 'featured_image', 'author' ];
if ( $acf_active ) { $expected_present[] = 'acf_field'; }
$missing = array_diff( $expected_present, $all_types );
check( 'all expected core + active-integration types are registered', $missing === [], 'missing: ' . implode( ',', $missing ) );

// Clean up: restore default settings for the post screen so other tests aren't broken.
\ColumnKit\Plugin::instance()->repository()->delete( 'post_type:post' );

echo "\n" . ( $pass ? "ALL PHASE 5 SMOKE TESTS PASSED" : "PHASE 5 SMOKE TESTS FAILED" ) . "\n";
exit( $pass ? 0 : 1 );
