<?php
declare( strict_types=1 );

namespace ColumnKit\ListScreens;

use ColumnKit\Admin\DataExporter;
use ColumnKit\ColumnRegistry;
use ColumnKit\Columns\EditableColumn;
use ColumnKit\Settings\SettingsRepository;
use ColumnKit\Support\ScreenIdentifier;

/**
 * Wires custom columns into admin list tables — posts, media, users, taxonomies.
 *
 * Each list-table kind hooks slightly differently:
 *   - Posts / Media:  manage_*_columns (filter) + manage_*_custom_column (action, echoes)
 *   - Users:          manage_users_columns (filter) + manage_users_custom_column (filter,
 *                     returns the cell HTML — does NOT echo)
 *   - Taxonomies:     manage_edit-{tax}_columns (filter) + manage_{tax}_custom_column
 *                     (filter, returns the cell HTML)
 *
 * Sort / filter / inline-edit / export are wired only for post screens (incl. media) in v1.
 * User and term sort/filter would require pre_user_query / get_terms_args — deferred.
 */
final class ListScreenManager {
	private ?string $active_screen_key = null;
	private string  $active_post_type  = '';

	/** @var array<int, array<string, mixed>> Currently-applied column definitions for this request. */
	private array $active_columns = [];

	public function __construct(
		private ColumnRegistry $registry,
		private SettingsRepository $repository,
		private SortManager $sort_manager,
		private FilterManager $filter_manager,
		private EditManager $edit_manager,
		private DataExporter $data_exporter
	) {}

	public function active_post_type(): string {
		return $this->active_post_type;
	}

	public function edit_manager(): EditManager {
		return $this->edit_manager;
	}

	/** @return array<int, array<string, mixed>> */
	public function active_columns(): array {
		return $this->active_columns;
	}

	public function register_hooks(): void {
		add_action( 'current_screen', [ $this, 'on_current_screen' ] );
		// EditManager has request-lifecycle-independent hooks (AJAX) that must register at boot,
		// not at current_screen — admin-ajax.php never fires current_screen for a list table.
		$this->edit_manager->register_global_hooks();
	}

	public function on_current_screen( $screen ): void {
		if ( ! $screen instanceof \WP_Screen ) {
			return;
		}
		$screen_key = ScreenIdentifier::from_screen( $screen );
		if ( $screen_key === null ) {
			return;
		}
		$columns = $this->repository->get_columns( $screen_key );
		if ( empty( $columns ) ) {
			return;
		}

		$this->active_screen_key = $screen_key;
		$this->active_columns    = $columns;

		// Dispatch by screen kind.
		if ( ScreenIdentifier::is_users( $screen_key ) ) {
			add_filter( 'manage_users_columns',       [ $this, 'filter_columns' ], 20 );
			add_filter( 'manage_users_custom_column', [ $this, 'filter_user_or_term_cell' ], 10, 3 );
			return; // No sort/filter/edit/export on users in v1.
		}

		$taxonomy = ScreenIdentifier::taxonomy( $screen_key );
		if ( $taxonomy !== null ) {
			add_filter( "manage_edit-{$taxonomy}_columns",   [ $this, 'filter_columns' ], 20 );
			add_filter( "manage_{$taxonomy}_custom_column", [ $this, 'filter_user_or_term_cell' ], 10, 3 );
			return; // No sort/filter/edit/export on terms in v1.
		}

		if ( ScreenIdentifier::is_media( $screen_key ) ) {
			$this->active_post_type = 'attachment';
			add_filter( 'manage_media_columns',       [ $this, 'filter_columns' ], 20 );
			add_action( 'manage_media_custom_column', [ $this, 'render_cell' ], 10, 2 );
			add_filter( 'the_posts', [ $this, 'prewarm_meta_cache' ], 10, 2 );
			$this->wire_post_extras( 'attachment', $columns );
			return;
		}

		// Post type (default).
		$post_type = ScreenIdentifier::post_type( $screen_key );
		if ( $post_type === null ) {
			return;
		}
		$this->active_post_type = $post_type;

		add_filter( "manage_{$post_type}_posts_columns",        [ $this, 'filter_columns' ], 20 );
		add_action( "manage_{$post_type}_posts_custom_column", [ $this, 'render_cell' ], 10, 2 );
		add_filter( 'the_posts', [ $this, 'prewarm_meta_cache' ], 10, 2 );

		$this->wire_post_extras( $post_type, $columns );
	}

	/**
	 * @param array<int, array<string, mixed>> $columns
	 */
	private function wire_post_extras( string $post_type, array $columns ): void {
		$this->sort_manager->activate( $post_type, $columns );
		$this->filter_manager->activate( $post_type, $columns );
		$this->edit_manager->activate( $post_type, $columns );
		$this->data_exporter->activate( $post_type, $columns );
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function filter_columns( array $columns ): array {
		foreach ( $this->active_columns as $entry ) {
			$col_key = 'ck_' . ( $entry['id'] ?? '' );
			$columns[ $col_key ] = wp_strip_all_tags( (string) ( $entry['label'] ?? '' ) );
		}
		return $columns;
	}

	/** Post / Media cell renderer — echoes (manage_*_custom_column is an action there). */
	public function render_cell( string $column_name, int $post_id ): void {
		if ( ! str_starts_with( $column_name, 'ck_' ) ) {
			return;
		}
		$id = substr( $column_name, 3 );
		foreach ( $this->active_columns as $entry ) {
			if ( ( $entry['id'] ?? '' ) !== $id ) {
				continue;
			}
			$type = (string) ( $entry['type'] ?? '' );
			$col  = $this->registry->get( $type );
			if ( ! $col ) {
				return;
			}
			$settings = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : [];
			$html     = $col->render( $post_id, $settings );

			if ( $col instanceof EditableColumn ) {
				$raw         = $col->get_raw_value( $post_id, $settings );
				$input_type  = $col->get_edit_input_type( $settings );
				$options     = $col->get_edit_options( $settings );
				$options_attr = '';
				if ( is_array( $options ) && $options !== [] ) {
					$options_attr = ' data-ck-options="' . esc_attr( (string) wp_json_encode( $options ) ) . '"';
				}
				printf(
					'<span class="ck-cell ck-editable" data-ck-col="%s" data-ck-input="%s"%s data-ck-raw="%s">%s</span>',
					esc_attr( $id ),
					esc_attr( $input_type ),
					$options_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_attr( $raw ),
					$html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
			} else {
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			return;
		}
	}

	/**
	 * User / Term cell renderer — these hooks are FILTERS that expect the HTML returned, not
	 * echoed. Signature: ($value, $column_name, $object_id) where $value is what the previous
	 * filter returned ('' if first).
	 */
	public function filter_user_or_term_cell( string $value, string $column_name, int $object_id ): string {
		if ( ! str_starts_with( $column_name, 'ck_' ) ) {
			return $value;
		}
		$id = substr( $column_name, 3 );
		foreach ( $this->active_columns as $entry ) {
			if ( ( $entry['id'] ?? '' ) !== $id ) {
				continue;
			}
			$col = $this->registry->get( (string) ( $entry['type'] ?? '' ) );
			if ( ! $col ) {
				return $value;
			}
			$settings = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : [];
			return (string) $col->render( $object_id, $settings );
		}
		return $value;
	}

	/**
	 * Pre-warm the post-meta cache for visible posts on the current list table. Without this,
	 * every PostMetaColumn render on every row would issue its own meta query — N+1.
	 *
	 * @param \WP_Post[]|null $posts
	 */
	public function prewarm_meta_cache( $posts, $query ) {
		if ( ! is_admin() || ! is_array( $posts ) || empty( $posts ) ) {
			return $posts;
		}
		if ( $this->active_post_type === '' ) {
			return $posts;
		}
		$post_type = isset( $query->query_vars['post_type'] ) ? $query->query_vars['post_type'] : '';
		if ( is_array( $post_type ) ) {
			$post_type = reset( $post_type );
		}
		if ( $post_type !== $this->active_post_type ) {
			return $posts;
		}
		$ids = array_map( static fn( $p ) => (int) $p->ID, $posts );
		if ( $ids ) {
			update_meta_cache( 'post', $ids );
		}
		return $posts;
	}
}
