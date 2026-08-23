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
	public const CACHE_SCHEMA_VERSION = 3;
	public const CACHE_TTL = 12 * HOUR_IN_SECONDS;
	public const REPO      = 'cosmincraciun97/stonewright-wp-mcp';
	public const API_URL   = 'https://api.github.com/repos/cosmincraciun97/stonewright-wp-mcp/releases?per_page=50';
	public const SLUG      = 'stonewright';
	private const RELEASE_CHANNELS = [ 'supported', 'preview', 'stable' ];
	private const SEMVER_PATTERN = '/^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-(?:(?:0|[1-9]\d*)|(?:\d*[A-Za-z-][0-9A-Za-z-]*))(?:\.(?:(?:0|[1-9]\d*)|(?:\d*[A-Za-z-][0-9A-Za-z-]*)))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/';

	public static function register(): void {
		add_filter( 'site_transient_update_plugins', [ self::class, 'inject_update' ] );
		add_filter( 'plugins_api', [ self::class, 'plugins_api' ], 10, 3 );
	}

	public static function cache_key( string $channel ): string {
		return self::CACHE_KEY . '_' . ( 'beta' === $channel ? 'beta' : 'stable' );
	}

	public static function installed_channel( string $version ): string {
		return self::is_prerelease_version( $version ) ? 'beta' : 'stable';
	}

	public static function installed_version(): string {
		$version = defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : '0.0.0';
		return (string) apply_filters( 'stonewright_installed_version', $version );
	}

	/**
	 * Return a stable reason code when release metadata is ineligible.
	 *
	 * @param array<string, mixed> $release Decoded GitHub release JSON.
	 */
	public static function release_rejection_reason( array $release, string $channel ): ?string {
		if ( ! in_array( $channel, [ 'stable', 'beta' ], true ) ) {
			return 'invalid_installed_channel';
		}

		if ( false !== ( $release['draft'] ?? null ) ) {
			return 'draft_release';
		}

		$version = self::release_version( $release );
		if ( null === $version ) {
			return 'invalid_semantic_version';
		}

		$channel_metadata = self::release_channel_metadata( $release );
		if ( null !== $channel_metadata['reason'] ) {
			return $channel_metadata['reason'];
		}

		$release_channel = $channel_metadata['channel'];
		$is_prerelease   = self::is_prerelease_version( $version );
		if (
			( 'stable' === $release_channel && $is_prerelease ) ||
			( in_array( $release_channel, [ 'supported', 'preview' ], true ) && ! $is_prerelease )
		) {
			return 'release_channel_version_incompatible';
		}

		$expected_prerelease = 'preview' === $release_channel;
		if ( $expected_prerelease !== ( $release['prerelease'] ?? null ) ) {
			return 'github_prerelease_incompatible';
		}

		if (
			( 'stable' === $channel && 'stable' !== $release_channel ) ||
			( 'beta' === $channel && ! in_array( $release_channel, [ 'supported', 'preview' ], true ) )
		) {
			return 'installed_channel_incompatible';
		}

		return null === self::parse_release( $release ) ? 'missing_required_assets' : null;
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
			if ( ! is_array( $release ) || null !== self::release_rejection_reason( $release, $channel ) ) {
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
		$result = self::release_metadata( $force_refresh, $installed_version );
		return is_array( $result['release'] ) ? $result['release'] : null;
	}

	/**
	 * Return release metadata with a safe, actionable lookup status.
	 *
	 * @param bool $force_refresh Ignore the cached release for an explicit user check.
	 * @return array{
	 *   ok: bool,
	 *   status: 'available'|'disabled'|'not_available'|'unavailable',
	 *   release: array{version: string, package: string, companion_package: string, checksums: string, url: string, body?: string, tested?: string, requires?: string, requires_php?: string}|null,
	 *   reason: array{code: string, message: string, action: string, http_status?: int}|null
	 * }
	 */
	public static function release_metadata( bool $force_refresh = false, ?string $installed_version = null ): array {
		if ( (bool) apply_filters( 'stonewright_disable_update_check', false ) ) {
			return self::lookup_disabled();
		}

		$installed_version ??= self::installed_version();
		$channel              = self::installed_channel( $installed_version );
		$cache_key            = self::cache_key( $channel );

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if (
				is_array( $cached ) &&
				self::CACHE_SCHEMA_VERSION === ( $cached['schema_version'] ?? null ) &&
				$channel === ( $cached['channel'] ?? null ) &&
				is_array( $cached['release'] ?? null ) &&
				isset( $cached['release']['version'], $cached['release']['package'], $cached['release']['companion_package'], $cached['release']['url'] )
			) {
				/** @var array{version: string, package: string, companion_package: string, checksums: string, url: string, body?: string, tested?: string, requires?: string, requires_php?: string} $release */
				$release = $cached['release'];
				return self::lookup_available( $release );
			}
			if (
				is_array( $cached ) &&
				self::CACHE_SCHEMA_VERSION === ( $cached['schema_version'] ?? null ) &&
				$channel === ( $cached['channel'] ?? null ) &&
				self::is_lookup_result( $cached['result'] ?? null )
			) {
				/** @var array{ok: bool, status: 'available'|'disabled'|'not_available'|'unavailable', release: array{version: string, package: string, companion_package: string, checksums: string, url: string, body?: string, tested?: string, requires?: string, requires_php?: string}|null, reason: array{code: string, message: string, action: string, http_status?: int}|null} $result */
				$result = $cached['result'];
				return $result;
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

		if ( is_wp_error( $response ) ) {
			$is_timeout = self::is_timeout_error( $response );
			$result     = $is_timeout
				? self::lookup_failure(
					'release_timeout',
					__( 'GitHub Releases did not respond before the update check timed out.', 'stonewright' ),
					__( 'Check outbound HTTPS access and try again.', 'stonewright' )
				)
				: self::lookup_failure(
					'release_transport_error',
					__( 'Stonewright could not reach GitHub Releases.', 'stonewright' ),
					__( 'Check outbound HTTPS and DNS, then try again.', 'stonewright' )
				);
			self::cache_lookup_result( $cache_key, $channel, $result, HOUR_IN_SECONDS );
			return $result;
		}

		$http_status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $http_status ) {
			$result = 404 === $http_status
				? self::lookup_failure(
					'release_http_not_found',
					__( 'GitHub Releases could not find the Stonewright release feed.', 'stonewright' ),
					__( 'Verify the configured repository and release API URL, then try again.', 'stonewright' ),
					$http_status
				)
				: self::lookup_failure(
					'release_http_error',
					__( 'GitHub Releases returned an unexpected HTTP response.', 'stonewright' ),
					__( 'Try again later. If it persists, check GitHub service status and outbound proxy rules.', 'stonewright' ),
					$http_status
				);
			self::cache_lookup_result( $cache_key, $channel, $result, HOUR_IN_SECONDS );
			return $result;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || ! array_is_list( $data ) || ! self::release_list_has_valid_shape( $data ) ) {
			$result = self::lookup_failure(
				'release_invalid_response',
				__( 'GitHub Releases returned invalid release metadata.', 'stonewright' ),
				__( 'Try again. If it persists, verify the release API response schema.', 'stonewright' )
			);
			self::cache_lookup_result( $cache_key, $channel, $result, HOUR_IN_SECONDS );
			return $result;
		}

		$parsed = self::select_release( array_values( $data ), $channel );
		if ( null === $parsed ) {
			$missing_artifacts = false;
			foreach ( $data as $release ) {
				if ( 'missing_required_assets' === self::release_rejection_reason( $release, $channel ) ) {
					$missing_artifacts = true;
					break;
				}
			}

			$result = $missing_artifacts
				? self::lookup_failure(
					'missing_required_artifacts',
					__( 'The compatible Stonewright release is missing required plugin or companion artifacts.', 'stonewright' ),
					__( 'Do not update. Publish both required packages, then try again.', 'stonewright' )
				)
				: self::lookup_not_available();
			self::cache_lookup_result( $cache_key, $channel, $result, $missing_artifacts ? HOUR_IN_SECONDS : self::CACHE_TTL );
			return $result;
		}

		set_transient( $cache_key, [ 'schema_version' => self::CACHE_SCHEMA_VERSION, 'channel' => $channel, 'release' => $parsed ], self::CACHE_TTL );
		return self::lookup_available( $parsed );
	}

	/**
	 * @param array<int, mixed> $releases
	 */
	private static function release_list_has_valid_shape( array $releases ): bool {
		foreach ( $releases as $release ) {
			if (
				! is_array( $release ) ||
				! is_string( $release['tag_name'] ?? null ) ||
				! is_bool( $release['draft'] ?? null ) ||
				! is_bool( $release['prerelease'] ?? null ) ||
				! is_array( $release['assets'] ?? null )
			) {
				return false;
			}
		}

		return true;
	}

	private static function is_timeout_error( \WP_Error $error ): bool {
		$code    = strtolower( (string) $error->get_error_code() );
		$message = strtolower( $error->get_error_message() );
		return str_contains( $code, 'timeout' ) || str_contains( $message, 'timed out' ) || str_contains( $message, 'curl error 28' );
	}

	/**
	 * @param array{version: string, package: string, companion_package: string, checksums: string, url: string, body?: string, tested?: string, requires?: string, requires_php?: string} $release
	 * @return array{ok: true, status: 'available', release: array{version: string, package: string, companion_package: string, checksums: string, url: string, body?: string, tested?: string, requires?: string, requires_php?: string}, reason: null}
	 */
	private static function lookup_available( array $release ): array {
		return [ 'ok' => true, 'status' => 'available', 'release' => $release, 'reason' => null ];
	}

	/**
	 * @return array{ok: true, status: 'not_available', release: null, reason: array{code: string, message: string, action: string}}
	 */
	private static function lookup_not_available(): array {
		return [
			'ok'      => true,
			'status'  => 'not_available',
			'release' => null,
			'reason'  => [
				'code'    => 'no_compatible_release',
				'message' => __( 'No compatible Stonewright release exists for the installed channel.', 'stonewright' ),
				'action'  => __( 'Stay on the current version or publish a release for this channel.', 'stonewright' ),
			],
		];
	}

	/**
	 * @return array{ok: true, status: 'disabled', release: null, reason: array{code: string, message: string, action: string}}
	 */
	private static function lookup_disabled(): array {
		return [
			'ok'      => true,
			'status'  => 'disabled',
			'release' => null,
			'reason'  => [
				'code'    => 'release_check_disabled',
				'message' => __( 'Automatic Stonewright release checks are disabled.', 'stonewright' ),
				'action'  => __( 'Enable release checks or verify the installed version manually.', 'stonewright' ),
			],
		];
	}

	/**
	 * @return array{ok: false, status: 'unavailable', release: null, reason: array{code: string, message: string, action: string, http_status?: int}}
	 */
	private static function lookup_failure( string $code, string $message, string $action, ?int $http_status = null ): array {
		$reason = [ 'code' => $code, 'message' => $message, 'action' => $action ];
		if ( null !== $http_status ) {
			$reason['http_status'] = $http_status;
		}

		return [ 'ok' => false, 'status' => 'unavailable', 'release' => null, 'reason' => $reason ];
	}

	private static function is_lookup_result( mixed $result ): bool {
		return is_array( $result )
			&& is_bool( $result['ok'] ?? null )
			&& in_array( $result['status'] ?? null, [ 'available', 'disabled', 'not_available', 'unavailable' ], true )
			&& array_key_exists( 'release', $result )
			&& array_key_exists( 'reason', $result );
	}

	/**
	 * @param array{ok: bool, status: 'available'|'disabled'|'not_available'|'unavailable', release: array<string, mixed>|null, reason: array<string, mixed>|null} $result
	 */
	private static function cache_lookup_result( string $cache_key, string $channel, array $result, int $ttl ): void {
		set_transient(
			$cache_key,
			[ 'schema_version' => self::CACHE_SCHEMA_VERSION, 'channel' => $channel, 'result' => $result ],
			$ttl
		);
	}

	/**
	 * @param array<string, mixed> $release Decoded GitHub release JSON.
	 * @return array{version: string, package: string, companion_package: string, checksums: string, url: string, body?: string, tested?: string, requires?: string, requires_php?: string}|null
	 */
	public static function parse_release( array $release ): ?array {
		$tag     = isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '';
		$version = self::release_version( $release );
		if ( null === $version ) {
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

	/**
	 * @param array<string, mixed> $release Decoded GitHub release JSON.
	 * @return array{channel: string, reason: string|null}
	 */
	private static function release_channel_metadata( array $release ): array {
		$body = $release['body'] ?? null;
		if ( ! is_string( $body ) || '' === trim( $body ) ) {
			return [ 'channel' => '', 'reason' => 'missing_release_channel' ];
		}

		preg_match_all( '/^Release channel:.*$/m', $body, $declarations );
		$declarations = $declarations[0];
		if ( 0 === count( $declarations ) ) {
			return [ 'channel' => '', 'reason' => str_contains( $body, 'Release channel:' ) ? 'malformed_release_channel' : 'missing_release_channel' ];
		}
		if ( 1 !== count( $declarations ) || 1 !== preg_match( '/^Release channel: `([^`]+)`$/', $declarations[0], $matches ) ) {
			return [ 'channel' => '', 'reason' => 'malformed_release_channel' ];
		}

		$channel = $matches[1];
		if ( ! in_array( $channel, self::RELEASE_CHANNELS, true ) ) {
			return [ 'channel' => '', 'reason' => 'unknown_release_channel' ];
		}

		return [ 'channel' => $channel, 'reason' => null ];
	}

	/**
	 * @param array<string, mixed> $release Decoded GitHub release JSON.
	 */
	private static function release_version( array $release ): ?string {
		$tag     = isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '';
		$version = ( str_starts_with( $tag, 'v' ) || str_starts_with( $tag, 'V' ) ) ? substr( $tag, 1 ) : $tag;
		return 1 === preg_match( self::SEMVER_PATTERN, $version ) ? $version : null;
	}

	private static function is_prerelease_version( string $version ): bool {
		if ( 1 !== preg_match( self::SEMVER_PATTERN, $version ) ) {
			return false;
		}

		$precedence_version = explode( '+', $version, 2 )[0];
		return str_contains( $precedence_version, '-' );
	}
}
