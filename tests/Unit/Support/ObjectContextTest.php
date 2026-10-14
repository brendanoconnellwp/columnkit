<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ColumnKit\Integrations\ACF\ACFFieldColumn;
use ColumnKit\Integrations\MetaBox\MetaBoxFieldColumn;
use ColumnKit\Support\MetaSortSql;
use ColumnKit\Support\ObjectContext;
use PHPUnit\Framework\TestCase;

/**
 * Object context (post / term / user) for custom-field columns, plus the per-integration
 * "does this field group target this screen?" matchers that drive the settings field picker.
 */
final class ObjectContextTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs( [
			'sanitize_key' => static fn( $k ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ),
		] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_object_from_screen(): void {
		$this->assertSame( 'post', ObjectContext::from_screen( 'post_type:post' ) );
		$this->assertSame( 'post', ObjectContext::from_screen( 'media' ) );
		$this->assertSame( 'term', ObjectContext::from_screen( 'taxonomy:genre' ) );
		$this->assertSame( 'user', ObjectContext::from_screen( 'users' ) );
	}

	public function test_settings_for_screen_stamps_taxonomy_only_on_term_screens(): void {
		$this->assertSame( [ 'object' => 'term', 'taxonomy' => 'genre' ], ObjectContext::settings_for_screen( 'taxonomy:genre' ) );
		$this->assertSame( [ 'object' => 'user' ], ObjectContext::settings_for_screen( 'users' ) );
		$this->assertSame( [ 'object' => 'post' ], ObjectContext::settings_for_screen( 'post_type:page' ) );
	}

	public function test_from_settings_defaults_to_post_and_rejects_unknown(): void {
		$this->assertSame( 'post', ObjectContext::from_settings( [] ), 'Pre-0.7 settings have no object key.' );
		$this->assertSame( 'post', ObjectContext::from_settings( [ 'object' => 'comment' ] ) );
		$this->assertSame( 'term', ObjectContext::from_settings( [ 'object' => 'term' ] ) );
		$this->assertSame( 'user', ObjectContext::from_settings( [ 'object' => 'user' ] ) );
	}

	public function test_meta_helpers_route_to_matching_meta_api(): void {
		Functions\expect( 'get_term_meta' )->once()->with( 5, 'k', true )->andReturn( 't' );
		Functions\expect( 'get_user_meta' )->once()->with( 7, 'k', true )->andReturn( 'u' );
		Functions\expect( 'get_post_meta' )->once()->with( 9, 'k', true )->andReturn( 'p' );
		$this->assertSame( 't', ObjectContext::get_meta( 'term', 5, 'k' ) );
		$this->assertSame( 'u', ObjectContext::get_meta( 'user', 7, 'k' ) );
		$this->assertSame( 'p', ObjectContext::get_meta( 'post', 9, 'k' ) );

		Functions\expect( 'update_term_meta' )->once()->with( 5, 'k', 'v' );
		ObjectContext::update_meta( 'term', 5, 'k', 'v' );
		Functions\expect( 'delete_user_meta' )->once()->with( 7, 'k' );
		ObjectContext::delete_meta( 'user', 7, 'k' );
	}

	public function test_protected_user_meta_requires_edit_users(): void {
		Functions\when( 'is_protected_meta' )->justReturn( true );
		Functions\expect( 'current_user_can' )->once()->with( 'edit_users' )->andReturn( false );
		$this->assertFalse( ObjectContext::can_write_key( 'user', 3, '_secret' ) );
	}

	public function test_unprotected_key_always_writable(): void {
		Functions\when( 'is_protected_meta' )->justReturn( false );
		Functions\expect( 'current_user_can' )->never();
		$this->assertTrue( ObjectContext::can_write_key( 'term', 3, 'colour' ) );
	}

	// --- ACF location matching ----------------------------------------------

	private static function acf_group( string $param, string $value, string $op = '==' ): array {
		return [ 'location' => [ [ [ 'param' => $param, 'operator' => $op, 'value' => $value ] ] ] ];
	}

	public function test_acf_taxonomy_rule_targets_only_that_taxonomy(): void {
		$group = self::acf_group( 'taxonomy', 'genre' );
		$this->assertTrue( ACFFieldColumn::group_targets_screen( $group, 'taxonomy:genre' ) );
		$this->assertFalse( ACFFieldColumn::group_targets_screen( $group, 'taxonomy:category' ) );
		$this->assertFalse( ACFFieldColumn::group_targets_screen( $group, 'post_type:post' ) );
		$this->assertFalse( ACFFieldColumn::group_targets_screen( $group, 'users' ) );
	}

	public function test_acf_all_and_not_equal_rules(): void {
		$this->assertTrue( ACFFieldColumn::group_targets_screen( self::acf_group( 'taxonomy', 'all' ), 'taxonomy:genre' ) );
		$not_cat = self::acf_group( 'taxonomy', 'category', '!=' );
		$this->assertTrue( ACFFieldColumn::group_targets_screen( $not_cat, 'taxonomy:genre' ) );
		$this->assertFalse( ACFFieldColumn::group_targets_screen( $not_cat, 'taxonomy:category' ) );
	}

	public function test_acf_user_and_post_rules(): void {
		$this->assertTrue( ACFFieldColumn::group_targets_screen( self::acf_group( 'user_form', 'all' ), 'users' ) );
		$this->assertTrue( ACFFieldColumn::group_targets_screen( self::acf_group( 'user_role', 'editor' ), 'users' ) );
		$this->assertTrue( ACFFieldColumn::group_targets_screen( self::acf_group( 'post_type', 'book' ), 'post_type:book' ) );
		$this->assertFalse( ACFFieldColumn::group_targets_screen( self::acf_group( 'post_type', 'book' ), 'post_type:post' ) );
		$this->assertTrue( ACFFieldColumn::group_targets_screen( self::acf_group( 'attachment', 'all' ), 'media' ) );
	}

	public function test_acf_object_id_convention(): void {
		$this->assertSame( 12, ACFFieldColumn::acf_id( 12, [] ) );
		$this->assertSame( 'term_12', ACFFieldColumn::acf_id( 12, [ 'object' => 'term' ] ) );
		$this->assertSame( 'user_12', ACFFieldColumn::acf_id( 12, [ 'object' => 'user' ] ) );
	}

	// --- Meta Box box matching ----------------------------------------------

	public function test_metabox_box_targeting(): void {
		$term_box = [ 'taxonomies' => [ 'genre' ], 'fields' => [] ];
		$user_box = [ 'type' => 'user', 'fields' => [] ];
		$post_box = [ 'post_types' => [ 'book' ], 'fields' => [] ];
		$default  = [ 'fields' => [] ]; // No post_types → Meta Box defaults to 'post'.

		$this->assertTrue( MetaBoxFieldColumn::box_targets_screen( $term_box, 'taxonomy:genre' ) );
		$this->assertFalse( MetaBoxFieldColumn::box_targets_screen( $term_box, 'taxonomy:category' ) );
		$this->assertFalse( MetaBoxFieldColumn::box_targets_screen( $term_box, 'post_type:post' ) );

		$this->assertTrue( MetaBoxFieldColumn::box_targets_screen( $user_box, 'users' ) );
		$this->assertFalse( MetaBoxFieldColumn::box_targets_screen( $user_box, 'post_type:post' ) );

		$this->assertTrue( MetaBoxFieldColumn::box_targets_screen( $post_box, 'post_type:book' ) );
		$this->assertFalse( MetaBoxFieldColumn::box_targets_screen( $post_box, 'post_type:post' ) );
		$this->assertTrue( MetaBoxFieldColumn::box_targets_screen( $default, 'post_type:post' ) );
		$this->assertFalse( MetaBoxFieldColumn::box_targets_screen( $default, 'users' ) );
	}

	// --- Meta sort SQL --------------------------------------------------------

	public function test_meta_sort_expression_and_order_whitelist(): void {
		$this->assertSame( 'a.meta_value', MetaSortSql::expression( 'a', 'string' ) );
		$this->assertSame( 'CAST(a.meta_value AS DECIMAL(20,6))', MetaSortSql::expression( 'a', 'numeric' ) );
		$this->assertSame( 'CAST(a.meta_value AS DATETIME)', MetaSortSql::expression( 'a', 'date' ) );
		$this->assertSame( 'a.meta_value', MetaSortSql::expression( 'a', 'evil; DROP' ) );
		$this->assertSame( 'ASC', MetaSortSql::order( 'asc' ) );
		$this->assertSame( 'DESC', MetaSortSql::order( 'ASC; DROP TABLE' ) );
	}
}
