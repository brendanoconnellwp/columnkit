<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

/**
 * A column that can be sorted by a single meta key, on screens whose queries are NOT WP_Query
 * (Users via WP_User_Query, Terms via WP_Term_Query). The list managers read the key and wire
 * the appropriate orderby/meta_key on the native query — columns don't touch the query object,
 * so one interface serves both user-meta and term-meta sorting.
 */
interface MetaSortable {
	/**
	 * The meta key to sort by, or '' to opt out of sorting for this instance.
	 *
	 * @param array<string, mixed> $settings
	 */
	public function sort_meta_key( array $settings ): string;
}
