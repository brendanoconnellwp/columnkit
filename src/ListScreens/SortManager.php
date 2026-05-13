<?php
declare( strict_types=1 );

namespace ColumnKit\ListScreens;

use ColumnKit\ColumnRegistry;
use ColumnKit\Columns\SortableColumn;
use WP_Query;

/**
 * Marks our columns sortable and intercepts the main query to perform the sort.
 *
 * Security: every raw SQL contribution from columns must use $wpdb->prepare. Order direction
 * is whitelisted to ASC|DESC here — columns never see arbitrary input for it.
 *
 * Targeting: this only fires on the admin main query for the post type that owns the active
 * column set, and only when the user actually clicked one of our column headers (orderby
 * starts with `ck_`). pre_get_posts is wide-blast — we narrow aggressively.
 */
final class SortManager {
	/** @var array<int, array<string, mixed>> Active column entries for the current screen. */
	private array $active_columns = [];

	private string $post_type = '';

	public function __construct( private ColumnRegistry $registry ) {}

	/**
	 * Called by ListScreenManager once it knows the active columns + post type.
	 *
	 * @param array<int, array<string, mixed>> $columns
	 */
	public function activate( string $post_type, array $columns ): void {
		$this->post_type      = $post_type;
		$this->active_columns = $columns;

		add_filter( "manage_edit-{$post_type}_sortable_columns", [ $this, 'register_sortable' ], 20 );
		add_action( 'pre_get_posts', [ $this, 'apply_sort' ] );
	}

	/**
	 * @param array<string, string|array> $columns
	 * @return array<string, string|array>
	 */
	public function register_sortable( array $columns ): array {
		foreach ( $this->active_columns as $entry ) {
			$col = $this->registry->get( (string) ( $entry['type'] ?? '' ) );
			if ( $col instanceof SortableColumn ) {
				$columns[ 'ck_' . ( $entry['id'] ?? '' ) ] = 'ck_' . ( $entry['id'] ?? '' );
			}
		}
		return $columns;
	}

	public function apply_sort( WP_Query $query ): void {
		// Only the main admin query for our post type.
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$qpt = $query->get( 'post_type' );
		if ( is_array( $qpt ) ) {
			$qpt = reset( $qpt );
		}
		if ( $qpt !== $this->post_type ) {
			return;
		}

		$orderby = $query->get( 'orderby' );
		if ( ! is_string( $orderby ) || ! str_starts_with( $orderby, 'ck_' ) ) {
			return;
		}
		$col_id = substr( $orderby, 3 );

		foreach ( $this->active_columns as $entry ) {
			if ( ( $entry['id'] ?? '' ) !== $col_id ) {
				continue;
			}
			$col = $this->registry->get( (string) ( $entry['type'] ?? '' ) );
			if ( ! $col instanceof SortableColumn ) {
				return;
			}
			$order    = strtoupper( (string) $query->get( 'order' ) );
			$order    = $order === 'ASC' ? 'ASC' : 'DESC';
			$settings = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : [];

			$col->apply_sort( $query, $settings, $order );
			return;
		}
	}
}
