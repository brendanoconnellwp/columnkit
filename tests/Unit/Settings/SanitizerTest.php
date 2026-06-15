<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\Settings;

use Brain\Monkey;
use ColumnKit\ColumnRegistry;
use ColumnKit\Columns\PostIdColumn;
use ColumnKit\Columns\PostMetaColumn;
use ColumnKit\Settings\Sanitizer;
use PHPUnit\Framework\TestCase;

final class SanitizerTest extends TestCase {
	private Sanitizer $sanitizer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubs( [
			'__'                  => static fn( $s ) => $s,
			'esc_html'            => static fn( $s ) => $s,
			// Mirror WP's sanitize_text_field: strip script/style *contents*, then strip remaining tags.
			'sanitize_text_field' => static function ( $s ) {
				if ( ! is_string( $s ) ) {
					return '';
				}
				$s = preg_replace( '#<(script|style)[^>]*?>.*?</\1>#si', '', $s );
				return trim( strip_tags( $s ) );
			},
			// Mirror WP: '' for empty, the colour for valid #rgb / #rrggbb, null otherwise.
			'sanitize_hex_color' => static function ( $c ) {
				$c = (string) $c;
				if ( $c === '' ) {
					return '';
				}
				return preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $c ) ? $c : null;
			},
		] );

		$registry = new ColumnRegistry();
		$registry->register( new PostIdColumn() );
		$registry->register( new PostMetaColumn() );
		$this->sanitizer = new Sanitizer( $registry );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_drops_unknown_column_types(): void {
		$out = $this->sanitizer->sanitize_columns( [
			[ 'id' => 'a', 'type' => 'evil_type', 'label' => 'X' ],
			[ 'id' => 'b', 'type' => 'post_id', 'label' => 'ID' ],
		] );
		$this->assertCount( 1, $out );
		$this->assertSame( 'post_id', $out[0]['type'] );
	}

	public function test_drops_non_array_input(): void {
		$this->assertSame( [], $this->sanitizer->sanitize_columns( 'pwn' ) );
		$this->assertSame( [], $this->sanitizer->sanitize_columns( null ) );
	}

	public function test_generates_id_when_missing_or_duplicate(): void {
		$out = $this->sanitizer->sanitize_columns( [
			[ 'id' => '', 'type' => 'post_id' ],
			[ 'id' => '', 'type' => 'post_id' ],
		] );
		$this->assertCount( 2, $out );
		$this->assertNotSame( '', $out[0]['id'] );
		$this->assertNotSame( $out[0]['id'], $out[1]['id'] );
	}

	public function test_strips_html_from_label(): void {
		$out = $this->sanitizer->sanitize_columns( [
			[ 'id' => 'a', 'type' => 'post_id', 'label' => '<script>alert(1)</script>Bad' ],
		] );
		$this->assertSame( 'Bad', $out[0]['label'] );
	}

	public function test_falls_back_to_default_label_when_blank(): void {
		$out = $this->sanitizer->sanitize_columns( [
			[ 'id' => 'a', 'type' => 'post_id', 'label' => '' ],
		] );
		$this->assertNotSame( '', $out[0]['label'] );
	}

	public function test_format_block_is_sanitised(): void {
		$out = $this->sanitizer->sanitize_columns( [
			[
				'id'     => 'a',
				'type'   => 'post_id',
				'format' => [
					'align'  => 'right',
					'prefix' => '<b>$</b>',
					'suffix' => ' USD',
					'style'  => 'badge',
					'color'  => '#fff',
					'bg'     => '#1d2327',
				],
			],
		] );
		$fmt = $out[0]['format'];
		$this->assertSame( 'right', $fmt['align'] );
		$this->assertSame( '$', $fmt['prefix'] ); // tags stripped
		$this->assertSame( 'USD', $fmt['suffix'] ); // sanitize_text_field trims
		$this->assertSame( 'badge', $fmt['style'] );
		$this->assertSame( '#fff', $fmt['color'] );
		$this->assertSame( '#1d2327', $fmt['bg'] );
	}

	public function test_format_rejects_bad_values(): void {
		$out = $this->sanitizer->sanitize_columns( [
			[
				'id'     => 'a',
				'type'   => 'post_id',
				'format' => [
					'align' => 'diagonal',
					'style' => 'marquee',
					'color' => 'red; expression(alert(1))',
					'bg'    => 'javascript:void',
				],
			],
		] );
		$fmt = $out[0]['format'];
		$this->assertSame( '', $fmt['align'] );
		$this->assertSame( '', $fmt['style'] );
		$this->assertSame( '', $fmt['color'] ); // invalid hex → null → ''
		$this->assertSame( '', $fmt['bg'] );
	}

	public function test_missing_format_defaults_to_empty_block(): void {
		$out = $this->sanitizer->sanitize_columns( [
			[ 'id' => 'a', 'type' => 'post_id' ],
		] );
		$this->assertArrayHasKey( 'format', $out[0] );
		$this->assertSame( [ 'align' => '', 'prefix' => '', 'suffix' => '', 'style' => '', 'color' => '', 'bg' => '' ], $out[0]['format'] );
	}

	public function test_post_meta_settings_whitelisted(): void {
		$out = $this->sanitizer->sanitize_columns( [
			[
				'id'       => 'a',
				'type'     => 'post_meta',
				'label'    => 'Order',
				'settings' => [
					'meta_key'   => '_order"; DROP TABLE--',
					'value_type' => 'haxx',
				],
			],
		] );
		$this->assertCount( 1, $out );
		// meta_key keeps only [A-Za-z0-9_\-.]
		$this->assertSame( '_orderDROPTABLE--', $out[0]['settings']['meta_key'] );
		// value_type falls back to 'string' when unknown.
		$this->assertSame( 'string', $out[0]['settings']['value_type'] );
	}
}
