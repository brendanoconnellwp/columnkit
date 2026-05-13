<?php
/**
 * Phase 2 smoke test — sorting + filtering.
 * Run with:
 *   localwp-wp --site="AI Experiments" eval-file tests/smoke/phase2-sort-filter.php
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

// -----------------------------------------------------------------------------
// 1. Seed test fixtures
// -----------------------------------------------------------------------------
// Clean up any previous test posts.
$prior = get_posts( [
	'post_type'   => 'post',
	'meta_key'    => '_ck_test_marker',
	'meta_value'  => '1',
	'numberposts' => -1,
	'fields'      => 'ids',
] );
foreach ( $prior as $id ) {
	wp_delete_post( $id, true );
}

// Create 5 posts with known prices. Title order intentionally != price order so
// sorting must actually use the meta value.
$fixtures = [
	[ 'title' => 'Item A', 'price' => '10', 'cat' => 'ck-test-cat-x' ],
	[ 'title' => 'Item B', 'price' => '100', 'cat' => 'ck-test-cat-x' ],
	[ 'title' => 'Item C', 'price' => '25', 'cat' => 'ck-test-cat-y' ],
	[ 'title' => 'Item D', 'price' => '50', 'cat' => 'ck-test-cat-y' ],
	[ 'title' => 'Item E', 'price' => '25', 'cat' => 'ck-test-cat-x' ],
];

$ids_by_title = [];
foreach ( $fixtures as $f ) {
	$pid = wp_insert_post( [
		'post_title'   => $f['title'],
		'post_status'  => 'publish',
		'post_type'    => 'post',
		'post_content' => 'bac smoke',
	] );
	if ( $pid && ! is_wp_error( $pid ) ) {
		update_post_meta( $pid, '_ck_test_marker', '1' );
		update_post_meta( $pid, '_ck_test_price', $f['price'] );
		wp_set_object_terms( $pid, $f['cat'], 'category', false );
		$ids_by_title[ $f['title'] ] = (int) $pid;
	}
}

check( 'created 5 fixture posts', count( $ids_by_title ) === 5, 'have ' . count( $ids_by_title ) );

// -----------------------------------------------------------------------------
// 2. Sort by post_meta numeric — direct column.apply_sort + WP_Query
// -----------------------------------------------------------------------------
$meta_col = new \ColumnKit\Columns\PostMetaColumn();
$meta_settings = [ 'meta_key' => '_ck_test_price', 'value_type' => 'numeric' ];

// DESC: expect 100, 50, then the two 25s, then 10.
$query_desc = new WP_Query();
$meta_col->apply_sort( $query_desc, $meta_settings, 'DESC' );
$query_desc->query( [
	'post_type'      => 'post',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'meta_query'     => [ [ 'key' => '_ck_test_marker', 'value' => '1' ] ],
] );

$got_titles_desc = array_map( static fn( $id ) => get_the_title( $id ), $query_desc->posts );
check(
	'DESC numeric sort returns 5 posts',
	count( $query_desc->posts ) === 5,
	'got ' . count( $query_desc->posts )
);
check(
	'DESC numeric sort: first is Item B (price 100)',
	( $query_desc->posts[0] ?? 0 ) === $ids_by_title['Item B'],
	'got title: ' . ( $got_titles_desc[0] ?? '?' )
);
check(
	'DESC numeric sort: second is Item D (price 50)',
	( $query_desc->posts[1] ?? 0 ) === $ids_by_title['Item D'],
	'got title: ' . ( $got_titles_desc[1] ?? '?' )
);
check(
	'DESC numeric sort: last is Item A (price 10)',
	end( $query_desc->posts ) === $ids_by_title['Item A'],
	'got title: ' . end( $got_titles_desc )
);

// ASC: expect 10, then the two 25s, then 50, 100.
$query_asc = new WP_Query();
$meta_col->apply_sort( $query_asc, $meta_settings, 'ASC' );
$query_asc->query( [
	'post_type'      => 'post',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'meta_query'     => [ [ 'key' => '_ck_test_marker', 'value' => '1' ] ],
] );
check(
	'ASC numeric sort: first is Item A (price 10)',
	( $query_asc->posts[0] ?? 0 ) === $ids_by_title['Item A']
);
check(
	'ASC numeric sort: last is Item B (price 100)',
	end( $query_asc->posts ) === $ids_by_title['Item B']
);

// Sort posts that DON'T all have the meta key still show up (LEFT JOIN check).
// Add one fixture without _ck_test_price.
$no_meta_id = wp_insert_post( [
	'post_title'  => 'Item F (no price)',
	'post_status' => 'publish',
	'post_type'   => 'post',
] );
update_post_meta( $no_meta_id, '_ck_test_marker', '1' );

$query_missing = new WP_Query();
$meta_col->apply_sort( $query_missing, $meta_settings, 'DESC' );
$query_missing->query( [
	'post_type'      => 'post',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'meta_query'     => [ [ 'key' => '_ck_test_marker', 'value' => '1' ] ],
] );
check(
	'sort includes posts WITHOUT the meta key (LEFT JOIN works)',
	in_array( (int) $no_meta_id, array_map( 'intval', $query_missing->posts ), true ),
	'no-price post id ' . $no_meta_id . ' was ' . ( in_array( (int) $no_meta_id, array_map( 'intval', $query_missing->posts ), true ) ? 'present' : 'missing' )
);

// -----------------------------------------------------------------------------
// 3. Filter by post_meta — using pre_get_posts (mirrors how FilterManager hooks it).
// We can't pre-set query vars on a WP_Query and then call ->query($args), because query()
// re-initialises and overwrites them from $args. The real plugin flow runs apply_filter
// from pre_get_posts, AFTER parse_query, BEFORE get_posts — so we replicate that here.
// -----------------------------------------------------------------------------
$run_filtered = static function ( callable $apply ) {
	$handler = static function ( $q ) use ( $apply ) {
		if ( $q->get( 'post_type' ) !== 'post' ) {
			return;
		}
		$mq   = (array) $q->get( 'meta_query' );
		$mq[] = [ 'key' => '_ck_test_marker', 'value' => '1' ];
		$q->set( 'meta_query', $mq );
		$apply( $q );
	};
	add_action( 'pre_get_posts', $handler, 5 );
	$query = new WP_Query( [ 'post_type' => 'post', 'posts_per_page' => -1, 'fields' => 'ids' ] );
	remove_action( 'pre_get_posts', $handler, 5 );
	return array_map( 'intval', $query->posts );
};

$range_ids = $run_filtered( static function ( $q ) use ( $meta_col, $meta_settings ) {
	$meta_col->apply_filter( $q, $meta_settings, [ 'min' => '20', 'max' => '60' ] );
} );
$expected_range = [
	$ids_by_title['Item C'], // 25
	$ids_by_title['Item D'], // 50
	$ids_by_title['Item E'], // 25
];
sort( $range_ids );
sort( $expected_range );
check(
	'range filter [20..60] returns exactly the 3 posts with price in that range',
	$range_ids === $expected_range,
	'got: ' . implode( ',', $range_ids ) . ' expected: ' . implode( ',', $expected_range )
);

$min_ids = $run_filtered( static function ( $q ) use ( $meta_col, $meta_settings ) {
	$meta_col->apply_filter( $q, $meta_settings, [ 'min' => '30' ] );
} );
sort( $min_ids );
$expected_min = [ $ids_by_title['Item D'], $ids_by_title['Item B'] ];
sort( $expected_min );
check(
	'min-only filter [>=30] returns 2 posts (50 and 100)',
	$min_ids === $expected_min,
	'got: ' . implode( ',', $min_ids )
);

// -----------------------------------------------------------------------------
// 4. Filter by post_meta — string LIKE
// -----------------------------------------------------------------------------
update_post_meta( $ids_by_title['Item A'], '_ck_test_color', 'red apple' );
update_post_meta( $ids_by_title['Item B'], '_ck_test_color', 'green grape' );
update_post_meta( $ids_by_title['Item C'], '_ck_test_color', 'red berry' );

$like_ids = $run_filtered( static function ( $q ) use ( $meta_col ) {
	$meta_col->apply_filter( $q, [ 'meta_key' => '_ck_test_color', 'value_type' => 'string' ], [ '' => 'red' ] );
} );
sort( $like_ids );
$expected_like = [ $ids_by_title['Item A'], $ids_by_title['Item C'] ];
sort( $expected_like );
check(
	'string LIKE "red" matches 2 posts',
	$like_ids === $expected_like,
	'got: ' . implode( ',', $like_ids )
);

// SQL-injection smoke: a filter value that tries to escape the prepared statement.
$injection_ids = $run_filtered( static function ( $q ) use ( $meta_col ) {
	$meta_col->apply_filter(
		$q,
		[ 'meta_key' => '_ck_test_color', 'value_type' => 'string' ],
		[ '' => "red'; DROP TABLE wp_posts; --" ]
	);
} );
check(
	'SQL injection in filter value does not break query (no posts match the literal string)',
	is_array( $injection_ids ) && count( $injection_ids ) === 0,
	'got count: ' . count( $injection_ids )
);

// -----------------------------------------------------------------------------
// 5. SortManager.register_sortable dispatch — only SortableColumns advertised
// -----------------------------------------------------------------------------
$registry = \ColumnKit\Plugin::instance()->registry();
$sm = new \ColumnKit\ListScreens\SortManager( $registry );
$sm->activate( 'post', [
	[ 'id' => 'a', 'type' => 'post_id', 'label' => '', 'settings' => [], 'width' => '' ],         // sortable
	[ 'id' => 'b', 'type' => 'featured_image', 'label' => '', 'settings' => [], 'width' => '' ], // NOT sortable
	[ 'id' => 'c', 'type' => 'post_meta', 'label' => '', 'settings' => [], 'width' => '' ],     // sortable
] );
$cols_in = [ 'title' => 'title', 'date' => 'date' ];
$cols_out = $sm->register_sortable( $cols_in );
check( 'SortManager advertises ck_a (post_id is sortable)', isset( $cols_out['ck_a'] ) );
check( 'SortManager advertises ck_c (post_meta is sortable)', isset( $cols_out['ck_c'] ) );
check( 'SortManager does NOT advertise ck_b (featured_image is not sortable)', ! isset( $cols_out['ck_b'] ) );
check( 'SortManager preserves pre-existing sortable columns', isset( $cols_out['title'], $cols_out['date'] ) );

// -----------------------------------------------------------------------------
// 6. FilterManager.render_filters — security smoke
// -----------------------------------------------------------------------------
// Ensure attacker GET params don't smuggle through. Set a value with HTML/quotes.
$_GET['ck_f_price__min'] = '"><script>alert(1)</script>';
ob_start();
$fm = new \ColumnKit\ListScreens\FilterManager( $registry );
$fm->activate( 'post', [
	[ 'id' => 'price', 'type' => 'post_meta', 'label' => 'Price', 'settings' => [ 'meta_key' => '_ck_test_price', 'value_type' => 'numeric' ], 'width' => '' ],
] );
$fm->render_filters( 'post' );
$html = ob_get_clean();
check(
	'filter input escapes attacker payload',
	! str_contains( $html, '<script>' ),
	'first 200 chars: ' . substr( $html, 0, 200 )
);
unset( $_GET['ck_f_price__min'] );

// -----------------------------------------------------------------------------
// 7. Cleanup
// -----------------------------------------------------------------------------
$cleanup = get_posts( [
	'post_type'   => 'post',
	'meta_key'    => '_ck_test_marker',
	'meta_value'  => '1',
	'numberposts' => -1,
	'fields'      => 'ids',
] );
foreach ( $cleanup as $id ) {
	wp_delete_post( $id, true );
}
// Delete leftover test categories.
foreach ( [ 'ck-test-cat-x', 'ck-test-cat-y' ] as $slug ) {
	$t = get_term_by( 'slug', $slug, 'category' );
	if ( $t ) {
		wp_delete_term( $t->term_id, 'category' );
	}
}

echo "\n" . ( $pass ? "ALL PHASE 2 SMOKE TESTS PASSED" : "PHASE 2 SMOKE TESTS FAILED" ) . "\n";
exit( $pass ? 0 : 1 );
