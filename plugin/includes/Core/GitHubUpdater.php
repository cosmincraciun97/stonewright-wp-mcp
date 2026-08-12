<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * GitHub Releases update checker for Stonewright.
 *
 * Compares STONEWRIGHT_VERSION with the latest public GitHub release and injects
 * update metadata into the WordPress update_plugins transient so native Plugins
 * screen updates work. Disable with the stonewright_disable_update_check filter.
 */
final class GitHubUpdater {

	public const CACHE_KEY = 'stonewright_github_release';
	public const CACHE_TTL = 12 * HOUR_IN_SECONDS;
	public const REPO      = 'cosmincraciun97/stonewright-wp-mcp';
	public const API_URL   = 'https://api.github.com/repos/cosmincraciun97/stonewright-wp-mcp/releases?per_page=50';
	public const SLUG      = 'stonewright';

	public static function register(): void {
		add_filter( 'site_transient_update_plugins', [ self::class, 'inject_update' ] );
		add_filter( 'plugins_api', [ self::class, 'plugins_api' ], 10, 3 );
	}

	public static function cache_key( string $channel ): string {
		return self::CACHE_KEY . '_' . ( 'beta' === $channel ? 'beta' : 'stable' );
	}

	public static function installed_channel( string $version ): string {
		return 1 === preg_match( '/^\d+\.\d+\.\d+-[0-9A-Za-z.-]+(?:\+[0-9A-Za-z.-]+)?$/', $version )
			? 'beta'
			: 'stable';
	}

	public static function installed_version(): string {
		$version = defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : '0.0.0';
		return (string) apply_filters( 'stonewright_installed_version', $version );
	}

	/**
	 * @param array<int, mixed> $releases Decoded GitHub release list.
	 * @return array{version: string, package: string, companion_package: string, checksums: string, url: string, body?: string, tested?: string, requires?: string, requires_php?: string}|null
	 */
	public static function select_release( array $releases, string $channel ): ?array {
		if ( ! in_array( $channel, [ 'stable', 'beta' ], true ) ) {
			return null;
		}

		$selected = null;
		foreach ( $releases as $release ) {
			if ( ! is_array( $release ) || false !== ( $release['draft'] ?? null ) ) {
				continue;
			}
			$tag     = isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '';
			$version = ltrim( $tag, 'vV' );
			$is_beta = 1 === preg_match( '/^\d+\.\d+\.\d+-[0-9A-Za-z.-]+(?:\+[0-9A-Za-z.-]+)?$/', $version );
			$flag    = $release['prerelease'] ?? null;
			if (
				( 'stable' === $channel && ( false !== $flag || $is_beta ) ) ||
				( 'beta' === $channel && ( true !== $flag || ! $is_beta ) )
			) {
				continue;
			}
			$parsed = self::parse_release( $release );
			if ( null === $parsed ) {
				continue;
			}
			if ( null === $selected || version_compare( $parsed['version'], $selected['version'], '>' ) ) {
				$selected = $parsed;
			}
		}

		return $selected;
	}

	/**
	 * Plugin file basenames used by the update system (e.g. stonewright/stonewright.php).
	 */
	public static function plugin_basename(): string {
		$file = defined( 'STONEWRIGHT_FILE' ) ? (string) constant( 'STONEWRIGHT_FILE' ) : ( defined( 'STONEWRIGHT_DIR' ) ? (string) STONEWRIGHT_DIR . 'stonewright.php' : 'stonewright/stonewright.php' );
		return function_exists( 'plugin_basename' ) ? plugin_basename( $file ) : 'stonewright/stonewright.php';
	}

	/**
	 * @param mixed $transient
	 * @return mixed
	 */
	public static function inject_update( mixed $transient ): mixed {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		if ( (bool) apply_filters( 'stonewright_disable_update_check', false ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = [];
		}

		$remote = self::fetch_latest_release();
		$plugin = self::plugin_basename();
		$current = self::installed_version();

		if ( null === $remote || ! version_compare( $current, $remote['version'], '<' ) ) {
			$transient->no_update[ $plugin ] = (object) [
				'slug'        => self::SLUG,
				'plugin'      => $plugin,
				'new_version' => $current,
				'url'         => 'https://github.com/' . self::REPO,
				'package'     => '',
			];
			return $transient;
		}

		$transient->response[ $plugin ] = (object) [
			'slug'        => self::SLUG,
			'plugin'      => $plugin,
			'new_version' => $remote['version'],
			'url'         => $remote['url'],
			'package'     => $remote['package'],
			'tested'      => $remote['tested'] ?? '',
			'requires'    => $remote['requires'] ?? '',
			'requires_php'=> $remote['requires_php'] ?? ( defined( 'STONEWRIGHT_MIN_PHP' ) ? (string) constant( 'STONEWRIGHT_MIN_PHP' ) : '8.1' ),
		];

		return $transient;
	}

	/**
	 * Supply plugin info for the "View Details" popup.
	 *
	 * @param mixed  $result
	 * @param string $action
	 * @param mixed  $args
	 * @return mixed
	 */
	public static function plugins_api( mixed $result, string $action, mixed $args ): mixed {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		$slug = is_object( $args ) ? (string) ( $args->slug ?? '' ) : '';
		if ( self::SLUG !== $slug ) {
			return $result;
		}

		$remote = self::fetch_latest_release();
		if ( null === $remote ) {
			return $result;
		}

		return (object) [
			'name'           => 'Stonewright',
			'slug'           => self::SLUG,
			'version'        => $remote['version'],
			'author'         => '<a href="https://github.com/cosmincraciun97/stonewright-wp-mcp">Stonewright</a>',
			'homepage'       => $remote['url'],
			'requires'       => $remote['requires'] ?? ( defined( 'STONEWRIGHT_MIN_WP' ) ? (string) constant( 'STONEWRIGHT_MIN_WP' ) : '6.7' ),
			'requires_php'   => $remote['requires_php'] ?? ( defined( 'STONEWRIGHT_MIN_PHP' ) ? (string) constant( 'STONEWRIGHT_MIN_PHP' ) : '8.1' ),
			'tested'         => $remote['tested'] ?? '',
			'download_link'  => $remote['package'],
			'sections'       => [
				'description' => $remote['body'] ?? __( 'AI builder tools for WordPress MCP.', 'stonewright' ),
			],
		];
	}

	/**
	 * @param bool $force_refresh Ignore the cached release for an explicit user check.
	 * @return array{version: string, package: string, companion_package: string, checksums: string, url: string, body?: string, tested?: string, requires?: string, requires_php?: string}|null
	 */
	public static function fetch_latest_release( bool $force_refresh = false, ?string $installed_version = null ): ?array {
		if ( (bool) apply_filters( 'stonewright_disable_update_check', false ) ) {
			return null;
		}

		$installed_version ??= self::installed_version();
		$channel              = self::installed_channel( $installed_version );
		$cache_key            = self::cache_key( $channel );

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if (
				is_array( $cached ) &&
				$channel === ( $cached['channel'] ?? null ) &&
				is_array( $cached['release'] ?? null ) &&
				isset( $cached['release']['version'], $cached['release']['package'], $cached['release']['companion_package'], $cached['release']['url'] )
			) {
				/** @var array{version: string, package: string, companion_package: string, checksums: string, url: string, body?: string, tested?: string, requires?: string, requires_php?: string} $release */
				$release = $cached['release'];
				return $release;
			}
			if ( is_array( $cached ) && $channel === ( $cached['channel'] ?? null ) && true === ( $cached['error'] ?? false ) ) {
				return null;
			}
		}

		$response = wp_remote_get(
			self::API_URL,
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Stonewright/' . ( defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : '0.0.0' ),
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $cache_key, [ 'channel' => $channel, 'error' => true ], HOUR_IN_SECONDS );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			set_transient( $cache_key, [ 'channel' => $channel, 'error' => true ], HOUR_IN_SECONDS );
			return null;
		}

		$parsed = self::select_release( array_values( $data ), $channel );
		if ( null === $parsed ) {
			set_transient( $cache_key, [ 'channel' => $channel, 'error' => true ], HOUR_IN_SECONDS );
			return null;
		}

		set_transient( $cache_key, [ 'channel' => $channel, 'release' => $parsed ], self::CACHE_TTL );
		return $parsed;
	}

	/**
	 * @param array<string, mixed> $release Decoded GitHub release JSON.
	 * @return array{version: string, package: string, companion_package: string, checksums: string, url: string, body?: string, tested?: string, requires?: string, requires_php?: string}|null
	 */
	public static function parse_release( array $release ): ?array {
		$tag = isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '';
		if ( '' === $tag ) {
			return null;
		}

		$version = ltrim( $tag, "vV" );
		if ( 1 !== preg_match( '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $version ) ) {
			return null;
		}

		$package           = '';
		$companion_package = '';
		$checksums         = '';
		$assets            = $release['assets'] ?? [];
		if ( is_array( $assets ) ) {
			$expected           = 'stonewright-' . $version . '.zip';
			$expected_companion = 'stonewright-companion-' . $version . '.tgz';
			$download_prefix    = 'https://github.com/' . self::REPO . '/releases/download/' . rawurlencode( $tag ) . '/';
			foreach ( $assets as $asset ) {
				if ( ! is_array( $asset ) ) {
					continue;
				}
				$name = (string) ( $asset['name'] ?? '' );
				$url  = (string) ( $asset['browser_download_url'] ?? '' );
				if ( '' === $url || $url !== $download_prefix . rawurlencode( $name ) ) {
					continue;
				}
				if ( $name === $expected ) {
					$package = $url;
				} elseif ( $name === $expected_companion ) {
					$companion_package = $url;
				} elseif ( 'SHA256SUMS.txt' === $name ) {
					$checksums = $url;
				}
			}
		}

		if ( '' === $package || '' === $companion_package ) {
			return null;
		}

		$expected_release_url = 'https://github.com/' . self::REPO . '/releases/tag/';
		$url                  = isset( $release['html_url'] ) ? (string) $release['html_url'] : '';
		if ( ! str_starts_with( $url, $expected_release_url ) ) {
			$url = $expected_release_url . rawurlencode( $tag );
		}

		$parsed = [
			'version'           => $version,
			'package'           => $package,
			'companion_package' => $companion_package,
			'checksums'         => $checksums,
			'url'               => $url,
		];

		if ( isset( $release['body'] ) && is_string( $release['body'] ) && '' !== $release['body'] ) {
			$parsed['body'] = $release['body'];
		}

		return $parsed;
	}
}
