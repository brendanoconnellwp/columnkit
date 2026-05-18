<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\Support;

use ColumnKit\Columns\ConditionallyEditableColumn;
use ColumnKit\Columns\EditableColumn;
use ColumnKit\Support\Editability;
use PHPUnit\Framework\TestCase;

final class EditabilityTest extends TestCase {
	public function test_non_editable_column_is_never_editable(): void {
		$col = new \stdClass();
		$this->assertFalse( Editability::is_editable( $col, [] ) );
	}

	public function test_plain_editable_column_is_always_editable(): void {
		$col = new class() implements EditableColumn {
			public function get_raw_value( int $object_id, array $settings ): string { return ''; }
			public function get_edit_input_type( array $settings ): string { return 'text'; }
			public function get_edit_options( array $settings ): ?array { return null; }
			public function render_bulk_edit_field( string $input_name, array $settings ): void {}
			public function save_value( int $post_id, string $raw_value, array $settings ): void {}
		};
		$this->assertTrue( Editability::is_editable( $col, [] ) );
		$this->assertTrue( Editability::is_editable( $col, [ 'anything' => 'x' ] ) );
	}

	public function test_conditional_column_defers_to_supports_inline_edit(): void {
		$col = new class() implements ConditionallyEditableColumn {
			public function get_raw_value( int $object_id, array $settings ): string { return ''; }
			public function get_edit_input_type( array $settings ): string { return 'text'; }
			public function get_edit_options( array $settings ): ?array { return null; }
			public function render_bulk_edit_field( string $input_name, array $settings ): void {}
			public function save_value( int $post_id, string $raw_value, array $settings ): void {}
			public function supports_inline_edit( array $settings ): bool {
				return ( $settings['ok'] ?? false ) === true;
			}
		};
		$this->assertTrue( Editability::is_editable( $col, [ 'ok' => true ] ) );
		$this->assertFalse( Editability::is_editable( $col, [ 'ok' => false ] ) );
		$this->assertFalse( Editability::is_editable( $col, [] ) );
	}
}
