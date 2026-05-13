<?php
declare( strict_types=1 );

namespace ColumnKit\Settings;

/**
 * Per-screen storage of user-defined column lists.
 *
 * Shape (per option):
 * [
 *   'schema_version' => 1,
 *   'screen_key'     => 'post_type:post',   // original key — option_name() rewrites it for storage
 *   'columns'        => [
 *     [ 'id' => 'col_xxxx', 'type' => 'post_meta', 'label' => 'Foo', 'settings' => [...], 'width' => '' ],
 *     ...
 *   ]
 * ]
 */
final class SettingsRepository {
	public const SCHEMA_VERSION = 1;
	private const OPTION_PREFIX = 'ck_screen_';

	private static array $cache = [];

	public function get( string $screen_key ): array {
		if ( array_key_exists( $screen_key, self::$cache ) ) {
			return self::$cache[ $screen_key ];
		}
		$option = get_option( self::option_name( $screen_key ), [] );
		if ( ! is_array( $option ) ) {
			$option = [];
		}
		$normalised = [
			'schema_version' => (int) ( $option['schema_version'] ?? self::SCHEMA_VERSION ),
			'screen_key'     => (string) ( $option['screen_key'] ?? $screen_key ),
			'columns'        => isset( $option['columns'] ) && is_array( $option['columns'] ) ? array_values( $option['columns'] ) : [],
		];
		self::$cache[ $screen_key ] = $normalised;
		return $normalised;
	}

	public function get_columns( string $screen_key ): array {
		return $this->get( $screen_key )['columns'];
	}

	public function save( string $screen_key, array $columns ): void {
		$payload = [
			'schema_version' => self::SCHEMA_VERSION,
			'screen_key'     => $screen_key,
			'columns'        => array_values( $columns ),
		];
		update_option( self::option_name( $screen_key ), $payload, false ); // autoload=false
		self::$cache[ $screen_key ] = $payload;
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
