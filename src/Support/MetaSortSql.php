<?php
declare( strict_types=1 );

namespace ColumnKit\Support;

/**
 * SQL fragments for "sort by a meta value" on term and user list tables.
 *
 * Why a LEFT JOIN instead of the native `meta_key` + `orderby=meta_value` query vars: both
 * WP_Term_Query and WP_User_Query turn `meta_key` into an INNER JOIN, so every term / user that
 * has no value for the key silently disappears from the list the moment you click the header.
 * A LEFT JOIN keeps them (they sort as NULL — first on ASC, last on DESC).
 */
final class MetaSortSql {
	/**
	 * ORDER BY expression for the joined meta value. $alias is a hard-coded identifier from the
	 * caller; $type is whitelisted here.
	 */
	public static function expression( string $alias, string $type ): string {
		$expr = "{$alias}.meta_value";
		if ( $type === 'numeric' ) {
			return "CAST({$expr} AS DECIMAL(20,6))";
		}
		if ( $type === 'date' ) {
			return "CAST({$expr} AS DATETIME)";
		}
		return $expr;
	}

	public static function order( string $order ): string {
		return strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';
	}
}
