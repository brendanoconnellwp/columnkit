<?php
declare( strict_types=1 );

namespace ColumnKit\Columns;

/**
 * User role column — shows all the role display-names for the user.
 */
final class UserRoleColumn extends BaseColumn {
	public function get_type(): string {
		return 'user_role';
	}

	public function get_label(): string {
		return __( 'User Role', 'columnkit' );
	}

	public function get_description(): string {
		return __( 'Translated display names of the user\'s assigned roles.', 'columnkit' );
	}

	public function applies_to_screen( string $screen_key ): bool {
		return $screen_key === 'users';
	}

	public function render( int $object_id, array $settings ): string {
		$user = get_userdata( $object_id );
		if ( ! $user || empty( $user->roles ) ) {
			return '';
		}
		$wp_roles = wp_roles()->roles;
		$names    = [];
		foreach ( $user->roles as $role ) {
			$display = isset( $wp_roles[ $role ]['name'] ) ? $wp_roles[ $role ]['name'] : $role;
			$names[] = translate_user_role( $display );
		}
		return esc_html( implode( ', ', $names ) );
	}
}
