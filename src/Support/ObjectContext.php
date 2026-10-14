<?php
declare( strict_types=1 );

namespace ColumnKit\Support;

/**
 * Which kind of WordPress object a column instance renders for — post, term or user — plus
 * object-aware meta read/write helpers.
 *
 * Custom-field integrations (ACF, Meta Box, JetEngine) expose ONE column type that works on
 * post, taxonomy and user list screens. `render()` / `save_value()` only receive an object ID,
 * so the object kind travels in the column's settings: Sanitizer stamps `object` (and
 * `taxonomy` for term screens) from the screen key at save time. Settings saved before this
 * existed have no `object` key and resolve to 'post' — which is exactly what those columns
 * were, since the integrations were post-only.
 */
final class ObjectContext {
	public const POST = 'post';
	public const TERM = 'term';
	public const USER = 'user';

	public static function from_screen( string $screen_key ): string {
		if ( ScreenIdentifier::is_users( $screen_key ) ) {
			return self::USER;
		}
		if ( ScreenIdentifier::taxonomy( $screen_key ) !== null ) {
			return self::TERM;
		}
		return self::POST;
	}

	/** @param array<string, mixed> $settings */
	public static function from_settings( array $settings ): string {
		$object = isset( $settings['object'] ) && is_string( $settings['object'] ) ? $settings['object'] : '';
		return in_array( $object, [ self::TERM, self::USER ], true ) ? $object : self::POST;
	}

	/** @param array<string, mixed> $settings */
	public static function taxonomy_from_settings( array $settings ): string {
		return isset( $settings['taxonomy'] ) && is_string( $settings['taxonomy'] ) ? sanitize_key( $settings['taxonomy'] ) : '';
	}

	/**
	 * The context keys a screen stamps onto a contextual column's settings.
	 *
	 * @return array<string, string>
	 */
	public static function settings_for_screen( string $screen_key ): array {
		$object = self::from_screen( $screen_key );
		$out    = [ 'object' => $object ];
		if ( $object === self::TERM ) {
			$out['taxonomy'] = (string) ScreenIdentifier::taxonomy( $screen_key );
		}
		return $out;
	}

	/** @return mixed */
	public static function get_meta( string $object, int $id, string $key ) {
		return match ( $object ) {
			self::TERM => get_term_meta( $id, $key, true ),
			self::USER => get_user_meta( $id, $key, true ),
			default    => get_post_meta( $id, $key, true ),
		};
	}

	/** @param mixed $value */
	public static function update_meta( string $object, int $id, string $key, $value ): void {
		match ( $object ) {
			self::TERM => update_term_meta( $id, $key, $value ),
			self::USER => update_user_meta( $id, $key, $value ),
			default    => update_post_meta( $id, $key, $value ),
		};
	}

	public static function delete_meta( string $object, int $id, string $key ): void {
		match ( $object ) {
			self::TERM => delete_term_meta( $id, $key ),
			self::USER => delete_user_meta( $id, $key ),
			default    => delete_post_meta( $id, $key ),
		};
	}

	/**
	 * Protected meta keys (underscore-prefixed, or flagged by is_protected_meta) are editable
	 * only by users trusted beyond the per-object edit cap the AJAX layer already checked:
	 * posts → the type's edit_others_posts, terms → the taxonomy's manage_terms, users →
	 * edit_users. Unprotected keys always pass.
	 */
	public static function can_write_key( string $object, int $id, string $key ): bool {
		if ( ! is_protected_meta( $key, $object ) ) {
			return true;
		}
		if ( $object === self::USER ) {
			return current_user_can( 'edit_users' );
		}
		if ( $object === self::TERM ) {
			$term    = get_term( $id );
			$tax_obj = ( $term instanceof \WP_Term ) ? get_taxonomy( $term->taxonomy ) : false;
			$cap     = $tax_obj ? $tax_obj->cap->manage_terms : 'manage_categories';
			return current_user_can( $cap );
		}
		$post_type = get_post_type( $id );
		$pt_obj    = $post_type ? get_post_type_object( $post_type ) : null;
		return current_user_can( $pt_obj->cap->edit_others_posts ?? 'edit_others_posts' );
	}
}
