<?php
declare( strict_types=1 );

namespace ColumnKit\Support;

use WP_Screen;

/**
 * Maps WP_Screen objects to stable internal screen keys.
 *
 * Recognised screen kinds:
 *   - post_type:{slug}  → posts/pages/CPTs
 *   - media             → /wp-admin/upload.php (Media library)
 *   - users             → /wp-admin/users.php
 *   - taxonomy:{slug}   → /wp-admin/edit-tags.php for a taxonomy
 */
final class ScreenIdentifier {
	public static function from_screen( WP_Screen $screen ): ?string {
		// Media library — WP_Media_List_Table uses `manage_media_*` hooks, so we identify it
		// by base, not by post_type=attachment.
		if ( $screen->base === 'upload' ) {
			return 'media';
		}
		if ( $screen->base === 'edit' && is_string( $screen->post_type ) && $screen->post_type !== '' ) {
			return 'post_type:' . $screen->post_type;
		}
		if ( $screen->base === 'users' ) {
			return 'users';
		}
		if ( $screen->base === 'edit-tags' && is_string( $screen->taxonomy ) && $screen->taxonomy !== '' ) {
			return 'taxonomy:' . $screen->taxonomy;
		}
		return null;
	}

	public static function post_type( string $screen_key ): ?string {
		if ( str_starts_with( $screen_key, 'post_type:' ) ) {
			return substr( $screen_key, strlen( 'post_type:' ) );
		}
		return null;
	}

	public static function taxonomy( string $screen_key ): ?string {
		if ( str_starts_with( $screen_key, 'taxonomy:' ) ) {
			return substr( $screen_key, strlen( 'taxonomy:' ) );
		}
		return null;
	}

	public static function is_users( string $screen_key ): bool {
		return $screen_key === 'users';
	}

	public static function is_media( string $screen_key ): bool {
		return $screen_key === 'media';
	}

	/**
	 * @return array<string, string> screen_key => human label, for the settings UI dropdown.
	 */
	public static function available_screens(): array {
		$out = [];

		foreach ( get_post_types( [ 'show_ui' => true ], 'objects' ) as $pt ) {
			if ( $pt->name === 'attachment' ) {
				continue;
			}
			$out[ 'post_type:' . $pt->name ] = sprintf(
				/* translators: %s: post type label */
				__( 'Posts — %s', 'columnkit' ),
				$pt->labels->name
			);
		}

		$out['media'] = __( 'Media library', 'columnkit' );
		$out['users'] = __( 'Users', 'columnkit' );

		foreach ( get_taxonomies( [ 'show_ui' => true ], 'objects' ) as $tax ) {
			$out[ 'taxonomy:' . $tax->name ] = sprintf(
				/* translators: %s: taxonomy plural label */
				__( 'Taxonomy — %s', 'columnkit' ),
				$tax->labels->name
			);
		}

		return $out;
	}
}
