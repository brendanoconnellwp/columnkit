<?php
declare( strict_types=1 );

namespace ColumnKit\Support;

use ColumnKit\Settings\SettingsRepository;

/**
 * Resolves which column set the current viewer should see on a list screen, and remembers
 * an explicit choice per-user so it sticks across visits (the way AC Pro's view switcher does).
 *
 * Resolution order:
 *   1. `?ck_set=` in the request — an explicit switch. Validated against the screen's sets;
 *      when valid it's persisted to the user's meta so the next visit (without the param) reuses it.
 *   2. The user's remembered choice (user meta), if it still exists for this screen.
 *   3. The default set.
 */
final class SetResolver {
	public const REQUEST_PARAM = 'ck_set';

	private const META_PREFIX = 'ck_active_set_';

	public static function resolve( SettingsRepository $repository, string $screen_key ): string {
		$requested = isset( $_GET[ self::REQUEST_PARAM ] ) && is_string( $_GET[ self::REQUEST_PARAM ] )
			? SettingsRepository::sanitize_set_id( wp_unslash( $_GET[ self::REQUEST_PARAM ] ) )
			: '';

		if ( $requested !== '' && $repository->set_exists( $screen_key, $requested ) ) {
			self::remember( $screen_key, $requested );
			return $requested;
		}

		$remembered = self::recall( $screen_key );
		if ( $remembered !== '' && $repository->set_exists( $screen_key, $remembered ) ) {
			return $remembered;
		}

		return SettingsRepository::DEFAULT_SET;
	}

	private static function remember( string $screen_key, string $set_id ): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		update_user_meta( $user_id, self::meta_key( $screen_key ), $set_id );
	}

	private static function recall( string $screen_key ): string {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return '';
		}
		$value = get_user_meta( $user_id, self::meta_key( $screen_key ), true );
		return is_string( $value ) ? SettingsRepository::sanitize_set_id( $value ) : '';
	}

	private static function meta_key( string $screen_key ): string {
		return self::META_PREFIX . preg_replace( '/[^A-Za-z0-9_]/', '_', $screen_key );
	}
}
