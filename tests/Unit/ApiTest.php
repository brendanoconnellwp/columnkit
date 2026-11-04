<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ColumnKit\Api;
use ColumnKit\ColumnRegistry;
use ColumnKit\Settings\SettingsRepository;
use ColumnKit\Tests\Unit\Fakes\FakeColumn;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase {
	private const POST = 42;

	/** @var array<string, bool> */
	private array $caps = [];
	/** @var array<int, string> */
	private array $roles = [ 'author' ];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		SettingsRepository::reset_cache();
		FakeColumn::$store  = [ self::POST => 'pitch' ];
		FakeColumn::$refuse = false;
		$this->caps         = [ 'edit_post' => true ];
		$this->roles        = [ 'author' ];

		$post            = new \WP_Post();
		$post->ID        = self::POST;
		$post->post_type = 'post';

		$option = [
			'schema_version' => 2,
			'sets'           => [
				'default'   => [ 'label' => 'Default', 'columns' => [
					[ 'id' => 'stage', 'type' => 'fake_select', 'settings' => [], 'format' => [ 'prefix' => '#' ] ],
					[ 'id' => 'flag', 'type' => 'fake_bool', 'settings' => [] ],
					[ 'id' => 'note', 'type' => 'fake_text', 'settings' => [] ],
					[ 'id' => 'body', 'type' => 'fake_textarea', 'settings' => [] ],
					[ 'id' => 'ghost', 'type' => 'not_registered', 'settings' => [] ],
				] ],
				'editorial' => [ 'label' => 'Editorial', 'roles' => [ 'editor' ], 'columns' => [
					[ 'id' => 'stage', 'type' => 'fake_select', 'settings' => [] ],
				] ],
			],
		];

		Functions\stubs( [
			'__'                     => static fn( $s ) => $s,
			'esc_html'               => static fn( $s ) => htmlspecialchars( (string) $s ),
			'get_post'               => static fn( $id ) => $id === self::POST ? $post : null,
			'get_option'             => static fn( $name, $default = false ) => $name === 'ck_screen_post_type_post' ? $option : $default,
			'sanitize_text_field'    => static fn( $v ) => trim( preg_replace( '/\s+/', ' ', (string) $v ) ),
			'sanitize_textarea_field'=> static fn( $v ) => trim( (string) $v ),
		] );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => $this->caps[ $cap ] ?? false );
		Functions\when( 'wp_get_current_user' )->alias( function () {
			return new class( $this->roles ) {
				public function __construct( public array $roles ) {}
				public function exists(): bool { return true; }
			};
		} );

		$registry = new ColumnRegistry();
		$registry->register( new FakeColumn( 'fake_select', 'select', [ 'pitch' => 'Pitch', 'draft' => 'Draft' ] ) );
		$registry->register( new FakeColumn( 'fake_bool', 'boolean' ) );
		$registry->register( new FakeColumn( 'fake_text', 'text' ) );
		$registry->register( new FakeColumn( 'fake_textarea', 'textarea' ) );
		Api::use_for_tests( $registry, new SettingsRepository() );
	}

	protected function tearDown(): void {
		Api::use_for_tests( null, null );
		SettingsRepository::reset_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function entry( string $id ): array {
		foreach ( Api::columns( 'post_type:post' ) as $e ) {
			if ( $e['id'] === $id ) {
				return $e;
			}
		}
		throw new \LogicException( "no column $id" );
	}

	private static function http_status( $result ): int {
		return $result instanceof \WP_Error ? (int) $result->get_error_data()['status'] : 0;
	}

	// ---- sets / columns: role-restricted views ------------------------------

	public function test_sets_hide_views_restricted_to_other_roles(): void {
		$this->assertSame( [ 'default' => 'Default' ], Api::sets( 'post_type:post' ) );
		$this->roles = [ 'editor' ];
		$this->assertSame( [ 'default' => 'Default', 'editorial' => 'Editorial' ], Api::sets( 'post_type:post' ) );
	}

	public function test_columns_of_invisible_view_are_empty_not_the_default_set(): void {
		$this->assertSame( [], Api::columns( 'post_type:post', 'editorial' ) );
		$this->assertSame( [], Api::columns( 'post_type:post', 'nope' ) );
		$this->assertCount( 5, Api::columns( 'post_type:post' ) );
	}

	// ---- render / text --------------------------------------------------------

	public function test_render_and_text_apply_format_and_unknown_is_empty(): void {
		$this->assertSame( '<span class="ck-affix ck-prefix">#</span><b>pitch</b>', Api::render( self::entry( 'stage' ), self::POST ) );
		$this->assertSame( '#pitch', Api::text( self::entry( 'stage' ), self::POST ) );
		$this->assertSame( '', Api::render( self::entry( 'ghost' ), self::POST ) );
		$this->assertSame( '', Api::text( self::entry( 'ghost' ), self::POST ) );
	}

	// ---- lane_choices / raw_value ---------------------------------------------

	public function test_lane_choices(): void {
		$this->assertSame( [ 'pitch' => 'Pitch', 'draft' => 'Draft' ], Api::lane_choices( self::entry( 'stage' ) ) );
		$this->assertSame( [ '1' => 'Yes', '0' => 'No' ], Api::lane_choices( self::entry( 'flag' ) ) );
		$this->assertNull( Api::lane_choices( self::entry( 'note' ) ), 'free text is not a lane source' );
		$this->assertNull( Api::lane_choices( self::entry( 'ghost' ) ), 'unknown column' );
	}

	public function test_raw_value(): void {
		$this->assertSame( 'pitch', Api::raw_value( self::entry( 'stage' ), self::POST ) );
		$this->assertSame( '', Api::raw_value( self::entry( 'ghost' ), self::POST ) );
	}

	// ---- save -----------------------------------------------------------------

	public function test_save_succeeds_and_writes(): void {
		$this->assertTrue( Api::save( 'post_type:post', 'default', 'stage', self::POST, 'draft' ) );
		$this->assertSame( 'draft', FakeColumn::$store[ self::POST ] );
	}

	public function test_save_without_edit_post_is_403_and_writes_nothing(): void {
		$this->caps = [];
		$this->assertSame( 403, self::http_status( Api::save( 'post_type:post', 'default', 'stage', self::POST, 'draft' ) ) );
		$this->assertSame( 'pitch', FakeColumn::$store[ self::POST ] );
	}

	public function test_save_rejects_bad_request_missing_post_and_screen_mismatch(): void {
		$this->assertSame( 400, self::http_status( Api::save( 'post_type:post', 'default', '', self::POST, 'x' ) ) );
		$this->assertSame( 400, self::http_status( Api::save( 'post_type:post', 'default', 'stage', 0, 'x' ) ) );
		$this->caps['edit_post'] = true;
		$this->assertSame( 404, self::http_status( Api::save( 'post_type:post', 'default', 'stage', 999, 'x' ) ) );
		$this->assertSame( 400, self::http_status( Api::save( 'post_type:page', 'default', 'stage', self::POST, 'draft' ) ) );
	}

	public function test_save_into_view_user_cannot_see_is_403_not_default_fallback(): void {
		// 'stage' exists in the default set too — a silent fallback would let this succeed.
		$this->assertSame( 403, self::http_status( Api::save( 'post_type:post', 'editorial', 'stage', self::POST, 'draft' ) ) );
		$this->assertSame( 'pitch', FakeColumn::$store[ self::POST ] );
	}

	public function test_save_unconfigured_and_non_editable_columns(): void {
		$this->assertSame( 404, self::http_status( Api::save( 'post_type:post', 'default', 'missing', self::POST, 'x' ) ) );
		$this->assertSame( 400, self::http_status( Api::save( 'post_type:post', 'default', 'ghost', self::POST, 'x' ) ) );
	}

	public function test_silently_refused_write_is_reported_not_faked(): void {
		FakeColumn::$refuse = true;
		$result = Api::save( 'post_type:post', 'default', 'stage', self::POST, 'draft' );
		$this->assertSame( 'ck_not_saved', $result->get_error_code() );
		$this->assertSame( 403, self::http_status( $result ) );
	}

	public function test_saving_the_current_value_is_success_not_refusal(): void {
		FakeColumn::$refuse = true;
		$this->assertTrue( Api::save( 'post_type:post', 'default', 'stage', self::POST, 'pitch' ) );
	}

	public function test_sanitises_by_input_type(): void {
		Api::save( 'post_type:post', 'default', 'note', self::POST, "a\n  b" );
		$this->assertSame( 'a b', FakeColumn::$store[ self::POST ] );
		Api::save( 'post_type:post', 'default', 'body', self::POST, "a\nb" );
		$this->assertSame( "a\nb", FakeColumn::$store[ self::POST ], 'textarea keeps newlines' );
	}
}
