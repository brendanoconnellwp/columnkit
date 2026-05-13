<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit;

use Brain\Monkey;
use ColumnKit\ColumnRegistry;
use ColumnKit\Columns\PostIdColumn;
use ColumnKit\Columns\PostMetaColumn;
use PHPUnit\Framework\TestCase;

final class ColumnRegistryTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubs( [
			'__'                  => static fn( $s ) => $s,
			'esc_html'            => static fn( $s ) => $s,
			'esc_html__'          => static fn( $s ) => $s,
			'sanitize_text_field' => static fn( $s ) => is_string( $s ) ? trim( $s ) : '',
		] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_registers_and_retrieves_columns(): void {
		$r = new ColumnRegistry();
		$r->register( new PostIdColumn() );
		$r->register( new PostMetaColumn() );

		$this->assertTrue( $r->has( 'post_id' ) );
		$this->assertTrue( $r->has( 'post_meta' ) );
		$this->assertFalse( $r->has( 'unknown_type' ) );
		$this->assertInstanceOf( PostIdColumn::class, $r->get( 'post_id' ) );
		$this->assertNull( $r->get( 'unknown_type' ) );
		$this->assertSame( [ 'post_id', 'post_meta' ], $r->type_slugs() );
	}

	public function test_re_registering_same_type_overwrites(): void {
		$r = new ColumnRegistry();
		$first  = new PostIdColumn();
		$second = new PostIdColumn();
		$r->register( $first );
		$r->register( $second );
		$this->assertSame( $second, $r->get( 'post_id' ) );
		$this->assertCount( 1, $r->all() );
	}
}
