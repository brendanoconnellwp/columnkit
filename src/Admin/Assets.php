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

		// View switcher + the "Columns" shortcut next to the page title — on every list table.
		if ( in_array( $hook, [ 'edit.php', 'upload.php', 'users.php', 'edit-tags.php' ], true ) ) {
			wp_enqueue_script(
				'ck-list-screen',
				CK_URL . 'assets/list-screen.js',
				[],
				CK_VERSION,
				true
			);
			$screen     = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$screen_key = $screen instanceof \WP_Screen ? \ColumnKit\Support\ScreenIdentifier::from_screen( $screen ) : null;
			if ( $screen_key !== null && current_user_can( SettingsPage::CAPABILITY ) ) {
				$set = \ColumnKit\Plugin::instance()->list_screen_manager()->active_set_id();
				wp_localize_script(
					'ck-list-screen',
					'CK_LIST',
					[
						'manageUrl'   => SettingsPage::url( [ 'screen' => $screen_key, 'set' => $set ] ),
						'manageLabel' => __( 'Columns', 'columnkit' ),
						'manageTitle' => __( 'Add, hide and reorder the columns on this screen', 'columnkit' ),
					]
				);
			}
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
					'leaveWarning'  => __( 'You have unsaved column changes.', 'columnkit' ),
					'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
					'metaAction'    => \ColumnKit\Admin\MetaKeySuggestions::AJAX_ACTION,
					'metaNonce'     => wp_create_nonce( \ColumnKit\Admin\MetaKeySuggestions::NONCE ),
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
					'searchTerms'  => __( 'Search…', 'columnkit' ),
					'addNewTerms'  => __( 'Add new (comma-separated)', 'columnkit' ),
					'noTerms'      => __( 'No terms yet.', 'columnkit' ),
					'ownRole'      => __( 'You cannot change your own role here.', 'columnkit' ),
				],
			];

			// WordPress's own columns (post Title/Date/Author/taxonomies, term Name/Slug/
			// Description, user Email/Role). Row values are collected as the list renders and
			// printed in the footer.
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen instanceof \WP_Screen ) {
				$core = \ColumnKit\ListScreens\CoreFields::boot_for_screen( $screen );
				if ( $core !== [] ) {
					$config = array_merge( $config, $core );
					// admin_footer-{hook} fires AFTER footer scripts (incl. the localised
					// CK_INLINE object) are printed; plain admin_footer fires before, and the
					// localisation would then overwrite coreData.
					add_action( 'admin_footer-' . $hook, [ $this, 'print_core_data' ] );
				}
			}

			// Featured Image cells open the media library.
			foreach ( $lsm->active_columns() as $entry ) {
				if ( ( $entry['type'] ?? '' ) === 'featured_image' ) {
					wp_enqueue_media();
					$config['i18n']['chooseImage'] = __( 'Choose image…', 'columnkit' );
					$config['i18n']['removeImage'] = __( 'Remove', 'columnkit' );
					$config['i18n']['useImage']    = __( 'Set featured image', 'columnkit' );
					break;
				}
			}

			wp_localize_script( 'ck-inline-edit', 'CK_INLINE', $config );
		}
	}

	public function print_core_data(): void {
		$data = \ColumnKit\ListScreens\CoreFields::data();
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
