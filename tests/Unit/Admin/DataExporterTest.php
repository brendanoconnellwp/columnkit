<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\Admin;

use ColumnKit\Admin\DataExporter;
use PHPUnit\Framework\TestCase;

final class DataExporterTest extends TestCase {
	/** @dataProvider formulaInjectionCases */
	public function test_csv_escape_neutralises_formula_starters( string $input, string $expected ): void {
		$this->assertSame( $expected, DataExporter::csv_escape( $input ) );
	}

	public static function formulaInjectionCases(): iterable {
		yield 'empty unchanged' => [ '', '' ];
		yield 'leading equals'  => [ '=SUM(1+1)', "'=SUM(1+1)" ];
		yield 'leading plus'    => [ '+12', "'+12" ];
		yield 'leading minus'   => [ '-1', "'-1" ];
		yield 'leading at'      => [ '@foo', "'@foo" ];
		yield 'leading tab'     => [ "\tEvil", "'\tEvil" ];
		yield 'leading CR'      => [ "\rEvil", "'\rEvil" ];
		yield 'safe plain'      => [ 'Hello', 'Hello' ];
		yield 'safe number'     => [ '42', '42' ];
		yield 'equals mid-text' => [ 'a=b', 'a=b' ];
		yield 'space first'     => [ ' =evil', ' =evil' ];
	}
}
