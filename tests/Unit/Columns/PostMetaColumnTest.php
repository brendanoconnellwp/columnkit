<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\Columns;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ColumnKit\Columns\PostMetaColumn;
use PHPUnit\Framework\TestCase;

final class PostMetaColumnTest extends TestCase {
	private PostMetaColumn $col;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubs( [
			'__'                  => static fn( $s ) => $s,
			'esc_html'            => static fn( $s ) => $s,
			'sanitize_text_field' => static fn( $s ) => is_string( $s ) ? trim( $s ) : '',
			'wp_date'             => static fn( $fmt, $ts ) => gmdate( 'Y-m-d', (int) $ts ),
			'wp_json_encode'      => 'json_encode',
			'number_format_i18n'  => static fn( $n, $d = 0 ) => number_format( (float) $n, (int) $d ),
		] );
		$this->col = new PostMetaColumn();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_renders_empty_when_meta_key_missing(): void {
		$this->assertSame( '', $this->col->render( 1, [] ) );
	}

	public function test_renders_string_value(): void {
		Functions\when( 'get_post_meta' )->justReturn( 'hello' );
		$this->assertSame( 'hello', $this->col->render( 1, [ 'meta_key' => 'foo', 'value_type' => 'string' ] ) );
	}

	public function test_renders_boolean_truthy(): void {
		Functions\when( 'get_post_meta' )->justReturn( '1' );
		$this->assertSame( 'Yes', $this->col->render( 1, [ 'meta_key' => 'foo', 'value_type' => 'boolean' ] ) );

		Functions\when( 'get_post_meta' )->justReturn( 'no' );
		$this->assertSame( 'No', $this->col->render( 1, [ 'meta_key' => 'foo', 'value_type' => 'boolean' ] ) );
	}

	public function test_renders_numeric_with_thousands_separator(): void {
		Functions\when( 'get_post_meta' )->justReturn( '1234.5' );
		$this->assertSame( '1,234.5', $this->col->render( 1, [ 'meta_key' => 'foo', 'value_type' => 'numeric' ] ) );
	}

	public function test_renders_date_from_unix_timestamp(): void {
		Functions\when( 'get_post_meta' )->justReturn( '1700000000' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
		$this->assertSame( '2023-11-14', $this->col->render( 1, [ 'meta_key' => 'foo', 'value_type' => 'date' ] ) );
	}

	public function test_array_values_are_json_encoded(): void {
		Functions\when( 'get_post_meta' )->justReturn( [ 'a', 'b' ] );
		$this->assertSame( '["a","b"]', $this->col->render( 1, [ 'meta_key' => 'foo', 'value_type' => 'string' ] ) );
	}

	public function test_sanitize_strips_dangerous_meta_key_chars(): void {
		$out = $this->col->sanitize_settings( [
			'meta_key'   => "evil';DROP TABLE",
			'value_type' => 'string',
		] );
		// Spaces, semicolons, quotes stripped — only [A-Za-z0-9_\-.] remain.
		$this->assertSame( 'evilDROPTABLE', $out['meta_key'] );
	}

	public function test_sanitize_defaults_value_type_to_string(): void {
		$out = $this->col->sanitize_settings( [ 'meta_key' => 'foo', 'value_type' => 'bogus' ] );
		$this->assertSame( 'string', $out['value_type'] );
	}
}
