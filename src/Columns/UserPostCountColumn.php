<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

/**
 * User post-count column — number of published posts authored by the user, for a chosen
 * post type (defaults to 'post').
 */
final class UserPostCountColumn extends BaseColumn {
	public function get_type(): string {
		return 'user_post_count';
	}

	public function get_label(): string {
		return __( 'User Post Count', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Published post count authored by the user, for a chosen post type.', 'columnkit' );
	}

	public function applies_to_screen( string $screen_key ): bool {
		return $screen_key === 'users';
	}

	public function settings_fields(): array {
		$options = [];
		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $pt ) {
			$options[ $pt->name ] = (string) $pt->labels->name;
		}
		return [
			[
				'key'     => 'post_type',
				'label'   => __( 'Post type', 'columnkit' ),
				'type'    => 'select',
				'options' => $options,
			],
		];
	}

	public function sanitize_settings( array $input ): array {
		$out = parent::sanitize_settings( $input );
		if ( isset( $out['post_type'] ) ) {
			$out['post_type'] = sanitize_key( $out['post_type'] );
		}
		if ( ! isset( $out['post_type'] ) || ! post_type_exists( $out['post_type'] ) ) {
			$out['post_type'] = 'post';
		}
		return $out;
	}

	public function render( int $object_id, array $settings ): string {
		$post_type = (string) ( $settings['post_type'] ?? 'post' );
		$count     = (int) count_user_posts( $object_id, $post_type, true );
		return esc_html( (string) $count );
	}
}
