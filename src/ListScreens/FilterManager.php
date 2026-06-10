<?php
declare( strict_types=1 );

namespace ColumnKit\ListScreens;

use ColumnKit\ColumnRegistry;
use ColumnKit\Columns\FilterableColumn;
use WP_Query;

/**
 * Renders filter controls above the list table and applies user-submitted filter values.
 *
 * URL convention:
 *   - ck_f_{col_id}         => bare value (e.g. a text or select filter)
 *   - ck_f_{col_id}__min    => range minimum
 *   - ck_f_{col_id}__max    => range maximum
 *
 * Implementations declare which suffixes they consume via FilterableColumn::filter_value_keys().
 */
final class FilterManager {
	private const PARAM_PREFIX = 'ck_f_';

	/** @var array<int, array<string, mixed>> */
	private array $active_columns = [];

	private string $post_type = '';

	public function __construct( private ColumnRegistry $registry ) {}

	/**
	 * @param array<int, array<string, mixed>> $columns
	 */
	public function activate( string $post_type, array $columns ): void {
		$this->post_type      = $post_type;
		$this->active_columns = $columns;

		add_action( 'restrict_manage_posts', [ $this, 'render_filters' ], 20, 1 );
		add_action( 'pre_get_posts', [ $this, 'apply_filters' ] );
	}

	public function render_filters( string $screen_post_type ): void {
		if ( $screen_post_type !== $this->post_type ) {
			return;
		}
		foreach ( $this->active_columns as $entry ) {
			$col = $this->registry->get( (string) ( $entry['type'] ?? '' ) );
			if ( ! $col instanceof FilterableColumn ) {
				continue;
			}
			$col_id      = (string) ( $entry['id'] ?? '' );
			$name_prefix = self::PARAM_PREFIX . $col_id;
			$settings    = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : [];
			$current     = $this->read_filter_values( $col_id, $col->filter_value_keys() );

			echo '<span class="ck-filter">';
			$col->render_filter( $name_prefix, $settings, $current );
			echo '</span>';
		}
	}

	public function apply_filters( WP_Query $query ): void {
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

		foreach ( $this->active_columns as $entry ) {
			$col = $this->registry->get( (string) ( $entry['type'] ?? '' ) );
			if ( ! $col instanceof FilterableColumn ) {
				continue;
			}
			$col_id   = (string) ( $entry['id'] ?? '' );
			$values   = $this->read_filter_values( $col_id, $col->filter_value_keys() );
			$has_any  = false;
			foreach ( $values as $v ) {
				if ( $v !== '' ) {
					$has_any = true;
					break;
				}
			}
			if ( ! $has_any ) {
				continue;
			}
			$settings = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : [];
			$col->apply_filter( $query, $settings, $values );
		}
	}

	/**
	 * @param string[] $keys
	 * @return array<string, string> keyed by suffix; '' means the bare param
	 */
	private function read_filter_values( string $col_id, array $keys ): array {
		$out = [];
		foreach ( $keys as $suffix ) {
			$param = self::PARAM_PREFIX . $col_id . ( $suffix === '' ? '' : '__' . $suffix );
			$raw   = isset( $_GET[ $param ] ) && is_scalar( $_GET[ $param ] ) ? wp_unslash( (string) $_GET[ $param ] ) : '';
			$out[ $suffix ] = sanitize_text_field( $raw );
		}
		return $out;
	}
}
