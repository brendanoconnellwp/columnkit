<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use WP_Query;

final class FeaturedImageColumn extends BaseColumn implements FilterableColumn {
	public function get_type(): string {
		return 'featured_image';
	}

	public function get_label(): string {
		return __( 'Featured Image', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Thumbnail of the post’s featured image.', 'columnkit' );
	}

	public function applies_to_screen( string $screen_key ): bool {
		// Media items ARE images, not posts WITH a featured image.
		return str_starts_with( $screen_key, 'post_type:' );
	}

	public function settings_fields(): array {
		return [
			[
				'key'     => 'size',
				'label'   => __( 'Image size', 'columnkit' ),
				'type'    => 'select',
				'options' => [
					'thumbnail' => 'thumbnail',
					'medium'    => 'medium',
					'large'     => 'large',
				],
			],
		];
	}

	public function sanitize_settings( array $input ): array {
		$out = parent::sanitize_settings( $input );
		$allowed = [ 'thumbnail', 'medium', 'large' ];
		if ( ! isset( $out['size'] ) || ! in_array( $out['size'], $allowed, true ) ) {
			$out['size'] = 'thumbnail';
		}
		return $out;
	}

	public function render( int $object_id, array $settings ): string {
		$size = (string) ( $settings['size'] ?? 'thumbnail' );
		$id   = (int) get_post_thumbnail_id( $object_id );
		if ( $id <= 0 ) {
			return '';
		}
		// get_the_post_thumbnail escapes attributes for us.
		return get_the_post_thumbnail(
			$object_id,
			$size,
			[
				'style'   => 'max-width:64px;height:auto;',
				'loading' => 'lazy',
			]
		);
	}

	// ------------------------------------------------------------------
	// FilterableColumn — filter by "has image" / "no image"
	// ------------------------------------------------------------------

	public function filter_value_keys(): array {
		return [ '' ];
	}

	public function render_filter( string $name_prefix, array $settings, array $current ): void {
		$v = (string) ( $current[''] ?? '' );
		echo '<select name="' . esc_attr( $name_prefix ) . '">';
		printf( '<option value="">%s</option>', esc_html__( 'Featured image: any', 'columnkit' ) );
		printf( '<option value="yes" %s>%s</option>', selected( $v, 'yes', false ), esc_html__( 'Has image', 'columnkit' ) );
		printf( '<option value="no" %s>%s</option>', selected( $v, 'no', false ), esc_html__( 'No image', 'columnkit' ) );
		echo '</select>';
	}

	public function get_export_value( int $object_id, array $settings ): string {
		$id = (int) get_post_thumbnail_id( $object_id );
		if ( $id <= 0 ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $id, 'full' );
		return is_string( $url ) ? $url : '';
	}

	public function apply_filter( WP_Query $query, array $settings, array $values ): void {
		$v = (string) ( $values[''] ?? '' );
		if ( $v !== 'yes' && $v !== 'no' ) {
			return;
		}
		$raw_mq = $query->get( 'meta_query' );
		$existing = is_array( $raw_mq ) ? $raw_mq : [];
		$existing[] = [
			'key'     => '_thumbnail_id',
			'compare' => $v === 'yes' ? 'EXISTS' : 'NOT EXISTS',
		];
		$query->set( 'meta_query', $existing );
	}
}
