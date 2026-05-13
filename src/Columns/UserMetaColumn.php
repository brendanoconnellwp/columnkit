<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

/**
 * User meta column — reads via get_user_meta. Only valid on the Users screen.
 * Read-only display in v1 (no inline edit / sort / filter on the users screen, since WP user
 * queries use a different mechanism than post queries — deferred to later iteration).
 */
final class UserMetaColumn extends BaseColumn {
	public function get_type(): string {
		return 'user_meta';
	}

	public function get_label(): string {
		return __( 'User Meta', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Show the value of a user meta key.', 'columnkit' );
	}

	public function applies_to_screen( string $screen_key ): bool {
		return $screen_key === 'users';
	}

	public function settings_fields(): array {
		return [
			[ 'key' => 'meta_key', 'label' => __( 'User meta key', 'columnkit' ), 'type' => 'text', 'required' => true ],
		];
	}

	public function sanitize_settings( array $input ): array {
		$out = parent::sanitize_settings( $input );
		if ( isset( $out['meta_key'] ) ) {
			$out['meta_key'] = preg_replace( '/[^A-Za-z0-9_\-.]/', '', $out['meta_key'] );
		}
		return $out;
	}

	public function render( int $object_id, array $settings ): string {
		$key = (string) ( $settings['meta_key'] ?? '' );
		if ( $key === '' ) {
			return '';
		}
		$val = get_user_meta( $object_id, $key, true );
		if ( is_array( $val ) || is_object( $val ) ) {
			$val = wp_json_encode( $val );
		}
		return esc_html( (string) $val );
	}
}
