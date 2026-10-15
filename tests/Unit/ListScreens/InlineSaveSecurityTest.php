<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\ListScreens;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ColumnKit\ColumnRegistry;
use ColumnKit\ListScreens\CoreFields;
use ColumnKit\ListScreens\EditManager;
use ColumnKit\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;

/** Thrown by the wp_send_json_* stubs so a test can read the status the handler chose. */
final class JsonSent extends \RuntimeException {
	public function __construct( public bool $success, public int $status, public mixed $data ) {
		parent::__construct( 'json sent' );
	}
}

/**
 * Authorisation on the Users / Terms inline-save path (security backlog W-1, W-2, W-3).
 * The `ck_inline_save` nonce is printed on the Posts list for every role that can open it,
 * so these checks are the only thing standing between a Contributor and other screens' fields.
 */
final class InlineSaveSecurityTest extends TestCase {
	private const ME = 5;

	/** @var array<string, bool> */
	private array $caps = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs( [
			'__'                  => static fn( $s ) => $s,
			'wp_unslash'          => static fn( $v ) => $v,
			'sanitize_key'        => static fn( $v ) => strtolower( (string) $v ),
			'sanitize_text_field' => static fn( $v ) => trim( (string) $v ),
			'check_ajax_referer'  => true,
			'get_current_user_id' => self::ME,
			'get_option'          => [],
			'taxonomy_exists'     => true,
			'is_wp_error'         => static fn( $v ) => $v instanceof \WP_Error,
		] );
		Functions\when( 'current_user_can' )->alias( fn( $cap ) => $this->caps[ $cap ] ?? false );
		Functions\when( 'wp_send_json_error' )->alias( static function ( $data = null, $status = 200 ) {
			throw new JsonSent( false, (int) $status, $data );
		} );
		Functions\when( 'wp_send_json_success' )->alias( static function ( $data = null ) {
			throw new JsonSent( true, 200, $data );
		} );
		$_POST = [];
	}

	protected function tearDown(): void {
		$_POST = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	private function save( array $post ): JsonSent {
		$_POST = $post + [ '_ajax_nonce' => 'n', 'set' => 'default', 'value' => 'gold' ];
		try {
			( new EditManager( new ColumnRegistry(), new SettingsRepository() ) )->ajax_inline_save();
		} catch ( JsonSent $sent ) {
			return $sent;
		}
		$this->fail( 'Handler returned without sending JSON.' );
	}

	private function user_save( int $user_id ): JsonSent {
		return $this->save( [ 'object' => 'user', 'screen' => 'users', 'object_id' => (string) $user_id, 'col_id' => 'badge' ] );
	}

	public function test_contributor_cannot_write_fields_onto_own_account(): void {
		// Core grants edit_user on yourself to every role — that alone must not be enough.
		$this->caps = [ 'edit_user' => true ];
		$this->assertSame( 403, $this->user_save( self::ME )->status );
	}

	public function test_self_edit_needs_edit_users_even_with_list_users(): void {
		$this->caps = [ 'edit_user' => true, 'list_users' => true ];
		$this->assertSame( 403, $this->user_save( self::ME )->status );
	}

	public function test_admin_passes_authorisation(): void {
		$this->caps = [ 'edit_user' => true, 'list_users' => true, 'edit_users' => true ];
		// Gets past auth to the column lookup (nothing configured → 404, not 403).
		$this->assertSame( 404, $this->user_save( self::ME )->status );
	}

	public function test_term_must_belong_to_screen_taxonomy(): void {
		$term           = new \WP_Term();
		$term->term_id  = 9;
		$term->taxonomy = 'category';
		Functions\when( 'get_term' )->justReturn( $term );
		$this->caps = [ 'edit_term' => true ];

		$sent = $this->save( [ 'object' => 'term', 'screen' => 'taxonomy:genre', 'object_id' => '9', 'col_id' => 'icon' ] );
		$this->assertSame( 404, $sent->status );
		$this->assertSame( 'Term not found.', $sent->data['message'] );
	}

	public function test_bulk_edit_reads_get_submission_and_does_not_reenter(): void {
		$registry = new ColumnRegistry();
		$registry->register( new \ColumnKit\Columns\PostStatusColumn() );
		$em = new EditManager( $registry, new SettingsRepository() );
		$em->activate( 'post', [ [ 'id' => 'st', 'type' => 'post_status', 'settings' => [] ] ] );

		$post              = new \WP_Post();
		$post->ID          = 5;
		$post->post_type   = 'post';
		$post->post_status = 'draft';
		Functions\stubs( [
			'wp_is_post_autosave'  => false,
			'wp_is_post_revision'  => false,
			'wp_verify_nonce'      => true,
			'get_post'             => $post,
			'get_post_type_object' => (object) [ 'cap' => (object) [ 'publish_posts' => 'publish_posts' ] ],
		] );
		$this->caps = [ 'edit_post' => true ];

		// Core's #posts-filter form submits Bulk Edit by GET: values arrive in $_REQUEST only.
		$_REQUEST = [ 'ck_bulk' => [ 'st' => 'pending' ], 'ck_bulk_apply' => [ 'st' => '1' ], '_wpnonce' => 'x' ];
		// wp_update_post fires save_post again for the same post — the nested call must no-op.
		Functions\expect( 'wp_update_post' )->once()->andReturnUsing( static function () use ( $em, $post ) {
			$em->on_save_post( 5, $post, true );
			return 5;
		} );

		$em->on_save_post( 5, $post, true );
		$_REQUEST = [];
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
	}

	public function test_cannot_change_own_email_inline(): void {
		$user     = new \WP_User();
		$user->ID = self::ME;
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\expect( 'wp_update_user' )->never();

		$result = CoreFields::save_user_field( self::ME, 'email', 'attacker@example.com' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ck_self_email', $result->get_error_code() );
	}
}
