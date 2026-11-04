<?php
declare( strict_types=1 );

namespace ColumnKit;

use ColumnKit\Columns\EditableColumn;
use ColumnKit\Settings\SettingsRepository;
use ColumnKit\Support\ColumnPresenter;
use ColumnKit\Support\Editability;
use ColumnKit\Support\InlineSaver;
use ColumnKit\Support\SetResolver;

/**
 * Public, versioned API for companion plugins (BoardKit). Everything outside this class is
 * internal and may change between releases; this class only changes compatibly within a VERSION.
 *
 * Companions must detect ColumnKit through the action, not class_exists():
 *
 *     add_action( 'columnkit/loaded', fn( int $v ) => $v >= 1 && My\Integration::enable() );
 *
 * Every method answers for the CURRENT user: views limited to roles the user doesn't have are
 * invisible here (no columns, no saves), exactly as on the list table.
 */
final class Api {
	/** Bump on any breaking change to this class. */
	public const VERSION = 1;

	/** @return array<string, string> set_id => label, only views the current user may see. */
	public static function sets( string $screen_key ): array {
		return SetResolver::visible_sets( self::repository(), $screen_key );
	}

	/**
	 * Column entries (id, type, label, settings, format) of one view. [] if the view doesn't
	 * exist or the current user may not see it — the caller should fall back, not guess.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function columns( string $screen_key, string $set_id = SettingsRepository::DEFAULT_SET ): array {
		$repository = self::repository();
		if ( ! SetResolver::can_view( $repository, $screen_key, $set_id ) ) {
			return [];
		}
		return array_values( $repository->get_columns( $screen_key, $set_id ) );
	}

	/**
	 * Escaped HTML for one value, formatted as the list table shows it. '' for an unknown column.
	 *
	 * @param array<string, mixed> $entry
	 */
	public static function render( array $entry, int $object_id ): string {
		$col = self::column( $entry );
		if ( $col === null ) {
			return '';
		}
		return ColumnPresenter::format( $col->render( $object_id, self::settings( $entry ) ), self::format( $entry ) );
	}

	/**
	 * Plain text (export value + prefix/suffix). For card titles, aria-labels. NOT escaped.
	 *
	 * @param array<string, mixed> $entry
	 */
	public static function text( array $entry, int $object_id ): string {
		$col = self::column( $entry );
		if ( $col === null ) {
			return '';
		}
		return ColumnPresenter::format_export( $col->get_export_value( $object_id, self::settings( $entry ) ), self::format( $entry ) );
	}

	/**
	 * Fixed choices if this column can act as a lane source, else null: the column must be
	 * editable and its input a 'select' (its own options) or 'boolean' ('1' Yes / '0' No).
	 *
	 * @param array<string, mixed> $entry
	 * @return array<string, string>|null value => label
	 */
	public static function lane_choices( array $entry ): ?array {
		$col = self::editable( $entry );
		if ( $col === null ) {
			return null;
		}
		$settings = self::settings( $entry );
		$input    = $col->get_edit_input_type( $settings );
		if ( $input === 'boolean' ) {
			return [
				'1' => __( 'Yes', 'columnkit' ),
				'0' => __( 'No', 'columnkit' ),
			];
		}
		if ( $input !== 'select' ) {
			return null;
		}
		$options = $col->get_edit_options( $settings );
		if ( ! is_array( $options ) || $options === [] ) {
			return null;
		}
		$out = [];
		foreach ( $options as $value => $label ) {
			$out[ (string) $value ] = (string) $label;
		}
		return $out;
	}

	/**
	 * Current raw (edit) value, '' if the column isn't editable.
	 *
	 * @param array<string, mixed> $entry
	 */
	public static function raw_value( array $entry, int $object_id ): string {
		$col = self::editable( $entry );
		return $col === null ? '' : $col->get_raw_value( $object_id, self::settings( $entry ) );
	}

	/**
	 * Save one value onto a post with the same checks as the list table's inline edit
	 * (InlineSaver: edit_post, the view visible to this user, Editability, sanitising by input
	 * type, the column's own save_value() rules).
	 *
	 * Columns refuse some writes silently (e.g. post status → publish without publish_posts).
	 * A value that didn't change to what was asked is reported as 'ck_not_saved' (403) rather
	 * than a false success.
	 *
	 * @param string $value Unslashed, unsanitised.
	 * @return bool|\WP_Error true on success (never false); WP_Error data is ['status' => int].
	 */
	public static function save( string $screen_key, string $set_id, string $col_id, int $post_id, string $value ): bool|\WP_Error {
		$saver = new InlineSaver( self::registry(), self::repository() );
		$post  = $saver->authorise_post( $post_id, $col_id );
		if ( $post instanceof \WP_Error ) {
			return $post;
		}
		if ( $screen_key !== 'post_type:' . $post->post_type ) {
			return InlineSaver::error( 'ck_screen_mismatch', __( 'This post does not belong to that screen.', 'columnkit' ), 400 );
		}

		$result = $saver->save_column( $post, $set_id, $col_id, $value, true );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		$asked_for_change = $result['value'] !== $result['before'];
		if ( $asked_for_change && $result['raw'] === $result['before'] ) {
			return InlineSaver::error( 'ck_not_saved', __( 'The value could not be saved.', 'columnkit' ), 403 );
		}
		return true;
	}

	// --- internals -----------------------------------------------------

	/** @param array<string, mixed> $entry */
	private static function column( array $entry ): ?Columns\ColumnInterface {
		return self::registry()->get( (string) ( $entry['type'] ?? '' ) );
	}

	/** @param array<string, mixed> $entry */
	private static function editable( array $entry ): ?EditableColumn {
		$col = self::column( $entry );
		return $col instanceof EditableColumn && Editability::is_editable( $col, self::settings( $entry ) ) ? $col : null;
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	private static function settings( array $entry ): array {
		return is_array( $entry['settings'] ?? null ) ? $entry['settings'] : [];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	private static function format( array $entry ): array {
		return is_array( $entry['format'] ?? null ) ? $entry['format'] : [];
	}

	/** Test seam: lets unit tests supply their own registry/repository. */
	private static ?ColumnRegistry $registry_override = null;
	private static ?SettingsRepository $repository_override = null;

	/** @internal For tests only. Pass nulls to reset. */
	public static function use_for_tests( ?ColumnRegistry $registry, ?SettingsRepository $repository ): void {
		self::$registry_override   = $registry;
		self::$repository_override = $repository;
	}

	private static function registry(): ColumnRegistry {
		return self::$registry_override ?? Plugin::instance()->registry();
	}

	private static function repository(): SettingsRepository {
		return self::$repository_override ?? Plugin::instance()->repository();
	}
}
