<?php
/**
 * Phase 8 smoke test — per-column display formatting.
 * Run with:
 *   localwp-wp --site="AI Experiments" eval-file tests/smoke/phase8-formatting.php
 *
 * Exercises Sanitizer::sanitize_format (uses WP's sanitize_hex_color) and ColumnPresenter
 * content/export formatting against the live runtime.
 */

global $pass;
$pass = true;
function check( string $label, bool $ok, string $detail = '' ): void {
	global $pass;
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label;
	if ( $detail !== '' ) { echo "  ($detail)"; }
	echo "\n";
	if ( ! $ok ) { $pass = false; }
}

use ColumnKit\Settings\Sanitizer;
use ColumnKit\Support\ColumnPresenter;

$registry = \ColumnKit\Plugin::instance()->registry();

// -----------------------------------------------------------------------------
// 1. sanitize_format — whitelists + real sanitize_hex_color
// -----------------------------------------------------------------------------
$clean = ( new Sanitizer( $registry ) )->sanitize_columns( [
	[
		'id'     => 'price',
		'type'   => 'post_id',
		'width'  => '120px',
		'format' => [
			'align'  => 'right',
			'prefix' => '$',
			'suffix' => ' USD',
			'style'  => 'badge',
			'color'  => '#ffffff',
			'bg'     => '#1d2327',
		],
	],
	[
		'id'     => 'bad',
		'type'   => 'post_id',
		'format' => [ 'align' => 'diagonal', 'style' => 'blink', 'color' => 'red)expr', 'bg' => '' ],
	],
] );

$f0 = $clean[0]['format'];
check( 'align whitelisted', $f0['align'] === 'right' );
check( 'style whitelisted', $f0['style'] === 'badge' );
check( 'valid hex colour kept', $f0['color'] === '#ffffff' && $f0['bg'] === '#1d2327' );
check( 'width preserved', $clean[0]['width'] === '120px' );

$f1 = $clean[1]['format'];
check( 'bad align rejected', $f1['align'] === '' );
check( 'bad style rejected', $f1['style'] === '' );
check( 'invalid hex rejected', $f1['color'] === '' );

// -----------------------------------------------------------------------------
// 2. ColumnPresenter content formatting
// -----------------------------------------------------------------------------
$html = ColumnPresenter::format( '42', $f0 );
check( 'prefix rendered', strpos( $html, 'ck-prefix' ) !== false && strpos( $html, '$' ) !== false );
check( 'suffix rendered', strpos( $html, 'ck-suffix' ) !== false && strpos( $html, 'USD' ) !== false );
check( 'badge wrapper + colours', strpos( $html, 'ck-badge' ) !== false && strpos( $html, 'background-color:#1d2327' ) !== false );

check( 'empty value stays empty', ColumnPresenter::format( '', $f0 ) === '' );

$xss = ColumnPresenter::format( '7', [ 'prefix' => '<script>alert(1)</script>' ] );
check( 'prefix XSS escaped', strpos( $xss, '<script>' ) === false );

// -----------------------------------------------------------------------------
// 3. Export formatting (plain text)
// -----------------------------------------------------------------------------
check( 'export prefix/suffix plain', ColumnPresenter::format_export( '42', $f0 ) === '$42 USD' );
check( 'export empty stays empty', ColumnPresenter::format_export( '', $f0 ) === '' );

echo "\n" . ( $pass ? 'ALL PASS' : 'SOME FAILED' ) . "\n";
