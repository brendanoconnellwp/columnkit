<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\Columns;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ColumnKit\Columns\FeaturedImageColumn;
use ColumnKit\Columns\InlineOnlyEditable;
use ColumnKit\Columns\TaxonomyColumn;
use ColumnKit\Support\Editability;
use ColumnKit\Support\TermAssigner;
use PHPUnit\Framework\TestCase;

/**
 * Featured image (media picker) and taxonomy (term checklist) columns are inline-editable, but
 * stay out of WP's Bulk Edit panel.
 */
final class InlinePickerColumnsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs( [ '__' => static fn( $s ) => $s ] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_both_are_editable_but_inline_only(): void {
		foreach ( [ new FeaturedImageColumn(), new TaxonomyColumn() ] as $col ) {
			$this->assertInstanceOf( InlineOnlyEditable::class, $col );
			$this->assertTrue( Editability::is_editable( $col, [] ) );
		}
		$this->assertSame( 'media', ( new FeaturedImageColumn() )->get_edit_input_type( [] ) );
		$this->assertSame( 'terms', ( new TaxonomyColumn() )->get_edit_input_type( [] ) );
		$this->assertSame( [ 'taxonomy' => 'genre' ], ( new TaxonomyColumn() )->edit_attributes( [ 'taxonomy' => 'genre' ] ) );
	}

	public function test_featured_image_remove_and_set(): void {
		Functions\when( 'get_post_type' )->alias( static fn( $id ) => $id === 9 ? 'attachment' : 'post' );
		Functions\when( 'post_type_supports' )->justReturn( true );
		Functions\expect( 'delete_post_thumbnail' )->once()->with( 5 );
		( new FeaturedImageColumn() )->save_value( 5, '0', [] );

		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\expect( 'set_post_thumbnail' )->once()->with( 5, 9 );
		( new FeaturedImageColumn() )->save_value( 5, '9', [] );
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
	}

	public function test_featured_image_rejects_non_image_attachment(): void {
		Functions\when( 'get_post_type' )->alias( static fn( $id ) => $id === 9 ? 'attachment' : 'post' );
		Functions\when( 'post_type_supports' )->justReturn( true );
		Functions\when( 'wp_attachment_is_image' )->justReturn( false );
		Functions\expect( 'set_post_thumbnail' )->never();
		( new FeaturedImageColumn() )->save_value( 5, '9', [] );
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
	}

	public function test_featured_image_ignored_when_type_lacks_thumbnails(): void {
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'post_type_supports' )->justReturn( false );
		Functions\expect( 'delete_post_thumbnail' )->never();
		( new FeaturedImageColumn() )->save_value( 5, '0', [] );
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
	}

	public function test_core_taxonomy_column_keys_round_trip(): void {
		foreach ( [ 'category' => 'categories', 'post_tag' => 'tags', 'genre' => 'taxonomy-genre' ] as $tax => $key ) {
			$this->assertSame( $key, TermAssigner::core_column_key( $tax ) );
			$this->assertSame( $tax, TermAssigner::taxonomy_from_core_key( $key ) );
		}
		$this->assertSame( '', TermAssigner::taxonomy_from_core_key( 'title' ) );
	}
}
