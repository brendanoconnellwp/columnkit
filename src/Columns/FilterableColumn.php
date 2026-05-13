<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use WP_Query;

interface FilterableColumn {
	/**
	 * Render the filter control(s) above the list table. Implementations should output HTML directly
	 * (echo). Use `$name_prefix` for input names so multiple filter UIs don't clash, e.g.
	 * "<input name='{$name_prefix}'>" or "<input name='{$name_prefix}__min'>" for a range.
	 *
	 * @param array<string, mixed> $settings   Column instance settings.
	 * @param array<string, string> $current   Current filter values from $_GET, keyed by suffix (''/'min'/'max'/etc.).
	 */
	public function render_filter( string $name_prefix, array $settings, array $current ): void;

	/**
	 * Apply the filter to the query. $values is the sanitised subset of $_GET keyed by suffix.
	 *
	 * @param array<string, string> $values
	 * @param array<string, mixed>  $settings
	 */
	public function apply_filter( WP_Query $query, array $settings, array $values ): void;

	/**
	 * Which value suffixes does this filter accept from $_GET? Returns e.g. ['', 'min', 'max'].
	 * '' means the bare param (ck_f_colid). Others append __suffix (ck_f_colid__min).
	 *
	 * @return string[]
	 */
	public function filter_value_keys(): array;
}
