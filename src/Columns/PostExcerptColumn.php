<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use WP_Query;

/**
 * The excerpt, trimmed for the table. When a post has no hand-written excerpt the cell can show
 * a muted preview generated from the content, so "missing excerpt" is still visible at a glance.
 * Inline-editable (textarea) and filterable by "has excerpt / missing".
 */
final class PostExcerptColumn extends AbstractPostFieldColumn implements FilterableColumn, InlineOnlyEditable {
	protected function supports_post_type( string $post_type ): bool {
		return post_type_supports( $post_type, 'excerpt' ) || post_type_supports( $post_type, 'editor' );
	}

	public function get_type(): string {
		return 'post_excerpt';
	}

	public function get_label(): string {
		return __( 'Excerpt', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'The excerpt (or a preview of the content when none is set) — edit it inline, filter for missing ones.', 'columnkit' );
	}

	public function settings_fields(): array {
		return [
			[
				'key'     => 'words',
				'label'   => __( 'Show up to', 'columnkit' ),
				'type'    => 'select',
				'options' => [
					'15' => __( '15 words', 'columnkit' ),
					'30' => __( '30 words', 'columnkit' ),
					'60' => __( '60 words', 'columnkit' ),
				],
			],
			[
				'key'     => 'fallback',
				'label'   => __( 'When empty', 'columnkit' ),
				'type'    => 'select',
				'options' => [
					'content' => __( 'Show a preview of the content (muted)', 'columnkit' ),
					'none'    => __( 'Show nothing', 'columnkit' ),
				],
			],
		];
	}

	public function sanitize_settings( array $input ): array {
		$out = parent::sanitize_settings( $input );
		if ( ! in_array( $out['words'] ?? '', [ '15', '30', '60' ], true ) ) {
			$out['words'] = '15';
		}
		if ( ! in_array( $out['fallback'] ?? '', [ 'content', 'none' ], true ) ) {
			$out['fallback'] = 'content';
		}
		return $out;
	}

	public function render( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		if ( ! $post ) {
			return '';
		}
		$words = (int) ( $settings['words'] ?? 15 );
		if ( trim( $post->post_excerpt ) !== '' ) {
			return esc_html( wp_trim_words( $post->post_excerpt, $words ) );
		}
		if ( ( $settings['fallback'] ?? 'content' ) === 'content' && trim( $post->post_content ) !== '' ) {
			$text = wp_trim_words( strip_shortcodes( excerpt_remove_blocks( $post->post_content ) ), $words );
			return '<span class="ck-muted" title="' . esc_attr__( 'No excerpt — generated from the content', 'columnkit' ) . '">' . esc_html( $text ) . '</span>';
		}
		return '';
	}

	public function get_export_value( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		return $post ? $post->post_excerpt : '';
	}

	// --- Inline edit -----------------------------------------------------

	public function get_raw_value( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		return $post ? $post->post_excerpt : '';
	}

	public function get_edit_input_type( array $settings ): string {
		return 'textarea';
	}

	public function get_edit_options( array $settings ): ?array {
		return null;
	}

	public function render_bulk_edit_field( string $input_name, array $settings ): void {
		// Inline-only: excerpts are per post.
	}

	public function save_value( int $post_id, string $raw_value, array $settings ): void {
		// The AJAX layer applied sanitize_text_field (single line); excerpts are plain text, so
		// that's the right shape. wp_update_post runs the excerpt through core's filters too.
		self::update_post( $post_id, [ 'post_excerpt' => $raw_value ] );
	}

	// --- Filter: has / missing ------------------------------------------

	public function filter_value_keys(): array {
		return [ '' ];
	}

	public function render_filter( string $name_prefix, array $settings, array $current ): void {
		$v = (string) ( $current[''] ?? '' );
		echo '<select name="' . esc_attr( $name_prefix ) . '">';
		printf( '<option value="">%s</option>', esc_html__( 'Excerpt: any', 'columnkit' ) );
		printf( '<option value="yes" %s>%s</option>', selected( $v, 'yes', false ), esc_html__( 'Has excerpt', 'columnkit' ) );
		printf( '<option value="no" %s>%s</option>', selected( $v, 'no', false ), esc_html__( 'Missing excerpt', 'columnkit' ) );
		echo '</select>';
	}

	public function apply_filter( WP_Query $query, array $settings, array $values ): void {
		$v = (string) ( $values[''] ?? '' );
		if ( $v !== 'yes' && $v !== 'no' ) {
			return;
		}
		add_filter(
			'posts_where',
			static function ( string $where, $q ) use ( $query, $v ) {
				if ( $q !== $query ) {
					return $where;
				}
				global $wpdb;
				return $where . ( $v === 'yes' ? " AND {$wpdb->posts}.post_excerpt <> ''" : " AND {$wpdb->posts}.post_excerpt = ''" );
			},
			10,
			2
		);
	}
}
