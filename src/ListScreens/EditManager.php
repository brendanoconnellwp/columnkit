<?php
declare( strict_types=1 );

namespace ColumnKit\ListScreens;

use ColumnKit\ColumnRegistry;
use ColumnKit\Columns\EditableColumn;
use ColumnKit\Settings\SettingsRepository;
use ColumnKit\Support\ScreenIdentifier;
use WP_Post;

/**
 * Inline edit (click-to-edit popover, AJAX-saved) + Bulk edit (WP's native panel).
 *
 * Two flows, two security models:
 *
 * 1. Click-to-edit popover:
 *    - Single AJAX endpoint `wp_ajax_ck_inline_save`.
 *    - Verified by our own nonce ('ck_inline_save'), our own cap check (`edit_post` on $post_id),
 *      and our own input validation.
 *    - Looks up the column config by post type → screen settings → col_id at request time.
 *    - Calls $col->save_value() then re-renders the cell HTML and returns it as JSON.
 *
 * 2. Bulk Edit (WP's native panel):
 *    - We render fields via `bulk_edit_custom_box`.
 *    - Save via `save_post`, requiring WP's 'bulk-posts' nonce + our cap check + an explicit
 *      "apply this column" checkbox per column (default unchecked).
 */
final class EditManager {
	public const INPUT_BULK     = 'ck_bulk';
	public const INPUT_APPLY    = 'ck_bulk_apply';
	public const AJAX_ACTION    = 'ck_inline_save';
	public const AJAX_NONCE     = 'ck_inline_save';
	public const CORE_PREFIX    = 'core_';

	/**
	 * Core column fields we support inline-editing on. Each entry: column slug => spec:
	 *   - input: 'text' | 'date' | 'select'
	 *   - td_class: WP's TD class for this column (used by JS to locate the cell)
	 *   - cap: capability required for this specific edit (in addition to edit_post on the row)
	 */
	private const CORE_FIELDS = [
		'title'  => [ 'input' => 'text',   'td_class' => 'column-title',  'cap' => null ],
		'date'   => [ 'input' => 'date',   'td_class' => 'column-date',   'cap' => null ],
		'author' => [ 'input' => 'select', 'td_class' => 'column-author', 'cap' => 'edit_others_posts' ],
	];

	/** @var array<int, array<string, mixed>> */
	private array $active_columns = [];

	private string $post_type = '';

	public function __construct(
		private ColumnRegistry $registry,
		private SettingsRepository $repository
	) {}

	/**
	 * Global hooks — registered once at boot, NOT per screen, because AJAX requests hit a
	 * different lifecycle (admin-ajax.php, not edit.php).
	 */
	public function register_global_hooks(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'ajax_inline_save' ] );
	}

	/** Static accessors used by Assets and core-data printer. */
	public static function core_fields(): array {
		return self::CORE_FIELDS;
	}

	/**
	 * Per-screen hooks — registered when ListScreenManager detects we're on a list table with
	 * active columns. Only handles WP's native Bulk Edit (inline edit is AJAX, see above).
	 *
	 * @param array<int, array<string, mixed>> $columns
	 */
	public function activate( string $post_type, array $columns ): void {
		$this->post_type      = $post_type;
		$this->active_columns = $columns;

		add_action( 'bulk_edit_custom_box', [ $this, 'render_bulk_edit' ], 10, 2 );
		add_action( 'save_post',            [ $this, 'on_save_post' ],     10, 3 );
	}

	// ------------------------------------------------------------------
	// Inline edit — AJAX
	// ------------------------------------------------------------------

	public function ajax_inline_save(): void {
		if ( ! check_ajax_referer( self::AJAX_NONCE, '_ajax_nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid security token.', 'columnkit' ) ], 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$col_id  = isset( $_POST['col_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['col_id'] ) ) : '';
		$value   = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['value'] ) ) : '';

		if ( $post_id <= 0 || $col_id === '' ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'columnkit' ) ], 400 );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'You cannot edit this post.', 'columnkit' ) ], 403 );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			wp_send_json_error( [ 'message' => __( 'Post not found.', 'columnkit' ) ], 404 );
		}

		// Core fields (Title, Date, Author) branch — col_id like "core_title".
		if ( str_starts_with( $col_id, self::CORE_PREFIX ) ) {
			$this->save_core_field( $post, substr( $col_id, strlen( self::CORE_PREFIX ) ), $value );
			return; // save_core_field always wp_send_json_*'s.
		}

		$screen_key = 'post_type:' . $post->post_type;
		$columns    = $this->repository->get_columns( $screen_key );
		$entry      = null;
		foreach ( $columns as $candidate ) {
			if ( ( $candidate['id'] ?? '' ) === $col_id ) {
				$entry = $candidate;
				break;
			}
		}
		if ( $entry === null ) {
			wp_send_json_error( [ 'message' => __( 'Column not configured.', 'columnkit' ) ], 404 );
		}

		$col = $this->registry->get( (string) ( $entry['type'] ?? '' ) );
		if ( ! $col instanceof EditableColumn ) {
			wp_send_json_error( [ 'message' => __( 'Column is not editable.', 'columnkit' ) ], 400 );
		}
		$settings = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : [];

		$col->save_value( $post_id, $value, $settings );

		// Re-render the cell (the column's render() returns escaped HTML) and read back the raw value.
		$html = $col->render( $post_id, $settings );
		$raw  = $col->get_raw_value( $post_id, $settings );

		wp_send_json_success(
			[
				'html' => $html,
				'raw'  => $raw,
			]
		);
	}

	// ------------------------------------------------------------------
	// Bulk edit — WP's native panel + save_post
	// ------------------------------------------------------------------

	public function render_bulk_edit( string $column_name, string $post_type ): void {
		if ( $post_type !== $this->post_type ) {
			return;
		}
		if ( ! str_starts_with( $column_name, 'ck_' ) ) {
			return;
		}
		$col_id = substr( $column_name, 3 );
		foreach ( $this->editable_entries() as $entry ) {
			if ( ( $entry['id'] ?? '' ) !== $col_id ) {
				continue;
			}
			$col = $this->registry->get( (string) ( $entry['type'] ?? '' ) );
			if ( ! $col instanceof EditableColumn ) {
				return;
			}
			$settings   = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : [];
			$input_name = self::INPUT_BULK . '[' . $col_id . ']';
			$apply_name = self::INPUT_APPLY . '[' . $col_id . ']';
			$label      = (string) ( $entry['label'] ?? '' );

			?>
			<fieldset class="inline-edit-col-right ck-bulk-edit" data-ck-col="<?php echo esc_attr( $col_id ); ?>">
				<div class="inline-edit-col">
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $apply_name ); ?>" value="1">
						<span class="title"><?php echo esc_html( $label ); ?></span>
						<span class="input-text-wrap"><?php $col->render_bulk_edit_field( $input_name, $settings ); ?></span>
					</label>
				</div>
			</fieldset>
			<?php
			return;
		}
	}

	public function on_save_post( int $post_id, WP_Post $post, bool $update ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( $post->post_type !== $this->post_type ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::INPUT_BULK ] ) || ! is_array( $_POST[ self::INPUT_BULK ] ) ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bulk-posts' ) ) {
			return;
		}

		$values_by_col = wp_unslash( (array) $_POST[ self::INPUT_BULK ] );
		$apply         = isset( $_POST[ self::INPUT_APPLY ] ) && is_array( $_POST[ self::INPUT_APPLY ] )
			? array_map( 'sanitize_key', array_keys( $_POST[ self::INPUT_APPLY ] ) )
			: [];

		foreach ( $this->editable_entries() as $entry ) {
			$col_id = (string) ( $entry['id'] ?? '' );
			if ( $col_id === '' || ! in_array( $col_id, $apply, true ) ) {
				continue;
			}
			if ( ! array_key_exists( $col_id, $values_by_col ) ) {
				continue;
			}
			$col = $this->registry->get( (string) ( $entry['type'] ?? '' ) );
			if ( ! $col instanceof EditableColumn ) {
				continue;
			}
			$settings = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : [];

			$raw = $values_by_col[ $col_id ];
			$raw = is_scalar( $raw ) ? (string) $raw : '';
			$raw = sanitize_text_field( $raw );

			$col->save_value( $post_id, $raw, $settings );
		}
	}

	// ------------------------------------------------------------------
	// Core fields (Title, Date, Author) — saved via wp_update_post
	// ------------------------------------------------------------------

	private function save_core_field( WP_Post $post, string $field, string $value ): void {
		if ( ! isset( self::CORE_FIELDS[ $field ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown core field.', 'columnkit' ) ], 400 );
		}
		$spec = self::CORE_FIELDS[ $field ];

		// Per-field additional capability (e.g. author edits need edit_others_posts).
		if ( $spec['cap'] !== null && ! current_user_can( $spec['cap'] ) ) {
			wp_send_json_error( [ 'message' => __( 'You cannot change this field.', 'columnkit' ) ], 403 );
		}

		$update = [ 'ID' => $post->ID ];
		$new_raw = '';
		$new_html = '';

		switch ( $field ) {
			case 'title':
				if ( trim( $value ) === '' ) {
					wp_send_json_error( [ 'message' => __( 'Title cannot be empty.', 'columnkit' ) ], 400 );
				}
				$update['post_title'] = $value;
				$new_raw  = $value;
				$new_html = '<strong><a class="row-title" href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">'
					. esc_html( $value ) . '</a></strong>';
				break;

			case 'date':
				$ts = strtotime( $value );
				if ( $ts === false ) {
					wp_send_json_error( [ 'message' => __( 'Invalid date.', 'columnkit' ) ], 400 );
				}
				// Preserve the post's existing time-of-day; only swap the date portion.
				$existing_ts = strtotime( $post->post_date );
				$time_str    = $existing_ts ? gmdate( 'H:i:s', $existing_ts ) : '00:00:00';
				$new_local   = gmdate( 'Y-m-d', $ts ) . ' ' . $time_str;
				$update['post_date']     = $new_local;
				$update['post_date_gmt'] = get_gmt_from_date( $new_local );
				$update['edit_date']     = true;
				$new_raw  = gmdate( 'Y-m-d', $ts );
				$new_html = '<abbr title="' . esc_attr( $new_local ) . '">'
					. esc_html( wp_date( (string) get_option( 'date_format', 'Y/m/d' ), $ts ) ) . '</abbr>';
				break;

			case 'author':
				$author_id = (int) $value;
				$user      = $author_id > 0 ? get_userdata( $author_id ) : false;
				if ( ! $user || ! user_can( $user, 'edit_posts' ) ) {
					wp_send_json_error( [ 'message' => __( 'Invalid author.', 'columnkit' ) ], 400 );
				}
				$update['post_author'] = $author_id;
				$new_raw  = (string) $author_id;
				$new_html = esc_html( $user->display_name );
				break;

			default:
				wp_send_json_error( [ 'message' => __( 'Unknown core field.', 'columnkit' ) ], 400 );
		}

		// Recursion guard: our on_save_post already short-circuits if INPUT_BULK isn't present,
		// and AJAX requests don't carry it, so wp_update_post here is safe. Belt-and-braces:
		// unhook before, rehook after.
		remove_action( 'save_post', [ $this, 'on_save_post' ], 10 );
		$result = wp_update_post( $update, true );
		add_action( 'save_post', [ $this, 'on_save_post' ], 10, 3 );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
		}

		wp_send_json_success( [ 'html' => $new_html, 'raw' => $new_raw ] );
	}

	/**
	 * Map of editable core fields → JS-side config (input type and options where applicable).
	 * Used by Assets.php when localising CK_INLINE.coreColumns.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function js_core_columns_config(): array {
		$out = [];
		foreach ( self::CORE_FIELDS as $field => $spec ) {
			$entry = [
				'input'    => $spec['input'],
				'td_class' => $spec['td_class'],
			];
			if ( $field === 'author' ) {
				$entry['options'] = self::author_options();
			}
			$out[ $field ] = $entry;
		}
		return $out;
	}

	/** @return array<string, string> user_id => display_name, capped at 200 entries. */
	private static function author_options(): array {
		$users = get_users( [
			'capability' => [ 'edit_posts' ],
			'number'     => 200,
			'orderby'    => 'display_name',
			'order'      => 'ASC',
			'fields'     => [ 'ID', 'display_name' ],
		] );
		$out = [];
		foreach ( $users as $u ) {
			$out[ (string) $u->ID ] = (string) $u->display_name;
		}
		return $out;
	}

	/**
	 * Collect per-post raw values for the current screen's posts, ready to ship to JS as a lookup
	 * map. Hooked on admin_footer-edit.php; the main query has already run by then.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function collect_core_data(): array {
		global $wp_query;
		if ( ! $wp_query || empty( $wp_query->posts ) ) {
			return [];
		}
		$out = [];
		foreach ( $wp_query->posts as $p ) {
			if ( ! $p instanceof WP_Post ) {
				continue;
			}
			$ts = strtotime( $p->post_date );
			$out[ (int) $p->ID ] = [
				'title'  => (string) $p->post_title,
				'date'   => $ts ? gmdate( 'Y-m-d', $ts ) : '',
				'author' => (string) $p->post_author,
			];
		}
		return $out;
	}

	/** @return array<int, array<string, mixed>> */
	private function editable_entries(): array {
		$out = [];
		foreach ( $this->active_columns as $entry ) {
			$col = $this->registry->get( (string) ( $entry['type'] ?? '' ) );
			if ( $col instanceof EditableColumn ) {
				$out[] = $entry;
			}
		}
		return $out;
	}
}
