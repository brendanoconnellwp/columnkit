<?php
declare( strict_types=1 );

namespace ColumnKit\Tests\Unit\Fakes;

use ColumnKit\Columns\BaseColumn;
use ColumnKit\Columns\EditableColumn;

/**
 * An in-memory editable column. `refuse` mimics columns whose save_value() silently ignores a
 * write it won't allow (PostStatusColumn on publish without publish_posts).
 */
final class FakeColumn extends BaseColumn implements EditableColumn {
	/** @var array<int, string> */
	public static array $store = [];
	public static bool $refuse = false;

	public function __construct( private string $type, private string $input, private ?array $options = null ) {}
	public function get_type(): string { return $this->type; }
	public function get_label(): string { return ucfirst( $this->type ); }
	public function render( int $object_id, array $settings ): string { return '<b>' . ( self::$store[ $object_id ] ?? '' ) . '</b>'; }
	public function get_export_value( int $object_id, array $settings ): string { return self::$store[ $object_id ] ?? ''; }
	public function get_raw_value( int $object_id, array $settings ): string { return self::$store[ $object_id ] ?? ''; }
	public function get_edit_input_type( array $settings ): string { return $this->input; }
	public function get_edit_options( array $settings ): ?array { return $this->options; }
	public function render_bulk_edit_field( string $input_name, array $settings ): void {}
	public function save_value( int $post_id, string $raw_value, array $settings ): void {
		if ( ! self::$refuse ) {
			self::$store[ $post_id ] = $raw_value;
		}
	}
}
