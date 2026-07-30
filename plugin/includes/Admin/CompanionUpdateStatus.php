<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Core\GitHubUpdater;

/**
 * Read-only release and configured-bridge status for the Setup update panel.
 *
 * A WordPress server cannot inspect or replace an stdio process running inside
 * an AI client on another machine. This report says that plainly and detects a
 * version only when the optional HTTP bridge is reachable.
 */
final class CompanionUpdateStatus {

	/**
	 * @param callable|null $bridge_transport Test seam matching wp_safe_remote_get.
	 * @param bool          $force_refresh    Ignore the cached release for an explicit user check.
	 * @return array<string, mixed>
	 */
	public static function report( ?callable $bridge_transport = null, bool $force_refresh = false ): array {
		$plugin_version = defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : '0.0.0';
		$release        = GitHubUpdater::fetch_latest_release( $force_refresh );
		$latest_version = is_array( $release ) ? (string) ( $release['version'] ?? '' ) : '';
		$target_version = '' !== $latest_version ? $latest_version : $plugin_version;
		$bridge         = self::bridge_health( $bridge_transport );
		$bridge_version = (string) ( $bridge['version'] ?? '' );

		$plugin_update_available = '' !== $latest_version && version_compare( $plugin_version, $latest_version, '<' );
		$companion_status        = 'unverified';
		if ( ! empty( $bridge['reachable'] ) && '' !== $bridge_version ) {
			$comparison = version_compare( $bridge_version, $target_version );
			if ( 0 === $comparison ) {
				$companion_status = 'current';
			} elseif ( -1 === $comparison ) {
				$companion_status = 'outdated';
			} else {
				$companion_status = 'mismatch';
			}
		}

		$package = is_array( $release ) && '' !== (string) ( $release['companion_package'] ?? '' )
			? (string) $release['companion_package']
			: ConnectClientConfig::companion_package_spec( $target_version );
		$prompt  = self::update_prompt( $target_version, $package );

		return [
			'ok'                      => is_array( $release ),
			'plugin_version'          => $plugin_version,
			'latest_release_version'  => $latest_version,
			'plugin_update_available' => $plugin_update_available,
			'companion_status'        => $companion_status,
			'companion_package'       => $package,
			'checksums'               => is_array( $release ) ? (string) ( $release['checksums'] ?? '' ) : '',
			'release_url'             => is_array( $release ) ? (string) ( $release['url'] ?? '' ) : '',
			'bridge'                  => $bridge,
			'update_prompt'           => $prompt,
			'boundary'                => __( 'WordPress cannot replace a local stdio companion process. Update it in the AI client, restart that client, then verify the reported companion version.', 'stonewright' ),
		];
	}

	/**
	 * @param callable|null $transport Test seam matching wp_safe_remote_get.
	 * @return array{reachable: bool, version: string, contract_version: string, detail: string}
	 */
	private static function bridge_health( ?callable $transport = null ): array {
		$base  = rtrim( (string) get_option( 'stonewright_companion_url', 'http://127.0.0.1:8765' ), '/' );
		$token = (string) get_option( 'stonewright_companion_token', '' );
		$transport ??= static fn( string $url, array $args ): array|\WP_Error => wp_safe_remote_get( $url, $args );

		$response = $transport(
			$base . '/health',
			[
				'headers'     => '' !== $token ? [ 'Authorization' => 'Bearer ' . $token ] : [],
				'timeout'     => 3,
				'redirection' => 0,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'reachable'        => false,
				'version'          => '',
				'contract_version' => '',
				'detail'           => __( 'Configured HTTP bridge is not reachable. Local stdio remains private to the AI client.', 'stonewright' ),
			];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $data ) ) {
			return [
				'reachable'        => false,
				'version'          => '',
				'contract_version' => '',
				'detail'           => __( 'Configured HTTP bridge did not return a valid health response.', 'stonewright' ),
			];
		}

		$version  = isset( $data['version'] ) && is_string( $data['version'] ) ? $data['version'] : '';
		$contract = isset( $data['contract_version'] ) && is_string( $data['contract_version'] ) ? $data['contract_version'] : '';

		return [
			'reachable'        => true,
			'version'          => $version,
			'contract_version' => $contract,
			'detail'           => '' !== $version
				? sprintf(
					/* translators: %s: companion version. */
					__( 'Configured HTTP bridge reports companion %s.', 'stonewright' ),
					$version
				)
				: __( 'Configured HTTP bridge is reachable but does not report its package version.', 'stonewright' ),
		];
	}

	private static function update_prompt( string $version, string $package ): string {
		return sprintf(
			"Update the Stonewright companion used by this AI client to %1\$s.\n\n"
			. "Official package:\n%2\$s\n\n"
			. "Use the client's official MCP settings or command to replace only the Stonewright package reference. Do not print, reveal, move, or commit surrounding credentials or private client configuration. Fully restart the client so the old stdio process and cached tool list are gone.\n\n"
			. "After restart:\n"
			. "1. Confirm stonewright-task-start is visible.\n"
			. "2. Call stonewright-task-start first.\n"
			. "3. Call stonewright-setup-profile and stonewright-wordpress-mcp-status.\n"
			. "4. Verify companion_version is %1\$s and refresh_required_tool_names is empty.\n"
			. "5. Stop and report the exact failure if the version or tool list is still stale. Do not use a generic adapter, scratch runner, direct REST workaround, or shell WP-CLI.",
			$version,
			$package
		);
	}
}
