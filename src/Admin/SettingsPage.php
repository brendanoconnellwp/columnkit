<?php
declare( strict_types=1 );

namespace ColumnKit\Admin;

use ColumnKit\ColumnRegistry;
use ColumnKit\Settings\Sanitizer;
use ColumnKit\Settings\SettingsRepository;
use ColumnKit\Support\ScreenIdentifier;

final class SettingsPage {
	public const MENU_SLUG  = 'columnkit';
	public const CAPABILITY = 'manage_options';
	public const NONCE      = 'ck_save_columns';
	public const ACTION     = 'ck_save_columns';
	public const SET_ACTION = 'ck_set_action';
	public const SET_NONCE  = 'ck_set_action';

	public function __construct(
		private ColumnRegistry $registry,
		private SettingsRepository $repository,
		private SettingsExporter $settings_exporter
	) {}

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_save' ] );
		add_action( 'admin_post_' . self::SET_ACTION, [ $this, 'handle_set_action' ] );
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

	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'columnkit' ) );
		}

		$screens     = ScreenIdentifier::available_screens();
		$default_key = array_key_first( $screens ) ?? '';
		$screen_key  = isset( $_GET['screen'] ) && is_string( $_GET['screen'] ) ? sanitize_text_field( wp_unslash( $_GET['screen'] ) ) : $default_key;
		if ( ! isset( $screens[ $screen_key ] ) ) {
			$screen_key = $default_key;
		}

		$sets    = $screen_key !== '' ? $this->repository->get_sets( $screen_key ) : [ SettingsRepository::DEFAULT_SET => 'Default' ];
		$set_id  = isset( $_GET['set'] ) && is_string( $_GET['set'] ) ? SettingsRepository::sanitize_set_id( wp_unslash( $_GET['set'] ) ) : SettingsRepository::DEFAULT_SET;
		if ( ! isset( $sets[ $set_id ] ) ) {
			$set_id = SettingsRepository::DEFAULT_SET;
		}

		$columns = $screen_key !== '' ? $this->repository->get_columns( $screen_key, $set_id ) : [];
		$saved   = isset( $_GET['updated'] ) && $_GET['updated'] === '1';

		?>
		<div class="wrap ck-wrap">
			<h1><?php esc_html_e( 'Admin Columns', 'columnkit' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Pick a list screen and a view, then add the columns you want shown. Drag to reorder.', 'columnkit' ); ?>
			</p>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'columnkit' ); ?></p>
				</div>
			<?php endif; ?>

			<?php $this->render_flash_notice(); ?>

			<form method="get" class="ck-screen-picker">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<label>
					<?php esc_html_e( 'List screen:', 'columnkit' ); ?>
					<select name="screen">
						<?php foreach ( $screens as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $screen_key ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Load', 'columnkit' ); ?></button>
			</form>

			<?php $this->render_set_bar( $screen_key, $sets, $set_id ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ck-form" id="ck-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="screen" value="<?php echo esc_attr( $screen_key ); ?>">
				<input type="hidden" name="set" value="<?php echo esc_attr( $set_id ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>

				<div class="ck-columns" id="ck-columns">
					<?php foreach ( $columns as $i => $entry ) :
						$this->render_column_row( (int) $i, $entry );
					endforeach; ?>
				</div>

				<div class="ck-add-row">
					<label>
						<?php esc_html_e( 'Add column:', 'columnkit' ); ?>
						<select id="ck-add-type">
							<?php foreach ( $this->registry->all() as $type => $col ) :
								if ( ! $col->applies_to_screen( $screen_key ) ) { continue; } ?>
								<option value="<?php echo esc_attr( $type ); ?>">
									<?php echo esc_html( $col->get_label() ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
					<button type="button" class="button" id="ck-add"><?php esc_html_e( 'Add', 'columnkit' ); ?></button>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Columns', 'columnkit' ); ?></button>
				</p>
			</form>

			<?php $this->render_templates(); ?>

			<?php $this->settings_exporter->render_section(); ?>
		</div>
		<?php
	}

	/**
	 * Column-set toolbar: tabs for each saved view plus create / rename / duplicate / delete.
	 *
	 * @param array<string, string> $sets
	 */
	private function render_set_bar( string $screen_key, array $sets, string $active_set ): void {
		if ( $screen_key === '' ) {
			return;
		}
		$base = [ 'page' => self::MENU_SLUG, 'screen' => $screen_key ];
		$post_url = admin_url( 'admin-post.php' );
		?>
		<div class="ck-set-bar">
			<nav class="ck-set-tabs nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Column views', 'columnkit' ); ?>">
				<?php foreach ( $sets as $id => $label ) :
					$url = add_query_arg( $base + [ 'set' => $id ], admin_url( 'options-general.php' ) );
					$classes = 'nav-tab' . ( $id === $active_set ? ' nav-tab-active' : '' );
					?>
					<a class="<?php echo esc_attr( $classes ); ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="ck-set-tools">
				<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="ck-set-form">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SET_ACTION ); ?>">
					<input type="hidden" name="op" value="create">
					<input type="hidden" name="screen" value="<?php echo esc_attr( $screen_key ); ?>">
					<?php wp_nonce_field( self::SET_NONCE ); ?>
					<input type="text" name="label" class="regular-text" placeholder="<?php esc_attr_e( 'New view name…', 'columnkit' ); ?>" required>
					<button type="submit" class="button"><?php esc_html_e( 'Add view', 'columnkit' ); ?></button>
				</form>

				<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="ck-set-form">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SET_ACTION ); ?>">
					<input type="hidden" name="op" value="rename">
					<input type="hidden" name="screen" value="<?php echo esc_attr( $screen_key ); ?>">
					<input type="hidden" name="set" value="<?php echo esc_attr( $active_set ); ?>">
					<?php wp_nonce_field( self::SET_NONCE ); ?>
					<input type="text" name="label" class="regular-text" value="<?php echo esc_attr( $sets[ $active_set ] ?? '' ); ?>" aria-label="<?php esc_attr_e( 'Rename current view', 'columnkit' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Rename', 'columnkit' ); ?></button>
				</form>

				<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="ck-set-form">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SET_ACTION ); ?>">
					<input type="hidden" name="op" value="duplicate">
					<input type="hidden" name="screen" value="<?php echo esc_attr( $screen_key ); ?>">
					<input type="hidden" name="set" value="<?php echo esc_attr( $active_set ); ?>">
					<?php wp_nonce_field( self::SET_NONCE ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Duplicate', 'columnkit' ); ?></button>
				</form>

				<?php if ( $active_set !== SettingsRepository::DEFAULT_SET ) : ?>
					<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="ck-set-form ck-set-delete" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this view? Its columns will be removed.', 'columnkit' ) ); ?>');">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::SET_ACTION ); ?>">
						<input type="hidden" name="op" value="delete">
						<input type="hidden" name="screen" value="<?php echo esc_attr( $screen_key ); ?>">
						<input type="hidden" name="set" value="<?php echo esc_attr( $active_set ); ?>">
						<?php wp_nonce_field( self::SET_NONCE ); ?>
						<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete view', 'columnkit' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
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

	/**
	 * @param array<string, mixed> $entry
	 */
	private function render_column_row( int $index, array $entry ): void {
		$type    = (string) ( $entry['type'] ?? '' );
		$col     = $this->registry->get( $type );
		if ( ! $col ) {
			return;
		}
		$id      = (string) ( $entry['id'] ?? '' );
		$label   = (string) ( $entry['label'] ?? $col->get_label() );
		$settings = is_array( $entry['settings'] ?? null ) ? $entry['settings'] : [];
		$width    = (string) ( $entry['width'] ?? '' );
		$format   = is_array( $entry['format'] ?? null ) ? $entry['format'] : [];
		$prefix   = 'columns[' . $index . ']';

		?>
		<div class="ck-column-row" data-type="<?php echo esc_attr( $type ); ?>">
			<div class="ck-column-head">
				<span class="ck-handle dashicons dashicons-menu" aria-hidden="true"></span>
				<strong class="ck-type-label"><?php echo esc_html( $col->get_label() ); ?></strong>
				<button type="button" class="button-link ck-remove" aria-label="<?php esc_attr_e( 'Remove column', 'columnkit' ); ?>">&times;</button>
			</div>
			<div class="ck-column-body">
				<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[id]" value="<?php echo esc_attr( $id ); ?>">
				<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[type]" value="<?php echo esc_attr( $type ); ?>">
				<p>
					<label>
						<?php esc_html_e( 'Label', 'columnkit' ); ?><br>
						<input type="text" class="regular-text" name="<?php echo esc_attr( $prefix ); ?>[label]" value="<?php echo esc_attr( $label ); ?>">
					</label>
				</p>
				<?php foreach ( $col->settings_fields() as $field ) : ?>
					<p>
						<?php $this->render_field( $prefix . '[settings]', $field, $settings ); ?>
					</p>
				<?php endforeach; ?>

				<?php $this->render_display_fields( $prefix, $width, $format ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Per-column "Display" controls: width, alignment, prefix/suffix, badge + colours.
	 *
	 * @param array<string, mixed> $format
	 */
	private function render_display_fields( string $prefix, string $width, array $format ): void {
		$align = (string) ( $format['align'] ?? '' );
		$style = (string) ( $format['style'] ?? '' );
		?>
		<details class="ck-display">
			<summary><?php esc_html_e( 'Display', 'columnkit' ); ?></summary>
			<div class="ck-display-grid">
				<label>
					<?php esc_html_e( 'Width', 'columnkit' ); ?><br>
					<input type="text" class="small-text" name="<?php echo esc_attr( $prefix ); ?>[width]" value="<?php echo esc_attr( $width ); ?>" placeholder="120px">
				</label>
				<label>
					<?php esc_html_e( 'Align', 'columnkit' ); ?><br>
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
					<?php esc_html_e( 'Prefix', 'columnkit' ); ?><br>
					<input type="text" class="regular-text" name="<?php echo esc_attr( $prefix ); ?>[format][prefix]" value="<?php echo esc_attr( (string) ( $format['prefix'] ?? '' ) ); ?>" placeholder="$">
				</label>
				<label>
					<?php esc_html_e( 'Suffix', 'columnkit' ); ?><br>
					<input type="text" class="regular-text" name="<?php echo esc_attr( $prefix ); ?>[format][suffix]" value="<?php echo esc_attr( (string) ( $format['suffix'] ?? '' ) ); ?>" placeholder="kg">
				</label>
				<label>
					<?php esc_html_e( 'Style', 'columnkit' ); ?><br>
					<select name="<?php echo esc_attr( $prefix ); ?>[format][style]">
						<option value="" <?php selected( '', $style ); ?>><?php esc_html_e( 'Plain text', 'columnkit' ); ?></option>
						<option value="badge" <?php selected( 'badge', $style ); ?>><?php esc_html_e( 'Badge / pill', 'columnkit' ); ?></option>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Text colour', 'columnkit' ); ?><br>
					<input type="text" class="ck-color" name="<?php echo esc_attr( $prefix ); ?>[format][color]" value="<?php echo esc_attr( (string) ( $format['color'] ?? '' ) ); ?>" placeholder="#1d2327">
				</label>
				<label>
					<?php esc_html_e( 'Background', 'columnkit' ); ?><br>
					<input type="text" class="ck-color" name="<?php echo esc_attr( $prefix ); ?>[format][bg]" value="<?php echo esc_attr( (string) ( $format['bg'] ?? '' ) ); ?>" placeholder="#f0f0f1">
				</label>
			</div>
		</details>
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

		echo '<label>' . esc_html( $label );
		if ( $required ) {
			echo ' <span class="ck-required" aria-hidden="true">*</span>';
		}
		echo '<br>';

		if ( $type === 'select' && is_array( $field['options'] ?? null ) ) {
			echo '<select name="' . esc_attr( $name ) . '">';
			foreach ( $field['options'] as $opt_value => $opt_label ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( (string) $opt_value ),
					selected( (string) $opt_value, $value, false ),
					esc_html( (string) $opt_label )
				);
			}
			echo '</select>';
		} else {
			printf(
				'<input type="text" class="regular-text" name="%s" value="%s">',
				esc_attr( $name ),
				esc_attr( $value )
			);
		}
		echo '</label>';
	}

	/** Hidden <template> blocks used by admin.js to clone new rows. */
	private function render_templates(): void {
		foreach ( $this->registry->all() as $type => $col ) {
			$template_id = 'ck-tpl-' . preg_replace( '/[^a-z0-9_-]/i', '', $type );
			echo '<template id="' . esc_attr( $template_id ) . '">';
			$this->render_column_row(
				0,
				[
					'id'       => '',
					'type'     => $type,
					'label'    => $col->get_label(),
					'settings' => [],
				]
			);
			echo '</template>';
		}
	}

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
		$raw_columns = isset( $_POST['columns'] ) ? wp_unslash( $_POST['columns'] ) : [];
		$sanitizer   = new Sanitizer( $this->registry );
		$clean       = $sanitizer->sanitize_columns( $raw_columns, $screen_key );

		// Preserve the set's label; create it if this is the first save to a brand-new id.
		$sets  = $this->repository->get_sets( $screen_key );
		$label = $sets[ $set_id ] ?? ( $set_id === SettingsRepository::DEFAULT_SET ? 'Default' : $set_id );
		$this->repository->save_set( $screen_key, $set_id, (string) $label, $clean );

		$this->redirect_to_set( $screen_key, $set_id, [ 'updated' => '1' ] );
	}

	/** Create / rename / duplicate / delete a column set. */
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
				$this->repository->save_set( $screen_key, $set_id, $label, $columns );
				$this->redirect_to_set( $screen_key, $set_id, [ 'ck_msg' => 'view_renamed' ] );
				break;

			case 'duplicate':
				$columns = $this->repository->get_columns( $screen_key, $set_id );
				$sets    = $this->repository->get_sets( $screen_key );
				$src     = $sets[ $set_id ] ?? 'Default';
				$new_id  = $this->repository->generate_set_id( $screen_key );
				/* translators: %s: source view name */
				$this->repository->save_set( $screen_key, $new_id, sprintf( __( '%s (copy)', 'columnkit' ), $src ), $columns );
				$this->redirect_to_set( $screen_key, $new_id, [ 'ck_msg' => 'view_duplicated' ] );
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
		$args = array_merge(
			[
				'page'   => self::MENU_SLUG,
				'screen' => $screen_key,
				'set'    => $set_id,
			],
			$extra
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}
}
