<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use WP_Query;

interface SortableColumn {
	/**
	 * Modify the given WP_Query to sort by this column's value.
	 *
	 * @param array<string, mixed> $settings User-configured settings for the column instance.
	 * @param string               $order    Either 'ASC' or 'DESC' (already whitelisted by SortManager).
	 */
	public function apply_sort( WP_Query $query, array $settings, string $order ): void;
}
