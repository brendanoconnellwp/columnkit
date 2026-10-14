<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use ColumnKit\Support\ScreenIdentifier;
use WP_Post;
use WP_Query;

/**
 * Shared plumbing for columns that show a field of the post itself (status, slug, excerpt,
 * parent, menu order …) rather than meta: post-type gating, a safe wp_update_post(), and a
 * one-query-only ORDER BY hook.
 */
abstract class AbstractPostFieldColumn extends BaseColumn {
	/**
	 * Post-type gate. Default: every post type. Override to require a feature
	 * (post_type_supports) or hierarchy.
	 */
	protected function supports_post_type( string $post_type ): bool {
		return true;
	}

	public function applies_to_screen( string $screen_key ): bool {
		$post_type = ScreenIdentifier::post_type( $screen_key );
		return $post_type !== null && $this->supports_post_type( $post_type );
	}

	protected static function post( int $id ): ?WP_Post {
		$post = get_post( $id );
		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * wp_update_post() for one field set. EditManager has already checked edit_post on the row;
	 * EditManager::on_save_post only acts on Bulk Edit submissions, so there is no re-entry.
	 *
	 * @param array<string, mixed> $fields
	 */
	protected static function update_post( int $id, array $fields ): bool {
		$result = wp_update_post( array_merge( [ 'ID' => $id ], $fields ), true );
		return ! is_wp_error( $result ) && (int) $result > 0;
	}

	/**
	 * ORDER BY a SQL expression, for this query only. $expr must be built from trusted
	 * identifiers (never request data); $order is already whitelisted by SortManager.
	 */
	protected static function order_by_sql( WP_Query $query, string $expr, string $order, string $join = '' ): void {
		add_filter(
			'posts_clauses',
			static function ( array $clauses, $q ) use ( $query, $expr, $order, $join ) {
				if ( $q !== $query ) {
					return $clauses;
				}
				global $wpdb;
				if ( $join !== '' ) {
					$clauses['join'] .= ' ' . $join;
				}
				$clauses['orderby'] = "{$expr} {$order}, {$wpdb->posts}.ID DESC";
				return $clauses;
			},
			10,
			2
		);
	}

	/** Muted em-dash, the way core shows "no value". */
	protected static function empty_cell(): string {
		return '<span aria-hidden="true">&#8212;</span>';
	}
}
