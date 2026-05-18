<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\Integrations;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ColumnKit\Integrations\MetaBox\MetaBoxFieldColumn;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class MetaBoxFieldColumnTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private MetaBoxFieldColumn $col;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubs( [
			'__'         => static fn( $s ) => $s,
			'esc_attr'   => static fn( $s ) => $s,
			'esc_html'   => static fn( $s ) => $s,
			'esc_html__' => static fn( $s ) => $s,
		] );

		$boxes = [
			[
				'fields' => [
					[ 'id' => 'blurb',  'type' => 'text' ],
					[ 'id' => 'qty',    'type' => 'number' ],
					[ 'id' => 'on',     'type' => 'switch' ],
					[ 'id' => 'pick',   'type' => 'select', 'options' => [ 'x' => 'X', 'y' => 'Y' ] ],
					[ 'id' => 'when',   'type' => 'date' ],
					[ 'id' => 'when_ts','type' => 'date', 'timestamp' => true ],
					[ 'id' => 'cloned', 'type' => 'text', 'clone' => true ],
					[ 'id' => 'gal',    'type' => 'image_advanced' ],
				],
			],
		];
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value = null ) => $hook === 'rwmb_meta_boxes' ? $boxes : $value
		);

		$this->col = new MetaBoxFieldColumn();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_editable_gating_per_type(): void {
		$this->assertTrue( $this->col->supports_inline_edit( [ 'field_id' => 'blurb' ] ) );
		$this->assertTrue( $this->col->supports_inline_edit( [ 'field_id' => 'qty' ] ) );
		$this->assertTrue( $this->col->supports_inline_edit( [ 'field_id' => 'on' ] ) );
		$this->assertTrue( $this->col->supports_inline_edit( [ 'field_id' => 'pick' ] ) );
		$this->assertTrue( $this->col->supports_inline_edit( [ 'field_id' => 'when' ] ) );
		// Excluded: timestamp date, cloned, structurally complex.
		$this->assertFalse( $this->col->supports_inline_edit( [ 'field_id' => 'when_ts' ] ) );
		$this->assertFalse( $this->col->supports_inline_edit( [ 'field_id' => 'cloned' ] ) );
		$this->assertFalse( $this->col->supports_inline_edit( [ 'field_id' => 'gal' ] ) );
	}

	public function test_switch_maps_to_boolean_input(): void {
		$this->assertSame( 'boolean', $this->col->get_edit_input_type( [ 'field_id' => 'on' ] ) );
	}

	public function test_select_options_exposed(): void {
		$this->assertSame(
			[ 'x' => 'X', 'y' => 'Y' ],
			$this->col->get_edit_options( [ 'field_id' => 'pick' ] )
		);
	}

	public function test_save_switch_writes_int_one_or_zero(): void {
		Functions\when( 'is_protected_meta' )->justReturn( false );
		Functions\expect( 'update_post_meta' )->once()->with( 8, 'on', 1 );
		$this->col->save_value( 8, 'yes', [ 'field_id' => 'on' ] );
	}

	public function test_save_switch_empty_is_unchanged(): void {
		Functions\when( 'is_protected_meta' )->justReturn( false );
		Functions\expect( 'update_post_meta' )->never();
		$this->col->save_value( 8, '', [ 'field_id' => 'on' ] );
	}

	public function test_save_select_rejects_unknown_option(): void {
		Functions\when( 'is_protected_meta' )->justReturn( false );
		Functions\expect( 'update_post_meta' )->never();
		$this->col->save_value( 8, 'z', [ 'field_id' => 'pick' ] );
	}

	public function test_save_date_stores_iso(): void {
		Functions\when( 'is_protected_meta' )->justReturn( false );
		Functions\expect( 'update_post_meta' )->once()->with( 4, 'when', '2026-05-17' );
		$this->col->save_value( 4, '2026-05-17', [ 'field_id' => 'when' ] );
	}

	public function test_save_cloned_field_never_writes(): void {
		Functions\when( 'is_protected_meta' )->justReturn( false );
		Functions\expect( 'update_post_meta' )->never();
		$this->col->save_value( 4, 'x', [ 'field_id' => 'cloned' ] );
	}
}
