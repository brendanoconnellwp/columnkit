<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\Integrations;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ColumnKit\Columns\ConditionallyEditableColumn;
use ColumnKit\Integrations\ACF\ACFFieldColumn;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Covers the inline-edit behaviour added for the "ACF true/false won't inline edit" bug.
 */
final class ACFFieldColumnTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private const FIELDS = [
		'is_featured' => [ 'name' => 'is_featured', 'type' => 'true_false',  'key' => 'field_tf' ],
		'hero'        => [ 'name' => 'hero',        'type' => 'image',       'key' => 'field_img' ],
		'event_date'  => [ 'name' => 'event_date',  'type' => 'date_picker', 'key' => 'field_dt' ],
		'status'      => [
			'name'       => 'status',
			'type'       => 'select',
			'key'        => 'field_sel',
			'choices'    => [ 'draft' => 'Draft', 'live' => 'Live' ],
			'allow_null' => 1,
		],
		'tags_multi'  => [ 'name' => 'tags_multi', 'type' => 'select', 'multiple' => 1, 'choices' => [ 'a' => 'A' ] ],
	];

	private ACFFieldColumn $col;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubs( [
			'__'          => static fn( $s ) => $s,
			'esc_attr'    => static fn( $s ) => $s,
			'esc_html'    => static fn( $s ) => $s,
			'esc_html__'  => static fn( $s ) => $s,
		] );
		Functions\when( 'acf_get_field' )->alias(
			static fn( $name ) => self::FIELDS[ $name ] ?? false
		);
		$this->col = new ACFFieldColumn();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_implements_conditionally_editable(): void {
		$this->assertInstanceOf( ConditionallyEditableColumn::class, $this->col );
	}

	public function test_true_false_is_inline_editable_as_boolean(): void {
		$settings = [ 'field_name' => 'is_featured' ];
		$this->assertTrue( $this->col->supports_inline_edit( $settings ) );
		$this->assertSame( 'boolean', $this->col->get_edit_input_type( $settings ) );
	}

	public function test_complex_field_is_not_inline_editable(): void {
		$this->assertFalse( $this->col->supports_inline_edit( [ 'field_name' => 'hero' ] ) );
	}

	public function test_multi_value_select_is_not_inline_editable(): void {
		$this->assertFalse( $this->col->supports_inline_edit( [ 'field_name' => 'tags_multi' ] ) );
	}

	public function test_unknown_field_is_not_inline_editable(): void {
		$this->assertFalse( $this->col->supports_inline_edit( [ 'field_name' => 'nope' ] ) );
		$this->assertFalse( $this->col->supports_inline_edit( [] ) );
	}

	public function test_select_options_include_none_when_nullable(): void {
		$opts = $this->col->get_edit_options( [ 'field_name' => 'status' ] );
		$this->assertSame(
			[ '' => '— (none)', 'draft' => 'Draft', 'live' => 'Live' ],
			$opts
		);
		$this->assertSame( 'select', $this->col->get_edit_input_type( [ 'field_name' => 'status' ] ) );
	}

	public function test_get_raw_value_normalises_stored_acf_date_to_iso(): void {
		Functions\when( 'get_field' )->justReturn( '20260517' ); // ACF stores Ymd.
		$this->assertSame(
			'2026-05-17',
			$this->col->get_raw_value( 1, [ 'field_name' => 'event_date' ] )
		);
	}

	public function test_get_raw_value_returns_boolean_string_for_true_false(): void {
		Functions\when( 'get_field' )->justReturn( '1' );
		$this->assertSame( '1', $this->col->get_raw_value( 1, [ 'field_name' => 'is_featured' ] ) );
	}

	public function test_save_true_false_writes_int_via_update_field_by_key(): void {
		Functions\when( 'is_protected_meta' )->justReturn( false );
		Functions\expect( 'update_field' )->once()->with( 'field_tf', 1, 123 );
		$this->col->save_value( 123, 'yes', [ 'field_name' => 'is_featured' ] );
	}

	public function test_save_true_false_empty_is_unchanged_no_write(): void {
		Functions\when( 'is_protected_meta' )->justReturn( false );
		Functions\expect( 'update_field' )->never();
		$this->col->save_value( 123, '', [ 'field_name' => 'is_featured' ] );
	}

	public function test_save_date_converts_iso_to_acf_ymd(): void {
		Functions\when( 'is_protected_meta' )->justReturn( false );
		Functions\expect( 'update_field' )->once()->with( 'field_dt', '20260517', 7 );
		$this->col->save_value( 7, '2026-05-17', [ 'field_name' => 'event_date' ] );
	}

	public function test_save_select_rejects_value_outside_choices(): void {
		Functions\when( 'is_protected_meta' )->justReturn( false );
		Functions\expect( 'update_field' )->never();
		$this->col->save_value( 5, 'bogus', [ 'field_name' => 'status' ] );
	}

	public function test_true_false_false_renders_no_not_blank(): void {
		// ACF formats true_false to a bool — a stored "No" arrives as false.
		Functions\when( 'get_field_object' )->justReturn( [ 'type' => 'true_false', 'value' => false ] );
		$this->assertSame( 'No', $this->col->render( 1, [ 'field_name' => 'is_featured' ] ) );
	}

	public function test_true_false_true_renders_yes(): void {
		Functions\when( 'get_field_object' )->justReturn( [ 'type' => 'true_false', 'value' => true ] );
		$this->assertSame( 'Yes', $this->col->render( 1, [ 'field_name' => 'is_featured' ] ) );
	}

	public function test_save_complex_field_never_writes(): void {
		Functions\when( 'is_protected_meta' )->justReturn( false );
		Functions\expect( 'update_field' )->never();
		Functions\expect( 'update_post_meta' )->never();
		$this->col->save_value( 9, 'whatever', [ 'field_name' => 'hero' ] );
	}
}
