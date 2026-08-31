<?php
declare( strict_types=1 );

namespace ColumnKit\Admin;

use ColumnKit\Support\ScreenIdentifier;

/**
 * Suggests real meta keys for the settings page's Custom Field columns, so users pick from
 * what actually exists in the database instead of guessing key names (or plugin prefixes).
 *
 * Served over AJAX to the settings page only (manage_options + nonce). The client renders the
 * keys into a <datalist>, so the input stays free-text — a key that doesn't exist YET (about to
 * be created by an import, say) can still be typed.
 *
 * Query notes:
 *   - DISTINCT meta_key joined/filtered per screen kind, alphabetical, capped at 200. On big
 *     postmeta tables this is an index-friendly scan of postmeta(meta_key) with a join filter;
 *     acceptable for an admin-only, on-demand request.
 *   - A small blocklist (filterable) hides pure-noise WP internals (edit locks, trash markers).
 *     Underscore-prefixed keys are otherwise KEPT — hidden keys like _price are the point.
 */
final class MetaKeySuggestions {
	public const AJAX_ACTION = 'ck_meta_keys';
	public const NONCE       = 'ck_meta_keys';
	public const LIMIT       = 200;

	public function register_hooks(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'handle' ] );
	}

	public function handle(): void {
		if ( ! check_ajax_referer( self::NONCE, '_ajax_nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid security token.', 'columnkit' ) ], 403 );
		}
		if ( ! current_user_can( SettingsPage::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'columnkit' ) ], 403 );
		}
		$screen  = isset( $_POST['screen'] ) && is_string( $_POST['screen'] ) ? sanitize_text_field( wp_unslash( $_POST['screen'] ) ) : '';
		$screens = ScreenIdentifier::available_screens();
		if ( ! isset( $screens[ $screen ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown screen.', 'columnkit' ) ], 400 );
		}

		wp_send_json_success( [ 'keys' => $this->keys_for_screen( $screen ) ] );
	}

	/**
	 * @return string[] Distinct meta keys for the given screen, alphabetical, blocklist applied.
	 */
	public function keys_for_screen( string $screen ): array {
		global $wpdb;

		$limit = self::LIMIT;
		$keys  = [];

		if ( ScreenIdentifier::is_users( $screen ) ) {
			$keys = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT meta_key FROM {$wpdb->usermeta} ORDER BY meta_key ASC LIMIT %d",
					$limit
				)
			);
		} elseif ( ( $taxonomy = ScreenIdentifier::taxonomy( $screen ) ) !== null ) {
			$keys = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT tm.meta_key FROM {$wpdb->termmeta} tm
					 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
					 WHERE tt.taxonomy = %s ORDER BY tm.meta_key ASC LIMIT %d",
					$taxonomy,
					$limit
				)
			);
		} else {
			// Post types, with Media as post_type=attachment.
			$post_type = ScreenIdentifier::is_media( $screen ) ? 'attachment' : ScreenIdentifier::post_type( $screen );
			if ( $post_type === null || $post_type === '' ) {
				return [];
			}
			$keys = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE p.post_type = %s ORDER BY pm.meta_key ASC LIMIT %d",
					$post_type,
					$limit
				)
			);
		}

		$blocklist = apply_filters(
			'columnkit/meta_key_blocklist',
			[
				'_edit_lock',
				'_edit_last',
				'_encloseme',
				'_pingme',
				'_wp_old_slug',
				'_wp_old_date',
				'_wp_trash_meta_status',
				'_wp_trash_meta_time',
				'_wp_desired_post_slug',
				'session_tokens',
			],
			$screen
		);

		$out = [];
		foreach ( (array) $keys as $key ) {
			$key = (string) $key;
			if ( $key === '' || in_array( $key, (array) $blocklist, true ) ) {
				continue;
			}
			$out[] = $key;
		}
		return $out;
	}
}
