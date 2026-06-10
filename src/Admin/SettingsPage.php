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

	public function __construct(
		private ColumnRegistry $registry,
		private SettingsRepository $repository,
		private SettingsExporter $settings_exporter
	) {}

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_save' ] );
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

		$columns = $screen_key !== '' ? $this->repository->get_columns( $screen_key ) : [];
		$saved   = isset( $_GET['updated'] ) && $_GET['updated'] === '1';

		?>
		<div class="wrap ck-wrap">
			<h1><?php esc_html_e( 'Admin Columns', 'columnkit' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Pick a list screen, then add the columns you want shown. Drag to reorder.', 'columnkit' ); ?>
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

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ck-form" id="ck-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="screen" value="<?php echo esc_attr( $screen_key ); ?>">
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
			</div>
		</div>
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$raw_columns = isset( $_POST['columns'] ) ? wp_unslash( $_POST['columns'] ) : [];
		$sanitizer   = new Sanitizer( $this->registry );
		$clean       = $sanitizer->sanitize_columns( $raw_columns, $screen_key );

		$this->repository->save( $screen_key, $clean );

		$redirect = add_query_arg(
			[
				'page'    => self::MENU_SLUG,
				'screen'  => $screen_key,
				'updated' => '1',
			],
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}
}
