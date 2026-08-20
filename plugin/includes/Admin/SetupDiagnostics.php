<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Companion\CompanionContract;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\OAuth\Bootstrap as OAuthBootstrap;
use Stonewright\WpMcp\OAuth\Endpoints\Discovery;
use Stonewright\WpMcp\OAuth\Transport as OAuthTransport;
use Stonewright\WpMcp\Support\TokenSurfaceBudgets;

/**
 * Produces a compact, side-effect-free setup report for the configuration UI.
 */
final class SetupDiagnostics {

	/**
	 * @param array{probe?:bool,loopback?:callable} $args
	 * @return array{ready: bool, checks: list<array{id: string, status: string, label: string, detail: string}>, versions: array<string, string|int>}
	 */
	public static function report( array $args = [] ): array {
		$enabled       = (bool) get_option( 'stonewright_enabled', false );
		$https         = is_ssl() || str_starts_with( (string) get_site_url(), 'https://' );
		$app_passwords = self::application_passwords_available();
		$endpoint      = ConnectClientConfig::mcp_endpoint_url();
		$tool_count    = count( AbilityRegistry::enabled_abilities() );
		$surface       = AbilityRegistry::mcp_surface();
		$oauth_allowed = OAuthTransport::allowed();
		$oauth_endpoint = OAuthBootstrap::resource_identifier();
		$oauth_discovery = Discovery::protected_resource_metadata_url();
		$probe         = (bool) ( $args['probe'] ?? false );
		$loopback_cb   = $args['loopback'] ?? null;

		$transport_detail = $https
			? __( 'HTTPS active.', 'stonewright' )
			: __( 'Running over HTTP. Fine for local and LAN sites; HTTPS is recommended when connecting from outside your network.', 'stonewright' );

		$app_password_detail = $app_passwords
			? __( 'Available for the current user.', 'stonewright' )
			: ( $https
				? __( 'Unavailable; check the user profile or Application Passwords settings.', 'stonewright' )
				: __( 'Unavailable on this HTTP site. For local setups add define( \'WP_ENVIRONMENT_TYPE\', \'local\' ); to wp-config.php so Application Passwords work without HTTPS.', 'stonewright' ) );

		$connection_ok = $enabled && '' !== $endpoint;
		$surface_ok    = in_array( $surface, [ 'bootstrap', 'essential', 'full' ], true );

		$checks = [
			self::check( 'plugin', $enabled, __( 'Stonewright abilities', 'stonewright' ), $enabled ? __( 'Enabled.', 'stonewright' ) : __( 'Enable Stonewright in step 1.', 'stonewright' ) ),
			self::check(
				'connection',
				$connection_ok,
				__( 'Connection', 'stonewright' ),
				$connection_ok
					? __( 'Abilities are enabled and an MCP endpoint is configured.', 'stonewright' )
					: __( 'Enable Stonewright and confirm the MCP endpoint before connecting a client.', 'stonewright' )
			),
			[
				'id'     => 'transport',
				'status' => $https ? 'ok' : 'info',
				'label'  => __( 'Connection transport', 'stonewright' ),
				'detail' => $transport_detail,
			],
			self::check( 'application_passwords', $app_passwords, __( 'Application Passwords', 'stonewright' ), $app_password_detail ),
			self::check( 'endpoint', '' !== $endpoint, __( 'MCP endpoint', 'stonewright' ), $endpoint ),
			self::check(
				'tool_surface',
				$surface_ok,
				__( 'Tool surface', 'stonewright' ),
				sprintf(
					/* translators: 1: MCP surface, 2: tool count */
					__( 'Profile %1$s with %2$d tools exposed.', 'stonewright' ),
					$surface,
					$tool_count
				)
			),
			self::check( 'tool_budget', $tool_count <= TokenSurfaceBudgets::ESSENTIAL_MAX_TOOLS, __( 'Compact tool surface', 'stonewright' ), sprintf( __( '%d tools exposed in the current profile.', 'stonewright' ), $tool_count ) ),
			self::check(
				'oauth_transport',
				$oauth_allowed,
				__( 'OAuth transport', 'stonewright' ),
				$oauth_allowed
					? __( 'HTTPS or an explicit local WordPress environment is active.', 'stonewright' )
					: __( 'OAuth is disabled on public plain HTTP sites.', 'stonewright' )
			),
			self::check( 'oauth_endpoint', '' !== $oauth_endpoint, __( 'OAuth MCP endpoint', 'stonewright' ), $oauth_endpoint ),
			self::check( 'oauth_discovery', '' !== $oauth_discovery, __( 'OAuth discovery', 'stonewright' ), $oauth_discovery ),
		];

		$probe_result = null;
		if ( $probe ) {
			$probe_result = is_callable( $loopback_cb ) ? $loopback_cb() : McpLoopbackSelfTest::run();
			$probe_result = is_array( $probe_result ) ? $probe_result : [];
			$probe_ok     = true === ( $probe_result['ok'] ?? false );
			$probe_detail = $probe_ok
				? __( 'Live MCP loopback passed (initialize, tools/list, task-start).', 'stonewright' )
				: self::probe_failure_detail( $probe_result );
			$checks[]     = self::check( 'connection_probe', $probe_ok, __( 'MCP connection probe', 'stonewright' ), $probe_detail );
			$waf_hit      = self::waf_blocked( $probe_result );
			$checks[]     = self::check(
				'waf',
				! $waf_hit,
				__( 'WAF-ish blocks', 'stonewright' ),
				$waf_hit
					? __( 'The MCP endpoint returned HTTP 403 or 406, which often means a firewall or WAF blocked the loopback.', 'stonewright' )
					: __( 'No 403/406 block observed on the MCP loopback.', 'stonewright' )
			);
		} else {
			$checks[] = [
				'id'     => 'connection_probe',
				'status' => 'info',
				'label'  => __( 'MCP connection probe', 'stonewright' ),
				'detail' => __( 'Click Run diagnostics to exercise the live MCP endpoint.', 'stonewright' ),
			];
			$checks[] = [
				'id'     => 'waf',
				'status' => 'info',
				'label'  => __( 'WAF-ish blocks', 'stonewright' ),
				'detail' => __( 'Run diagnostics to reuse the MCP loopback for 403/406 style blocks.', 'stonewright' ),
			];
		}

		return [
			'ready'    => ! in_array( 'error', array_column( $checks, 'status' ), true ),
			'checks'   => $checks,
			'versions' => [
				'plugin'             => defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : 'unknown',
				'companion_contract' => CompanionContract::EXPECTED_CONTRACT_VERSION,
				'wordpress'          => isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : 'unknown',
				'php'                => PHP_VERSION,
				'tool_count'         => $tool_count,
			],
		];
	}

	/**
	 * @param array<string, mixed> $probe
	 */
	private static function probe_failure_detail( array $probe ): string {
		$endpoint = (string) ( $probe['endpoint'] ?? ConnectClientConfig::mcp_endpoint_url() );
		$steps    = is_array( $probe['steps'] ?? null ) ? $probe['steps'] : [];
		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			if ( 'failed' === ( $step['status'] ?? '' ) && '' !== (string) ( $step['detail'] ?? '' ) ) {
				return trim( (string) $step['detail'] . ' ' . $endpoint );
			}
		}

		return __( 'MCP loopback failed.', 'stonewright' ) . ' ' . $endpoint;
	}

	/**
	 * @param array<string, mixed> $probe
	 */
	private static function waf_blocked( array $probe ): bool {
		$steps = is_array( $probe['steps'] ?? null ) ? $probe['steps'] : [];
		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$detail = (string) ( $step['detail'] ?? '' );
			if ( str_contains( $detail, 'HTTP 403' ) || str_contains( $detail, 'HTTP 406' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array{id: string, status: string, label: string, detail: string}
	 */
	private static function check( string $id, bool $passes, string $label, string $detail ): array {
		return [
			'id'     => $id,
			'status' => $passes ? 'ok' : 'error',
			'label'  => $label,
			'detail' => $detail,
		];
	}

	private static function application_passwords_available(): bool {
		if ( ! class_exists( '\\WP_Application_Passwords' ) ) {
			return false;
		}

		if ( function_exists( 'wp_is_application_passwords_available' ) && ! wp_is_application_passwords_available() ) {
			return false;
		}

		return ! function_exists( 'wp_is_application_passwords_available_for_user' )
			|| (bool) wp_is_application_passwords_available_for_user( wp_get_current_user() );
	}
}
