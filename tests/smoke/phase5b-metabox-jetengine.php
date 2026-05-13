<?php
/**
 * Phase 5b smoke test — Meta Box + JetEngine integrations.
 * Run with:
 *   localwp-wp --site="AI Experiments" eval-file tests/smoke/phase5b-metabox-jetengine.php
 *
 * Expected on ai-experiments: Meta Box installed (live test), JetEngine NOT installed
 * (detect-only test).
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
// 1. Meta Box detection + column registration
// -----------------------------------------------------------------------------
$mb_active = \ColumnKit\Integrations\MetaBox\Loader::is_active();
check( 'Meta Box is detected as active on this site', $mb_active );
check( 'metabox_field column is registered when Meta Box is active',
	$registry->has( 'metabox_field' ) === $mb_active
);

if ( $mb_active ) {
	$col = $registry->get( 'metabox_field' );
	check( 'MetaBoxFieldColumn class is correct',
		$col instanceof \ColumnKit\Integrations\MetaBox\MetaBoxFieldColumn
	);

	// 2. Register a synthetic meta box for the rest of the test.
	$test_mb = static function ( $meta_boxes ) {
		$meta_boxes[] = [
			'id'         => 'ck_smoke_mb',
			'title'      => 'BAC Smoke MB',
			'post_types' => [ 'post' ],
			'fields'     => [
				[ 'id' => '_ck_mb_price',  'name' => 'Price',  'type' => 'number' ],
				[ 'id' => '_ck_mb_status', 'name' => 'Status', 'type' => 'text' ],
				[ 'id' => '_ck_mb_image',  'name' => 'Image',  'type' => 'single_image' ],
			],
		];
		return $meta_boxes;
	};
	add_filter( 'rwmb_meta_boxes', $test_mb );

	$options = $col->settings_fields()[0]['options'] ?? [];
	check( 'discover_field_options finds Price field from filter',
		isset( $options['_ck_mb_price'] ) && str_contains( $options['_ck_mb_price'], 'Price' )
	);
	check( 'discover_field_options finds Status field',
		isset( $options['_ck_mb_status'] ) && str_contains( $options['_ck_mb_status'], 'Status' )
	);

	// 3. Render with a real post + raw meta value.
	$prior = get_posts( [ 'post_type' => 'post', 'meta_key' => '_ck_test_phase5b', 'meta_value' => '1', 'numberposts' => -1, 'fields' => 'ids' ] );
	foreach ( $prior as $id ) { wp_delete_post( $id, true ); }

	$post_id = wp_insert_post( [ 'post_title' => 'Phase5b MB Test', 'post_status' => 'publish', 'post_type' => 'post' ] );
	update_post_meta( $post_id, '_ck_test_phase5b', '1' );
	update_post_meta( $post_id, '_ck_mb_price',  '42.50' );
	update_post_meta( $post_id, '_ck_mb_status', 'in stock' );

	$out = $col->render( $post_id, [ 'field_id' => '_ck_mb_price' ] );
	check( 'MetaBox column renders scalar price value',
		str_contains( $out, '42.50' ) || str_contains( $out, '42' ),
		'got: ' . $out
	);

	$out = $col->render( $post_id, [ 'field_id' => '_ck_mb_status' ] );
	check( 'MetaBox column renders text status value',
		str_contains( $out, 'in stock' )
	);

	// XSS payload should be escaped on render.
	update_post_meta( $post_id, '_ck_mb_evil', '"><script>alert(1)</script>' );
	$out = $col->render( $post_id, [ 'field_id' => '_ck_mb_evil' ] );
	check( 'MetaBox column escapes XSS payload in value',
		! str_contains( $out, '<script>' ) && str_contains( $out, '&quot;&gt;&lt;script&gt;' ),
		'got: ' . $out
	);

	// 4. Sort + filter SQL — direct method calls.
	$query = new WP_Query();
	$col->apply_sort( $query, [ 'field_id' => '_ck_mb_price' ], 'DESC' );
	check( 'MetaBox apply_sort registers posts_clauses', has_filter( 'posts_clauses' ) > 0 );

	$query2 = new WP_Query();
	$col->apply_filter( $query2, [ 'field_id' => '_ck_mb_status' ], [ '' => 'stock' ] );
	$mq = (array) $query2->get( 'meta_query' );
	check( 'MetaBox apply_filter adds LIKE meta_query clause',
		count( $mq ) > 0 && ( $mq[0]['key'] ?? '' ) === '_ck_mb_status' && ( $mq[0]['compare'] ?? '' ) === 'LIKE'
	);

	// 5. Sanitizer whitelists allowed chars in field_id.
	$sanitised = $col->sanitize_settings( [ 'field_id' => "evil'; DROP TABLE--" ] );
	check( 'MetaBox sanitize strips SQL-dangerous chars from field_id',
		( $sanitised['field_id'] ?? '' ) === 'evilDROPTABLE--'
	);

	// 6. End-to-end via list-table cell.
	$columns = [
		[ 'id' => 'mb_price', 'type' => 'metabox_field', 'label' => 'MB Price',
		  'settings' => [ 'field_id' => '_ck_mb_price' ], 'width' => '' ],
	];
	\ColumnKit\Plugin::instance()->repository()->save( 'post_type:post', $columns );
	set_current_screen( 'edit-post' );
	ob_start();
	do_action( 'manage_post_posts_custom_column', 'ck_mb_price', $post_id );
	$cell = ob_get_clean();
	check( 'MetaBox column renders inside list-table cell',
		str_contains( $cell, '42' )
	);

	remove_filter( 'rwmb_meta_boxes', $test_mb );
	wp_delete_post( $post_id, true );
	\ColumnKit\Plugin::instance()->repository()->delete( 'post_type:post' );
}

// -----------------------------------------------------------------------------
// 7. JetEngine — not installed → loader inactive, column NOT registered
// -----------------------------------------------------------------------------
$je_active = \ColumnKit\Integrations\JetEngine\Loader::is_active();
check( 'JetEngine is NOT active on this dev site', ! $je_active );

check( 'jetengine_field column NOT registered when JE absent',
	$registry->has( 'jetengine_field' ) === $je_active
);

// JetEngine column class still instantiates and renders gracefully without JE loaded.
$je_col = new \ColumnKit\Integrations\JetEngine\JetEngineFieldColumn();
check( 'JetEngineFieldColumn instantiates without JE',
	$je_col instanceof \ColumnKit\Integrations\JetEngine\JetEngineFieldColumn
);
check( 'JetEngineFieldColumn::settings_fields returns empty options without JE',
	$je_col->settings_fields()[0]['options'] === []
);

$post_id = wp_insert_post( [ 'post_title' => 'JE Smoke', 'post_status' => 'publish', 'post_type' => 'post' ] );
update_post_meta( $post_id, '_ck_je_field', 'hello' );
$out = $je_col->render( $post_id, [ 'field_name' => '_ck_je_field' ] );
check( 'JetEngineFieldColumn renders raw meta value (post_meta storage)',
	$out === 'hello',
	'got: ' . $out
);

// XSS smoke.
update_post_meta( $post_id, '_ck_je_xss', '<script>alert(1)</script>' );
$out = $je_col->render( $post_id, [ 'field_name' => '_ck_je_xss' ] );
check( 'JetEngineFieldColumn escapes XSS',
	! str_contains( $out, '<script>' ) && str_contains( $out, '&lt;script&gt;' )
);

wp_delete_post( $post_id, true );

// -----------------------------------------------------------------------------
// 8. applies_to_screen restrictions
// -----------------------------------------------------------------------------
$mb_col = $mb_active ? $registry->get( 'metabox_field' ) : new \ColumnKit\Integrations\MetaBox\MetaBoxFieldColumn();
check( 'MetaBox column applies to posts/media, not users/terms',
	$mb_col->applies_to_screen( 'post_type:post' ) === true
	&& $mb_col->applies_to_screen( 'media' ) === true
	&& $mb_col->applies_to_screen( 'users' ) === false
	&& $mb_col->applies_to_screen( 'taxonomy:category' ) === false
);

check( 'JetEngine column applies to posts/media, not users/terms',
	$je_col->applies_to_screen( 'post_type:post' ) === true
	&& $je_col->applies_to_screen( 'media' ) === true
	&& $je_col->applies_to_screen( 'users' ) === false
	&& $je_col->applies_to_screen( 'taxonomy:category' ) === false
);

echo "\n" . ( $pass ? "ALL PHASE 5b SMOKE TESTS PASSED" : "PHASE 5b SMOKE TESTS FAILED" ) . "\n";
exit( $pass ? 0 : 1 );
