<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ColumnKit\Settings\SettingsRepository;
use ColumnKit\Support\SetResolver;
use PHPUnit\Framework\TestCase;

/** Role-restricted views: who sees which view, and which view each user lands on. */
final class SetResolverTest extends TestCase {
	private array $option;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		SettingsRepository::reset_cache();
		$_GET = [];
		$this->option = [
			'schema_version' => 2,
			'screen_key'     => 'post_type:post',
			'sets'           => [
				'default'  => [ 'label' => 'Default', 'columns' => [] ],
				'set_seo'  => [ 'label' => 'SEO', 'columns' => [] ],
				'set_edit' => [ 'label' => 'Editorial', 'columns' => [], 'roles' => [ 'editor' ] ],
			],
		];
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $name === 'ck_screen_post_type_post' ? $this->option : $default );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
	}

	protected function tearDown(): void {
		$_GET = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	private function as_role( string $role, bool $admin = false ): void {
		Functions\when( 'wp_get_current_user' )->justReturn( new class( $role ) {
			public array $roles;
			public function __construct( string $role ) { $this->roles = [ $role ]; }
			public function exists(): bool { return true; }
		} );
		Functions\when( 'current_user_can' )->alias( static fn( $cap ) => $admin && $cap === 'manage_options' );
	}

	public function test_editor_lands_on_their_restricted_view(): void {
		$this->as_role( 'editor' );
		$this->assertSame( 'set_edit', SetResolver::resolve( new SettingsRepository(), 'post_type:post' ) );
	}

	public function test_author_cannot_see_or_request_editor_view(): void {
		$this->as_role( 'author' );
		$repo = new SettingsRepository();
		$this->assertSame( [ 'default', 'set_seo' ], array_keys( SetResolver::visible_sets( $repo, 'post_type:post' ) ) );
		$_GET['ck_set'] = 'set_edit';
		$this->assertSame( 'default', SetResolver::resolve( $repo, 'post_type:post' ) );
		$this->assertSame( 'default', SetResolver::allowed( $repo, 'post_type:post', 'set_edit' ) );
	}

	public function test_admin_sees_every_view_but_lands_on_default(): void {
		$this->as_role( 'administrator', true );
		$repo = new SettingsRepository();
		$this->assertCount( 3, SetResolver::visible_sets( $repo, 'post_type:post' ) );
		$this->assertSame( 'default', SetResolver::resolve( $repo, 'post_type:post' ) );
	}

	public function test_explicit_switch_to_visible_view_wins(): void {
		$this->as_role( 'editor' );
		$_GET['ck_set'] = 'set_seo';
		$this->assertSame( 'set_seo', SetResolver::resolve( new SettingsRepository(), 'post_type:post' ) );
	}

	public function test_default_view_can_never_be_restricted(): void {
		$this->option['sets']['default']['roles'] = [ 'editor' ];
		SettingsRepository::reset_cache();
		$this->as_role( 'author' );
		$this->assertSame( [], ( new SettingsRepository() )->get_roles( 'post_type:post', 'default' ) );
		$this->assertTrue( SetResolver::can_view( new SettingsRepository(), 'post_type:post', 'default' ) );
	}
}
