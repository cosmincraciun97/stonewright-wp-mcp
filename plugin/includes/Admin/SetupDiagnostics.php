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
	 * @param array{probe?:bool,loopback?:callable,mode?:string} $args
	 * @return array{ready: bool, checks: list<array{id: string, status: string, label: string, detail: string}>, versions: array<string, string|int>, mode: string}
	 */
	public static function report( array $args = [] ): array {
		$mode = isset( $args['mode'] ) ? sanitize_key( (string) $args['mode'] ) : 'both';
		if ( ! in_array( $mode, [ 'both', 'http', 'stdio' ], true ) ) {
			$mode = 'both';
		}

		$enabled       = (bool) get_option( 'stonewright_enabled', false );
		$https         = is_ssl() || str_starts_with( (string) get_site_url(), 'https://' );
		$app_passwords = self::application_passwords_available();
		$endpoint      = ConnectClientConfig::mcp_endpoint_url();
		$tool_count    = count( AbilityRegistry::enabled_abilities() );
		$surface       = AbilityRegistry::mcp_surface();
		$oauth_allowed = OAuthTransport::allowed();
		$oauth_endpoint = OAuthBootstrap::resource_identifier();
		$oauth_discovery = Discovery::protected_resource_metadata_url();
		$probe         = (bool) ( $args['probe'] ?? false ) && 'stdio' !== $mode;
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
			self::compact_tool_surface_check( $surface, $tool_count ),
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
			$checks[] = self::bot_filter_check( $args, $endpoint );
			$checks[] = self::oauth_registration_check( $args );
		} elseif ( 'stdio' === $mode ) {
			$checks[]        = [
				'id'     => 'connection_probe',
				'status' => 'info',
				'label'  => __( 'MCP connection probe', 'stonewright' ),
				'detail' => __( 'HTTP loopback skipped for local companion (stdio).', 'stonewright' ),
			];
			$checks[]        = [
				'id'     => 'waf',
				'status' => 'info',
				'label'  => __( 'WAF-ish blocks', 'stonewright' ),
				'detail' => __( 'Not checked for stdio; WAF-ish blocks apply to remote HTTP.', 'stonewright' ),
			];
			$checks[]        = [
				'id'     => 'bot_filter',
				'status' => 'info',
				'label'  => __( 'Bot / WAF user-agent filter', 'stonewright' ),
				'detail' => __( 'Not checked for stdio; User-Agent probes apply to remote HTTP.', 'stonewright' ),
			];
			$checks[]        = [
				'id'     => 'oauth_registration',
				'status' => 'info',
				'label'  => __( 'OAuth dynamic registration', 'stonewright' ),
				'detail' => __( 'Not checked for stdio; OAuth registration applies to remote HTTP.', 'stonewright' ),
			];
			$companion_url = trim( (string) get_option( 'stonewright_companion_url', '' ) );
			$checks[]      = [
				'id'     => 'companion_url',
				'status' => '' !== $companion_url ? 'ok' : 'warn',
				'label'  => __( 'Local companion URL', 'stonewright' ),
				'detail' => '' !== $companion_url
					? $companion_url
					: __( 'No companion URL is configured.', 'stonewright' ),
			];
		} else {
			$checks[] = [
				'id'     => 'connection_probe',
				'status' => 'info',
				'label'  => __( 'MCP connection probe', 'stonewright' ),
				'detail' => __( 'Not run yet — click Run diagnostics', 'stonewright' ),
			];
			$checks[] = [
				'id'     => 'waf',
				'status' => 'info',
				'label'  => __( 'WAF-ish blocks', 'stonewright' ),
				'detail' => __( 'Not run yet — click Run diagnostics', 'stonewright' ),
			];
			$checks[] = [
				'id'     => 'bot_filter',
				'status' => 'info',
				'label'  => __( 'Bot / WAF user-agent filter', 'stonewright' ),
				'detail' => __( 'Not run yet — click Run diagnostics', 'stonewright' ),
			];
			$checks[] = [
				'id'     => 'oauth_registration',
				'status' => 'info',
				'label'  => __( 'OAuth dynamic registration', 'stonewright' ),
				'detail' => __( 'Not run yet — click Run diagnostics', 'stonewright' ),
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
			'mode'     => $mode,
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
	private static function compact_tool_surface_check( string $surface, int $tool_count ): array {
		$label   = __( 'Compact tool surface', 'stonewright' );
		$compact = in_array( $surface, [ 'bootstrap', 'essential' ], true );
		$over    = $tool_count > TokenSurfaceBudgets::ESSENTIAL_MAX_TOOLS;

		if ( 'full' === $surface ) {
			return [
				'id'     => 'tool_budget',
				'status' => 'info',
				'label'  => $label,
				'detail' => sprintf(
					/* translators: %d: number of exposed MCP tools */
					__( 'Full surface selected — %d tools. Compact profiles reduce agent token cost.', 'stonewright' ),
					$tool_count
				),
			];
		}

		if ( $compact && $over ) {
			return [
				'id'     => 'tool_budget',
				'status' => 'warn',
				'label'  => $label,
				'detail' => sprintf(
					/* translators: 1: stored MCP surface, 2: tool count, 3: compact budget */
					__( 'Stored preference is %1$s but %2$d tools are exposed. Compact profiles stay at or under %3$d tools.', 'stonewright' ),
					$surface,
					$tool_count,
					TokenSurfaceBudgets::ESSENTIAL_MAX_TOOLS
				),
			];
		}

		return self::check(
			'tool_budget',
			! $over,
			$label,
			sprintf(
				/* translators: %d: number of exposed MCP tools */
				__( '%d tools exposed in the current profile.', 'stonewright' ),
				$tool_count
			)
		);
	}

	/**
	 * @param array{http?:callable} $args
	 * @return array{id: string, status: string, label: string, detail: string, ticket?: string}
	 */
	private static function bot_filter_check( array $args, string $endpoint ): array {
		$label            = __( 'Bot / WAF user-agent filter', 'stonewright' );
		$uas              = [ 'python-httpx', 'node', 'Go-http-client' ];
		$hits             = [];
		$transport_errors = [];
		$server_errors    = [];
		$reached          = false;

		foreach ( $uas as $ua ) {
			$response = self::http(
				$args,
				'GET',
				$endpoint,
				[
					'timeout'     => 5,
					'redirection' => 0,
					'user-agent'  => $ua,
					'headers'     => [ 'User-Agent' => $ua ],
				]
			);
			if ( is_wp_error( $response ) ) {
				$transport_errors[] = sprintf( '%s: %s', $ua, $response->get_error_message() );
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 403 === $code || 406 === $code ) {
				$hits[] = sprintf( '%s (HTTP %d)', $ua, $code );
				continue;
			}
			if ( $code >= 500 ) {
				$body            = trim( (string) wp_remote_retrieve_body( $response ) );
				$server_errors[] = sprintf(
					'%s (HTTP %d)%s',
					$ua,
					$code,
					'' !== $body ? ': ' . $body : ''
				);
				continue;
			}
			if ( $code > 0 ) {
				$reached = true;
			}
		}

		if ( [] !== $hits ) {
			$site = (string) get_site_url();
			return [
				'id'     => 'bot_filter',
				'status' => 'warn',
				'label'  => $label,
				'detail' => sprintf(
					/* translators: %s: User-Agent and HTTP status list */
					__( 'Hosting bot filter blocked MCP User-Agents: %s.', 'stonewright' ),
					implode( ', ', $hits )
				),
				'ticket' => self::hosting_ticket( $site, $endpoint, $hits ),
			];
		}

		if ( [] !== $transport_errors ) {
			return [
				'id'     => 'bot_filter',
				'status' => 'warn',
				'label'  => $label,
				'detail' => sprintf(
					/* translators: %s: User-Agent and transport error list */
					__( 'Bot-filter probe failed to reach the MCP endpoint: %s.', 'stonewright' ),
					implode( '; ', $transport_errors )
				),
			];
		}

		if ( [] !== $server_errors && ! $reached ) {
			return [
				'id'     => 'bot_filter',
				'status' => 'warn',
				'label'  => $label,
				'detail' => sprintf(
					/* translators: %s: User-Agent and HTTP error list */
					__( 'Bot-filter probe received an error from the MCP endpoint: %s.', 'stonewright' ),
					implode( '; ', $server_errors )
				),
			];
		}

		return [
			'id'     => 'bot_filter',
			'status' => 'ok',
			'label'  => $label,
			'detail' => __( 'python-httpx, node, and Go-http-client reached the MCP endpoint without a 403/406 block.', 'stonewright' ),
		];
	}

	/**
	 * @param array{http?:callable} $args
	 * @return array{id: string, status: string, label: string, detail: string}
	 */
	private static function oauth_registration_check( array $args ): array {
		$label = __( 'OAuth dynamic registration', 'stonewright' );
		$url   = rest_url( 'stonewright/v1/oauth/register' );
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( 'stonewright_oauth_selftest_' . hash( 'sha256', $token ), '1', 30 );

		$response = self::http(
			$args,
			'POST',
			$url,
			[
				'timeout' => 5,
				'headers' => [
					'Content-Type'            => 'application/json',
					'Accept'                  => 'application/json',
					'x-stonewright-self-test' => $token,
				],
				'body'    => '{}',
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'id'     => 'oauth_registration',
				'status' => 'warn',
				'label'  => $label,
				'detail' => $response->get_error_message(),
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $code || 503 === $code ) {
			$body = trim( (string) wp_remote_retrieve_body( $response ) );
			return [
				'id'     => 'oauth_registration',
				'status' => 'warn',
				'label'  => $label,
				'detail' => sprintf(
					/* translators: 1: HTTP status, 2: response body suffix */
					__( 'OAuth registration endpoint responded with HTTP %1$d%2$s', 'stonewright' ),
					$code,
					'' !== $body ? ': ' . $body : '.'
				),
			];
		}

		return [
			'id'     => 'oauth_registration',
			'status' => 'ok',
			'label'  => $label,
			'detail' => sprintf(
				/* translators: %d: HTTP status from the registration endpoint */
				__( 'OAuth registration endpoint responded with HTTP %d.', 'stonewright' ),
				$code
			),
		];
	}

	/**
	 * @param list<string> $hits
	 */
	private static function hosting_ticket( string $site, string $endpoint, array $hits ): string {
		$lines = [
			'Please allow AI HTTP clients to reach the WordPress MCP endpoint on this site.',
			'',
			'Site: ' . $site,
			'MCP endpoint: ' . $endpoint,
			'',
			'Requests that send these User-Agent values currently receive HTTP 403 or 406 (hosting bot filter or WAF):',
		];
		foreach ( $hits as $hit ) {
			$lines[] = '- ' . $hit;
		}
		$lines[] = '';
		$lines[] = 'Please allow User-Agent values python-httpx, node, and Go-http-client (or allow the /wp-json/mcp/ path) so MCP clients can connect.';

		return implode( "\n", $lines );
	}

	/**
	 * @param array{http?:callable} $args
	 * @param array<string, mixed>  $request
	 */
	private static function http( array $args, string $method, string $url, array $request ): array|\WP_Error {
		if ( isset( $args['http'] ) && is_callable( $args['http'] ) ) {
			return $args['http']( $method, $url, $request );
		}

		$method = strtoupper( $method );
		if ( 'GET' === $method ) {
			return wp_remote_get( $url, $request );
		}
		if ( function_exists( 'wp_remote_post' ) ) {
			return wp_remote_post( $url, $request );
		}
		if ( function_exists( 'wp_remote_request' ) ) {
			$request['method'] = $method;
			return wp_remote_request( $url, $request );
		}

		return new \WP_Error( 'http_unavailable', __( 'HTTP POST is unavailable in this environment.', 'stonewright' ) );
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
