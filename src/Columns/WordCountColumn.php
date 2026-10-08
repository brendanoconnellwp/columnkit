<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use WP_Query;

/**
 * Length of the post content — word count, estimated reading time, or both. Sort it to find
 * thin content.
 *
 * Counting needs the content with markup, blocks and shortcodes stripped, which SQL can't do,
 * so the count is cached in post meta (`_ck_word_count`): refreshed on every save, and
 * backfilled in batches the first time someone sorts by the column.
 */
final class WordCountColumn extends AbstractPostFieldColumn implements SortableColumn {
	public const META_KEY = '_ck_word_count';
	private const BACKFILL_BATCH = 1000;

	protected function supports_post_type( string $post_type ): bool {
		return post_type_supports( $post_type, 'editor' );
	}

	/** Keep the cached count fresh. Registered once at boot. */
	public static function register_hooks(): void {
		add_action(
			'save_post',
			static function ( int $post_id, $post ): void {
				if ( wp_is_post_revision( $post_id ) || ! $post instanceof \WP_Post ) {
					return;
				}
				update_post_meta( $post_id, self::META_KEY, self::count_words( $post->post_content ) );
			},
			20,
			2
		);
	}

	public static function count_words( string $content ): int {
		$text = wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( $content ) ), true );
		$text = trim( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
		if ( $text === '' ) {
			return 0;
		}
		$words = preg_split( '/[\s\x{00A0}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $words ) ? count( $words ) : 0;
	}

	public function get_type(): string {
		return 'word_count';
	}

	public function get_label(): string {
		return __( 'Word count', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Words in the content and/or estimated reading time. Sort to find thin content.', 'columnkit' );
	}

	public function settings_fields(): array {
		return [
			[
				'key'     => 'display',
				'label'   => __( 'Show', 'columnkit' ),
				'type'    => 'select',
				'options' => [
					'words'   => __( 'Word count', 'columnkit' ),
					'reading' => __( 'Reading time', 'columnkit' ),
					'both'    => __( 'Both', 'columnkit' ),
				],
			],
			[
				'key'     => 'wpm',
				'label'   => __( 'Reading speed (words / minute)', 'columnkit' ),
				'type'    => 'select',
				'options' => [ '150' => '150', '200' => '200', '250' => '250', '300' => '300' ],
			],
		];
	}

	public function sanitize_settings( array $input ): array {
		$out = parent::sanitize_settings( $input );
		if ( ! in_array( $out['display'] ?? '', [ 'words', 'reading', 'both' ], true ) ) {
			$out['display'] = 'words';
		}
		if ( ! in_array( $out['wpm'] ?? '', [ '150', '200', '250', '300' ], true ) ) {
			$out['wpm'] = '200';
		}
		return $out;
	}

	public function render( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		if ( ! $post ) {
			return '';
		}
		$count   = self::count_words( $post->post_content );
		$minutes = max( 1, (int) ceil( $count / max( 1, (int) ( $settings['wpm'] ?? 200 ) ) ) );
		/* translators: %s: number of words */
		$words   = sprintf( _n( '%s word', '%s words', $count, 'columnkit' ), number_format_i18n( $count ) );
		/* translators: %d: minutes */
		$reading = $count === 0 ? '—' : sprintf( _n( '%d min read', '%d min read', $minutes, 'columnkit' ), $minutes );

		return match ( (string) ( $settings['display'] ?? 'words' ) ) {
			'reading' => esc_html( $reading ),
			'both'    => esc_html( $words ) . '<br><span class="ck-muted">' . esc_html( $reading ) . '</span>',
			default   => esc_html( $words ),
		};
	}

	public function get_export_value( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		return $post ? (string) self::count_words( $post->post_content ) : '';
	}

	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		$post_type = $query->get( 'post_type' );
		self::backfill( is_string( $post_type ) && $post_type !== '' ? $post_type : 'post' );

		global $wpdb;
		$join = $wpdb->prepare(
			"LEFT JOIN {$wpdb->postmeta} AS ck_wc ON {$wpdb->posts}.ID = ck_wc.post_id AND ck_wc.meta_key = %s",
			self::META_KEY
		);
		self::order_by_sql( $query, 'CAST(ck_wc.meta_value AS UNSIGNED)', $order, $join );
	}

	/** Compute counts for posts of this type that don't have one cached yet (bounded per request). */
	private static function backfill( string $post_type ): void {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				 WHERE p.post_type = %s AND p.post_status NOT IN ('auto-draft','inherit') AND m.meta_id IS NULL
				 LIMIT %d",
				self::META_KEY,
				$post_type,
				self::BACKFILL_BATCH
			)
		);
		foreach ( (array) $ids as $id ) {
			$post = get_post( (int) $id );
			if ( $post ) {
				update_post_meta( (int) $id, self::META_KEY, self::count_words( $post->post_content ) );
			}
		}
	}
}
