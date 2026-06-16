<?php
declare( strict_types=1 );

namespace ColumnKit\Admin;

final class Assets {
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue( string $hook ): void {
		// List-table display formatting (badges, prefix/suffix, view switcher) — load anywhere
		// our custom cells can render.
		if ( in_array( $hook, [ 'edit.php', 'upload.php', 'users.php', 'edit-tags.php' ], true ) ) {
			wp_enqueue_style(
				'ck-list-screen',
				CK_URL . 'assets/list-screen.css',
				[],
				CK_VERSION
			);
		}

		// View switcher — load on the list tables where it can appear (posts, media, users).
		if ( in_array( $hook, [ 'edit.php', 'upload.php', 'users.php' ], true ) ) {
			wp_enqueue_script(
				'ck-list-screen',
				CK_URL . 'assets/list-screen.js',
				[],
				CK_VERSION,
				true
			);
		}

		// Settings page assets.
		if ( $hook === 'settings_page_columnkit' ) {
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_style(
				'ck-admin',
				CK_URL . 'assets/admin.css',
				[ 'wp-color-picker' ],
				CK_VERSION
			);
			wp_enqueue_script(
				'ck-admin',
				CK_URL . 'assets/admin.js',
				[ 'jquery', 'jquery-ui-sortable', 'wp-color-picker' ],
				CK_VERSION,
				true
			);
			wp_localize_script(
				'ck-admin',
				'CK_I18N',
				[
					'removeConfirm' => __( 'Remove this column?', 'columnkit' ),
					'addedLabel'    => __( 'New column', 'columnkit' ),
				]
			);
			return;
		}

		// Click-to-edit popover. Posts (edit.php) get core-column editing too; Users and Terms
		// get inline editing of their meta columns via the same popover + a shared AJAX endpoint.
		if ( in_array( $hook, [ 'edit.php', 'users.php', 'edit-tags.php' ], true ) ) {
			$lsm = \ColumnKit\Plugin::instance()->list_screen_manager();

			wp_enqueue_style(
				'ck-inline-edit',
				CK_URL . 'assets/admin-inline.css',
				[],
				CK_VERSION
			);
			wp_enqueue_script(
				'ck-inline-edit',
				CK_URL . 'assets/admin-inline.js',
				[ 'jquery' ],
				CK_VERSION,
				true
			);

			$config = [
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( \ColumnKit\ListScreens\EditManager::AJAX_NONCE ),
				'action'     => \ColumnKit\ListScreens\EditManager::AJAX_ACTION,
				'corePrefix' => \ColumnKit\ListScreens\EditManager::CORE_PREFIX,
				'set'        => $lsm->active_set_id(),
				'screen'     => $lsm->active_screen_key(),
				'i18n'       => [
					'save'         => __( 'Save', 'columnkit' ),
					'cancel'       => __( 'Cancel', 'columnkit' ),
					'saving'       => __( 'Saving…', 'columnkit' ),
					'saved'        => __( 'Saved', 'columnkit' ),
					'error'        => __( 'Save failed', 'columnkit' ),
					'networkError' => __( 'Network error', 'columnkit' ),
					'unchanged'    => __( '— (unchanged)', 'columnkit' ),
					'yes'          => __( 'Yes', 'columnkit' ),
					'no'           => __( 'No', 'columnkit' ),
					'edit'         => __( 'Edit', 'columnkit' ),
				],
			];

			// Core Title/Date/Author editing is a posts-only feature.
			if ( $hook === 'edit.php' ) {
				$config['coreColumns'] = \ColumnKit\ListScreens\EditManager::js_core_columns_config();
				// Per-post raw values are only available after the list-table query has run.
				add_action( 'admin_footer-edit.php', [ $this, 'print_core_data' ] );
			}

			wp_localize_script( 'ck-inline-edit', 'CK_INLINE', $config );
		}
	}

	public function print_core_data(): void {
		$edit_manager = \ColumnKit\Plugin::instance()->list_screen_manager()->edit_manager();
		$data         = $edit_manager->collect_core_data();
		if ( $data === [] ) {
			return;
		}
		// JSON_HEX_* escapes <, >, &, ', " so a post title containing "</script>" (or any
		// other markup) cannot break out of this inline <script> block. Without these flags
		// wp_json_encode() leaves "<" untouched and any author-controlled title becomes
		// stored XSS in wp-admin.
		printf(
			"<script>window.CK_INLINE=window.CK_INLINE||{};CK_INLINE.coreData=%s;</script>\n",
			wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT )
		);
	}
}
