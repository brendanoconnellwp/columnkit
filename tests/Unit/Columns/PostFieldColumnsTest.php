<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\Columns;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ColumnKit\Columns\PostParentColumn;
use ColumnKit\Columns\PostStatusColumn;
use ColumnKit\Columns\StickyColumn;
use ColumnKit\Columns\WordCountColumn;
use PHPUnit\Framework\TestCase;

/** Guards on the post-field columns (status, parent, sticky) and the word counter. */
final class PostFieldColumnsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs( [
			'__'                   => static fn( $s ) => $s,
			'excerpt_remove_blocks' => static fn( $s ) => preg_replace( '/<!--.*?-->/s', '', (string) $s ),
			'strip_shortcodes'     => static fn( $s ) => preg_replace( '/\[[^\]]+\]/', '', (string) $s ),
			'wp_strip_all_tags'    => static fn( $s ) => trim( strip_tags( (string) $s ) ),
			'get_post_status_object' => static fn( $s ) => (object) [ 'label' => ucfirst( (string) $s ) ],
		] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function post( array $fields ): \WP_Post {
		$p = new \WP_Post();
		foreach ( $fields as $k => $v ) {
			$p->{$k} = $v;
		}
		return $p;
	}

	public function test_word_count_ignores_markup_blocks_and_shortcodes(): void {
		$content = "<!-- wp:paragraph --><p>Hello <strong>big</strong> world</p><!-- /wp:paragraph -->\n[gallery ids=\"1,2\"]<p>Two&nbsp;more</p>";
		$this->assertSame( 5, WordCountColumn::count_words( $content ) );
		$this->assertSame( 0, WordCountColumn::count_words( '<p> </p>' ) );
	}

	public function test_status_publish_requires_publish_cap(): void {
		Functions\when( 'get_post' )->justReturn( self::post( [ 'ID' => 5, 'post_status' => 'draft', 'post_type' => 'post' ] ) );
		Functions\when( 'get_post_type_object' )->justReturn( (object) [ 'cap' => (object) [ 'publish_posts' => 'publish_posts' ] ] );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'wp_update_post' )->never();
		( new PostStatusColumn() )->save_value( 5, 'publish', [] );

		// Pending needs no publish cap.
		Functions\expect( 'wp_update_post' )->once()->andReturn( 5 );
		( new PostStatusColumn() )->save_value( 5, 'pending', [] );
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
	}

	public function test_status_rejects_unknown_status(): void {
		Functions\when( 'get_post' )->justReturn( self::post( [ 'ID' => 5, 'post_status' => 'draft', 'post_type' => 'post' ] ) );
		Functions\expect( 'wp_update_post' )->never();
		( new PostStatusColumn() )->save_value( 5, 'trash', [] );
		( new PostStatusColumn() )->save_value( 5, 'evil', [] );
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
	}

	public function test_parent_refuses_cycles_and_other_types(): void {
		$page  = self::post( [ 'ID' => 10, 'post_parent' => 0, 'post_type' => 'page' ] );
		$child = self::post( [ 'ID' => 11, 'post_parent' => 10, 'post_type' => 'page' ] );
		$other = self::post( [ 'ID' => 12, 'post_parent' => 0, 'post_type' => 'post' ] );
		Functions\when( 'get_post' )->alias( static fn( $id ) => [ 10 => $page, 11 => $child, 12 => $other ][ $id ] ?? null );
		Functions\when( 'get_post_ancestors' )->alias( static fn( $p ) => $p->ID === 11 ? [ 10 ] : [] );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'wp_update_post' )->never();

		$col = new PostParentColumn();
		$col->save_value( 10, '11', [] ); // Its own child → cycle.
		$col->save_value( 10, '10', [] ); // Itself.
		$col->save_value( 10, '12', [] ); // A post, not a page.
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
	}

	public function test_parent_allows_valid_move(): void {
		$page  = self::post( [ 'ID' => 10, 'post_parent' => 0, 'post_type' => 'page' ] );
		$other = self::post( [ 'ID' => 13, 'post_parent' => 0, 'post_type' => 'page' ] );
		Functions\when( 'get_post' )->alias( static fn( $id ) => [ 10 => $page, 13 => $other ][ $id ] ?? null );
		Functions\when( 'get_post_ancestors' )->justReturn( [] );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'wp_update_post' )->once()->with( [ 'ID' => 10, 'post_parent' => 13 ], true )->andReturn( 10 );
		( new PostParentColumn() )->save_value( 10, '13', [] );
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
	}

	public function test_parent_refuses_unreadable_parent(): void {
		$page    = self::post( [ 'ID' => 10, 'post_parent' => 0, 'post_type' => 'page' ] );
		$private = self::post( [ 'ID' => 14, 'post_parent' => 0, 'post_type' => 'page' ] );
		Functions\when( 'get_post' )->alias( static fn( $id ) => [ 10 => $page, 14 => $private ][ $id ] ?? null );
		Functions\when( 'get_post_ancestors' )->justReturn( [] );
		Functions\when( 'current_user_can' )->alias( static fn( $cap, $id = 0 ) => ! ( $cap === 'read_post' && $id === 14 ) );
		Functions\expect( 'wp_update_post' )->never();
		( new PostParentColumn() )->save_value( 10, '14', [] );
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
	}

	public function test_status_restore_from_trash_requires_delete_post(): void {
		Functions\when( 'get_post' )->justReturn( self::post( [ 'ID' => 5, 'post_status' => 'trash', 'post_type' => 'post' ] ) );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => $cap !== 'delete_post' );
		Functions\expect( 'wp_untrash_post' )->never();
		Functions\expect( 'wp_update_post' )->never();
		( new PostStatusColumn() )->save_value( 5, 'draft', [] );
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
	}

	public function test_status_restore_from_trash_goes_through_untrash(): void {
		$trashed  = self::post( [ 'ID' => 5, 'post_status' => 'trash', 'post_type' => 'post' ] );
		$restored = self::post( [ 'ID' => 5, 'post_status' => 'draft', 'post_type' => 'post' ] );
		$state    = (object) [ 'post' => $trashed ];
		Functions\when( 'get_post' )->alias( static fn() => $state->post );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'wp_untrash_post' )->once()->with( 5 )->andReturnUsing( static function () use ( $state, $restored ) {
			$state->post = $restored;
			return $restored;
		} );
		Functions\expect( 'wp_update_post' )->once()->with( [ 'ID' => 5, 'post_status' => 'pending' ], true )->andReturn( 5 );
		( new PostStatusColumn() )->save_value( 5, 'pending', [] );
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
	}

	public function test_sticky_needs_both_caps(): void {
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_type_object' )->justReturn( (object) [ 'cap' => (object) [ 'edit_others_posts' => 'edit_others_posts', 'publish_posts' => 'publish_posts' ] ] );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => $cap === 'publish_posts' );
		Functions\expect( 'stick_post' )->never();
		( new StickyColumn() )->save_value( 5, '1', [] );
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
	}
}
