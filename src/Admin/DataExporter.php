<?php
declare( strict_types=1 );

namespace ColumnKit\Admin;

use ColumnKit\ColumnRegistry;
use ColumnKit\Columns\FilterableColumn;
use ColumnKit\Columns\SortableColumn;
use ColumnKit\Settings\SettingsRepository;
use WP_Query;

/**
 * Exports list-table data to CSV or JSON.
 *
 * Security model:
 *   - admin_post_ck_export handler is gated by the post type's `edit_posts` capability,
 *     plus a per-export nonce.
 *   - CSV output prefixes formula characters (`=`, `+`, `-`, `@`, `\t`, `\r`) with `'` so
 *     spreadsheet applications don't execute exported strings as formulas.
 *   - Output streams to php://output. We don't build a giant in-memory string.
 *   - Filename is run through sanitize_file_name() to prevent header injection.
 *
 * Filter/sort preservation:
 *   - The export URL preserves the user's current $_GET state (filters, orderby, etc.).
 *   - A one-shot pre_get_posts hook applies the same logic the list table would.
 */
final class DataExporter {
	public const ACTION = 'ck_export';
	public const NONCE  = 'ck_export';

	/** @var array<int, array<string, mixed>> */
	private array $active_columns = [];
	private string $active_post_type = '';

	public function __construct(
		private ColumnRegistry $registry,
		private SettingsRepository $repository
	) {}

	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_export' ] );
	}

	/**
	 * Called by ListScreenManager once the active screen + columns are known. Registers the
	 * restrict_manage_posts hook that renders the Export buttons above the list table.
	 *
	 * @param array<int, array<string, mixed>> $columns
	 */
	public function activate( string $post_type, array $columns ): void {
		$this->active_post_type = $post_type;
		$this->active_columns   = $columns;
		add_action( 'restrict_manage_posts', [ $this, 'render_export_buttons' ], 30, 2 );
	}

	public function render_export_buttons( string $post_type, string $which = 'top' ): void {
		if ( $post_type !== $this->active_post_type ) {
			return;
		}
		if ( $which !== 'top' ) {
			return; // only render above the table
		}

		// Preserve current $_GET (filters, orderby, etc.), then layer export-specific params.
		$params = [];
		foreach ( (array) $_GET as $k => $v ) {
			if ( is_scalar( $v ) ) {
				$params[ sanitize_key( (string) $k ) ] = sanitize_text_field( wp_unslash( (string) $v ) );
			}
		}
		unset( $params['action'], $params['format'], $params['_wpnonce'], $params['paged'] );
		$params['action']   = self::ACTION;
		$params['_wpnonce'] = wp_create_nonce( self::NONCE );

		$base = admin_url( 'admin-post.php' ) . '?' . http_build_query( $params );

		echo '<span class="ck-export-actions" style="margin-left:8px;">';
		printf(
			'<a href="%s" class="button">%s</a> ',
			esc_url( $base . '&format=csv' ),
			esc_html__( 'Export CSV', 'columnkit' )
		);
		printf(
			'<a href="%s" class="button">%s</a>',
			esc_url( $base . '&format=json' ),
			esc_html__( 'Export JSON', 'columnkit' )
		);
		echo '</span>';
	}

	public function handle_export(): void {
		if ( ! check_admin_referer( self::NONCE ) ) {
			wp_die( esc_html__( 'Invalid security token.', 'columnkit' ), '', [ 'response' => 403 ] );
		}

		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['post_type'] ) ) : '';
		$format    = isset( $_GET['format'] )    ? sanitize_key( wp_unslash( (string) $_GET['format'] ) )    : 'csv';
		if ( ! in_array( $format, [ 'csv', 'json' ], true ) ) {
			wp_die( esc_html__( 'Unknown export format.', 'columnkit' ), '', [ 'response' => 400 ] );
		}
		$pt_obj = get_post_type_object( $post_type );
		if ( ! $pt_obj ) {
			wp_die( esc_html__( 'Unknown post type.', 'columnkit' ), '', [ 'response' => 404 ] );
		}
		if ( ! current_user_can( $pt_obj->cap->edit_posts ) ) {
			wp_die( esc_html__( 'Permission denied.', 'columnkit' ), '', [ 'response' => 403 ] );
		}

		$screen_key = 'post_type:' . $post_type;
		$columns    = $this->repository->get_columns( $screen_key );
		if ( empty( $columns ) ) {
			wp_die( esc_html__( 'No columns configured for this screen.', 'columnkit' ), '', [ 'response' => 400 ] );
		}

		// Build query — one-shot pre_get_posts hook applies filters + sort, then we remove it.
		$query   = new WP_Query();
		$apply   = function ( $q ) use ( $query, $columns ) {
			if ( $q !== $query ) {
				return;
			}
			// Filters
			foreach ( $columns as $entry ) {
				$col = $this->registry->get( (string) ( $entry['type'] ?? '' ) );
				if ( ! $col instanceof FilterableColumn ) {
					continue;
				}
				$values  = $this->read_filter_values( (string) ( $entry['id'] ?? '' ), $col->filter_value_keys() );
				$has_any = false;
				foreach ( $values as $v ) {
					if ( $v !== '' ) {
						$has_any = true;
						break;
					}
				}
				if ( $has_any ) {
					$settings = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : [];
					$col->apply_filter( $q, $settings, $values );
				}
			}
			// Sort
			$orderby = isset( $_GET['orderby'] ) ? (string) $_GET['orderby'] : '';
			if ( str_starts_with( $orderby, 'ck_' ) ) {
				$col_id = substr( $orderby, 3 );
				foreach ( $columns as $entry ) {
					if ( ( $entry['id'] ?? '' ) !== $col_id ) {
						continue;
					}
					$col = $this->registry->get( (string) ( $entry['type'] ?? '' ) );
					if ( $col instanceof SortableColumn ) {
						$order    = strtoupper( (string) ( $_GET['order'] ?? 'DESC' ) );
						$order    = $order === 'ASC' ? 'ASC' : 'DESC';
						$settings = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : [];
						$col->apply_sort( $q, $settings, $order );
					}
					break;
				}
			}
		};
		add_action( 'pre_get_posts', $apply );

		// Mirror the admin list table's access scoping. Without this a low-privileged user
		// (e.g. a Contributor, who has edit_posts) could pass ?post_status=draft&author=N to
		// the export and exfiltrate other users' unpublished posts + every configured column,
		// since WP_Query does NOT author-restrict protected statuses unless perm=editable and
		// the export builds its own query outside edit.php's scoping.
		$can_edit_others = current_user_can( $pt_obj->cap->edit_others_posts );

		$base_args = [
			'post_type'      => $post_type,
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'post_status'    => 'any',
			'perm'           => 'editable',
		];

		// Users who can't edit others' posts must not be able to widen the result set via
		// author/post_status — those GET vars are only honoured for users who already see
		// everything in the list table.
		$passthrough = $can_edit_others
			? [ 'author', 'm', 'cat', 'tag', 'post_status', 's' ]
			: [ 'm', 'cat', 'tag', 's' ];
		foreach ( $passthrough as $key ) {
			if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ) {
				$base_args[ $key ] = sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
			}
		}
		if ( ! $can_edit_others ) {
			$base_args['author'] = get_current_user_id();
		}
		$query->query( $base_args );

		remove_action( 'pre_get_posts', $apply );

		// Build filename + headers.
		$filename = sanitize_file_name(
			sprintf( '%s-export-%s.%s', $post_type, gmdate( 'Ymd-His' ), $format )
		);

		$is_test = defined( 'CK_TEST_MODE' ) && CK_TEST_MODE;

		// Discard any output buffers WP / themes / other plugins may have started — but skip
		// this in test mode so PHPUnit / wp-cli output capture keeps working.
		if ( ! $is_test ) {
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			nocache_headers();

			if ( $format === 'csv' ) {
				header( 'Content-Type: text/csv; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			} else {
				header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			}
		}

		if ( $format === 'csv' ) {
			$this->stream_csv( $query->posts, $columns );
		} else {
			$this->stream_json( $query->posts, $columns );
		}

		if ( ! $is_test ) {
			exit;
		}
	}

	/**
	 * @param \WP_Post[]              $posts
	 * @param array<int, array<string, mixed>> $columns
	 */
	private function stream_csv( array $posts, array $columns ): void {
		echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel decodes non-ASCII correctly.
		$out = fopen( 'php://output', 'w' );

		$headers = [ 'ID' ];
		foreach ( $columns as $entry ) {
			$headers[] = (string) ( $entry['label'] ?? '' );
		}
		fputcsv( $out, array_map( [ self::class, 'csv_escape' ], $headers ) );

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$row = [ (string) $post->ID ];
			foreach ( $columns as $entry ) {
				$col = $this->registry->get( (string) ( $entry['type'] ?? '' ) );
				if ( ! $col ) {
					$row[] = '';
					continue;
				}
				$settings = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : [];
				$row[]    = $col->get_export_value( $post->ID, $settings );
			}
			fputcsv( $out, array_map( [ self::class, 'csv_escape' ], $row ) );
		}
		fclose( $out );
	}

	/**
	 * @param \WP_Post[]              $posts
	 * @param array<int, array<string, mixed>> $columns
	 */
	private function stream_json( array $posts, array $columns ): void {
		echo '[';
		$first = true;
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$entry = [ 'ID' => $post->ID ];
			foreach ( $columns as $col_entry ) {
				$col = $this->registry->get( (string) ( $col_entry['type'] ?? '' ) );
				if ( ! $col ) {
					continue;
				}
				$col_id              = (string) ( $col_entry['id'] ?? '' );
				$settings            = is_array( $col_entry['settings'] ?? null ) ? $col_entry['settings'] : [];
				$entry[ $col_id ]    = $col->get_export_value( $post->ID, $settings );
			}
			if ( ! $first ) {
				echo ',';
			}
			echo wp_json_encode( $entry );
			$first = false;
		}
		echo ']';
	}

	/**
	 * Escape a CSV cell against formula injection. If the value begins with one of
	 * =, +, -, @, TAB, or CR, prefix it with a single quote so spreadsheet apps treat it as text.
	 */
	public static function csv_escape( string $value ): string {
		if ( $value === '' ) {
			return $value;
		}
		$first = $value[0];
		if ( in_array( $first, [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * @param string[] $keys
	 * @return array<string, string>
	 */
	private function read_filter_values( string $col_id, array $keys ): array {
		$out = [];
		foreach ( $keys as $suffix ) {
			$param = 'ck_f_' . $col_id . ( $suffix === '' ? '' : '__' . $suffix );
			$raw   = isset( $_GET[ $param ] ) ? wp_unslash( (string) $_GET[ $param ] ) : '';
			$out[ $suffix ] = sanitize_text_field( $raw );
		}
		return $out;
	}
}
