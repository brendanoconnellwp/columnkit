<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use WP_Query;

final class PostIdColumn extends BaseColumn implements SortableColumn {
	public function get_type(): string {
		return 'post_id';
	}

	public function get_label(): string {
		return __( 'ID', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'The post or object ID.', 'columnkit' );
	}

	public function render( int $object_id, array $settings ): string {
		return (string) $object_id;
	}

	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		$query->set( 'orderby', 'ID' );
		$query->set( 'order', $order );
	}
}
