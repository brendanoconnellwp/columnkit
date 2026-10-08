<?php
declare( strict_types=1 );

namespace ColumnKit\Support;

/**
 * Discovers a list screen's built-in ("native") columns — Title, Author, Date, Categories,
 * plus anything other plugins add — so the column editor can show, rename, hide and reorder
 * them alongside our own, the way Admin Columns does.
 *
 * Two sources, merged:
 *   1. A snapshot captured whenever the real list table renders (capture()). This is the
 *      authoritative list — it includes columns other plugins add only on that screen.
 *   2. A best-effort computation on the settings page: instantiate the core list table for
 *      the screen and read its headers (compute()). Covers screens never visited yet.
 *
 * The snapshot lives in one non-autoloaded option and is only rewritten when it changes.
 */
final class NativeColumns {
	private const OPTION = 'ck_native_columns';

	/**
	 * Built-in columns for a screen: key => plain-text label. Never includes the checkbox
	 * column or our own ck_* columns.
	 *
	 * @return array<string, string>
	 */
	public static function for_screen( string $screen_key ): array {
		$snapshot = self::snapshot( $screen_key );
		$computed = self::compute( $screen_key );
		// Snapshot order + labels win; computed fills in anything not seen live yet.
		return $snapshot + $computed;
	}

	/**
	 * Record the native columns of a list table as it renders.
	 *
	 * @param array<string, string> $columns Final header map (HTML labels allowed).
	 */
	public static function capture( string $screen_key, array $columns ): void {
		$clean = self::clean( $columns );
		if ( $clean === [] ) {
			return;
		}
		$all = get_option( self::OPTION, [] );
		$all = is_array( $all ) ? $all : [];
		if ( ( $all[ $screen_key ] ?? null ) === $clean ) {
			return;
		}
		$all[ $screen_key ] = $clean;
		update_option( self::OPTION, $all, false );
	}

	/** @return array<string, string> */
	private static function snapshot( string $screen_key ): array {
		$all = get_option( self::OPTION, [] );
		$out = is_array( $all ) && is_array( $all[ $screen_key ] ?? null ) ? $all[ $screen_key ] : [];
		return array_map( 'strval', $out );
	}

	/**
	 * Header map → key => plain label, without cb / ck_* columns. Icon-only headers (e.g. the
	 * comments bubble) fall back to their title / screen-reader text, then a humanised key.
	 *
	 * @param array<string, mixed> $columns
	 * @return array<string, string>
	 */
	public static function clean( array $columns ): array {
		$out = [];
		foreach ( $columns as $key => $label ) {
			$key = (string) $key;
			if ( $key === 'cb' || str_starts_with( $key, 'ck_' ) || preg_match( '/^[A-Za-z0-9_\-]+$/', $key ) !== 1 ) {
				continue;
			}
			$out[ $key ] = self::plain_label( $key, is_scalar( $label ) ? (string) $label : '' );
		}
		return $out;
	}

	public static function plain_label( string $key, string $html ): string {
		$text = trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
		if ( $text === '' && preg_match( '/(?:title|aria-label)="([^"]+)"/', $html, $m ) === 1 ) {
			$text = trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		}
		if ( $text === '' ) {
			$text = ucwords( str_replace( [ '_', '-' ], ' ', $key ) );
		}
		return $text;
	}

	/**
	 * Build the core list table for a screen and read its column headers. Any failure (a list
	 * table that insists on request globals, a plugin filter that fatals out of context) yields
	 * an empty list — the live snapshot will fill it in on the next visit.
	 *
	 * @return array<string, string>
	 */
	private static function compute( string $screen_key ): array {
		if ( ! function_exists( '_get_list_table' ) || ! class_exists( '\WP_Screen' ) ) {
			return [];
		}
		$class   = '';
		$hook_id = '';
		if ( ScreenIdentifier::is_users( $screen_key ) ) {
			$class   = 'WP_Users_List_Table';
			$hook_id = 'users';
		} elseif ( ScreenIdentifier::is_media( $screen_key ) ) {
			$class   = 'WP_Media_List_Table';
			$hook_id = 'upload';
		} elseif ( ( $tax = ScreenIdentifier::taxonomy( $screen_key ) ) !== null ) {
			if ( ! taxonomy_exists( $tax ) ) {
				return [];
			}
			$class   = 'WP_Terms_List_Table';
			$hook_id = 'edit-' . $tax;
		} elseif ( ( $pt = ScreenIdentifier::post_type( $screen_key ) ) !== null ) {
			if ( ! post_type_exists( $pt ) ) {
				return [];
			}
			$class   = 'WP_Posts_List_Table';
			$hook_id = 'edit-' . $pt;
		} else {
			return [];
		}

		// The list-table constructors write request globals ($taxonomy, $post_type, …).
		// Snapshot and restore them so the settings page isn't affected.
		$saved = [];
		foreach ( [ 'taxonomy', 'post_type', 'post_type_object', 'tax' ] as $g ) {
			$saved[ $g ] = $GLOBALS[ $g ] ?? null;
		}

		$columns = [];
		ob_start();
		try {
			$screen = \WP_Screen::get( $hook_id );
			$table  = _get_list_table( $class, [ 'screen' => $screen ] );
			if ( $table ) {
				// Applies "manage_{$screen->id}_columns", which the list table hooks its own
				// get_columns() onto — so this includes columns other plugins filter in.
				$columns = get_column_headers( $screen );
			}
		} catch ( \Throwable $e ) {
			$columns = [];
		}
		ob_end_clean();

		foreach ( $saved as $g => $v ) {
			if ( $v === null ) {
				unset( $GLOBALS[ $g ] );
			} else {
				$GLOBALS[ $g ] = $v;
			}
		}

		return self::clean( is_array( $columns ) ? $columns : [] );
	}
}
