<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

use WP_Query;

final class AuthorColumn extends BaseColumn implements SortableColumn, FilterableColumn {
	public function get_type(): string {
		return 'author';
	}

	public function get_label(): string {
		return __( 'Author', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Display name of the post author.', 'columnkit' );
	}

	public function applies_to_screen( string $screen_key ): bool {
		return str_starts_with( $screen_key, 'post_type:' ) || $screen_key === 'media';
	}

	public function settings_fields(): array {
		return [
			[
				'key'     => 'display',
				'label'   => __( 'Show', 'columnkit' ),
				'type'    => 'select',
				'options' => [
					'display_name' => __( 'Display name', 'columnkit' ),
					'user_login'   => __( 'Username', 'columnkit' ),
					'user_email'   => __( 'Email', 'columnkit' ),
				],
			],
		];
	}

	public function sanitize_settings( array $input ): array {
		$out = parent::sanitize_settings( $input );
		$allowed = [ 'display_name', 'user_login', 'user_email' ];
		if ( ! isset( $out['display'] ) || ! in_array( $out['display'], $allowed, true ) ) {
			$out['display'] = 'display_name';
		}
		return $out;
	}

	public function render( int $object_id, array $settings ): string {
		$post = get_post( $object_id );
		if ( ! $post ) {
			return '';
		}
		$user = get_userdata( (int) $post->post_author );
		if ( ! $user ) {
			return '';
		}
		$field = (string) ( $settings['display'] ?? 'display_name' );
		$value = $user->{$field} ?? '';
		return esc_html( (string) $value );
	}

	// ------------------------------------------------------------------
	// SortableColumn
	// ------------------------------------------------------------------

	public function apply_sort( WP_Query $query, array $settings, string $order ): void {
		// WP handles 'author' orderby natively (post_author column).
		$query->set( 'orderby', 'author' );
		$query->set( 'order', $order );
	}

	// ------------------------------------------------------------------
	// FilterableColumn
	// ------------------------------------------------------------------

	public function filter_value_keys(): array {
		return [ '' ];
	}

	public function render_filter( string $name_prefix, array $settings, array $current ): void {
		wp_dropdown_users(
			[
				'name'             => $name_prefix,
				'show_option_all'  => __( 'All authors', 'columnkit' ),
				'selected'         => (int) ( $current[''] ?? 0 ),
				'capability'       => [ 'edit_posts' ],
			]
		);
	}

	public function apply_filter( WP_Query $query, array $settings, array $values ): void {
		$author_id = (int) ( $values[''] ?? 0 );
		if ( $author_id > 0 ) {
			$query->set( 'author', $author_id );
		}
	}
}
