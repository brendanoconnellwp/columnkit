<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\Support;

use Brain\Monkey;
use ColumnKit\Support\ColumnPresenter;
use PHPUnit\Framework\TestCase;

final class ColumnPresenterTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Monkey\Functions\stubs( [
			'esc_html' => static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ),
			'esc_attr' => static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ),
		] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_empty_value_stays_empty(): void {
		$this->assertSame( '', ColumnPresenter::format( '', [ 'prefix' => '$', 'style' => 'badge' ] ) );
	}

	public function test_no_format_is_passthrough(): void {
		$this->assertSame( '<a>x</a>', ColumnPresenter::format( '<a>x</a>', [] ) );
	}

	public function test_prefix_and_suffix_wrap_value(): void {
		$out = ColumnPresenter::format( '42', [ 'prefix' => '$', 'suffix' => ' USD' ] );
		$this->assertStringContainsString( '<span class="ck-affix ck-prefix">$</span>', $out );
		$this->assertStringContainsString( '<span class="ck-affix ck-suffix"> USD</span>', $out );
		$this->assertStringContainsString( '42', $out );
		// prefix precedes suffix.
		$this->assertLessThan( strpos( $out, 'ck-suffix' ), strpos( $out, 'ck-prefix' ) );
	}

	public function test_prefix_is_escaped(): void {
		$out = ColumnPresenter::format( '42', [ 'prefix' => '<script>' ] );
		$this->assertStringNotContainsString( '<script>', $out );
		$this->assertStringContainsString( '&lt;script&gt;', $out );
	}

	public function test_badge_wraps_with_colours(): void {
		$out = ColumnPresenter::format( 'Live', [ 'style' => 'badge', 'color' => '#fff', 'bg' => '#1d2327' ] );
		$this->assertStringContainsString( 'class="ck-badge"', $out );
		$this->assertStringContainsString( 'color:#fff', $out );
		$this->assertStringContainsString( 'background-color:#1d2327', $out );
		$this->assertStringContainsString( 'Live', $out );
	}

	public function test_colour_without_badge_uses_colored_class(): void {
		$out = ColumnPresenter::format( 'x', [ 'color' => '#abc' ] );
		$this->assertStringContainsString( 'class="ck-colored"', $out );
		$this->assertStringContainsString( 'color:#abc', $out );
	}

	public function test_export_format_is_plain_text(): void {
		$this->assertSame( '$42 USD', ColumnPresenter::format_export( '42', [ 'prefix' => '$', 'suffix' => ' USD' ] ) );
		$this->assertSame( '', ColumnPresenter::format_export( '', [ 'prefix' => '$' ] ) );
	}
}
