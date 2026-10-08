<?php
declare( strict_types=1 );

namespace ColumnKit\Support;

use ColumnKit\Settings\SettingsRepository;

/**
 * Resolves which column set the current viewer should see on a list screen, and remembers
 * an explicit choice per-user so it sticks across visits (the way AC Pro's view switcher does).
 *
 * Views can be limited to roles (SettingsRepository::get_roles). A user only ever resolves to,
 * or can switch to, a view they're allowed to see; site admins (manage_options) see every view
 * so they can check them. The default view is always visible.
 *
 * Resolution order:
 *   1. `?ck_set=` in the request — an explicit switch, if visible to this user. Persisted to
 *      user meta so the next visit (without the param) reuses it.
 *   2. The user's remembered choice, if it still exists and is still visible.
 *   3. The first view restricted to one of the user's roles — so "Editors see the Editorial
 *      view" works without anyone switching.
 *   4. The default set.
 */
final class SetResolver {
	public const REQUEST_PARAM = 'ck_set';

	private const META_PREFIX = 'ck_active_set_';

	public static function resolve( SettingsRepository $repository, string $screen_key ): string {
		$requested = isset( $_GET[ self::REQUEST_PARAM ] ) && is_string( $_GET[ self::REQUEST_PARAM ] )
			? SettingsRepository::sanitize_set_id( wp_unslash( $_GET[ self::REQUEST_PARAM ] ) )
			: '';

		if ( $requested !== '' && self::can_view( $repository, $screen_key, $requested ) ) {
			self::remember( $screen_key, $requested );
			return $requested;
		}

		$remembered = self::recall( $screen_key );
		if ( $remembered !== '' && self::can_view( $repository, $screen_key, $remembered ) ) {
			return $remembered;
		}

		$roles = self::current_roles();
		foreach ( array_keys( $repository->get_sets( $screen_key ) ) as $set_id ) {
			$allowed = $repository->get_roles( $screen_key, (string) $set_id );
			if ( $allowed !== [] && array_intersect( $allowed, $roles ) !== [] ) {
				return (string) $set_id;
			}
		}

		return SettingsRepository::DEFAULT_SET;
	}

	/**
	 * Views the current user may see: id => label.
	 *
	 * @return array<string, string>
	 */
	public static function visible_sets( SettingsRepository $repository, string $screen_key ): array {
		$out = [];
		foreach ( $repository->get_sets( $screen_key ) as $id => $label ) {
			if ( self::can_view( $repository, $screen_key, (string) $id ) ) {
				$out[ (string) $id ] = $label;
			}
		}
		return $out;
	}

	public static function can_view( SettingsRepository $repository, string $screen_key, string $set_id ): bool {
		if ( ! $repository->set_exists( $screen_key, $set_id ) ) {
			return false;
		}
		$allowed = $repository->get_roles( $screen_key, $set_id );
		if ( $allowed === [] || current_user_can( 'manage_options' ) ) {
			return true;
		}
		return array_intersect( $allowed, self::current_roles() ) !== [];
	}

	/** A requested set id if this user may use it, else the default set. */
	public static function allowed( SettingsRepository $repository, string $screen_key, string $set_id ): string {
		return self::can_view( $repository, $screen_key, $set_id ) ? $set_id : SettingsRepository::DEFAULT_SET;
	}

	/** @return array<int, string> */
	private static function current_roles(): array {
		$user = wp_get_current_user();
		return ( $user && $user->exists() ) ? array_values( array_map( 'strval', (array) $user->roles ) ) : [];
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
		// No stored choice comes back as '' — which sanitize_set_id() would turn into 'default'
		// and so shadow the role-based pick below.
		return is_string( $value ) && $value !== '' ? SettingsRepository::sanitize_set_id( $value ) : '';
	}

	private static function meta_key( string $screen_key ): string {
		return self::META_PREFIX . preg_replace( '/[^A-Za-z0-9_]/', '_', $screen_key );
	}
}
