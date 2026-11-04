<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ColumnKit\Admin\SettingsExporter;
use ColumnKit\ColumnRegistry;
use ColumnKit\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;

/**
 * Importing settings must keep each view's role restriction (security backlog R-3) — dropping it
 * makes a restricted view, and its exports, available to every role on the target site.
 */
final class SettingsImportTest extends TestCase {
	/** @var array<string, mixed> */
	private array $options = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = [];
		Functions\stubs( [
			'sanitize_text_field' => static fn( $v ) => trim( (string) $v ),
			'sanitize_key'        => static fn( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ),
		] );
		Functions\when( 'get_option' )->alias( fn( $k, $d = false ) => $this->options[ $k ] ?? $d );
		Functions\when( 'update_option' )->alias( function ( $k, $v ) {
			$this->options[ $k ] = $v;
			return true;
		} );
		Functions\when( 'wp_roles' )->justReturn( new class() {
			public function get_names(): array {
				return [ 'administrator' => 'Administrator', 'editor' => 'Editor' ];
			}
		} );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_import_keeps_role_restrictions_and_drops_unknown_roles(): void {
		$repo     = new SettingsRepository();
		$exporter = new SettingsExporter( new ColumnRegistry(), $repo );
		$json     = (string) json_encode( [
			'screens' => [
				'users' => [
					'sets' => [
						'default' => [ 'label' => 'Default', 'columns' => [] ],
						'hr'      => [ 'label' => 'HR', 'columns' => [], 'roles' => [ 'editor', 'ghost_role', 7 ] ],
					],
				],
			],
		] );

		$this->assertSame( 1, $exporter->import_from_json( $json ) );
		$this->assertSame( [ 'editor' ], $repo->get( 'users' )['sets']['hr']['roles'] ?? null );
	}
}
