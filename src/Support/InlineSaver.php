<?php
declare( strict_types=1 );

namespace ColumnKit\Support;

use ColumnKit\ColumnRegistry;
use ColumnKit\Columns\EditableColumn;
use ColumnKit\Settings\SettingsRepository;
use WP_Error;
use WP_Post;

/**
 * The one copy of the checks that guard a single-value save onto a post column.
 *
 * Used by EditManager::ajax_inline_save() (the list-table popover) and by Api::save()
 * (companion plugins such as BoardKit). Same reasoning as Editability: two copies of a
 * security check eventually disagree, so both callers go through here.
 *
 * Every failure is a WP_Error whose data is ['status' => HTTP code]. Messages match the
 * strings the AJAX handler has always sent.
 */
final class InlineSaver {
	public function __construct(
		private ColumnRegistry $registry,
		private SettingsRepository $repository
	) {}

	/**
	 * Request-shape, capability and existence checks for a post. Core fields (core_title…)
	 * also pass through here before EditManager handles them.
	 */
	public function authorise_post( int $post_id, string $col_id ): WP_Post|WP_Error {
		if ( $post_id <= 0 || $col_id === '' ) {
			return self::error( 'ck_invalid', __( 'Invalid request.', 'columnkit' ), 400 );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return self::error( 'ck_forbidden', __( 'You cannot edit this post.', 'columnkit' ), 403 );
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return self::error( 'ck_not_found', __( 'Post not found.', 'columnkit' ), 404 );
		}
		return $post;
	}

	/**
	 * Save one configured column on an already-authorised post.
	 *
	 * @param string $unsanitised Slashes already removed; sanitised here by input type.
	 * @param bool   $strict_set  true: a set the user may not view is a 403 (API callers).
	 *                            false: fall back to the default set, as the list table's
	 *                            switcher does (AJAX caller).
	 * @return array{html: string, raw: string, before: string, value: string}|WP_Error
	 */
	public function save_column( WP_Post $post, string $set_id, string $col_id, string $unsanitised, bool $strict_set ): array|WP_Error {
		$screen_key = 'post_type:' . $post->post_type;

		if ( $strict_set ) {
			if ( ! SetResolver::can_view( $this->repository, $screen_key, $set_id ) ) {
				return self::error( 'ck_set_forbidden', __( 'You cannot use this view.', 'columnkit' ), 403 );
			}
		} else {
			$set_id = SetResolver::allowed( $this->repository, $screen_key, $set_id );
		}

		$entry = self::find_entry( $this->repository->get_columns( $screen_key, $set_id ), $col_id );
		if ( $entry === null ) {
			return self::error( 'ck_no_column', __( 'Column not configured.', 'columnkit' ), 404 );
		}

		$col      = $this->registry->get( (string) ( $entry['type'] ?? '' ) );
		$settings = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : [];
		if ( ! $col instanceof EditableColumn || ! Editability::is_editable( $col, $settings ) ) {
			return self::error( 'ck_not_editable', __( 'Column is not editable.', 'columnkit' ), 400 );
		}

		// Multi-line fields (excerpts) keep their newlines; everything else is single-line.
		$value = $col->get_edit_input_type( $settings ) === 'textarea'
			? sanitize_textarea_field( $unsanitised )
			: sanitize_text_field( $unsanitised );

		$before = $col->get_raw_value( $post->ID, $settings );
		$col->save_value( $post->ID, $value, $settings );

		// Re-render the cell (render() returns escaped HTML) with the list table's formatting so
		// prefix/suffix/badge survive a save, and read back the raw value.
		$format = is_array( $entry['format'] ?? null ) ? $entry['format'] : [];

		return [
			'html'   => ColumnPresenter::format( $col->render( $post->ID, $settings ), $format ),
			'raw'    => $col->get_raw_value( $post->ID, $settings ),
			'before' => $before,
			'value'  => $value,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $columns
	 * @return array<string, mixed>|null
	 */
	public static function find_entry( array $columns, string $col_id ): ?array {
		foreach ( $columns as $candidate ) {
			if ( ( $candidate['id'] ?? '' ) === $col_id ) {
				return $candidate;
			}
		}
		return null;
	}

	public static function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status ] );
	}

	public static function status_of( WP_Error $error ): int {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
	}
}
