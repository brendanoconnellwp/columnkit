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
