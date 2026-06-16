<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\Columns;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ColumnKit\Columns\EditableColumn;
use ColumnKit\Columns\MetaSortable;
use ColumnKit\Columns\TermMetaColumn;
use ColumnKit\Columns\UserMetaColumn;
use PHPUnit\Framework\TestCase;

/**
 * User/Term meta columns gained inline-edit + meta-sort capability in the Users/Taxonomies
 * parity phase. These tests pin the contract: which interfaces they implement, the sort key,
 * and that save routes to the right meta API (incl. empty-clears-meta).
 */
final class MetaColumnEditTest extends TestCase {
	/** @var array<int, array{fn:string, args:array}> */
	private array $calls = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->calls = [];
		Functions\stubs( [
			'__'       => static fn( $s ) => $s,
			'esc_html' => static fn( $s ) => $s,
		] );
		foreach ( [ 'update_user_meta', 'delete_user_meta', 'update_term_meta', 'delete_term_meta' ] as $fn ) {
			Functions\when( $fn )->alias( function ( ...$args ) use ( $fn ) {
				$this->calls[] = [ 'fn' => $fn, 'args' => $args ];
				return true;
			} );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_user_meta_column_implements_capabilities(): void {
		$col = new UserMetaColumn();
		$this->assertInstanceOf( EditableColumn::class, $col );
		$this->assertInstanceOf( MetaSortable::class, $col );
		$this->assertSame( 'mk', $col->sort_meta_key( [ 'meta_key' => 'mk' ] ) );
		$this->assertSame( '', $col->sort_meta_key( [] ) );
		$this->assertSame( 'text', $col->get_edit_input_type( [] ) );
	}

	public function test_user_meta_save_updates_then_clears(): void {
		$col = new UserMetaColumn();
		$col->save_value( 7, 'gold', [ 'meta_key' => 'badge' ] );
		$col->save_value( 7, '', [ 'meta_key' => 'badge' ] );

		$this->assertSame( [ 'update_user_meta', [ 7, 'badge', 'gold' ] ], [ $this->calls[0]['fn'], $this->calls[0]['args'] ] );
		$this->assertSame( [ 'delete_user_meta', [ 7, 'badge' ] ], [ $this->calls[1]['fn'], $this->calls[1]['args'] ] );
	}

	public function test_user_meta_save_noop_without_key(): void {
		$col = new UserMetaColumn();
		$col->save_value( 7, 'x', [] );
		$this->assertSame( [], $this->calls );
	}

	public function test_term_meta_column_implements_capabilities(): void {
		$col = new TermMetaColumn();
		$this->assertInstanceOf( EditableColumn::class, $col );
		$this->assertInstanceOf( MetaSortable::class, $col );
		$this->assertSame( 'tk', $col->sort_meta_key( [ 'meta_key' => 'tk' ] ) );
	}

	public function test_term_meta_save_updates_then_clears(): void {
		$col = new TermMetaColumn();
		$col->save_value( 3, 'star', [ 'meta_key' => 'icon' ] );
		$col->save_value( 3, '', [ 'meta_key' => 'icon' ] );

		$this->assertSame( [ 'update_term_meta', [ 3, 'icon', 'star' ] ], [ $this->calls[0]['fn'], $this->calls[0]['args'] ] );
		$this->assertSame( [ 'delete_term_meta', [ 3, 'icon' ] ], [ $this->calls[1]['fn'], $this->calls[1]['args'] ] );
	}

	public function test_term_meta_raw_value_reads_meta(): void {
		$col = new TermMetaColumn();
		Functions\when( 'get_term_meta' )->justReturn( 'star' );
		$this->assertSame( 'star', $col->get_raw_value( 3, [ 'meta_key' => 'icon' ] ) );
	}
}
