<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\Settings;

use Brain\Monkey;
use ColumnKit\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class SettingsRepositoryTest extends TestCase {
	/** @var array<string, mixed> in-memory option store */
	private static array $options = [];

	private SettingsRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		self::$options = [];
		SettingsRepository::reset_cache();

		Monkey\Functions\when( 'get_option' )->alias( static function ( $name, $default = false ) {
			return self::$options[ $name ] ?? $default;
		} );
		Monkey\Functions\when( 'update_option' )->alias( static function ( $name, $value ) {
			self::$options[ $name ] = $value;
			return true;
		} );
		Monkey\Functions\when( 'delete_option' )->alias( static function ( $name ) {
			unset( self::$options[ $name ] );
			return true;
		} );
		Monkey\Functions\when( 'wp_rand' )->alias( static fn() => random_int( 0, PHP_INT_MAX ) );

		$this->repo = new SettingsRepository();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---- sanitize_set_id ------------------------------------------------

	public function test_sanitize_set_id_blanks_collapse_to_default(): void {
		$this->assertSame( 'default', SettingsRepository::sanitize_set_id( '' ) );
		$this->assertSame( 'default', SettingsRepository::sanitize_set_id( '!!!' ) );
	}

	public function test_sanitize_set_id_strips_unsafe_chars(): void {
		$this->assertSame( 'setab12', SettingsRepository::sanitize_set_id( 'set-ab.12!' ) );
		$this->assertSame( 'set_ab12', SettingsRepository::sanitize_set_id( 'set_ab12' ) );
	}

	// ---- migration ------------------------------------------------------

	public function test_v1_flat_columns_migrate_into_default_set(): void {
		// Simulate a legacy v1 option.
		self::$options['ck_screen_post_type_post'] = [
			'schema_version' => 1,
			'screen_key'     => 'post_type:post',
			'columns'        => [ [ 'id' => 'col_a', 'type' => 'post_id' ] ],
		];

		$payload = $this->repo->get( 'post_type:post' );
		$this->assertSame( 2, $payload['schema_version'] );
		$this->assertArrayHasKey( 'default', $payload['sets'] );
		$this->assertSame( 'col_a', $payload['sets']['default']['columns'][0]['id'] );
		$this->assertSame( [ [ 'id' => 'col_a', 'type' => 'post_id' ] ], $this->repo->get_columns( 'post_type:post' ) );
	}

	public function test_empty_option_yields_empty_default_set(): void {
		$payload = $this->repo->get( 'post_type:page' );
		$this->assertArrayHasKey( 'default', $payload['sets'] );
		$this->assertSame( [], $payload['sets']['default']['columns'] );
		$this->assertSame( [], $this->repo->get_columns( 'post_type:page' ) );
	}

	public function test_garbage_option_is_normalised(): void {
		self::$options['ck_screen_users'] = 'not-an-array-somehow';
		$payload = $this->repo->get( 'users' );
		$this->assertArrayHasKey( 'default', $payload['sets'] );
	}

	// ---- set CRUD -------------------------------------------------------

	public function test_save_set_creates_and_preserves_other_sets(): void {
		$this->repo->save_set( 'post_type:post', 'default', 'Default', [ [ 'id' => 'd1', 'type' => 'post_id' ] ] );
		$this->repo->save_set( 'post_type:post', 'set_seo', 'SEO view', [ [ 'id' => 's1', 'type' => 'post_meta' ] ] );

		$sets = $this->repo->get_sets( 'post_type:post' );
		$this->assertSame( [ 'default' => 'Default', 'set_seo' => 'SEO view' ], $sets );
		$this->assertSame( 'd1', $this->repo->get_columns( 'post_type:post', 'default' )[0]['id'] );
		$this->assertSame( 's1', $this->repo->get_columns( 'post_type:post', 'set_seo' )[0]['id'] );
	}

	public function test_get_columns_unknown_set_falls_back_to_default(): void {
		$this->repo->save_set( 'post_type:post', 'default', 'Default', [ [ 'id' => 'd1', 'type' => 'post_id' ] ] );
		$this->assertSame( 'd1', $this->repo->get_columns( 'post_type:post', 'nope' )[0]['id'] );
	}

	public function test_delete_non_default_set_removes_it(): void {
		$this->repo->save_set( 'post_type:post', 'set_seo', 'SEO', [ [ 'id' => 's1', 'type' => 'post_meta' ] ] );
		$this->assertTrue( $this->repo->set_exists( 'post_type:post', 'set_seo' ) );
		$this->repo->delete_set( 'post_type:post', 'set_seo' );
		$this->assertFalse( $this->repo->set_exists( 'post_type:post', 'set_seo' ) );
	}

	public function test_delete_default_set_empties_but_keeps_it(): void {
		$this->repo->save_set( 'post_type:post', 'default', 'Default', [ [ 'id' => 'd1', 'type' => 'post_id' ] ] );
		$this->repo->delete_set( 'post_type:post', 'default' );
		$this->assertTrue( $this->repo->set_exists( 'post_type:post', 'default' ) );
		$this->assertSame( [], $this->repo->get_columns( 'post_type:post', 'default' ) );
	}

	public function test_back_compat_save_targets_default_set(): void {
		$this->repo->save_set( 'post_type:post', 'set_seo', 'SEO', [ [ 'id' => 's1', 'type' => 'post_meta' ] ] );
		$this->repo->save( 'post_type:post', [ [ 'id' => 'd1', 'type' => 'post_id' ] ] );

		// Default set updated, SEO set untouched.
		$this->assertSame( 'd1', $this->repo->get_columns( 'post_type:post', 'default' )[0]['id'] );
		$this->assertSame( 's1', $this->repo->get_columns( 'post_type:post', 'set_seo' )[0]['id'] );
	}

	public function test_generate_set_id_is_unique_and_prefixed(): void {
		$this->repo->save_set( 'post_type:post', 'default', 'Default', [] );
		$id = $this->repo->generate_set_id( 'post_type:post' );
		$this->assertStringStartsWith( 'set_', $id );
		$this->assertFalse( $this->repo->set_exists( 'post_type:post', $id ) );
	}

	public function test_persisted_payload_is_v2_shaped(): void {
		$this->repo->save_set( 'post_type:post', 'default', 'Default', [ [ 'id' => 'd1', 'type' => 'post_id' ] ] );
		$stored = self::$options['ck_screen_post_type_post'];
		$this->assertSame( 2, $stored['schema_version'] );
		$this->assertSame( 'post_type:post', $stored['screen_key'] );
		$this->assertArrayHasKey( 'sets', $stored );
		$this->assertArrayHasKey( 'default', $stored['sets'] );
	}
}
