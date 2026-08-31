<?php
declare( strict_types=1 );

namespace ColumnKit\Updater;

/**
 * Self-hosted plugin updates from GitHub Releases.
 *
 * Uses WP core's `Update URI` mechanism (WP 5.8+): wp_update_plugins() sees the
 * `Update URI: https://github.com/...` plugin header and fires the
 * `update_plugins_github.com` filter, letting us answer "is there a newer version?"
 * ourselves. When we return a version + package URL, WP shows the native
 * "update available" row and one-click installs the zip — no wp.org listing needed.
 *
 * Release contract (matches .github/workflows/release.yml):
 *   - Tag v{X.Y.Z} → GitHub release with asset columnkit-{X.Y.Z}.zip
 *   - The zip contains a single columnkit/ folder, so in-place upgrades keep the
 *     plugin directory name stable.
 *
 * Private-repo support (token optional):
 *   - Public repo: no config needed; the asset's browser_download_url works anywhere.
 *   - Private repo: define CK_GITHUB_TOKEN in wp-config.php (one fine-grained
 *     read-only PAT, reusable across sites). We then talk to the releases API with
 *     an Authorization header, and use the asset *API* URL as the package (the
 *     browser URL 404s for private repos). `http_request_args` injects the auth +
 *     octet-stream headers on that download. NOTE: private-repo downloads need
 *     WP 6.2+ — earlier Requests versions forwarded the Authorization header to
 *     GitHub's S3 redirect, which S3 rejects.
 *
 * Failure posture: API errors are cached briefly (no hammering GitHub from wp-cron),
 * and we return "no update" — never a broken update offer.
 */
final class GitHubUpdater {
	public const REPO      = 'brendanoconnellwp/columnkit';
	public const HOSTNAME  = 'github.com'; // must match the Update URI host in columnkit.php
	private const API_LATEST = 'https://api.github.com/repos/%s/releases/latest';
	private const ASSET_API  = 'https://api.github.com/repos/%s/releases/assets/';
	private const TRANSIENT  = 'ck_github_update';

	private const CACHE_OK_TTL   = 6 * HOUR_IN_SECONDS;
	private const CACHE_FAIL_TTL = HOUR_IN_SECONDS;

	private string $token;

	public function __construct( ?string $token = null ) {
		$this->token = $token ?? ( defined( 'CK_GITHUB_TOKEN' ) && is_string( CK_GITHUB_TOKEN ) ? CK_GITHUB_TOKEN : '' );
	}

	public function register_hooks(): void {
		// Registered unconditionally — update checks run from wp-cron and wp-admin both.
		add_filter( 'update_plugins_' . self::HOSTNAME, [ $this, 'check_update' ], 10, 3 );
		add_filter( 'http_request_args', [ $this, 'authorize_download' ], 10, 2 );
		add_action( 'upgrader_process_complete', [ $this, 'flush_cache' ], 10, 2 );
		// WP's "Check again" (Dashboard → Updates, force-check) deletes core's update_plugins
		// site transient; mirror that so a forced check reaches GitHub instead of serving our
		// cached (possibly failed) lookup for up to 6 more hours.
		add_action( 'delete_site_transient_update_plugins', [ $this, 'flush_transient' ] );
	}

	/** Drop the cached release lookup so the next check hits the GitHub API fresh. */
	public function flush_transient(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * `update_plugins_github.com` callback. Fires for EVERY plugin whose Update URI
	 * host is github.com, so the basename gate matters — never answer for someone
	 * else's plugin.
	 *
	 * @param array|false          $update      Existing answer from another filter (usually false).
	 * @param array<string, mixed> $plugin_data Parsed plugin headers.
	 * @param string               $plugin_file Plugin basename, e.g. "columnkit/columnkit.php".
	 * @return array|false
	 */
	public function check_update( $update, array $plugin_data, string $plugin_file ) {
		if ( $plugin_file !== CK_BASENAME ) {
			return $update;
		}

		$release = $this->get_latest_release();
		if ( $release === null ) {
			return $update;
		}

		$new_version = ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' );
		$installed   = (string) ( $plugin_data['Version'] ?? '0' );
		if ( $new_version === '' || version_compare( $new_version, $installed, '<=' ) ) {
			return $update;
		}

		$package = $this->pick_package( $release, $new_version );
		if ( $package === '' ) {
			return $update; // Release exists but has no usable zip — offer nothing.
		}

		return [
			'id'      => self::HOSTNAME . '/' . self::REPO,
			'slug'    => 'columnkit',
			'plugin'  => $plugin_file,
			'version' => $new_version,
			'url'     => (string) ( $release['html_url'] ?? 'https://github.com/' . self::REPO ),
			'package' => $package,
		];
	}

	/**
	 * Choose the download URL for the release zip.
	 *
	 * Prefers the asset named columnkit-{version}.zip; falls back to the first .zip
	 * asset. With a token (private repo) the asset API URL is used — the browser URL
	 * is not accessible for private repos.
	 *
	 * @param array<string, mixed> $release
	 */
	private function pick_package( array $release, string $version ): string {
		$assets = isset( $release['assets'] ) && is_array( $release['assets'] ) ? $release['assets'] : [];
		$chosen = null;
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$name = (string) ( $asset['name'] ?? '' );
			if ( $name === 'columnkit-' . $version . '.zip' ) {
				$chosen = $asset;
				break;
			}
			if ( $chosen === null && str_ends_with( $name, '.zip' ) ) {
				$chosen = $asset;
			}
		}
		if ( $chosen === null ) {
			return '';
		}
		if ( $this->token !== '' ) {
			$id = isset( $chosen['id'] ) && is_scalar( $chosen['id'] ) ? (string) (int) $chosen['id'] : '';
			return $id !== '' ? sprintf( self::ASSET_API, self::REPO ) . $id : '';
		}
		return (string) ( $chosen['browser_download_url'] ?? '' );
	}

	/**
	 * Latest release from the GitHub API, cached in a transient. Null on any failure
	 * (and the failure itself is cached briefly so twice-daily cron + admin page loads
	 * can't hammer a rate-limited or unreachable API).
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_latest_release(): ?array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return empty( $cached['ck_error'] ) ? $cached : null;
		}

		$headers = [
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
		];
		if ( $this->token !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}

		$response = wp_remote_get(
			sprintf( self::API_LATEST, self::REPO ),
			[ 'headers' => $headers, 'timeout' => 10 ]
		);

		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			set_transient( self::TRANSIENT, [ 'ck_error' => true ], self::CACHE_FAIL_TTL );
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['tag_name'] ) ) {
			set_transient( self::TRANSIENT, [ 'ck_error' => true ], self::CACHE_FAIL_TTL );
			return null;
		}

		// Cache only the fields we use — the full release payload is bulky.
		$slim = [
			'tag_name' => (string) $body['tag_name'],
			'html_url' => (string) ( $body['html_url'] ?? '' ),
			'assets'   => [],
		];
		foreach ( (array) ( $body['assets'] ?? [] ) as $asset ) {
			if ( is_array( $asset ) ) {
				$slim['assets'][] = [
					'id'                   => (int) ( $asset['id'] ?? 0 ),
					'name'                 => (string) ( $asset['name'] ?? '' ),
					'browser_download_url' => (string) ( $asset['browser_download_url'] ?? '' ),
				];
			}
		}
		set_transient( self::TRANSIENT, $slim, (int) apply_filters( 'columnkit/update_cache_ttl', self::CACHE_OK_TTL ) );
		return $slim;
	}

	/**
	 * `http_request_args` — add auth headers ONLY on downloads of OUR release assets
	 * (private-repo mode). The prefix match is strict so the token never rides along
	 * on any other request WP makes.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function authorize_download( array $args, string $url ): array {
		if ( $this->token === '' || ! str_starts_with( $url, sprintf( self::ASSET_API, self::REPO ) ) ) {
			return $args;
		}
		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = [];
		}
		$args['headers']['Authorization'] = 'Bearer ' . $this->token;
		$args['headers']['Accept']        = 'application/octet-stream';
		return $args;
	}

	/**
	 * After any plugin install/update completes, drop the cache so the row clears
	 * immediately instead of showing a stale "update available" for hours.
	 *
	 * @param mixed                $upgrader
	 * @param array<string, mixed> $options
	 */
	public function flush_cache( $upgrader, array $options ): void {
		if ( ( $options['type'] ?? '' ) === 'plugin' ) {
			delete_transient( self::TRANSIENT );
		}
	}
}
