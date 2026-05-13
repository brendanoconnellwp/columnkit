<?php
declare( strict_types=1 );

namespace ColumnKit\Admin;

use ColumnKit\ColumnRegistry;
use ColumnKit\Settings\Sanitizer;
use ColumnKit\Settings\SettingsRepository;

/**
 * Settings JSON import/export — backup and migrate column configurations between sites.
 *
 * Security model:
 *   - Both endpoints require `manage_options` (same cap as the settings page itself).
 *   - Each endpoint has its own nonce.
 *   - Imported data is size-capped (5 MB) and passes through `Sanitizer::sanitize_columns()`
 *     before being persisted — unknown column types are silently dropped, IDs are regenerated
 *     for collisions, etc.
 *   - Screen keys are pattern-matched (post_type:slug) so an attacker can't make us write to
 *     unrelated option names.
 */
final class SettingsExporter {
	public const ACTION_EXPORT = 'ck_settings_export';
	public const ACTION_IMPORT = 'ck_settings_import';
	public const NONCE_EXPORT  = 'ck_settings_export';
	public const NONCE_IMPORT  = 'ck_settings_import';
	public const MAX_IMPORT_BYTES = 5 * 1024 * 1024;
	public const SCHEMA_VERSION = 1;

	public function __construct(
		private ColumnRegistry $registry,
		private SettingsRepository $repository
	) {}

	public function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION_EXPORT, [ $this, 'handle_export' ] );
		add_action( 'admin_post_' . self::ACTION_IMPORT, [ $this, 'handle_import' ] );
	}

	/** Rendered inside the SettingsPage view. */
	public function render_section(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_EXPORT ),
			self::NONCE_EXPORT
		);
		?>
		<hr>
		<h2><?php esc_html_e( 'Import / Export Settings', 'columnkit' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Back up the column configurations for every screen, or restore from a previous backup. JSON format.', 'columnkit' ); ?>
		</p>

		<p>
			<a href="<?php echo esc_url( $export_url ); ?>" class="button">
				<?php esc_html_e( 'Export Settings (JSON)', 'columnkit' ); ?>
			</a>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin-top:16px;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_IMPORT ); ?>">
			<?php wp_nonce_field( self::NONCE_IMPORT ); ?>
			<input type="file" name="ck_settings_file" accept=".json,application/json" required>
			<button type="submit" class="button"><?php esc_html_e( 'Import Settings', 'columnkit' ); ?></button>
			<p class="description">
				<?php esc_html_e( 'Imported screens replace existing configurations with the same key. Other screens are left untouched.', 'columnkit' ); ?>
			</p>
		</form>
		<?php
	}

	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'columnkit' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_EXPORT );

		$screens = $this->repository->configured_screens();
		$payload = [
			'schema_version' => self::SCHEMA_VERSION,
			'plugin'         => 'columnkit',
			'exported_at'    => gmdate( 'c' ),
			'screens'        => [],
		];
		foreach ( $screens as $key ) {
			$payload['screens'][ $key ] = $this->repository->get( $key );
		}

		$filename = sanitize_file_name(
			sprintf( 'ck-settings-%s.json', gmdate( 'Ymd-His' ) )
		);

		$is_test = defined( 'CK_TEST_MODE' ) && CK_TEST_MODE;
		if ( ! $is_test ) {
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		}
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! $is_test ) {
			exit;
		}
	}

	public function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'columnkit' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_IMPORT );

		if ( empty( $_FILES['ck_settings_file'] ) || ! is_array( $_FILES['ck_settings_file'] ) ) {
			$this->redirect_with_message( 'error', __( 'No file uploaded.', 'columnkit' ) );
		}
		$file = $_FILES['ck_settings_file'];
		if ( ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			$this->redirect_with_message( 'error', __( 'Upload failed.', 'columnkit' ) );
		}
		if ( (int) ( $file['size'] ?? 0 ) <= 0 || (int) $file['size'] > self::MAX_IMPORT_BYTES ) {
			$this->redirect_with_message( 'error', __( 'File too large or empty.', 'columnkit' ) );
		}

		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( $tmp === '' || ! is_uploaded_file( $tmp ) ) {
			$this->redirect_with_message( 'error', __( 'Invalid upload.', 'columnkit' ) );
		}

		$contents = file_get_contents( $tmp );
		if ( $contents === false || $contents === '' ) {
			$this->redirect_with_message( 'error', __( 'Could not read file.', 'columnkit' ) );
		}

		$imported = $this->import_from_json( $contents );
		if ( $imported < 0 ) {
			$this->redirect_with_message( 'error', __( 'Invalid JSON structure.', 'columnkit' ) );
		}
		$this->redirect_with_message( 'success', sprintf(
			/* translators: %d: number of screens imported */
			_n( 'Imported %d screen.', 'Imported %d screens.', $imported, 'columnkit' ),
			$imported
		) );
	}

	/**
	 * Parses a JSON payload (same shape as exported) and saves each valid screen.
	 *
	 * Returns the number of screens imported, or -1 if the payload is structurally invalid.
	 * Public so smoke tests can exercise the import without going through admin-post.php /
	 * is_uploaded_file (which doesn't return true in CLI / test contexts).
	 */
	public function import_from_json( string $json ): int {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['screens'] ) || ! is_array( $data['screens'] ) ) {
			return -1;
		}
		$sanitizer = new Sanitizer( $this->registry );
		$imported  = 0;
		foreach ( $data['screens'] as $screen_key => $screen_data ) {
			$screen_key = (string) $screen_key;
			// Only accept post_type:<slug> keys — no writing to arbitrary option names.
			if ( ! preg_match( '/^post_type:[a-z0-9_\-]+$/i', $screen_key ) ) {
				continue;
			}
			if ( ! is_array( $screen_data ) || ! isset( $screen_data['columns'] ) || ! is_array( $screen_data['columns'] ) ) {
				continue;
			}
			$clean = $sanitizer->sanitize_columns( $screen_data['columns'] );
			$this->repository->save( $screen_key, $clean );
			$imported++;
		}
		return $imported;
	}

	private function redirect_with_message( string $level, string $message ): void {
		$redirect = add_query_arg(
			[
				'page'        => 'columnkit',
				'ck_message' => rawurlencode( $message ),
				'ck_level'   => $level,
			],
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}
}
