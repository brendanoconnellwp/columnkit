<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ColumnKit\Admin\SettingsPage;
use ColumnKit\ColumnRegistry;
use ColumnKit\Columns\PostIdColumn;
use ColumnKit\ListScreens\ListScreenManager;
use ColumnKit\Settings\Sanitizer;
use ColumnKit\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;

/**
 * Built-in column management: the editor's unified row list → (custom columns, layout), the
 * layout → final list-table headers, and the editor's row order from stored state.
 */
final class ColumnLayoutTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs( [
			'__'                  => static fn( $s ) => $s,
			'esc_html'            => static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ),
			'sanitize_text_field' => static fn( $s ) => trim( strip_tags( (string) $s ) ),
			'sanitize_hex_color'  => static fn( $c ) => '',
			'wp_rand'             => static fn() => 4,
		] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function sanitizer(): Sanitizer {
		$registry = new ColumnRegistry();
		$registry->register( new PostIdColumn() );
		return new Sanitizer( $registry );
	}

	public function test_split_rows_builds_order_and_native_overrides(): void {
		$rows = [
			[ 'kind' => 'native', 'key' => 'date', 'label' => '', 'width' => '120px' ],
			[ 'kind' => 'custom', 'id' => 'colA', 'type' => 'post_id', 'label' => 'ID' ],
			[ 'kind' => 'native', 'key' => 'title', 'label' => 'Title' ], // same as default → no override
			[ 'kind' => 'native', 'key' => 'author', 'label' => 'Writer', 'hidden' => '1' ],
		];
		[ $clean, $layout ] = SettingsPage::split_rows( $rows, 'post_type:post', $this->sanitizer(), [ 'title' => 'Title', 'author' => 'Author', 'date' => 'Date' ] );

		$this->assertCount( 1, $clean );
		$this->assertSame( 'colA', $clean[0]['id'] );
		$this->assertSame( [ 'date', 'ck_colA', 'title', 'author' ], $layout['order'] );
		$this->assertSame( '', $layout['native']['title']['label'] );
		$this->assertSame( 'Writer', $layout['native']['author']['label'] );
		$this->assertTrue( $layout['native']['author']['hidden'] );
		$this->assertFalse( $layout['native']['date']['hidden'] );
	}

	public function test_split_rows_drops_invalid_and_dedupes_ids(): void {
		$rows = [
			[ 'kind' => 'native', 'key' => 'title' ],
			[ 'kind' => 'custom', 'id' => 'dup', 'type' => 'post_id' ],
			[ 'kind' => 'custom', 'id' => 'dup', 'type' => 'post_id' ],
			[ 'kind' => 'custom', 'id' => 'x', 'type' => 'evil' ],
			[ 'kind' => 'native', 'key' => 'ck_spoof' ],
			[ 'kind' => 'native', 'key' => 'cb' ],
		];
		[ $clean, $layout ] = SettingsPage::split_rows( $rows, 'post_type:post', $this->sanitizer(), [ 'title' => 'Title' ] );

		$this->assertCount( 2, $clean );
		$this->assertNotSame( $clean[0]['id'], $clean[1]['id'] );
		$this->assertSame( [ 'title' ], array_keys( $layout['native'] ) );
		$this->assertCount( 3, $layout['order'] );
	}

	public function test_split_rows_without_natives_yields_no_layout(): void {
		[ $clean, $layout ] = SettingsPage::split_rows(
			[ [ 'kind' => 'custom', 'id' => 'a', 'type' => 'post_id' ] ],
			'post_type:post',
			$this->sanitizer(),
			[]
		);
		$this->assertCount( 1, $clean );
		$this->assertSame( [], $layout );
	}

	public function test_merge_rows_follows_layout_then_appends_unknown(): void {
		$natives = [ 'title' => 'Title', 'author' => 'Author', 'date' => 'Date', 'seo' => 'SEO' ];
		$columns = [ [ 'id' => 'a', 'type' => 'post_id' ], [ 'id' => 'b', 'type' => 'post_id' ] ];
		$layout  = [ 'order' => [ 'date', 'ck_a', 'title', 'author' ], 'native' => [ 'date' => [], 'title' => [], 'author' => [] ] ];

		$keys = array_column( SettingsPage::merge_rows( $natives, $columns, $layout ), 'key' );
		// seo (new plugin column) and ck_b (added without layout) come after the saved order.
		$this->assertSame( [ 'date', 'ck_a', 'title', 'author', 'seo', 'ck_b' ], $keys );
	}

	public function test_merge_rows_without_layout_is_natives_then_customs(): void {
		$keys = array_column( SettingsPage::merge_rows( [ 'title' => 'Title' ], [ [ 'id' => 'a' ] ], [] ), 'key' );
		$this->assertSame( [ 'title', 'ck_a' ], $keys );
	}

	public function test_apply_layout_orders_renames_hides_and_appends(): void {
		$headers = [
			'cb'       => '<input type="checkbox">',
			'title'    => 'Title',
			'author'   => 'Author',
			'comments' => '<span class="vers">Comments</span>',
			'date'     => 'Date',
			'seo'      => 'SEO score', // a plugin column the layout has never seen
			'ck_a'     => 'Featured',
		];
		$layout = [
			'order'  => [ 'date', 'ck_a', 'title', 'author', 'comments' ],
			'native' => [
				'date'     => [ 'label' => '', 'hidden' => false ],
				'title'    => [ 'label' => '', 'hidden' => false ],
				'author'   => [ 'label' => 'Writer <b>', 'hidden' => false ],
				'comments' => [ 'label' => '', 'hidden' => true ],
			],
		];
		$out = ListScreenManager::apply_layout( $headers, $layout );

		$this->assertSame( [ 'cb', 'date', 'ck_a', 'title', 'author', 'seo' ], array_keys( $out ) );
		$this->assertSame( 'Writer &lt;b&gt;', $out['author'], 'Renamed labels are escaped.' );
		$this->assertSame( 'Title', $out['title'], 'Un-renamed headers keep their original markup.' );
	}

	public function test_apply_layout_is_noop_without_native_map(): void {
		$headers = [ 'cb' => 'x', 'title' => 'Title', 'ck_a' => 'A' ];
		$this->assertSame( $headers, ListScreenManager::apply_layout( $headers, [] ) );
		$this->assertSame( $headers, ListScreenManager::apply_layout( $headers, [ 'order' => [ 'ck_a', 'title' ] ] ) );
	}

	public function test_sanitize_layout_whitelists_keys_labels_widths(): void {
		$out = SettingsRepository::sanitize_layout( [
			'order'  => [ 'title', 'title', 'cb', 'bad key!', '<script>' ],
			'native' => [
				'title'      => [ 'label' => '<i>Name</i>', 'hidden' => '1', 'width' => '120px' ],
				'author'     => [ 'width' => '12; color:red' ],
				'ck_spoofed' => [],
				'cb'         => [],
			],
		] );
		$this->assertSame( [ 'title', 'badkey', 'script' ], $out['order'] );
		$this->assertSame( [ 'title', 'author' ], array_keys( $out['native'] ) );
		$this->assertSame( 'Name', $out['native']['title']['label'] );
		$this->assertTrue( $out['native']['title']['hidden'] );
		$this->assertSame( '120px', $out['native']['title']['width'] );
		$this->assertSame( '', $out['native']['author']['width'] );
		$this->assertSame( [], SettingsRepository::sanitize_layout( [ 'order' => [ 'title' ] ] ) );
	}
}
