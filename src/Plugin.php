<?php
declare( strict_types=1 );

namespace ColumnKit;

use ColumnKit\Admin\Assets;
use ColumnKit\Admin\DataExporter;
use ColumnKit\Admin\SettingsExporter;
use ColumnKit\Admin\SettingsPage;
use ColumnKit\Columns\AuthorColumn;
use ColumnKit\Columns\FeaturedImageColumn;
use ColumnKit\Columns\PostIdColumn;
use ColumnKit\Columns\PostMetaColumn;
use ColumnKit\Columns\TaxonomyColumn;
use ColumnKit\Columns\TermMetaColumn;
use ColumnKit\Columns\UserMetaColumn;
use ColumnKit\Columns\UserPostCountColumn;
use ColumnKit\Columns\UserRoleColumn;
use ColumnKit\Integrations\ACF\Loader as ACFLoader;
use ColumnKit\Integrations\JetEngine\Loader as JetEngineLoader;
use ColumnKit\Integrations\MetaBox\Loader as MetaBoxLoader;
use ColumnKit\Integrations\WooCommerce\Loader as WooCommerceLoader;
use ColumnKit\Integrations\Yoast\Loader as YoastLoader;
use ColumnKit\ListScreens\EditManager;
use ColumnKit\ListScreens\FilterManager;
use ColumnKit\ListScreens\ListScreenManager;
use ColumnKit\ListScreens\SortManager;
use ColumnKit\ListScreens\TermListManager;
use ColumnKit\ListScreens\UserListManager;
use ColumnKit\Settings\SettingsRepository;

final class Plugin {
	private static ?Plugin $instance = null;

	private ColumnRegistry $registry;
	private SettingsRepository $repository;
	private ListScreenManager $list_screen_manager;
	private SettingsPage $settings_page;
	private Assets $assets;
	private DataExporter $data_exporter;
	private SettingsExporter $settings_exporter;
	private UserListManager $user_list_manager;
	private TermListManager $term_list_manager;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		$this->registry          = new ColumnRegistry();
		$this->repository        = new SettingsRepository();
		$this->data_exporter     = new DataExporter( $this->registry, $this->repository );
		$this->settings_exporter = new SettingsExporter( $this->registry, $this->repository );
		$this->user_list_manager = new UserListManager( $this->registry, $this->repository );
		$this->term_list_manager = new TermListManager( $this->registry, $this->repository );
		$this->list_screen_manager = new ListScreenManager(
			$this->registry,
			$this->repository,
			new SortManager( $this->registry ),
			new FilterManager( $this->registry ),
			new EditManager( $this->registry, $this->repository ),
			$this->data_exporter,
			$this->user_list_manager,
			$this->term_list_manager
		);
		$this->settings_page = new SettingsPage(
			$this->registry,
			$this->repository,
			$this->settings_exporter
		);
		$this->assets = new Assets();
	}

	public function boot(): void {
		$this->register_built_in_columns();
		$this->list_screen_manager->register_hooks();

		// admin_post_* hooks fire only when admin-post.php receives a request, so registering
		// them at boot is safe and idempotent. (Gating on is_admin() would break wp-cli context,
		// which doesn't define WP_ADMIN.)
		$this->data_exporter->register_hooks();
		$this->settings_exporter->register_hooks();
		$this->user_list_manager->register_hooks();
		$this->term_list_manager->register_hooks();

		if ( is_admin() ) {
			$this->settings_page->register_hooks();
			$this->assets->register_hooks();
		}
	}

	public function registry(): ColumnRegistry {
		return $this->registry;
	}

	public function repository(): SettingsRepository {
		return $this->repository;
	}

	public function list_screen_manager(): ListScreenManager {
		return $this->list_screen_manager;
	}

	private function register_built_in_columns(): void {
		$this->registry->register( new PostIdColumn() );
		$this->registry->register( new PostMetaColumn() );
		$this->registry->register( new TaxonomyColumn() );
		$this->registry->register( new FeaturedImageColumn() );
		$this->registry->register( new AuthorColumn() );

		// Phase 6: Users + Terms screens.
		$this->registry->register( new UserMetaColumn() );
		$this->registry->register( new UserRoleColumn() );
		$this->registry->register( new UserPostCountColumn() );
		$this->registry->register( new TermMetaColumn() );

		// Conditional integrations — each Loader self-detects and only registers if its host
		// plugin is active. Cheap (no-ops when inactive).
		( new ACFLoader() )->register( $this->registry );
		( new MetaBoxLoader() )->register( $this->registry );
		( new JetEngineLoader() )->register( $this->registry );
		( new WooCommerceLoader() )->register( $this->registry );
		( new YoastLoader() )->register( $this->registry );

		do_action( 'columnkit/register_columns', $this->registry );
	}
}
