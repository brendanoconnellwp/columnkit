<?php
declare( strict_types=1 );

namespace ColumnKit\Settings;

/**
 * Per-screen storage of user-defined column lists, organised into named "column sets"
 * (a.k.a. saved views — the Admin Columns Pro flagship feature).
 *
 * Shape (per option), schema v2:
 * [
 *   'schema_version' => 2,
 *   'screen_key'     => 'post_type:post',   // original key — option_name() rewrites it for storage
 *   'sets'           => [
 *     'default'  => [ 'label' => 'Default',  'columns' => [ ... ] ],
 *     'set_ab12' => [ 'label' => 'SEO view', 'columns' => [ ... ] ],
 *   ],
 * ]
 *
 * Each column entry:
 *   [ 'id' => 'col_xxxx', 'type' => 'post_meta', 'label' => 'Foo',
 *     'settings' => [...], 'width' => '', 'format' => [...] ]
 *
 * Optional per-set `layout` (written by the column editor; absent = legacy "append our columns
 * after WordPress's own" behaviour):
 *   [ 'order'  => [ 'title', 'ck_col_xxxx', 'author', ... ],        // WP column keys, in order
 *     'native' => [ 'title' => [ 'label' => '', 'hidden' => false, 'width' => '' ], ... ] ]
 * `native` lists every built-in column the editor knew about when it saved; one missing from
 * it (a plugin activated later) is appended rather than hidden. `columns` never contains
 * built-in columns, so every consumer of get_columns() keeps seeing custom columns only.
 *
 * Backwards compatibility: schema v1 stored a flat `columns` array with no sets. get()
 * migrates v1 payloads to v2 in memory on read (wrapping the old list into the `default`
 * set); the migrated shape is persisted on the next save(), never during a read request.
 */
final class SettingsRepository {
	public const SCHEMA_VERSION = 2;
	public const DEFAULT_SET    = 'default';

	private const OPTION_PREFIX = 'ck_screen_';

	private static array $cache = [];

	/**
	 * Normalised v2 payload for a screen. Always contains a 'default' set (possibly empty).
	 *
	 * @return array{schema_version:int, screen_key:string, sets:array<string, array{label:string, columns:array<int, array<string, mixed>>}>}
	 */
	public function get( string $screen_key ): array {
		if ( array_key_exists( $screen_key, self::$cache ) ) {
			return self::$cache[ $screen_key ];
		}
		$option = get_option( self::option_name( $screen_key ), [] );
		if ( ! is_array( $option ) ) {
			$option = [];
		}
		$normalised = self::normalise( $option, $screen_key );
		self::$cache[ $screen_key ] = $normalised;
		return $normalised;
	}

	/**
	 * Columns for a given set. Falls back to the default set when the requested set is unknown,
	 * then to an empty list.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_columns( string $screen_key, string $set_id = self::DEFAULT_SET ): array {
		$sets = $this->get( $screen_key )['sets'];
		if ( isset( $sets[ $set_id ] ) ) {
			return $sets[ $set_id ]['columns'];
		}
		if ( isset( $sets[ self::DEFAULT_SET ] ) ) {
			return $sets[ self::DEFAULT_SET ]['columns'];
		}
		return [];
	}

	/**
	 * Map of set id => label for a screen, default set first.
	 *
	 * @return array<string, string>
	 */
	public function get_sets( string $screen_key ): array {
		$out = [];
		foreach ( $this->get( $screen_key )['sets'] as $id => $set ) {
			$out[ (string) $id ] = (string) ( $set['label'] ?? $id );
		}
		return $out;
	}

	/**
	 * Layout (order + built-in column overrides) for a set, or [] when the set has never been
	 * saved from the layout-aware editor.
	 *
	 * @return array{order?: array<int, string>, native?: array<string, array{label: string, hidden: bool, width: string}>}
	 */
	public function get_layout( string $screen_key, string $set_id = self::DEFAULT_SET ): array {
		$sets = $this->get( $screen_key )['sets'];
		$set  = $sets[ $set_id ] ?? ( $sets[ self::DEFAULT_SET ] ?? [] );
		return isset( $set['layout'] ) && is_array( $set['layout'] ) ? $set['layout'] : [];
	}

	public function set_exists( string $screen_key, string $set_id ): bool {
		return isset( $this->get( $screen_key )['sets'][ $set_id ] );
	}

	/**
	 * Create or replace a single set's columns + label, preserving every other set.
	 *
	 * @param array<int, array<string, mixed>> $columns
	 * @param array<string, mixed>|null        $layout  New layout; null keeps the set's existing one
	 *                                                  (so rename / programmatic saves don't drop it).
	 */
	public function save_set( string $screen_key, string $set_id, string $label, array $columns, ?array $layout = null ): void {
		$set_id   = self::sanitize_set_id( $set_id );
		$payload  = $this->get( $screen_key );
		$previous = $payload['sets'][ $set_id ]['layout'] ?? [];
		$layout   = self::sanitize_layout( $layout ?? ( is_array( $previous ) ? $previous : [] ) );

		$set = [
			'label'   => $label !== '' ? $label : ( $set_id === self::DEFAULT_SET ? 'Default' : $set_id ),
			'columns' => array_values( $columns ),
		];
		if ( $layout !== [] ) {
			$set['layout'] = $layout;
		}
		$payload['sets'][ $set_id ] = $set;
		$this->persist( $screen_key, $payload );
	}

	/**
	 * Whitelist a layout block. Column keys are WP list-table keys ([A-Za-z0-9_-], e.g.
	 * `title`, `taxonomy-genre`, `ck_col_ab12`); labels are plain text; widths are a number
	 * with an optional px/%/em/rem unit.
	 *
	 * @param array<string, mixed> $in
	 * @return array{order?: array<int, string>, native?: array<string, array{label: string, hidden: bool, width: string}>}
	 */
	public static function sanitize_layout( array $in ): array {
		$order = [];
		foreach ( is_array( $in['order'] ?? null ) ? $in['order'] : [] as $key ) {
			$key = is_scalar( $key ) ? self::sanitize_column_key( (string) $key ) : '';
			if ( $key !== '' && $key !== 'cb' && ! in_array( $key, $order, true ) ) {
				$order[] = $key;
			}
		}
		$native = [];
		foreach ( is_array( $in['native'] ?? null ) ? $in['native'] : [] as $key => $cfg ) {
			$key = self::sanitize_column_key( (string) $key );
			if ( $key === '' || $key === 'cb' || str_starts_with( $key, 'ck_' ) ) {
				continue;
			}
			$cfg   = is_array( $cfg ) ? $cfg : [];
			$label = isset( $cfg['label'] ) && is_scalar( $cfg['label'] ) ? sanitize_text_field( (string) $cfg['label'] ) : '';
			$width = isset( $cfg['width'] ) && is_scalar( $cfg['width'] ) ? strtolower( trim( (string) $cfg['width'] ) ) : '';
			if ( preg_match( '/^\d{1,4}(px|%|em|rem)?$/', $width ) !== 1 ) {
				$width = '';
			}
			$native[ $key ] = [
				'label'  => $label,
				'hidden' => ! empty( $cfg['hidden'] ),
				'width'  => $width,
			];
		}
		if ( $native === [] ) {
			return []; // Without known built-ins a layout can't place anything reliably.
		}
		return [ 'order' => $order, 'native' => $native ];
	}

	public static function sanitize_column_key( string $key ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $key ), 0, 100 );
	}

	/**
	 * Delete a set. The default set can't be removed (it's emptied instead) so every screen
	 * always resolves to something.
	 */
	public function delete_set( string $screen_key, string $set_id ): void {
		$set_id  = self::sanitize_set_id( $set_id );
		$payload = $this->get( $screen_key );
		if ( $set_id === self::DEFAULT_SET ) {
			$payload['sets'][ self::DEFAULT_SET ] = [ 'label' => 'Default', 'columns' => [] ];
		} else {
			unset( $payload['sets'][ $set_id ] );
		}
		$this->persist( $screen_key, $payload );
	}

	/**
	 * Back-compat shim: save the default set's columns, preserving other sets.
	 *
	 * @param array<int, array<string, mixed>> $columns
	 */
	public function save( string $screen_key, array $columns ): void {
		$existing = $this->get( $screen_key )['sets'][ self::DEFAULT_SET ]['label'] ?? 'Default';
		$this->save_set( $screen_key, self::DEFAULT_SET, (string) $existing, $columns );
	}

	public function delete( string $screen_key ): void {
		delete_option( self::option_name( $screen_key ) );
		unset( self::$cache[ $screen_key ] );
	}

	/**
	 * @return string[] All screen keys for which we have stored settings.
	 *
	 * Reads each matching option to pull the original `screen_key` field — option names can't
	 * always be reversed (we replace `:` with `_` for storage), so we trust the stored field.
	 */
	public function configured_screens(): array {
		global $wpdb;
		$prefix = self::OPTION_PREFIX;
		$like   = $wpdb->esc_like( $prefix ) . '%';
		$names  = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

		$keys = [];
		foreach ( (array) $names as $name ) {
			$opt = get_option( $name, [] );
			if ( is_array( $opt ) && isset( $opt['screen_key'] ) && is_string( $opt['screen_key'] ) ) {
				$keys[] = $opt['screen_key'];
			} else {
				// Legacy fallback for options saved before schema added screen_key.
				$keys[] = substr( $name, strlen( $prefix ) );
			}
		}
		return $keys;
	}

	/**
	 * Coerce any stored payload (v1 flat, v2 sets, or junk) into the canonical v2 shape.
	 *
	 * @param array<string, mixed> $option
	 * @return array{schema_version:int, screen_key:string, sets:array<string, array{label:string, columns:array<int, array<string, mixed>>}>}
	 */
	public static function normalise( array $option, string $screen_key ): array {
		$resolved_key = isset( $option['screen_key'] ) && is_string( $option['screen_key'] )
			? $option['screen_key']
			: $screen_key;

		$sets = [];
		if ( isset( $option['sets'] ) && is_array( $option['sets'] ) ) {
			// Already v2-ish — clean each set.
			foreach ( $option['sets'] as $id => $set ) {
				$id = self::sanitize_set_id( (string) $id );
				if ( ! is_array( $set ) ) {
					continue;
				}
				$label   = isset( $set['label'] ) && is_string( $set['label'] ) ? $set['label'] : $id;
				$columns = isset( $set['columns'] ) && is_array( $set['columns'] ) ? array_values( $set['columns'] ) : [];
				$sets[ $id ] = [ 'label' => $label, 'columns' => $columns ];
				if ( isset( $set['layout'] ) && is_array( $set['layout'] ) && $set['layout'] !== [] ) {
					$sets[ $id ]['layout'] = $set['layout'];
				}
			}
		} elseif ( isset( $option['columns'] ) && is_array( $option['columns'] ) ) {
			// v1 → v2: wrap the flat column list into the default set.
			$sets[ self::DEFAULT_SET ] = [
				'label'   => 'Default',
				'columns' => array_values( $option['columns'] ),
			];
		}

		if ( ! isset( $sets[ self::DEFAULT_SET ] ) ) {
			// Guarantee the default set always exists, first in iteration order.
			$sets = [ self::DEFAULT_SET => [ 'label' => 'Default', 'columns' => [] ] ] + $sets;
		}

		return [
			'schema_version' => self::SCHEMA_VERSION,
			'screen_key'     => $resolved_key,
			'sets'           => $sets,
		];
	}

	/** Set ids are 'default' or a slug of [a-z0-9_] (max 40). Anything else collapses to default. */
	public static function sanitize_set_id( string $set_id ): string {
		$clean = strtolower( (string) preg_replace( '/[^a-z0-9_]/i', '', $set_id ) );
		if ( $clean === '' ) {
			return self::DEFAULT_SET;
		}
		return substr( $clean, 0, 40 );
	}

	/** Generate a fresh, collision-checked set id for a screen. */
	public function generate_set_id( string $screen_key ): string {
		$existing = $this->get( $screen_key )['sets'];
		do {
			$id = 'set_' . substr( md5( $screen_key . microtime( true ) . wp_rand() ), 0, 8 );
		} while ( isset( $existing[ $id ] ) );
		return $id;
	}

	/**
	 * @param array{schema_version:int, screen_key:string, sets:array<string, mixed>} $payload
	 */
	private function persist( string $screen_key, array $payload ): void {
		$payload['schema_version'] = self::SCHEMA_VERSION;
		$payload['screen_key']     = $screen_key;
		update_option( self::option_name( $screen_key ), $payload, false ); // autoload=false
		self::$cache[ $screen_key ] = $payload;
	}

	private static function option_name( string $screen_key ): string {
		// Option names must be reasonable — replace anything outside [A-Za-z0-9_] with _.
		$safe = preg_replace( '/[^A-Za-z0-9_]/', '_', $screen_key );
		return self::OPTION_PREFIX . $safe;
	}

	/** Test helper. */
	public static function reset_cache(): void {
		self::$cache = [];
	}
}
