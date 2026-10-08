<?php
declare( strict_types=1 );

namespace ColumnKit\ListScreens;

use ColumnKit\Support\TermAssigner;
use WP_Post;
use WP_Term;
use WP_User;

/**
 * Inline editing of WordPress's OWN list-table columns (not ColumnKit's custom ones):
 *
 *   Posts / pages / CPTs : Title, Date, Author, and every taxonomy column (Categories, Tags,
 *                          taxonomy-{slug}) — saved by EditManager::save_core_field().
 *   Taxonomy term lists  : Name, Slug, Description — wp_update_term(), gated on edit_term.
 *   Users                : Email (edit_user) and Role (promote_user, editable roles only,
 *                          never your own) — wp_update_user().
 *
 * The browser decorates the existing cells (admin-inline.js): this class supplies the column
 * config (CK_INLINE.coreColumns), each row's raw values (CK_INLINE.coreData, printed in the
 * footer once the list has rendered), and the term-picker lists (CK_INLINE.taxonomies).
 */
final class CoreFields {
	/** @var array<int, array<string, string>> object id => field => raw value */
	private static array $data = [];

	private static string $object = '';

	/**
	 * JS config for the current list screen, and arm the per-row collectors. Returns [] when
	 * the screen has no editable core columns for this user.
	 *
	 * @return array<string, mixed>
	 */
	public static function boot_for_screen( \WP_Screen $screen ): array {
		if ( $screen->base === 'edit' && is_string( $screen->post_type ) && $screen->post_type !== '' ) {
			return self::boot_posts( $screen->post_type );
		}
		if ( $screen->base === 'edit-tags' && is_string( $screen->taxonomy ) && $screen->taxonomy !== '' ) {
			return self::boot_terms( $screen->taxonomy );
		}
		if ( $screen->base === 'users' ) {
			return self::boot_users();
		}
		return [];
	}

	/** @return array<int, array<string, string>> */
	public static function data(): array {
		return self::$data;
	}

	// ------------------------------------------------------------------
	// Posts
	// ------------------------------------------------------------------

	/** @return array<string, mixed> */
	private static function boot_posts( string $post_type ): array {
		self::$object = 'post';
		$columns      = [];
		foreach ( EditManager::js_core_columns_config() as $field => $spec ) {
			$columns[ $field ] = $spec;
		}
		$taxonomies = [];
		foreach ( TermAssigner::editable_taxonomies( $post_type ) as $tax ) {
			$config = TermAssigner::picker_config( $tax->name );
			if ( $config === null ) {
				continue;
			}
			$taxonomies[ $tax->name ] = $config;
			if ( $tax->show_admin_column || in_array( $tax->name, [ 'category', 'post_tag' ], true ) ) {
				$key             = TermAssigner::core_column_key( $tax->name );
				$columns[ $key ] = [ 'input' => 'terms', 'column' => $key, 'taxonomy' => $tax->name ];
			}
		}

		$collect = static function ( array $actions, $post ) use ( $columns ): array {
			if ( $post instanceof WP_Post ) {
				self::$data[ (int) $post->ID ] = self::post_row( $post, $columns );
			}
			return $actions;
		};
		add_filter( 'post_row_actions', $collect, 10, 2 );
		add_filter( 'page_row_actions', $collect, 10, 2 );

		return [ 'coreObject' => 'post', 'coreColumns' => $columns, 'taxonomies' => $taxonomies ];
	}

	/**
	 * @param array<string, array<string, mixed>> $columns
	 * @return array<string, string>
	 */
	private static function post_row( WP_Post $post, array $columns ): array {
		$ts  = strtotime( $post->post_date );
		$row = [
			'title'  => (string) $post->post_title,
			'date'   => $ts ? gmdate( 'Y-m-d', $ts ) : '',
			'author' => (string) $post->post_author,
		];
		foreach ( $columns as $key => $spec ) {
			if ( ( $spec['input'] ?? '' ) === 'terms' ) {
				$row[ $key ] = TermAssigner::raw_value( (int) $post->ID, (string) $spec['taxonomy'] );
			}
		}
		return $row;
	}

	// ------------------------------------------------------------------
	// Terms
	// ------------------------------------------------------------------

	/** @return array<string, mixed> */
	private static function boot_terms( string $taxonomy ): array {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || ! current_user_can( $tax->cap->edit_terms ) ) {
			return [];
		}
		self::$object = 'term';
		add_filter(
			"{$taxonomy}_row_actions",
			static function ( array $actions, $term ): array {
				if ( $term instanceof WP_Term && current_user_can( 'edit_term', $term->term_id ) ) {
					self::$data[ (int) $term->term_id ] = [
						'name'        => (string) $term->name,
						'slug'        => (string) apply_filters( 'editable_slug', $term->slug, $term ),
						'description' => (string) $term->description,
					];
				}
				return $actions;
			},
			10,
			2
		);
		return [
			'coreObject'  => 'term',
			'coreColumns' => [
				'name'        => [ 'input' => 'text', 'column' => 'name', 'wrap' => 'strong' ],
				'slug'        => [ 'input' => 'text', 'column' => 'slug' ],
				'description' => [ 'input' => 'textarea', 'column' => 'description' ],
			],
		];
	}

	/**
	 * Save one core term field. Caller verified nonce, screen and edit_term.
	 *
	 * @return array{html: string, raw: string}|\WP_Error
	 */
	public static function save_term_field( int $term_id, string $taxonomy, string $field, string $value ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof WP_Term ) {
			return new \WP_Error( 'ck_not_found', __( 'Term not found.', 'columnkit' ) );
		}
		$args = match ( $field ) {
			'name'        => [ 'name' => $value ],
			'slug'        => [ 'slug' => $value ],
			'description' => [ 'description' => $value ],
			default       => null,
		};
		if ( $args === null ) {
			return new \WP_Error( 'ck_bad_field', __( 'Unknown core field.', 'columnkit' ) );
		}
		if ( $field === 'name' && trim( $value ) === '' ) {
			return new \WP_Error( 'ck_empty', __( 'Name cannot be empty.', 'columnkit' ) );
		}
		$result = wp_update_term( $term_id, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof WP_Term ) {
			return new \WP_Error( 'ck_not_found', __( 'Term not found.', 'columnkit' ) );
		}
		switch ( $field ) {
			case 'name':
				$link = get_edit_term_link( $term, $taxonomy );
				return [
					'html' => '<strong><a class="row-title" href="' . esc_url( (string) $link ) . '">' . esc_html( $term->name ) . '</a></strong>',
					'raw'  => (string) $term->name,
				];
			case 'slug':
				$slug = (string) apply_filters( 'editable_slug', $term->slug, $term );
				return [ 'html' => esc_html( $slug ), 'raw' => $slug ];
			default:
				$desc = (string) $term->description;
				return [
					'html' => $desc !== '' ? esc_html( $desc ) : '<span aria-hidden="true">&#8212;</span>',
					'raw'  => $desc,
				];
		}
	}

	// ------------------------------------------------------------------
	// Users
	// ------------------------------------------------------------------

	/** @return array<string, mixed> */
	private static function boot_users(): array {
		self::$object = 'user';
		$roles        = self::assignable_roles();
		add_filter(
			'user_row_actions',
			static function ( array $actions, $user ): array {
				if ( $user instanceof WP_User && current_user_can( 'edit_user', $user->ID ) ) {
					self::$data[ (int) $user->ID ] = [
						'email' => (string) $user->user_email,
						'role'  => (string) ( $user->roles[0] ?? '' ),
					];
				}
				return $actions;
			},
			10,
			2
		);
		$columns = [ 'email' => [ 'input' => 'text', 'column' => 'email' ] ];
		if ( $roles !== [] && current_user_can( 'promote_users' ) ) {
			$columns['role'] = [ 'input' => 'select', 'column' => 'role', 'options' => $roles ];
		}
		return [ 'coreObject' => 'user', 'coreColumns' => $columns, 'selfId' => get_current_user_id() ];
	}

	/** @return array<string, string> role slug => translated name, limited to editable roles. */
	private static function assignable_roles(): array {
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$out = [];
		foreach ( get_editable_roles() as $slug => $role ) {
			$out[ (string) $slug ] = translate_user_role( (string) $role['name'] );
		}
		return $out;
	}

	/**
	 * Save one core user field. Caller verified nonce, screen and edit_user.
	 *
	 * @return array{html: string, raw: string}|\WP_Error
	 */
	public static function save_user_field( int $user_id, string $field, string $value ) {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return new \WP_Error( 'ck_not_found', __( 'User not found.', 'columnkit' ) );
		}

		if ( $field === 'email' ) {
			$email = sanitize_email( $value );
			if ( $email === '' || ! is_email( $email ) ) {
				return new \WP_Error( 'ck_bad_email', __( 'Please enter a valid email address.', 'columnkit' ) );
			}
			$owner = email_exists( $email );
			if ( $owner && (int) $owner !== $user_id ) {
				return new \WP_Error( 'ck_email_taken', __( 'That email address is already used by another account.', 'columnkit' ) );
			}
			$result = wp_update_user( [ 'ID' => $user_id, 'user_email' => $email ] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return [ 'html' => '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>', 'raw' => $email ];
		}

		if ( $field === 'role' ) {
			// Same rules as core's "Change role to…": promote_user on the target, an editable
			// role, and never your own account (you could lock yourself out).
			if ( $user_id === get_current_user_id() ) {
				return new \WP_Error( 'ck_self_role', __( 'You cannot change your own role here.', 'columnkit' ) );
			}
			if ( ! current_user_can( 'promote_user', $user_id ) ) {
				return new \WP_Error( 'ck_forbidden', __( 'You cannot change this user’s role.', 'columnkit' ) );
			}
			$roles = self::assignable_roles();
			if ( ! isset( $roles[ $value ] ) ) {
				return new \WP_Error( 'ck_bad_role', __( 'You cannot assign that role.', 'columnkit' ) );
			}
			$user->set_role( $value );
			return [ 'html' => esc_html( $roles[ $value ] ), 'raw' => $value ];
		}

		return new \WP_Error( 'ck_bad_field', __( 'Unknown core field.', 'columnkit' ) );
	}
}
