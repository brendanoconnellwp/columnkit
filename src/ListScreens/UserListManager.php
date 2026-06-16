<?php
declare( strict_types=1 );

namespace ColumnKit\ListScreens;

use ColumnKit\Admin\DataExporter;
use ColumnKit\ColumnRegistry;
use ColumnKit\Columns\MetaSortable;
use ColumnKit\Settings\SettingsRepository;
use ColumnKit\Support\ColumnPresenter;
use ColumnKit\Support\SetResolver;
use WP_User_Query;

/**
 * Sort + export for the Users list table (WP_User_Query, not WP_Query).
 *
 * Inline editing of user-meta columns is handled by EditManager (object=user); this manager
 * owns the meta sort wiring and the CSV/JSON export of the current users view.
 *
 * Security: export requires `list_users` + a per-export nonce; meta-sort order is whitelisted
 * to ASC|DESC; only columns that declare a meta key via MetaSortable become sortable.
 */
final class UserListManager {
	public const EXPORT_ACTION = 'ck_user_export';
	public const EXPORT_NONCE  = 'ck_user_export';

	/** @var array<int, array<string, mixed>> */
	private array $active_columns = [];
	private bool  $active = false;

	public function __construct( private ColumnRegistry $registry, private SettingsRepository $repository ) {}

	/** admin-post export handler — registered at boot (admin-post.php has no current_screen). */
	public function register_hooks(): void {
		add_action( 'admin_post_' . self::EXPORT_ACTION, [ $this, 'handle_export' ] );
	}

	/**
	 * @param array<int, array<string, mixed>> $columns
	 */
	public function activate( string $screen_key, array $columns ): void {
		$this->active_columns = $columns;
		$this->active         = true;

		add_filter( 'manage_users_sortable_columns', [ $this, 'register_sortable' ], 20 );
		add_action( 'pre_get_users', [ $this, 'apply_sort' ] );
		add_action( 'restrict_manage_users', [ $this, 'render_export_buttons' ], 30, 1 );
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

	public function apply_sort( WP_User_Query $query ): void {
		if ( ! is_admin() ) {
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
			if ( ! $col instanceof MetaSortable ) {
				return;
			}
			$settings = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : [];
			$key      = $col->sort_meta_key( $settings );
			if ( $key === '' ) {
				return;
			}
			$order = strtoupper( (string) $query->get( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';
			$query->set( 'meta_key', $key );
			$query->set( 'orderby', 'meta_value' );
			$query->set( 'order', $order );
			return;
		}
	}

	public function render_export_buttons( string $which ): void {
		if ( ! $this->active || $which !== 'top' ) {
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
		$params['_wpnonce'] = wp_create_nonce( self::EXPORT_NONCE );
		$base = admin_url( 'admin-post.php' ) . '?' . http_build_query( $params );

		echo '<span class="ck-export-actions" style="margin-left:8px;">';
		printf( '<a href="%s" class="button">%s</a> ', esc_url( $base . '&format=csv' ), esc_html__( 'Export CSV', 'columnkit' ) );
		printf( '<a href="%s" class="button">%s</a>', esc_url( $base . '&format=json' ), esc_html__( 'Export JSON', 'columnkit' ) );
		echo '</span>';
	}

	public function handle_export(): void {
		if ( ! check_admin_referer( self::EXPORT_NONCE ) ) {
			wp_die( esc_html__( 'Invalid security token.', 'columnkit' ), '', [ 'response' => 403 ] );
		}
		if ( ! current_user_can( 'list_users' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'columnkit' ), '', [ 'response' => 403 ] );
		}
		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( (string) $_GET['format'] ) ) : 'csv';
		if ( ! in_array( $format, [ 'csv', 'json' ], true ) ) {
			wp_die( esc_html__( 'Unknown export format.', 'columnkit' ), '', [ 'response' => 400 ] );
		}

		$set_id  = isset( $_GET[ SetResolver::REQUEST_PARAM ] ) && is_string( $_GET[ SetResolver::REQUEST_PARAM ] )
			? SettingsRepository::sanitize_set_id( wp_unslash( $_GET[ SetResolver::REQUEST_PARAM ] ) )
			: SettingsRepository::DEFAULT_SET;
		$columns = $this->repository->get_columns( 'users', $set_id );
		if ( empty( $columns ) ) {
			wp_die( esc_html__( 'No columns configured for this screen.', 'columnkit' ), '', [ 'response' => 400 ] );
		}

		$max  = (int) apply_filters( 'columnkit/user_export_max_rows', 10000 );
		$args = [ 'number' => $max > 0 ? $max : -1, 'fields' => 'ID' ];
		if ( isset( $_GET['role'] ) && is_scalar( $_GET['role'] ) ) {
			$args['role'] = sanitize_text_field( wp_unslash( (string) $_GET['role'] ) );
		}
		if ( isset( $_GET['s'] ) && is_scalar( $_GET['s'] ) ) {
			$args['search'] = '*' . sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) . '*';
		}
		$user_ids = ( new WP_User_Query( $args ) )->get_results();

		$filename = sanitize_file_name( sprintf( 'users-export-%s.%s', gmdate( 'Ymd-His' ), $format ) );
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
			$this->stream_csv( $user_ids, $columns );
		} else {
			$this->stream_json( $user_ids, $columns );
		}
		if ( ! $is_test ) {
			exit;
		}
	}

	/**
	 * @param array<int, int>                  $ids
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
			$row = [ (string) $id ];
			foreach ( $columns as $entry ) {
				$row[] = $this->cell_value( (int) $id, $entry );
			}
			fputcsv( $out, array_map( [ DataExporter::class, 'csv_escape' ], $row ) );
		}
		fclose( $out );
	}

	/**
	 * @param array<int, int>                  $ids
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
