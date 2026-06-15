<?php
declare( strict_types=1 );

namespace ColumnKit\ListScreens;

use ColumnKit\Admin\DataExporter;
use ColumnKit\ColumnRegistry;
use ColumnKit\Columns\MetaSortable;
use ColumnKit\Settings\SettingsRepository;
use ColumnKit\Support\ColumnPresenter;
use ColumnKit\Support\SetResolver;

/**
 * Sort + export for taxonomy term list tables (WP_Term_Query, not WP_Query).
 *
 * Inline editing of term-meta columns is handled by EditManager (object=term). Term filtering
 * is deferred — edit-tags.php exposes no native "above the table" hook to render filter inputs.
 *
 * Security: export requires the taxonomy's `manage_terms` cap + a nonce; meta-sort order is
 * whitelisted; only MetaSortable columns become sortable. The get_terms_args hook gates hard on
 * being the admin term screen for THIS taxonomy with an explicit ck_ orderby, since get_terms is
 * called widely.
 */
final class TermListManager {
	public const EXPORT_ACTION = 'ck_term_export';
	public const EXPORT_NONCE  = 'ck_term_export';

	/** @var array<int, array<string, mixed>> */
	private array $active_columns = [];
	private string $taxonomy = '';
	private bool $active = false;

	public function __construct( private ColumnRegistry $registry, private SettingsRepository $repository ) {}

	public function register_hooks(): void {
		add_action( 'admin_post_' . self::EXPORT_ACTION, [ $this, 'handle_export' ] );
	}

	/**
	 * @param array<int, array<string, mixed>> $columns
	 */
	public function activate( string $screen_key, string $taxonomy, array $columns ): void {
		$this->active_columns = $columns;
		$this->taxonomy       = $taxonomy;
		$this->active         = true;

		add_filter( "manage_edit-{$taxonomy}_sortable_columns", [ $this, 'register_sortable' ], 20 );
		add_filter( 'get_terms_args', [ $this, 'apply_sort' ], 10, 2 );
		// edit-tags.php has no "above/below the table" action; admin_notices renders inside
		// .wrap and is the most reliable spot. Only hooked on the term screen (via activate()).
		add_action( 'admin_notices', [ $this, 'render_export_buttons' ] );
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function register_sortable( array $columns ): array {
		foreach ( $this->active_columns as $entry ) {
			$col = $this->registry->get( (string) ( $entry['type'] ?? '' ) );
			if ( $col instanceof MetaSortable ) {
				$settings = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : [];
				if ( $col->sort_meta_key( $settings ) !== '' ) {
					$columns[ 'ck_' . ( $entry['id'] ?? '' ) ] = 'ck_' . ( $entry['id'] ?? '' );
				}
			}
		}
		return $columns;
	}

	/**
	 * @param array<string, mixed>  $args
	 * @param array<int, string>|string $taxonomies
	 * @return array<string, mixed>
	 */
	public function apply_sort( array $args, $taxonomies ): array {
		if ( ! is_admin() || ! $this->active ) {
			return $args;
		}
		$taxes = (array) $taxonomies;
		if ( ! in_array( $this->taxonomy, $taxes, true ) ) {
			return $args;
		}
		$orderby = isset( $_GET['orderby'] ) && is_string( $_GET['orderby'] ) ? wp_unslash( $_GET['orderby'] ) : '';
		if ( ! str_starts_with( $orderby, 'ck_' ) ) {
			return $args;
		}
		$col_id = substr( $orderby, 3 );
		foreach ( $this->active_columns as $entry ) {
			if ( ( $entry['id'] ?? '' ) !== $col_id ) {
				continue;
			}
			$col = $this->registry->get( (string) ( $entry['type'] ?? '' ) );
			if ( ! $col instanceof MetaSortable ) {
				return $args;
			}
			$settings = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : [];
			$key      = $col->sort_meta_key( $settings );
			if ( $key === '' ) {
				return $args;
			}
			$order        = isset( $_GET['order'] ) && is_scalar( $_GET['order'] ) && strtoupper( (string) $_GET['order'] ) === 'ASC' ? 'ASC' : 'DESC';
			$args['meta_key'] = $key;
			$args['orderby']  = 'meta_value';
			$args['order']    = $order;
			return $args;
		}
		return $args;
	}

	public function render_export_buttons(): void {
		if ( ! $this->active ) {
			return;
		}
		$params = [];
		foreach ( (array) $_GET as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$params[ sanitize_key( (string) $k ) ] = sanitize_text_field( wp_unslash( (string) $v ) );
			}
		}
		unset( $params['action'], $params['format'], $params['_wpnonce'], $params['paged'] );
		$params['action']   = self::EXPORT_ACTION;
		$params['taxonomy'] = $this->taxonomy;
		$params['_wpnonce'] = wp_create_nonce( self::EXPORT_NONCE );
		$base = admin_url( 'admin-post.php' ) . '?' . http_build_query( $params );

		echo '<div class="notice notice-info inline ck-term-export" style="padding:8px 12px;">';
		echo '<span style="margin-right:8px;">' . esc_html__( 'Export this term list:', 'columnkit' ) . '</span>';
		printf( '<a href="%s" class="button">%s</a> ', esc_url( $base . '&format=csv' ), esc_html__( 'Export CSV', 'columnkit' ) );
		printf( '<a href="%s" class="button">%s</a>', esc_url( $base . '&format=json' ), esc_html__( 'Export JSON', 'columnkit' ) );
		echo '</div>';
	}

	public function handle_export(): void {
		if ( ! check_admin_referer( self::EXPORT_NONCE ) ) {
			wp_die( esc_html__( 'Invalid security token.', 'columnkit' ), '', [ 'response' => 403 ] );
		}
		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( (string) $_GET['taxonomy'] ) ) : '';
		$tax_obj  = $taxonomy !== '' ? get_taxonomy( $taxonomy ) : false;
		if ( ! $tax_obj ) {
			wp_die( esc_html__( 'Unknown taxonomy.', 'columnkit' ), '', [ 'response' => 404 ] );
		}
		if ( ! current_user_can( $tax_obj->cap->manage_terms ) ) {
			wp_die( esc_html__( 'Permission denied.', 'columnkit' ), '', [ 'response' => 403 ] );
		}
		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( (string) $_GET['format'] ) ) : 'csv';
		if ( ! in_array( $format, [ 'csv', 'json' ], true ) ) {
			wp_die( esc_html__( 'Unknown export format.', 'columnkit' ), '', [ 'response' => 400 ] );
		}

		$set_id  = isset( $_GET[ SetResolver::REQUEST_PARAM ] ) && is_string( $_GET[ SetResolver::REQUEST_PARAM ] )
			? SettingsRepository::sanitize_set_id( wp_unslash( $_GET[ SetResolver::REQUEST_PARAM ] ) )
			: SettingsRepository::DEFAULT_SET;
		$columns = $this->repository->get_columns( 'taxonomy:' . $taxonomy, $set_id );
		if ( empty( $columns ) ) {
			wp_die( esc_html__( 'No columns configured for this screen.', 'columnkit' ), '', [ 'response' => 400 ] );
		}

		$max      = (int) apply_filters( 'columnkit/term_export_max_rows', 10000 );
		$term_ids = get_terms( [
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => $max > 0 ? $max : 0,
			'fields'     => 'ids',
		] );
		if ( is_wp_error( $term_ids ) ) {
			$term_ids = [];
		}

		$filename = sanitize_file_name( sprintf( '%s-terms-export-%s.%s', $taxonomy, gmdate( 'Ymd-His' ), $format ) );
		$is_test  = defined( 'CK_TEST_MODE' ) && CK_TEST_MODE;
		if ( ! $is_test ) {
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			nocache_headers();
			header( 'Content-Type: ' . ( $format === 'csv' ? 'text/csv' : 'application/json' ) . '; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		}

		if ( $format === 'csv' ) {
			$this->stream_csv( (array) $term_ids, $columns );
		} else {
			$this->stream_json( (array) $term_ids, $columns );
		}
		if ( ! $is_test ) {
			exit;
		}
	}

	/**
	 * @param array<int, int|string>           $ids
	 * @param array<int, array<string, mixed>> $columns
	 */
	private function stream_csv( array $ids, array $columns ): void {
		echo "\xEF\xBB\xBF";
		$out     = fopen( 'php://output', 'w' );
		$headers = [ 'ID' ];
		foreach ( $columns as $entry ) {
			$headers[] = (string) ( $entry['label'] ?? '' );
		}
		fputcsv( $out, array_map( [ DataExporter::class, 'csv_escape' ], $headers ) );
		foreach ( $ids as $id ) {
			$row = [ (string) (int) $id ];
			foreach ( $columns as $entry ) {
				$row[] = $this->cell_value( (int) $id, $entry );
			}
			fputcsv( $out, array_map( [ DataExporter::class, 'csv_escape' ], $row ) );
		}
		fclose( $out );
	}

	/**
	 * @param array<int, int|string>           $ids
	 * @param array<int, array<string, mixed>> $columns
	 */
	private function stream_json( array $ids, array $columns ): void {
		echo '[';
		$first = true;
		foreach ( $ids as $id ) {
			$entry = [ 'ID' => (int) $id ];
			foreach ( $columns as $col_entry ) {
				$entry[ (string) ( $col_entry['id'] ?? '' ) ] = $this->cell_value( (int) $id, $col_entry );
			}
			echo ( $first ? '' : ',' ) . wp_json_encode( $entry );
			$first = false;
		}
		echo ']';
	}

	/**
	 * @param array<string, mixed> $entry
	 */
	private function cell_value( int $id, array $entry ): string {
		$col = $this->registry->get( (string) ( $entry['type'] ?? '' ) );
		if ( ! $col ) {
			return '';
		}
		$settings = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : [];
		$format   = is_array( $entry['format'] ?? null ) ? $entry['format'] : [];
		return ColumnPresenter::format_export( $col->get_export_value( $id, $settings ), $format );
	}
}
