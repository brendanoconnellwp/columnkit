<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\ListScreens;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ColumnKit\ColumnRegistry;
use ColumnKit\ListScreens\EditManager;
use ColumnKit\Settings\SettingsRepository;
use ColumnKit\Tests\Unit\Fakes\FakeColumn;
use PHPUnit\Framework\TestCase;

/** Thrown by the wp_send_json_* stubs so a test can read what the handler sent. */
final class PostJsonSent extends \RuntimeException {
	public function __construct( public bool $success, public int $status, public mixed $data ) {
		parent::__construct( 'json sent' );
	}
}

/**
 * The Posts branch of ck_inline_save, now routed through Support\InlineSaver (shared with
 * Api::save). Pins the list table's behaviour so the extraction can't drift it.
 */
final class InlinePostSaveTest extends TestCase {
	private const POST = 42;

	/** @var array<string, bool> */
	private array $caps = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		SettingsRepository::reset_cache();
		FakeColumn::$store  = [ self::POST => 'pitch' ];
		FakeColumn::$refuse = false;
		$this->caps         = [ 'edit_post' => true ];

		$post            = new \WP_Post();
		$post->ID        = self::POST;
		$post->post_type = 'post';

		$option = [ 'sets' => [
			'default'   => [ 'label' => 'Default', 'columns' => [
				[ 'id' => 'stage', 'type' => 'fake_select', 'settings' => [] ],
				[ 'id' => 'body', 'type' => 'fake_textarea', 'settings' => [] ],
			] ],
			'editorial' => [ 'label' => 'Editorial', 'roles' => [ 'editor' ], 'columns' => [] ],
		] ];

		Functions\stubs( [
			'__'                      => static fn( $s ) => $s,
			'wp_unslash'              => static fn( $v ) => $v,
			'sanitize_key'            => static fn( $v ) => strtolower( (string) $v ),
			'sanitize_text_field'     => static fn( $v ) => trim( preg_replace( '/\s+/', ' ', (string) $v ) ),
			'sanitize_textarea_field' => static fn( $v ) => trim( (string) $v ),
			'check_ajax_referer'      => true,
			'get_post'                => static fn( $id ) => $id === self::POST ? $post : null,
			'get_option'              => static fn( $name, $default = false ) => $name === 'ck_screen_post_type_post' ? $option : $default,
		] );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => $this->caps[ $cap ] ?? false );
		Functions\when( 'wp_get_current_user' )->alias( static fn() => new class() {
			public array $roles = [ 'author' ];
			public function exists(): bool { return true; }
		} );
		Functions\when( 'wp_send_json_error' )->alias( static function ( $data = null, $status = 200 ) {
			throw new PostJsonSent( false, (int) $status, $data );
		} );
		Functions\when( 'wp_send_json_success' )->alias( static function ( $data = null ) {
			throw new PostJsonSent( true, 200, $data );
		} );
	}

	protected function tearDown(): void {
		$_POST = [];
		SettingsRepository::reset_cache();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function save( array $post ): PostJsonSent {
		$_POST    = $post + [ '_ajax_nonce' => 'n', 'post_id' => (string) self::POST, 'set' => 'default', 'col_id' => 'stage', 'value' => 'draft' ];
		$registry = new ColumnRegistry();
		$registry->register( new FakeColumn( 'fake_select', 'select', [ 'pitch' => 'Pitch', 'draft' => 'Draft' ] ) );
		$registry->register( new FakeColumn( 'fake_textarea', 'textarea' ) );
		try {
			( new EditManager( $registry, new SettingsRepository() ) )->ajax_inline_save();
		} catch ( PostJsonSent $sent ) {
			return $sent;
		}
		$this->fail( 'Handler returned without sending JSON.' );
	}

	public function test_success_returns_html_and_raw(): void {
		$sent = $this->save( [] );
		$this->assertTrue( $sent->success );
		$this->assertSame( [ 'html' => '<b>draft</b>', 'raw' => 'draft' ], $sent->data );
	}

	public function test_without_edit_post_is_403_with_original_message(): void {
		$this->caps = [];
		$sent       = $this->save( [] );
		$this->assertSame( 403, $sent->status );
		$this->assertSame( 'You cannot edit this post.', $sent->data['message'] );
		$this->assertSame( 'pitch', FakeColumn::$store[ self::POST ] );
	}

	public function test_bad_request_missing_post_unknown_column(): void {
		$this->assertSame( 400, $this->save( [ 'col_id' => '' ] )->status );
		$this->assertSame( 404, $this->save( [ 'post_id' => '999' ] )->status );
		$this->assertSame( 'Column not configured.', $this->save( [ 'col_id' => 'nope' ] )->data['message'] );
	}

	public function test_invisible_view_still_falls_back_to_default_on_the_list_table(): void {
		// Unlike Api::save (strict), the popover keeps its old fallback behaviour.
		$this->assertTrue( $this->save( [ 'set' => 'editorial' ] )->success );
	}

	public function test_textarea_keeps_newlines(): void {
		$this->save( [ 'col_id' => 'body', 'value' => "a\nb" ] );
		$this->assertSame( "a\nb", FakeColumn::$store[ self::POST ] );
	}
}
