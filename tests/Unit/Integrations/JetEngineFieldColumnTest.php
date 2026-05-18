<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\Integrations;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ColumnKit\Integrations\JetEngine\JetEngineFieldColumn;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class JetEngineFieldColumnTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private JetEngineFieldColumn $col;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubs( [
			'__'         => static fn( $s ) => $s,
			'esc_attr'   => static fn( $s ) => $s,
			'esc_html'   => static fn( $s ) => $s,
			'esc_html__' => static fn( $s ) => $s,
		] );

		$fields = [
			[ 'name' => 'subtitle', 'type' => 'text' ],
			[ 'name' => 'rank',     'type' => 'number' ],
			[ 'name' => 'featured', 'type' => 'switcher' ],
			[ 'name' => 'go_date',  'type' => 'date' ],
			[ 'name' => 'go_ts',    'type' => 'date', 'is_timestamp' => true ],
			[
				'name'    => 'tier',
				'type'    => 'select',
				'options' => [
					[ 'key' => 'gold',   'value' => 'Gold' ],
					[ 'key' => 'silver', 'value' => 'Silver' ],
				],
			],
			// Builder persists flags as strings — 'false' must read as single-value (editable).
			[ 'name' => 'tier_single', 'type' => 'select', 'is_multiple' => 'false', 'options' => [ 'a' => 'A' ] ],
			[ 'name' => 'tier_multi',  'type' => 'select', 'is_multiple' => 'true',  'options' => [ 'a' => 'A' ] ],
		];
		$meta_boxes = new class( $fields ) {
			public function __construct( private array $fields ) {}
			public function get_registered_boxes(): array {
				return [ [ 'args' => [ 'meta_fields' => $this->fields ] ] ];
			}
		};
		$engine = new class( $meta_boxes ) {
			public object $meta_boxes;
			public function __construct( object $mb ) { $this->meta_boxes = $mb; }
		};
		Functions\when( 'jet_engine' )->justReturn( $engine );

		$this->col = new JetEngineFieldColumn();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_scalar_types_are_editable_boolean_ish_is_not(): void {
		$this->assertTrue( $this->col->supports_inline_edit( [ 'field_name' => 'subtitle' ] ) );
		$this->assertTrue( $this->col->supports_inline_edit( [ 'field_name' => 'rank' ] ) );
		$this->assertTrue( $this->col->supports_inline_edit( [ 'field_name' => 'go_date' ] ) );
		$this->assertTrue( $this->col->supports_inline_edit( [ 'field_name' => 'tier' ] ) );
		// switcher: storage varies — intentionally read-only.
		$this->assertFalse( $this->col->supports_inline_edit( [ 'field_name' => 'featured' ] ) );
		// timestamp date can't round-trip through <input type=date>.
		$this->assertFalse( $this->col->supports_inline_edit( [ 'field_name' => 'go_ts' ] ) );
	}

	public function test_string_false_multiple_flag_treated_as_single_value(): void {
		// 'is_multiple' => 'false' (string) must NOT exclude the field from inline edit.
		$this->assertTrue( $this->col->supports_inline_edit( [ 'field_name' => 'tier_single' ] ) );
		$this->assertFalse( $this->col->supports_inline_edit( [ 'field_name' => 'tier_multi' ] ) );
	}

	public function test_select_options_normalised_from_builder_rows(): void {
		$this->assertSame(
			[ 'gold' => 'Gold', 'silver' => 'Silver' ],
			$this->col->get_edit_options( [ 'field_name' => 'tier' ] )
		);
		$this->assertSame( 'select', $this->col->get_edit_input_type( [ 'field_name' => 'tier' ] ) );
	}

	public function test_save_text_writes_plain_post_meta(): void {
		Functions\when( 'is_protected_meta' )->justReturn( false );
		Functions\expect( 'update_post_meta' )->once()->with( 12, 'subtitle', 'Hello' );
		$this->col->save_value( 12, 'Hello', [ 'field_name' => 'subtitle' ] );
	}

	public function test_save_empty_deletes_meta(): void {
		Functions\when( 'is_protected_meta' )->justReturn( false );
		Functions\expect( 'delete_post_meta' )->once()->with( 12, 'subtitle' );
		$this->col->save_value( 12, '', [ 'field_name' => 'subtitle' ] );
	}

	public function test_save_select_rejects_unknown_choice(): void {
		Functions\when( 'is_protected_meta' )->justReturn( false );
		Functions\expect( 'update_post_meta' )->never();
		$this->col->save_value( 12, 'bronze', [ 'field_name' => 'tier' ] );
	}

	public function test_save_date_stores_iso(): void {
		Functions\when( 'is_protected_meta' )->justReturn( false );
		Functions\expect( 'update_post_meta' )->once()->with( 3, 'go_date', '2026-05-17' );
		$this->col->save_value( 3, '2026-05-17', [ 'field_name' => 'go_date' ] );
	}

	public function test_save_non_editable_field_never_writes(): void {
		Functions\when( 'is_protected_meta' )->justReturn( false );
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'delete_post_meta' )->never();
		$this->col->save_value( 3, 'true', [ 'field_name' => 'featured' ] );
	}
}
