<?php
declare( strict_types=1 );

namespace ColumnKit\Admin;

use ColumnKit\ColumnRegistry;
use ColumnKit\Columns\ColumnInterface;
use ColumnKit\Columns\ContextualColumn;
use ColumnKit\Settings\Sanitizer;
use ColumnKit\Settings\SettingsRepository;
use ColumnKit\Support\NativeColumns;
use ColumnKit\Support\ScreenIdentifier;

/**
 * The column editor (Settings → Admin Columns).
 *
 * Layout mirrors Admin Columns Pro: every list screen in a sidebar, and for the selected screen
 * one ordered list holding BOTH WordPress's built-in columns (rename / hide / resize / reorder)
 * and our custom columns (configure / remove). "Add column" opens a searchable picker grouped by
 * source; field integrations list each field directly, so adding "ACF › Priority" is one click.
 *
 * Saving posts one unified, ordered row list. handle_save() splits it into the set's custom
 * `columns` (sanitised exactly as before) and its `layout` (order + built-in overrides).
 */
final class SettingsPage {
	public const MENU_SLUG  = 'columnkit';
	public const CAPABILITY = 'manage_options';
	public const NONCE      = 'ck_save_columns';
	public const ACTION     = 'ck_save_columns';
	public const SET_ACTION = 'ck_set_action';
	public const SET_NONCE  = 'ck_set_action';

	/** Integration namespace segment => picker group label. */
	private const GROUP_LABELS = [
		'ACF'         => 'Advanced Custom Fields',
		'MetaBox'     => 'Meta Box',
		'JetEngine'   => 'JetEngine',
		'WooCommerce' => 'WooCommerce',
		'Yoast'       => 'Yoast SEO',
	];

	public function __construct(
		private ColumnRegistry $registry,
		private SettingsRepository $repository,
		private SettingsExporter $settings_exporter
	) {}

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_save' ] );
		add_action( 'admin_post_' . self::SET_ACTION, [ $this, 'handle_set_action' ] );
		add_filter( 'plugin_action_links_' . CK_BASENAME, [ $this, 'plugin_action_links' ] );
	}

	public function register_menu(): void {
		add_options_page(
			__( 'Admin Columns', 'columnkit' ),
			__( 'Admin Columns', 'columnkit' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * @param array<int|string, string> $links
	 * @return array<int|string, string>
	 */
	public function plugin_action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'Manage columns', 'columnkit' ) )
		);
		return $links;
	}

	/** @param array<string, string> $args */
	public static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::MENU_SLUG ], $args ), admin_url( 'options-general.php' ) );
	}

	// ------------------------------------------------------------------
	// Page
	// ------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'columnkit' ) );
		}

		$screens     = ScreenIdentifier::available_screens();
		$default_key = isset( $screens['post_type:post'] ) ? 'post_type:post' : (string) ( array_key_first( $screens ) ?? '' );
		$screen_key  = isset( $_GET['screen'] ) && is_string( $_GET['screen'] ) ? sanitize_text_field( wp_unslash( $_GET['screen'] ) ) : $default_key;
		if ( ! isset( $screens[ $screen_key ] ) ) {
			$screen_key = $default_key;
		}

		$sets   = $screen_key !== '' ? $this->repository->get_sets( $screen_key ) : [ SettingsRepository::DEFAULT_SET => 'Default' ];
		$set_id = isset( $_GET['set'] ) && is_string( $_GET['set'] ) ? SettingsRepository::sanitize_set_id( wp_unslash( $_GET['set'] ) ) : SettingsRepository::DEFAULT_SET;
		if ( ! isset( $sets[ $set_id ] ) ) {
			$set_id = SettingsRepository::DEFAULT_SET;
		}

		$columns = $screen_key !== '' ? $this->repository->get_columns( $screen_key, $set_id ) : [];
		$layout  = $screen_key !== '' ? $this->repository->get_layout( $screen_key, $set_id ) : [];
		$natives = $screen_key !== '' ? NativeColumns::for_screen( $screen_key ) : [];
		$rows    = self::merge_rows( $natives, $columns, $layout );
		$saved   = isset( $_GET['updated'] ) && $_GET['updated'] === '1';
		$list    = $screen_key !== '' ? add_query_arg( 'ck_set', $set_id, ScreenIdentifier::list_url( $screen_key ) ) : '';
		?>
		<div class="wrap ck-wrap">
			<div class="ck-topbar">
				<div class="ck-topbar-title">
					<h1><?php esc_html_e( 'Admin Columns', 'columnkit' ); ?></h1>
					<span class="ck-version">ColumnKit <?php echo esc_html( CK_VERSION ); ?></span>
				</div>
				<button type="button" class="button ck-tools-toggle" aria-expanded="false" aria-controls="ck-tools">
					<span class="dashicons dashicons-database-export" aria-hidden="true"></span>
					<?php esc_html_e( 'Import / Export', 'columnkit' ); ?>
				</button>
			</div>
			<hr class="wp-header-end">

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php esc_html_e( 'Columns saved.', 'columnkit' ); ?>
					<?php if ( $list !== '' ) : ?>
						<a href="<?php echo esc_url( $list ); ?>"><?php esc_html_e( 'View the list →', 'columnkit' ); ?></a>
					<?php endif; ?>
				</p></div>
			<?php endif; ?>
			<?php $this->render_flash_notice(); ?>

			<div id="ck-tools" class="ck-tools" hidden>
				<?php $this->settings_exporter->render_section(); ?>
			</div>

			<div class="ck-layout">
				<?php $this->render_sidebar( $screens, $screen_key ); ?>

				<main class="ck-main">
					<header class="ck-main-head">
						<div>
							<h2 class="ck-screen-title"><?php echo esc_html( self::screen_title( $screens[ $screen_key ] ?? '' ) ); ?></h2>
							<p class="ck-screen-sub"><?php esc_html_e( 'Drag to reorder. Rename or hide WordPress columns, add your own, then save.', 'columnkit' ); ?></p>
						</div>
						<?php if ( $list !== '' ) : ?>
							<a class="button ck-open-list" href="<?php echo esc_url( $list ); ?>">
								<?php esc_html_e( 'Open list', 'columnkit' ); ?>
								<span class="dashicons dashicons-external" aria-hidden="true"></span>
							</a>
						<?php endif; ?>
					</header>

					<?php $this->render_set_bar( $screen_key, $sets, $set_id ); ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ck-form" id="ck-form">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
						<input type="hidden" name="screen" value="<?php echo esc_attr( $screen_key ); ?>">
						<input type="hidden" name="set" value="<?php echo esc_attr( $set_id ); ?>">
						<?php wp_nonce_field( self::NONCE ); ?>

						<div class="ck-table" role="list">
							<div class="ck-table-head" aria-hidden="true">
								<span class="ck-th-label"><?php esc_html_e( 'Column', 'columnkit' ); ?></span>
								<span class="ck-th-type"><?php esc_html_e( 'Type', 'columnkit' ); ?></span>
								<span class="ck-th-width"><?php esc_html_e( 'Width', 'columnkit' ); ?></span>
								<span class="ck-th-actions"></span>
							</div>
							<div class="ck-columns" id="ck-columns">
								<?php
								foreach ( $rows as $i => $row ) {
									if ( $row['kind'] === 'native' ) {
										$this->render_native_row( (int) $i, $row['key'], $row['default'], $row['cfg'], self::native_editable( $screen_key, $row['key'] ) );
									} else {
										$this->render_column_row( (int) $i, $row['entry'], $screen_key );
									}
								}
								?>
							</div>
							<?php if ( $rows === [] ) : ?>
								<p class="ck-empty-note"><?php esc_html_e( 'No columns yet — add one below.', 'columnkit' ); ?></p>
							<?php endif; ?>
						</div>

						<?php $this->render_picker( $screen_key ); ?>

						<div class="ck-savebar">
							<span class="ck-dirty" hidden>
								<span class="ck-dirty-dot" aria-hidden="true"></span>
								<?php esc_html_e( 'Unsaved changes', 'columnkit' ); ?>
							</span>
							<span class="ck-savebar-hint"><?php esc_html_e( 'Ctrl / ⌘ + S to save', 'columnkit' ); ?></span>
							<button type="submit" class="button button-primary button-large ck-save"><?php esc_html_e( 'Save changes', 'columnkit' ); ?></button>
						</div>
					</form>
				</main>
			</div>

			<?php $this->render_templates( $screen_key ); ?>

			<?php // Filled by admin.js from the ck_meta_keys AJAX endpoint — real meta keys for
			      // this screen, so Custom Field columns offer pick-from-list instead of typing. ?>
			<datalist id="ck-meta-keys" data-screen="<?php echo esc_attr( $screen_key ); ?>"></datalist>
		</div>
		<?php
	}

	/** "Posts — Pages" → "Pages"; the sidebar already says which group a screen is in. */
	private static function screen_title( string $label ): string {
		$pos = strpos( $label, ' — ' );
		return $pos === false ? $label : substr( $label, $pos + strlen( ' — ' ) );
	}

	/**
	 * Merge built-in + custom columns into the editor's row order.
	 *
	 * With a saved layout: its order first, then anything it doesn't mention (new built-ins
	 * from a plugin activated since, custom columns added before the layout existed). Without
	 * one: built-ins first, customs after — exactly how the list table renders today.
	 *
	 * @param array<string, string>            $natives key => default label
	 * @param array<int, array<string, mixed>> $columns custom column entries
	 * @param array<string, mixed>             $layout
	 * @return array<int, array<string, mixed>>
	 */
	public static function merge_rows( array $natives, array $columns, array $layout ): array {
		$by_key = [];
		foreach ( $natives as $key => $default ) {
			$cfg              = is_array( $layout['native'][ $key ] ?? null ) ? $layout['native'][ $key ] : [];
			$by_key[ $key ] = [ 'kind' => 'native', 'key' => (string) $key, 'default' => (string) $default, 'cfg' => $cfg ];
		}
		foreach ( $columns as $entry ) {
			$key            = 'ck_' . (string) ( $entry['id'] ?? '' );
			$by_key[ $key ] = [ 'kind' => 'custom', 'key' => $key, 'entry' => $entry ];
		}

		$rows = [];
		foreach ( is_array( $layout['order'] ?? null ) ? $layout['order'] : [] as $key ) {
			if ( isset( $by_key[ $key ] ) ) {
				$rows[] = $by_key[ $key ];
				unset( $by_key[ $key ] );
			}
		}
		foreach ( $by_key as $row ) {
			$rows[] = $row;
		}
		return $rows;
	}

	// ------------------------------------------------------------------
	// Sidebar
	// ------------------------------------------------------------------

	/** @param array<string, string> $screens */
	private function render_sidebar( array $screens, string $active ): void {
		$groups = [
			'content'    => [ 'label' => __( 'Content', 'columnkit' ), 'items' => [] ],
			'taxonomies' => [ 'label' => __( 'Taxonomies', 'columnkit' ), 'items' => [] ],
			'other'      => [ 'label' => __( 'Media & users', 'columnkit' ), 'items' => [] ],
		];
		foreach ( $screens as $key => $label ) {
			if ( $key !== $active && ScreenIdentifier::is_internal( $key ) && $this->repository->get_columns( $key ) === [] ) {
				continue;
			}
			$group = str_starts_with( $key, 'post_type:' ) ? 'content' : ( str_starts_with( $key, 'taxonomy:' ) ? 'taxonomies' : 'other' );
			$groups[ $group ]['items'][ $key ] = $label;
		}
		?>
		<nav class="ck-sidebar" aria-label="<?php esc_attr_e( 'List screens', 'columnkit' ); ?>">
			<label class="screen-reader-text" for="ck-screen-filter"><?php esc_html_e( 'Filter screens', 'columnkit' ); ?></label>
			<input type="search" id="ck-screen-filter" class="ck-screen-filter" placeholder="<?php esc_attr_e( 'Find a screen…', 'columnkit' ); ?>">
			<?php foreach ( $groups as $group ) :
				if ( $group['items'] === [] ) {
					continue;
				}
				?>
				<div class="ck-nav-group">
					<h3 class="ck-nav-heading"><?php echo esc_html( $group['label'] ); ?></h3>
					<ul>
						<?php foreach ( $group['items'] as $key => $label ) :
							$count  = count( $this->repository->get_columns( $key ) );
							$edited = $count > 0 || $this->repository->get_layout( $key ) !== [];
							$title  = self::screen_title( $label );
							?>
							<li data-search="<?php echo esc_attr( strtolower( $label ) ); ?>">
								<a href="<?php echo esc_url( self::url( [ 'screen' => $key ] ) ); ?>"
									class="<?php echo esc_attr( $key === $active ? 'is-active' : '' ); ?>"
									<?php echo $key === $active ? 'aria-current="page"' : ''; ?>>
									<span class="ck-nav-label"><?php echo esc_html( $title ); ?></span>
									<?php if ( $count > 0 ) : ?>
										<span class="ck-nav-count" title="<?php esc_attr_e( 'Custom columns', 'columnkit' ); ?>"><?php echo (int) $count; ?></span>
									<?php elseif ( $edited ) : ?>
										<span class="ck-nav-dot" title="<?php esc_attr_e( 'Customised', 'columnkit' ); ?>"></span>
									<?php endif; ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	// ------------------------------------------------------------------
	// Views (column sets)
	// ------------------------------------------------------------------

	/** @param array<string, string> $sets */
	private function render_set_bar( string $screen_key, array $sets, string $active_set ): void {
		if ( $screen_key === '' ) {
			return;
		}
		$post_url = admin_url( 'admin-post.php' );
		$hidden   = function ( string $op, bool $with_set = true ) use ( $screen_key, $active_set ): void {
			printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::SET_ACTION ) );
			printf( '<input type="hidden" name="op" value="%s">', esc_attr( $op ) );
			printf( '<input type="hidden" name="screen" value="%s">', esc_attr( $screen_key ) );
			if ( $with_set ) {
				printf( '<input type="hidden" name="set" value="%s">', esc_attr( $active_set ) );
			}
			wp_nonce_field( self::SET_NONCE );
		};
		?>
		<div class="ck-views">
			<span class="ck-views-label"><?php esc_html_e( 'Views', 'columnkit' ); ?></span>
			<nav class="ck-view-pills" aria-label="<?php esc_attr_e( 'Column views', 'columnkit' ); ?>">
				<?php foreach ( $sets as $id => $label ) :
					$roles = $this->repository->get_roles( $screen_key, (string) $id );
					?>
					<a class="ck-pill<?php echo $id === $active_set ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( self::url( [ 'screen' => $screen_key, 'set' => $id ] ) ); ?>"
						<?php if ( $roles !== [] ) : ?>title="<?php echo esc_attr( sprintf( /* translators: %s: comma-separated role names */ __( 'Visible to: %s', 'columnkit' ), implode( ', ', self::role_names( $roles ) ) ) ); ?>"<?php endif; ?>
						<?php echo $id === $active_set ? 'aria-current="true"' : ''; ?>><?php if ( $roles !== [] ) : ?><span class="dashicons dashicons-lock" aria-hidden="true"></span><?php endif; ?><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<details class="ck-menu ck-new-view">
				<summary class="button button-small"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e( 'New view', 'columnkit' ); ?></summary>
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="ck-menu-panel">
					<?php $hidden( 'create', false ); ?>
					<label for="ck-new-view-name"><?php esc_html_e( 'View name', 'columnkit' ); ?></label>
					<input type="text" id="ck-new-view-name" name="label" placeholder="<?php esc_attr_e( 'e.g. SEO review', 'columnkit' ); ?>" required>
					<p class="description"><?php esc_html_e( 'Views are alternative column layouts. Editors switch between them from a dropdown above the list.', 'columnkit' ); ?></p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Create view', 'columnkit' ); ?></button>
				</form>
			</details>

			<details class="ck-menu ck-view-actions">
				<summary class="button button-small" aria-label="<?php esc_attr_e( 'View actions', 'columnkit' ); ?>"><span class="dashicons dashicons-ellipsis" aria-hidden="true"></span></summary>
				<div class="ck-menu-panel">
					<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="ck-menu-row">
						<?php $hidden( 'rename' ); ?>
						<label class="screen-reader-text" for="ck-rename-view"><?php esc_html_e( 'Rename view', 'columnkit' ); ?></label>
						<input type="text" id="ck-rename-view" name="label" value="<?php echo esc_attr( $sets[ $active_set ] ?? '' ); ?>">
						<button type="submit" class="button"><?php esc_html_e( 'Rename', 'columnkit' ); ?></button>
					</form>
					<?php if ( $active_set !== SettingsRepository::DEFAULT_SET ) :
						$allowed = $this->repository->get_roles( $screen_key, $active_set );
						?>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="ck-menu-roles">
							<?php $hidden( 'roles' ); ?>
							<fieldset>
								<legend><?php esc_html_e( 'Visible to', 'columnkit' ); ?></legend>
								<p class="description"><?php esc_html_e( 'Leave all unticked for everyone. Users with a ticked role get this view by default.', 'columnkit' ); ?></p>
								<?php foreach ( wp_roles()->get_names() as $slug => $name ) : ?>
									<label><input type="checkbox" name="roles[]" value="<?php echo esc_attr( (string) $slug ); ?>" <?php checked( in_array( (string) $slug, $allowed, true ) ); ?>> <?php echo esc_html( translate_user_role( (string) $name ) ); ?></label>
								<?php endforeach; ?>
							</fieldset>
							<button type="submit" class="button"><?php esc_html_e( 'Save visibility', 'columnkit' ); ?></button>
						</form>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( $post_url ); ?>">
						<?php $hidden( 'duplicate' ); ?>
						<button type="submit" class="ck-menu-item"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span><?php esc_html_e( 'Duplicate this view', 'columnkit' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( $post_url ); ?>" data-ck-confirm="<?php echo esc_attr__( 'Reset this view? Custom columns are removed and WordPress columns go back to their defaults.', 'columnkit' ); ?>">
						<?php $hidden( 'reset' ); ?>
						<button type="submit" class="ck-menu-item"><span class="dashicons dashicons-image-rotate" aria-hidden="true"></span><?php esc_html_e( 'Reset to WordPress defaults', 'columnkit' ); ?></button>
					</form>
					<?php if ( $active_set !== SettingsRepository::DEFAULT_SET ) : ?>
						<form method="post" action="<?php echo esc_url( $post_url ); ?>" data-ck-confirm="<?php echo esc_attr__( 'Delete this view? Its columns will be removed.', 'columnkit' ); ?>">
							<?php $hidden( 'delete' ); ?>
							<button type="submit" class="ck-menu-item is-danger"><span class="dashicons dashicons-trash" aria-hidden="true"></span><?php esc_html_e( 'Delete view', 'columnkit' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * Flash messages from import/export. Only whitelisted message codes are rendered — free-text
	 * GET params must never reach a trusted admin notice, even escaped, or a crafted link could
	 * plant arbitrary instructions ("Your site is at risk, go to ...") inside wp-admin chrome.
	 */
	private function render_flash_notice(): void {
		if ( ! isset( $_GET['ck_msg'] ) || ! is_string( $_GET['ck_msg'] ) ) {
			return;
		}
		$code  = sanitize_key( wp_unslash( $_GET['ck_msg'] ) );
		$count = isset( $_GET['ck_count'] ) && is_scalar( $_GET['ck_count'] ) ? max( 0, (int) $_GET['ck_count'] ) : 0;

		if ( $code === 'imported' ) {
			$msg = sprintf(
				/* translators: %d: number of screens imported */
				_n( 'Imported %d screen.', 'Imported %d screens.', $count, 'columnkit' ),
				$count
			);
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $msg ) );
			return;
		}

		$notices = [
			'view_created'    => [ 'success', __( 'View created.', 'columnkit' ) ],
			'view_renamed'    => [ 'success', __( 'View renamed.', 'columnkit' ) ],
			'view_duplicated' => [ 'success', __( 'View duplicated.', 'columnkit' ) ],
			'view_deleted'    => [ 'success', __( 'View deleted.', 'columnkit' ) ],
			'view_reset'      => [ 'success', __( 'View reset to WordPress defaults.', 'columnkit' ) ],
			'view_roles'      => [ 'success', __( 'View visibility saved.', 'columnkit' ) ],
		];
		if ( isset( $notices[ $code ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notices[ $code ][0] ), esc_html( $notices[ $code ][1] ) );
			return;
		}

		$errors = [
			'no_file'        => __( 'No file uploaded.', 'columnkit' ),
			'upload_failed'  => __( 'Upload failed.', 'columnkit' ),
			'file_invalid'   => __( 'File too large or empty.', 'columnkit' ),
			'invalid_upload' => __( 'Invalid upload.', 'columnkit' ),
			'unreadable'     => __( 'Could not read file.', 'columnkit' ),
			'invalid_json'   => __( 'Invalid JSON structure.', 'columnkit' ),
		];
		if ( isset( $errors[ $code ] ) ) {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $errors[ $code ] ) );
		}
	}

	// ------------------------------------------------------------------
	// Rows
	// ------------------------------------------------------------------

	/**
	 * A WordPress built-in column: rename (blank = default), width, show/hide, drag.
	 *
	 * @param array<string, mixed> $cfg
	 */
	private function render_native_row( int $index, string $key, string $default_label, array $cfg, bool $editable = false ): void {
		$prefix = 'columns[' . $index . ']';
		$label  = (string) ( $cfg['label'] ?? '' );
		$hidden = ! empty( $cfg['hidden'] );
		$width  = (string) ( $cfg['width'] ?? '' );
		?>
		<div class="ck-row ck-row--native<?php echo $hidden ? ' is-hidden' : ''; ?>" data-kind="native" data-key="<?php echo esc_attr( $key ); ?>" role="listitem">
			<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[kind]" value="native">
			<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[key]" value="<?php echo esc_attr( $key ); ?>">
			<input type="hidden" class="ck-hidden-input" name="<?php echo esc_attr( $prefix ); ?>[hidden]" value="<?php echo $hidden ? '1' : '0'; ?>">
			<div class="ck-row-head">
				<span class="ck-handle dashicons dashicons-menu" aria-hidden="true" title="<?php esc_attr_e( 'Drag to reorder', 'columnkit' ); ?>"></span>
				<span class="ck-cell-label">
					<label class="screen-reader-text" for="ck-native-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Column label', 'columnkit' ); ?></label>
					<input type="text" id="ck-native-<?php echo esc_attr( $key ); ?>" class="ck-inline-input ck-label-input" name="<?php echo esc_attr( $prefix ); ?>[label]"
						value="<?php echo esc_attr( $label ); ?>" placeholder="<?php echo esc_attr( $default_label ); ?>">
				</span>
				<span class="ck-cell-type">
					<span class="ck-badge ck-badge--wp"><?php esc_html_e( 'WordPress', 'columnkit' ); ?></span>
					<?php $this->render_editable_hint( $editable ); ?>
				</span>
				<span class="ck-cell-width">
					<input type="text" class="ck-inline-input ck-width-input" name="<?php echo esc_attr( $prefix ); ?>[width]" value="<?php echo esc_attr( $width ); ?>" placeholder="<?php esc_attr_e( 'auto', 'columnkit' ); ?>" aria-label="<?php esc_attr_e( 'Width', 'columnkit' ); ?>">
				</span>
				<span class="ck-cell-actions">
					<button type="button" class="ck-icon-btn ck-visibility" aria-pressed="<?php echo $hidden ? 'false' : 'true'; ?>"
						title="<?php esc_attr_e( 'Show / hide this column', 'columnkit' ); ?>"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s: column label */ __( 'Show or hide %s', 'columnkit' ), $default_label ) ); ?>">
						<span class="dashicons <?php echo $hidden ? 'dashicons-hidden' : 'dashicons-visibility'; ?>" aria-hidden="true"></span>
					</button>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * One custom column: compact head (label, type, width, actions) + expandable settings.
	 *
	 * @param array<string, mixed> $entry
	 */
	private function render_column_row( int $index, array $entry, string $screen_key, bool $expanded = false ): void {
		$type = (string) ( $entry['type'] ?? '' );
		$col  = $this->registry->get( $type );
		if ( ! $col ) {
			return;
		}
		$id       = (string) ( $entry['id'] ?? '' );
		$label    = (string) ( $entry['label'] ?? $col->get_label() );
		$settings = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : [];
		$width    = (string) ( $entry['width'] ?? '' );
		$format   = is_array( $entry['format'] ?? null ) ? $entry['format'] : [];
		$prefix   = 'columns[' . $index . ']';
		$group    = $this->type_group( $col );
		$fields   = $this->fields_for( $col, $screen_key );
		$body_id  = 'ck-body-' . ( $id !== '' ? $id : '__ID__' );
		?>
		<div class="ck-row ck-row--custom<?php echo $expanded ? ' is-open' : ''; ?>" data-kind="custom" data-type="<?php echo esc_attr( $type ); ?>" role="listitem">
			<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[kind]" value="custom">
			<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[id]" value="<?php echo esc_attr( $id ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[type]" value="<?php echo esc_attr( $type ); ?>">
			<div class="ck-row-head">
				<span class="ck-handle dashicons dashicons-menu" aria-hidden="true" title="<?php esc_attr_e( 'Drag to reorder', 'columnkit' ); ?>"></span>
				<span class="ck-cell-label">
					<input type="text" class="ck-inline-input ck-label-input" name="<?php echo esc_attr( $prefix ); ?>[label]"
						value="<?php echo esc_attr( $label ); ?>" placeholder="<?php echo esc_attr( $col->get_label() ); ?>" aria-label="<?php esc_attr_e( 'Column label', 'columnkit' ); ?>">
				</span>
				<span class="ck-cell-type">
					<span class="ck-badge ck-badge--<?php echo esc_attr( sanitize_html_class( strtolower( $group['slug'] ) ) ); ?>"><?php echo esc_html( $col->get_label() ); ?></span>
					<?php $this->render_editable_hint( \ColumnKit\Support\Editability::is_editable( $col, $settings ) ); ?>
					<span class="ck-type-detail"></span>
				</span>
				<span class="ck-cell-width">
					<input type="text" class="ck-inline-input ck-width-input" name="<?php echo esc_attr( $prefix ); ?>[width]" value="<?php echo esc_attr( $width ); ?>" placeholder="<?php esc_attr_e( 'auto', 'columnkit' ); ?>" aria-label="<?php esc_attr_e( 'Width', 'columnkit' ); ?>">
				</span>
				<span class="ck-cell-actions">
					<button type="button" class="ck-icon-btn ck-expand" aria-expanded="<?php echo $expanded ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $body_id ); ?>" title="<?php esc_attr_e( 'Settings', 'columnkit' ); ?>" aria-label="<?php esc_attr_e( 'Column settings', 'columnkit' ); ?>">
						<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
					</button>
					<button type="button" class="ck-icon-btn ck-remove" title="<?php esc_attr_e( 'Remove', 'columnkit' ); ?>" aria-label="<?php esc_attr_e( 'Remove column', 'columnkit' ); ?>">
						<span class="dashicons dashicons-trash" aria-hidden="true"></span>
					</button>
				</span>
			</div>
			<div class="ck-row-body" id="<?php echo esc_attr( $body_id ); ?>"<?php echo $expanded ? '' : ' hidden'; ?>>
				<?php if ( $col->get_description() !== '' ) : ?>
					<p class="ck-col-desc"><?php echo esc_html( $col->get_description() ); ?></p>
				<?php endif; ?>
				<?php if ( $fields !== [] ) : ?>
					<div class="ck-settings-grid">
						<?php foreach ( $fields as $field ) : ?>
							<div class="ck-field"><?php $this->render_field( $prefix . '[settings]', $field, $settings ); ?></div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<?php $this->render_display_fields( $prefix, $format ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<int, string> $roles
	 * @return array<int, string>
	 */
	private static function role_names( array $roles ): array {
		$names = wp_roles()->get_names();
		return array_map( static fn( $r ) => isset( $names[ $r ] ) ? translate_user_role( (string) $names[ $r ] ) : $r, $roles );
	}

	/** Small pencil marking a column whose cells can be edited inline on the list table. */
	private function render_editable_hint( bool $editable ): void {
		if ( ! $editable ) {
			return;
		}
		printf(
			'<span class="ck-editable-hint dashicons dashicons-edit" title="%1$s" aria-label="%1$s"></span>',
			esc_attr__( 'Editable inline on the list', 'columnkit' )
		);
	}

	/** Whether WordPress's own column `$key` on this screen is inline-editable (CoreFields). */
	private static function native_editable( string $screen_key, string $key ): bool {
		if ( ScreenIdentifier::is_users( $screen_key ) ) {
			return in_array( $key, [ 'email', 'role' ], true );
		}
		if ( ScreenIdentifier::taxonomy( $screen_key ) !== null ) {
			return in_array( $key, [ 'name', 'slug', 'description' ], true );
		}
		if ( ScreenIdentifier::post_type( $screen_key ) !== null ) {
			return in_array( $key, [ 'title', 'date', 'author', 'categories', 'tags' ], true ) || str_starts_with( $key, 'taxonomy-' );
		}
		return false;
	}

	/** @return array<int, array<string, mixed>> */
	private function fields_for( ColumnInterface $col, string $screen_key ): array {
		return ( $col instanceof ContextualColumn && $screen_key !== '' )
			? $col->settings_fields_for_screen( $screen_key )
			: $col->settings_fields();
	}

	/**
	 * Picker group for a column type, from its integration namespace.
	 *
	 * @return array{slug: string, label: string}
	 */
	private function type_group( ColumnInterface $col ): array {
		$class = get_class( $col );
		if ( preg_match( '/^ColumnKit\\\\Integrations\\\\(\w+)\\\\/', $class, $m ) === 1 ) {
			return [ 'slug' => $m[1], 'label' => self::GROUP_LABELS[ $m[1] ] ?? $m[1] ];
		}
		if ( str_starts_with( $class, 'ColumnKit\\' ) ) {
			return [ 'slug' => 'wp', 'label' => __( 'WordPress', 'columnkit' ) ];
		}
		return [ 'slug' => 'other', 'label' => __( 'Other', 'columnkit' ) ];
	}

	/**
	 * Per-column "Display" controls: alignment, prefix/suffix, badge + colours.
	 *
	 * @param array<string, mixed> $format
	 */
	private function render_display_fields( string $prefix, array $format ): void {
		$align = (string) ( $format['align'] ?? '' );
		$style = (string) ( $format['style'] ?? '' );
		?>
		<fieldset class="ck-display">
			<legend><?php esc_html_e( 'Display', 'columnkit' ); ?></legend>
			<div class="ck-display-grid">
				<label>
					<span><?php esc_html_e( 'Align', 'columnkit' ); ?></span>
					<select name="<?php echo esc_attr( $prefix ); ?>[format][align]">
						<?php
						$align_opts = [
							''       => __( 'Default', 'columnkit' ),
							'left'   => __( 'Left', 'columnkit' ),
							'center' => __( 'Center', 'columnkit' ),
							'right'  => __( 'Right', 'columnkit' ),
						];
						foreach ( $align_opts as $val => $opt_label ) {
							printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $val, $align, false ), esc_html( $opt_label ) );
						}
						?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Prefix', 'columnkit' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[format][prefix]" value="<?php echo esc_attr( (string) ( $format['prefix'] ?? '' ) ); ?>" placeholder="$">
				</label>
				<label>
					<span><?php esc_html_e( 'Suffix', 'columnkit' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $prefix ); ?>[format][suffix]" value="<?php echo esc_attr( (string) ( $format['suffix'] ?? '' ) ); ?>" placeholder="kg">
				</label>
				<label>
					<span><?php esc_html_e( 'Style', 'columnkit' ); ?></span>
					<select name="<?php echo esc_attr( $prefix ); ?>[format][style]">
						<option value="" <?php selected( '', $style ); ?>><?php esc_html_e( 'Plain text', 'columnkit' ); ?></option>
						<option value="badge" <?php selected( 'badge', $style ); ?>><?php esc_html_e( 'Badge / pill', 'columnkit' ); ?></option>
					</select>
				</label>
				<div class="ck-color-field">
					<span class="ck-color-title"><?php esc_html_e( 'Text colour', 'columnkit' ); ?></span>
					<input type="text" class="ck-color" name="<?php echo esc_attr( $prefix ); ?>[format][color]" value="<?php echo esc_attr( (string) ( $format['color'] ?? '' ) ); ?>" aria-label="<?php esc_attr_e( 'Text colour', 'columnkit' ); ?>">
				</div>
				<div class="ck-color-field">
					<span class="ck-color-title"><?php esc_html_e( 'Background', 'columnkit' ); ?></span>
					<input type="text" class="ck-color" name="<?php echo esc_attr( $prefix ); ?>[format][bg]" value="<?php echo esc_attr( (string) ( $format['bg'] ?? '' ) ); ?>" aria-label="<?php esc_attr_e( 'Background', 'columnkit' ); ?>">
				</div>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * @param array<string, mixed> $field
	 * @param array<string, mixed> $values
	 */
	private function render_field( string $name_prefix, array $field, array $values ): void {
		$key      = (string) $field['key'];
		$label    = (string) $field['label'];
		$type     = (string) ( $field['type'] ?? 'text' );
		$value    = isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) ? (string) $values[ $key ] : '';
		$required = ! empty( $field['required'] );
		$name     = $name_prefix . '[' . $key . ']';

		echo '<label><span>' . esc_html( $label );
		if ( $required ) {
			echo ' <span class="ck-required" aria-hidden="true">*</span>';
		}
		echo '</span>';

		if ( $type === 'select' && is_array( $field['options'] ?? null ) ) {
			$options = $field['options'];
			// A saved value that's no longer offered (field group re-targeted, plugin config
			// changed) must stay selected — otherwise the browser silently picks the first
			// option and the next save would repoint the column at a different field.
			if ( $value !== '' && ! array_key_exists( $value, $options ) ) {
				/* translators: %s: stored field name */
				$options = [ $value => sprintf( __( '%s (not found on this screen)', 'columnkit' ), $value ) ] + $options;
			}
			if ( $options === [] ) {
				$empty = isset( $field['empty'] ) && is_string( $field['empty'] ) ? $field['empty'] : __( 'Nothing available.', 'columnkit' );
				echo '<select name="' . esc_attr( $name ) . '" disabled><option value="">' . esc_html__( '— none available —', 'columnkit' ) . '</option></select>';
				echo '</label><span class="ck-field-help ck-field-help--warn">' . esc_html( $empty ) . '</span>';
				return;
			}
			echo '<select name="' . esc_attr( $name ) . '" class="ck-setting-select" data-setting="' . esc_attr( $key ) . '">';
			foreach ( $options as $opt_value => $opt_label ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( (string) $opt_value ),
					selected( (string) $opt_value, $value, false ),
					esc_html( (string) $opt_label )
				);
			}
			echo '</select>';
		} else {
			// Meta-key fields get the shared datalist: pick from keys that exist in the DB,
			// while free typing stays possible for keys that don't exist yet.
			$list_attr = $key === 'meta_key' ? ' list="ck-meta-keys" autocomplete="off"' : '';
			printf(
				'<input type="text" class="regular-text ck-setting-text" data-setting="%s" name="%s" value="%s"%s>',
				esc_attr( $key ),
				esc_attr( $name ),
				esc_attr( $value ),
				$list_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal.
			);
		}
		echo '</label>';

		$help = isset( $field['help'] ) && is_string( $field['help'] ) ? $field['help'] : '';
		if ( $help !== '' ) {
			echo '<span class="ck-field-help">' . esc_html( $help ) . '</span>';
		}
	}

	// ------------------------------------------------------------------
	// Column picker
	// ------------------------------------------------------------------

	private function render_picker( string $screen_key ): void {
		$groups = [];
		foreach ( $this->registry->all() as $type => $col ) {
			if ( ! $col->applies_to_screen( $screen_key ) ) {
				continue;
			}
			$group = $this->type_group( $col );
			$slug  = $group['slug'];
			$groups[ $slug ] ??= [ 'label' => $group['label'], 'items' => [] ];

			// Field integrations: one item per field, so adding a field is a single click.
			$presets = [];
			if ( $col instanceof ContextualColumn ) {
				$fields = $col->settings_fields_for_screen( $screen_key );
				$first  = $fields[0] ?? null;
				if ( is_array( $first ) && ( $first['type'] ?? '' ) === 'select' && is_array( $first['options'] ?? null ) ) {
					foreach ( $first['options'] as $value => $opt_label ) {
						$name      = preg_replace( '/\s*\([^)]*\)\s*$/', '', (string) $opt_label );
						$presets[] = [
							'label'  => (string) $name,
							'detail' => (string) $opt_label,
							'preset' => [ (string) $first['key'] => (string) $value ],
						];
					}
				}
			}
			foreach ( $presets as $p ) {
				$groups[ $slug ]['items'][] = [
					'type'   => $type,
					'name'   => $p['label'],
					'desc'   => $p['detail'],
					'preset' => $p['preset'],
					'label'  => $p['label'],
				];
			}
			if ( $presets === [] ) {
				$groups[ $slug ]['items'][] = [
					'type'   => $type,
					'name'   => $col->get_label(),
					'desc'   => $col->get_description(),
					'preset' => [],
					'label'  => $col->get_label(),
				];
			}
		}
		// WordPress first, integrations alphabetically after.
		uksort( $groups, static fn( $a, $b ) => ( $a === 'wp' ? -1 : ( $b === 'wp' ? 1 : strcmp( (string) $a, (string) $b ) ) ) );
		?>
		<div class="ck-add">
			<button type="button" class="button button-secondary ck-add-toggle" aria-expanded="false" aria-controls="ck-picker">
				<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
				<?php esc_html_e( 'Add column', 'columnkit' ); ?>
			</button>
			<div class="ck-picker" id="ck-picker" hidden role="dialog" aria-label="<?php esc_attr_e( 'Add a column', 'columnkit' ); ?>">
				<div class="ck-picker-search-wrap">
					<span class="dashicons dashicons-search" aria-hidden="true"></span>
					<input type="search" class="ck-picker-search" placeholder="<?php esc_attr_e( 'Search columns and fields…', 'columnkit' ); ?>" aria-label="<?php esc_attr_e( 'Search column types', 'columnkit' ); ?>">
				</div>
				<div class="ck-picker-groups">
					<?php foreach ( $groups as $slug => $group ) : ?>
						<section class="ck-picker-group">
							<h4><?php echo esc_html( $group['label'] ); ?></h4>
							<ul>
								<?php foreach ( $group['items'] as $item ) :
									$search = strtolower( $group['label'] . ' ' . $item['name'] . ' ' . $item['desc'] );
									?>
									<li>
										<button type="button" class="ck-picker-item"
											data-type="<?php echo esc_attr( $item['type'] ); ?>"
											data-label="<?php echo esc_attr( $item['label'] ); ?>"
											data-preset="<?php echo esc_attr( (string) wp_json_encode( (object) $item['preset'] ) ); ?>"
											data-search="<?php echo esc_attr( $search ); ?>">
											<span class="ck-picker-name"><?php echo esc_html( $item['name'] ); ?></span>
											<?php if ( $item['desc'] !== '' && $item['desc'] !== $item['name'] ) : ?>
												<span class="ck-picker-desc"><?php echo esc_html( $item['desc'] ); ?></span>
											<?php endif; ?>
										</button>
									</li>
								<?php endforeach; ?>
							</ul>
						</section>
					<?php endforeach; ?>
				</div>
				<p class="ck-picker-empty" hidden><?php esc_html_e( 'No matching columns.', 'columnkit' ); ?></p>
			</div>
		</div>
		<?php
	}

	/** Hidden <template> blocks used by admin.js to clone new rows. */
	private function render_templates( string $screen_key ): void {
		foreach ( $this->registry->all() as $type => $col ) {
			if ( ! $col->applies_to_screen( $screen_key ) ) {
				continue;
			}
			$template_id = 'ck-tpl-' . preg_replace( '/[^a-z0-9_-]/i', '', $type );
			echo '<template id="' . esc_attr( $template_id ) . '">';
			$this->render_column_row(
				0,
				[
					'id'       => '',
					'type'     => $type,
					'label'    => $col->get_label(),
					'settings' => [],
				],
				$screen_key,
				true
			);
			echo '</template>';
		}
	}

	// ------------------------------------------------------------------
	// Save
	// ------------------------------------------------------------------

	public function handle_save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'columnkit' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE );

		$screen_key = isset( $_POST['screen'] ) && is_string( $_POST['screen'] ) ? sanitize_text_field( wp_unslash( $_POST['screen'] ) ) : '';
		$screens    = ScreenIdentifier::available_screens();
		if ( ! isset( $screens[ $screen_key ] ) ) {
			wp_die( esc_html__( 'Unknown screen.', 'columnkit' ), '', [ 'response' => 400 ] );
		}

		$set_id = isset( $_POST['set'] ) && is_string( $_POST['set'] ) ? SettingsRepository::sanitize_set_id( wp_unslash( $_POST['set'] ) ) : SettingsRepository::DEFAULT_SET;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$raw_rows = isset( $_POST['columns'] ) && is_array( $_POST['columns'] ) ? wp_unslash( $_POST['columns'] ) : [];

		[ $clean, $layout ] = self::split_rows( $raw_rows, $screen_key, new Sanitizer( $this->registry ), NativeColumns::for_screen( $screen_key ) );

		// Preserve the set's label; create it if this is the first save to a brand-new id.
		$sets  = $this->repository->get_sets( $screen_key );
		$label = $sets[ $set_id ] ?? ( $set_id === SettingsRepository::DEFAULT_SET ? 'Default' : $set_id );
		$this->repository->save_set( $screen_key, $set_id, (string) $label, $clean, $layout );

		$this->redirect_to_set( $screen_key, $set_id, [ 'updated' => '1' ] );
	}

	/**
	 * Split the editor's unified, ordered row list into custom columns + a layout.
	 *
	 * Custom rows are sanitised one by one (so each keeps its position in the order even if a
	 * sibling is dropped); built-in rows keep only key / label / hidden / width, and a label equal
	 * to WordPress's default is stored as '' (no override) so it tracks translations.
	 *
	 * @param array<int|string, mixed> $raw_rows
	 * @param array<string, string>    $natives key => default label
	 * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
	 */
	public static function split_rows( array $raw_rows, string $screen_key, Sanitizer $sanitizer, array $natives ): array {
		ksort( $raw_rows, SORT_NUMERIC );

		$clean  = [];
		$used   = [];
		$order  = [];
		$native = [];
		foreach ( $raw_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$kind = isset( $row['kind'] ) && is_string( $row['kind'] ) ? $row['kind'] : 'custom';

			if ( $kind === 'native' ) {
				$key = SettingsRepository::sanitize_column_key( isset( $row['key'] ) && is_scalar( $row['key'] ) ? (string) $row['key'] : '' );
				if ( $key === '' || $key === 'cb' || str_starts_with( $key, 'ck_' ) || isset( $native[ $key ] ) ) {
					continue;
				}
				$label = isset( $row['label'] ) && is_scalar( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
				if ( isset( $natives[ $key ] ) && $label === $natives[ $key ] ) {
					$label = '';
				}
				$native[ $key ] = [
					'label'  => $label,
					'hidden' => ! empty( $row['hidden'] ) && (string) $row['hidden'] !== '0',
					'width'  => isset( $row['width'] ) && is_scalar( $row['width'] ) ? (string) $row['width'] : '',
				];
				$order[] = $key;
				continue;
			}

			unset( $row['kind'] );
			if ( isset( $row['id'] ) && is_string( $row['id'] ) && isset( $used[ $row['id'] ] ) ) {
				$row['id'] = ''; // Duplicate id (a cloned row) → let the sanitiser mint a fresh one.
			}
			$out = $sanitizer->sanitize_columns( [ $row ], $screen_key );
			if ( $out === [] ) {
				continue; // Unknown type / not valid on this screen.
			}
			$entry = $out[0];
			while ( isset( $used[ $entry['id'] ] ) ) {
				$entry['id'] = 'col_' . substr( md5( $entry['id'] . wp_rand() ), 0, 8 );
			}
			$used[ $entry['id'] ] = true;
			$clean[]              = $entry;
			$order[]              = 'ck_' . $entry['id'];
		}

		$layout = $native === [] ? [] : [ 'order' => $order, 'native' => $native ];
		return [ $clean, $layout ];
	}

	/** Create / rename / duplicate / delete / reset a column set. */
	public function handle_set_action(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'columnkit' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::SET_NONCE );

		$screen_key = isset( $_POST['screen'] ) && is_string( $_POST['screen'] ) ? sanitize_text_field( wp_unslash( $_POST['screen'] ) ) : '';
		$screens    = ScreenIdentifier::available_screens();
		if ( ! isset( $screens[ $screen_key ] ) ) {
			wp_die( esc_html__( 'Unknown screen.', 'columnkit' ), '', [ 'response' => 400 ] );
		}

		$op     = isset( $_POST['op'] ) && is_string( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$set_id = isset( $_POST['set'] ) && is_string( $_POST['set'] ) ? SettingsRepository::sanitize_set_id( wp_unslash( $_POST['set'] ) ) : SettingsRepository::DEFAULT_SET;
		$label  = isset( $_POST['label'] ) && is_string( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';

		switch ( $op ) {
			case 'create':
				$new_id = $this->repository->generate_set_id( $screen_key );
				$this->repository->save_set( $screen_key, $new_id, $label !== '' ? $label : __( 'New view', 'columnkit' ), [] );
				$this->redirect_to_set( $screen_key, $new_id, [ 'ck_msg' => 'view_created' ] );
				break;

			case 'rename':
				if ( $label === '' ) {
					$label = $set_id === SettingsRepository::DEFAULT_SET ? 'Default' : $set_id;
				}
				$columns = $this->repository->get_columns( $screen_key, $set_id );
				$this->repository->save_set( $screen_key, $set_id, $label, $columns ); // null layout = keep.
				$this->redirect_to_set( $screen_key, $set_id, [ 'ck_msg' => 'view_renamed' ] );
				break;

			case 'duplicate':
				$columns = $this->repository->get_columns( $screen_key, $set_id );
				$layout  = $this->repository->get_layout( $screen_key, $set_id );
				$sets    = $this->repository->get_sets( $screen_key );
				$src     = $sets[ $set_id ] ?? 'Default';
				$new_id  = $this->repository->generate_set_id( $screen_key );
				/* translators: %s: source view name */
				$this->repository->save_set( $screen_key, $new_id, sprintf( __( '%s (copy)', 'columnkit' ), $src ), $columns, $layout );
				$this->repository->set_roles( $screen_key, $new_id, $this->repository->get_roles( $screen_key, $set_id ) );
				$this->redirect_to_set( $screen_key, $new_id, [ 'ck_msg' => 'view_duplicated' ] );
				break;

			case 'roles':
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
				$roles = isset( $_POST['roles'] ) && is_array( $_POST['roles'] ) ? wp_unslash( $_POST['roles'] ) : [];
				$this->repository->set_roles( $screen_key, $set_id, $roles );
				$this->redirect_to_set( $screen_key, $set_id, [ 'ck_msg' => 'view_roles' ] );
				break;

			case 'reset':
				$sets = $this->repository->get_sets( $screen_key );
				$this->repository->save_set( $screen_key, $set_id, (string) ( $sets[ $set_id ] ?? 'Default' ), [], [] );
				$this->redirect_to_set( $screen_key, $set_id, [ 'ck_msg' => 'view_reset' ] );
				break;

			case 'delete':
				$this->repository->delete_set( $screen_key, $set_id );
				$this->redirect_to_set( $screen_key, SettingsRepository::DEFAULT_SET, [ 'ck_msg' => 'view_deleted' ] );
				break;

			default:
				wp_die( esc_html__( 'Unknown action.', 'columnkit' ), '', [ 'response' => 400 ] );
		}
	}

	/**
	 * @param array<string, string> $extra
	 */
	private function redirect_to_set( string $screen_key, string $set_id, array $extra = [] ): void {
		wp_safe_redirect( self::url( array_merge( [ 'screen' => $screen_key, 'set' => $set_id ], $extra ) ) );
		exit;
	}
}
