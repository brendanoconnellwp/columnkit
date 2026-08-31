<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\Admin;

use Brain\Monkey;
use ColumnKit\Admin\MetaKeySuggestions;
use PHPUnit\Framework\TestCase;

final class MetaKeySuggestionsTest extends TestCase {
	/** @var object fake $wpdb capturing queries */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb = new class() {
			public string $postmeta      = 'wp_postmeta';
			public string $posts         = 'wp_posts';
			public string $usermeta      = 'wp_usermeta';
			public string $termmeta      = 'wp_termmeta';
			public string $term_taxonomy = 'wp_term_taxonomy';

			public string $last_query = '';
			/** @var array<int, mixed> */
			public array $last_args = [];
			/** @var string[] */
			public array $return_keys = [];

			public function prepare( string $query, ...$args ): string {
				$this->last_query = $query;
				$this->last_args  = $args;
				return $query; // placeholder resolution irrelevant for these assertions
			}

			public function get_col( string $query ): array {
				return $this->return_keys;
			}
		};
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_post_type_screen_queries_postmeta_scoped_to_type(): void {
		$this->wpdb->return_keys = [ 'event_start', 'event_end' ];
		$keys = ( new MetaKeySuggestions() )->keys_for_screen( 'post_type:events' );

		$this->assertSame( [ 'event_start', 'event_end' ], $keys );
		$this->assertStringContainsString( 'wp_postmeta', $this->wpdb->last_query );
		$this->assertStringContainsString( 'post_type = %s', $this->wpdb->last_query );
		$this->assertSame( [ 'events', MetaKeySuggestions::LIMIT ], $this->wpdb->last_args );
	}

	public function test_media_screen_maps_to_attachment(): void {
		$this->wpdb->return_keys = [];
		( new MetaKeySuggestions() )->keys_for_screen( 'media' );
		$this->assertSame( 'attachment', $this->wpdb->last_args[0] );
	}

	public function test_users_screen_queries_usermeta(): void {
		$this->wpdb->return_keys = [ 'ck_badge' ];
		$keys = ( new MetaKeySuggestions() )->keys_for_screen( 'users' );
		$this->assertSame( [ 'ck_badge' ], $keys );
		$this->assertStringContainsString( 'wp_usermeta', $this->wpdb->last_query );
	}

	public function test_taxonomy_screen_joins_term_taxonomy(): void {
		$this->wpdb->return_keys = [ 'ck_icon' ];
		$keys = ( new MetaKeySuggestions() )->keys_for_screen( 'taxonomy:category' );
		$this->assertSame( [ 'ck_icon' ], $keys );
		$this->assertStringContainsString( 'wp_term_taxonomy', $this->wpdb->last_query );
		$this->assertSame( 'category', $this->wpdb->last_args[0] );
	}

	public function test_noise_keys_are_blocklisted_but_hidden_keys_kept(): void {
		$this->wpdb->return_keys = [ '_edit_lock', '_price', '_wp_old_slug', 'event_start', '' ];
		$keys = ( new MetaKeySuggestions() )->keys_for_screen( 'post_type:post' );
		$this->assertSame( [ '_price', 'event_start' ], $keys );
	}

	public function test_unknown_screen_shape_returns_empty(): void {
		$this->assertSame( [], ( new MetaKeySuggestions() )->keys_for_screen( 'post_type:' ) );
	}
}
