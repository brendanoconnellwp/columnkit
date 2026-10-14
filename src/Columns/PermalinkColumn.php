<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

/** The public URL, as a link (path or full URL). Export always gives the full URL. */
final class PermalinkColumn extends AbstractPostFieldColumn {
	protected function supports_post_type( string $post_type ): bool {
		$obj = get_post_type_object( $post_type );
		return $obj !== null && ( $obj->public || $obj->publicly_queryable );
	}

	public function get_type(): string {
		return 'permalink';
	}

	public function get_label(): string {
		return __( 'Permalink', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'The public URL, linked so you can open or copy it.', 'columnkit' );
	}

	public function settings_fields(): array {
		return [
			[
				'key'     => 'display',
				'label'   => __( 'Show', 'columnkit' ),
				'type'    => 'select',
				'options' => [
					'path' => __( 'Path only (/about/team/)', 'columnkit' ),
					'full' => __( 'Full URL', 'columnkit' ),
				],
			],
		];
	}

	public function sanitize_settings( array $input ): array {
		$out = parent::sanitize_settings( $input );
		if ( ! in_array( $out['display'] ?? '', [ 'path', 'full' ], true ) ) {
			$out['display'] = 'path';
		}
		return $out;
	}

	public function render( int $object_id, array $settings ): string {
		$post = self::post( $object_id );
		$url  = $post ? get_permalink( $post ) : false;
		if ( ! is_string( $url ) || $url === '' ) {
			return self::empty_cell();
		}
		$text = $url;
		if ( ( $settings['display'] ?? 'path' ) === 'path' ) {
			$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
			$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
			$text  = ( $path !== '' ? $path : '/' ) . ( $query !== '' ? '?' . $query : '' );
		}
		return sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $url ), esc_html( urldecode( $text ) ) );
	}

	public function get_export_value( int $object_id, array $settings ): string {
		$url = get_permalink( $object_id );
		return is_string( $url ) ? $url : '';
	}
}
