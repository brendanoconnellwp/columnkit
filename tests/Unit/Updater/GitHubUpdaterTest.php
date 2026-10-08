<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\Updater;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ColumnKit\Updater\GitHubUpdater;
use PHPUnit\Framework\TestCase;

final class GitHubUpdaterTest extends TestCase {
	/** @var array<string, mixed> in-memory transient store */
	private array $transients = [];

	private int $http_calls = 0;

	/** @var array<string, mixed>|\WP_Error|null next wp_remote_get response */
	private $next_response = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->transients    = [];
		$this->http_calls    = 0;
		$this->next_response = null;

		Functions\when( 'get_transient' )->alias( fn( $k ) => $this->transients[ $k ] ?? false );
		Functions\when( 'set_transient' )->alias( function ( $k, $v, $ttl = 0 ) {
			$this->transients[ $k ] = $v;
			return true;
		} );
		Functions\when( 'delete_transient' )->alias( function ( $k ) {
			unset( $this->transients[ $k ] );
			return true;
		} );
		Functions\when( 'wp_remote_get' )->alias( function () {
			$this->http_calls++;
			return $this->next_response;
		} );
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v === null );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['code'] ?? 0 );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] ?? '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** A realistic /releases/latest payload with two assets (zip + stray file). */
	private function release_response( string $tag ): array {
		$version = ltrim( $tag, 'v' );
		return [
			'code' => 200,
			'body' => (string) json_encode( [
				'tag_name' => $tag,
				'html_url' => 'https://github.com/brendanoconnellwp/columnkit/releases/tag/' . $tag,
				'assets'   => [
					[ 'id' => 11, 'name' => 'notes.txt', 'browser_download_url' => 'https://example.com/notes.txt' ],
					[ 'id' => 42, 'name' => "columnkit-{$version}.zip", 'browser_download_url' => "https://github.com/brendanoconnellwp/columnkit/releases/download/{$tag}/columnkit-{$version}.zip" ],
				],
			] ),
		];
	}

	public function test_other_plugins_pass_through_without_http(): void {
		$updater = new GitHubUpdater( '' );
		$result  = $updater->check_update( false, [ 'Version' => '1.0.0' ], 'other-plugin/other.php' );
		$this->assertFalse( $result );
		$this->assertSame( 0, $this->http_calls );
	}

	public function test_no_update_when_release_is_not_newer(): void {
		$this->next_response = $this->release_response( 'v0.6.0' );
		$updater = new GitHubUpdater( '' );
		$this->assertFalse( $updater->check_update( false, [ 'Version' => '0.6.0' ], CK_BASENAME ) );
		$this->assertFalse( $updater->check_update( false, [ 'Version' => '0.7.0' ], CK_BASENAME ) );
	}

	public function test_offers_update_with_browser_url_when_no_token(): void {
		$this->next_response = $this->release_response( 'v0.6.0' );
		$updater = new GitHubUpdater( '' );
		$update  = $updater->check_update( false, [ 'Version' => '0.5.3' ], CK_BASENAME );

		$this->assertIsArray( $update );
		$this->assertSame( '0.6.0', $update['version'] );
		$this->assertSame( CK_BASENAME, $update['plugin'] );
		$this->assertSame( 'columnkit', $update['slug'] );
		// Picks the columnkit-{version}.zip asset, not notes.txt.
		$this->assertStringEndsWith( 'columnkit-0.6.0.zip', $update['package'] );
		$this->assertStringContainsString( 'releases/download', $update['package'] );
	}

	public function test_offers_asset_api_url_when_token_set(): void {
		$this->next_response = $this->release_response( 'v0.6.0' );
		$updater = new GitHubUpdater( 'ghp_test_token' );
		$update  = $updater->check_update( false, [ 'Version' => '0.5.3' ], CK_BASENAME );

		$this->assertIsArray( $update );
		$this->assertSame(
			'https://api.github.com/repos/brendanoconnellwp/columnkit/releases/assets/42',
			$update['package']
		);
	}

	public function test_authorize_download_scopes_headers_to_our_assets(): void {
		$updater = new GitHubUpdater( 'ghp_test_token' );

		$ours = $updater->authorize_download( [], 'https://api.github.com/repos/brendanoconnellwp/columnkit/releases/assets/42' );
		$this->assertSame( 'Bearer ghp_test_token', $ours['headers']['Authorization'] );
		$this->assertSame( 'application/octet-stream', $ours['headers']['Accept'] );

		// Any other URL — including other GitHub URLs — is untouched.
		$other = $updater->authorize_download( [ 'headers' => [] ], 'https://api.github.com/repos/someone/else/releases/assets/1' );
		$this->assertArrayNotHasKey( 'Authorization', $other['headers'] );

		// No token → nothing added even for our URL.
		$no_token = ( new GitHubUpdater( '' ) )->authorize_download( [], 'https://api.github.com/repos/brendanoconnellwp/columnkit/releases/assets/42' );
		$this->assertArrayNotHasKey( 'headers', $no_token );
	}

	public function test_api_failure_is_cached_and_offers_nothing(): void {
		$this->next_response = [ 'code' => 403, 'body' => '{"message":"rate limited"}' ];
		$updater = new GitHubUpdater( '' );

		$this->assertFalse( $updater->check_update( false, [ 'Version' => '0.5.3' ], CK_BASENAME ) );
		$this->assertSame( 1, $this->http_calls );

		// Second check hits the cached failure — no new HTTP call.
		$this->assertFalse( $updater->check_update( false, [ 'Version' => '0.5.3' ], CK_BASENAME ) );
		$this->assertSame( 1, $this->http_calls );
	}

	public function test_success_is_cached_across_checks(): void {
		$this->next_response = $this->release_response( 'v0.6.0' );
		$updater = new GitHubUpdater( '' );

		$updater->check_update( false, [ 'Version' => '0.5.3' ], CK_BASENAME );
		$updater->check_update( false, [ 'Version' => '0.5.3' ], CK_BASENAME );
		$this->assertSame( 1, $this->http_calls );
	}

	private function stub_escaping(): void {
		Functions\when( 'esc_html' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_url' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_html__' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
	}

	public function test_plugins_api_passes_through_other_slugs_and_actions(): void {
		$updater = new GitHubUpdater( '' );
		$this->assertFalse( $updater->plugin_information( false, 'plugin_information', (object) [ 'slug' => 'akismet' ] ) );
		$this->assertFalse( $updater->plugin_information( false, 'query_plugins', (object) [ 'slug' => 'columnkit' ] ) );
		$this->assertSame( 0, $this->http_calls );
	}

	public function test_plugins_api_returns_details_for_our_slug(): void {
		$this->stub_escaping();
		$response = $this->release_response( 'v0.7.0' );
		$body     = json_decode( $response['body'], true );
		$body['body']         = "## What's new\n- Term columns\n- See [docs](https://github.com/brendanoconnellwp/columnkit)";
		$body['published_at'] = '2026-10-07T00:00:00Z';
		$response['body']     = (string) json_encode( $body );
		$this->next_response  = $response;

		$info = ( new GitHubUpdater( '' ) )->plugin_information( false, 'plugin_information', (object) [ 'slug' => 'columnkit' ] );

		$this->assertIsObject( $info );
		$this->assertSame( 'columnkit', $info->slug );
		$this->assertSame( '0.7.0', $info->version );
		$this->assertSame( '6.0', $info->requires );
		$this->assertSame( '8.0', $info->requires_php );
		$this->assertSame( '2026-10-07T00:00:00Z', $info->last_updated );
		$this->assertStringEndsWith( 'columnkit-0.7.0.zip', $info->download_link );
		$this->assertStringContainsString( '<h3>', $info->sections['changelog'] );
		$this->assertStringContainsString( '<li>Term columns</li>', $info->sections['changelog'] );
		$this->assertStringContainsString( '<a href="https://github.com/brendanoconnellwp/columnkit"', $info->sections['changelog'] );
		$this->assertNotSame( '', $info->sections['description'] );
	}

	public function test_changelog_markdown_escapes_html(): void {
		$this->stub_escaping();
		$html = GitHubUpdater::markdown_to_html( "<script>alert(1)</script>\n- <img src=x onerror=alert(1)>\n[x](javascript:alert(1))" );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		// Non-http(s) link targets are never turned into anchors.
		$this->assertStringNotContainsString( 'href="javascript', $html );
	}

	public function test_flush_cache_on_plugin_upgrade(): void {
		$this->transients['ck_github_update'] = [ 'tag_name' => 'v0.6.0' ];
		$updater = new GitHubUpdater( '' );

		$updater->flush_cache( null, [ 'type' => 'theme' ] );
		$this->assertArrayHasKey( 'ck_github_update', $this->transients );

		$updater->flush_cache( null, [ 'type' => 'plugin' ] );
		$this->assertArrayNotHasKey( 'ck_github_update', $this->transients );
	}
}
