<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Diagnostics\DiagnosticCheck;
use Stonewright\WpMcp\Admin\Diagnostics\DiagnosticGraph;
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

	public const METHODS = [ 'oauth-http', 'application-password-stdio', 'stdio', 'not-sure' ];

	/**
	 * @param array{probe?:bool,loopback?:callable,mode?:string,method?:string,http?:callable} $args
	 * @return array{
	 *   ready: bool,
	 *   method: string,
	 *   counts: array{problem: int, warning: int, info: int, ok: int, skipped: int},
	 *   checks: list<array<string, mixed>>,
	 *   versions: array<string, string|int>,
	 *   mode: string
	 * }
	 */
	public static function report( array $args = [] ): array {
		$method = self::resolve_method( $args );
		$enabled       = (bool) get_option( 'stonewright_enabled', false );
		$https         = is_ssl() || str_starts_with( (string) get_site_url(), 'https://' );
		$app_passwords = self::application_passwords_available();
		$endpoint      = ConnectClientConfig::mcp_endpoint_url();
		$tool_count    = count( AbilityRegistry::enabled_abilities() );
		$surface       = AbilityRegistry::mcp_surface();
		$oauth_allowed = OAuthTransport::allowed();
		$oauth_endpoint = OAuthBootstrap::resource_identifier();
		$oauth_discovery = Discovery::protected_resource_metadata_url();
		$stdio         = in_array( $method, [ 'application-password-stdio', 'stdio' ], true );
		$probe         = (bool) ( $args['probe'] ?? false ) && ! $stdio;
		$loopback_cb   = $args['loopback'] ?? null;
		$scope         = $method;

		$transport_summary = $https
			? __( 'HTTPS active.', 'stonewright' )
			: __( 'Running over HTTP. Fine for local and LAN sites; HTTPS is recommended when connecting from outside your network.', 'stonewright' );

		$app_password_summary = $app_passwords
			? __( 'Available for the current user.', 'stonewright' )
			: ( $https
				? __( 'Unavailable; check the user profile or Application Passwords settings.', 'stonewright' )
				: __( 'Unavailable on this HTTP site. For local setups add define( \'WP_ENVIRONMENT_TYPE\', \'local\' ); to wp-config.php so Application Passwords work without HTTPS.', 'stonewright' ) );

		$surface_ok    = in_array( $surface, [ 'bootstrap', 'essential', 'full' ], true );
		$surface_view  = AbilityRegistry::surface_session_view();
		$surface_summary = $surface_view['widened']
			? sprintf(
				/* translators: 1: configured MCP surface, 2: configured tool count, 3: live session profile, 4: session tool count */
				__( 'Configured: %1$s (%2$d) · Active session: %3$s (%4$d)', 'stonewright' ),
				$surface_view['configured'],
				$surface_view['configured_count'],
				(string) $surface_view['session_profile'],
				(int) $surface_view['session_count']
			)
			: sprintf(
				/* translators: 1: MCP surface, 2: tool count */
				__( 'Profile %1$s with %2$d tools exposed.', 'stonewright' ),
				$surface,
				$tool_count
			);

		$graph = new DiagnosticGraph();
		$graph->add(
			'plugin',
			[],
			static fn() => self::pass_or_problem(
				'plugin',
				$enabled,
				__( 'Stonewright abilities', 'stonewright' ),
				$enabled ? __( 'Enabled.', 'stonewright' ) : __( 'Enable Stonewright in step 1.', 'stonewright' ),
				$scope
			)
		);
		$graph->add(
			'endpoint',
			[],
			static fn() => self::pass_or_problem(
				'endpoint',
				'' !== $endpoint,
				__( 'MCP endpoint', 'stonewright' ),
				$endpoint,
				$scope
			)
		);
		$graph->add(
			'connection',
			[ 'plugin', 'endpoint' ],
			static fn() => DiagnosticCheck::ok(
				'connection',
				__( 'Connection', 'stonewright' ),
				__( 'Abilities are enabled and an MCP endpoint is configured.', 'stonewright' ),
				[],
				$scope
			)
		);
		$graph->add(
			'transport',
			[],
			static fn() => $https
				? DiagnosticCheck::ok( 'transport', __( 'Connection transport', 'stonewright' ), $transport_summary, [], $scope )
				: DiagnosticCheck::info( 'transport', __( 'Connection transport', 'stonewright' ), $transport_summary, [], $scope )
		);
		$graph->add(
			'application_passwords',
			[],
			static fn() => self::pass_or_problem(
				'application_passwords',
				$app_passwords,
				__( 'Application Passwords', 'stonewright' ),
				$app_password_summary,
				$scope
			)
		);
		$graph->add(
			'tool_surface',
			[ 'plugin' ],
			static fn() => self::pass_or_problem(
				'tool_surface',
				$surface_ok,
				__( 'Tool surface', 'stonewright' ),
				$surface_summary,
				$scope
			)
		);
		$graph->add(
			'tool_budget',
			[ 'plugin' ],
			static fn() => self::compact_tool_surface_check( $surface, $tool_count, $scope )
		);
		$graph->add(
			'oauth_transport',
			[],
			static fn() => self::pass_or_problem(
				'oauth_transport',
				$oauth_allowed,
				__( 'OAuth transport', 'stonewright' ),
				$oauth_allowed
					? __( 'HTTPS or an explicit local WordPress environment is active.', 'stonewright' )
					: __( 'OAuth is disabled on public plain HTTP sites.', 'stonewright' ),
				$scope
			)
		);
		$graph->add(
			'oauth_endpoint',
			[ 'oauth_transport' ],
			static fn() => self::pass_or_problem(
				'oauth_endpoint',
				'' !== $oauth_endpoint,
				__( 'OAuth MCP endpoint', 'stonewright' ),
				$oauth_endpoint,
				$scope
			)
		);
		$graph->add(
			'oauth_discovery',
			[ 'oauth_endpoint' ],
			static fn() => self::pass_or_problem(
				'oauth_discovery',
				'' !== $oauth_discovery,
				__( 'OAuth discovery', 'stonewright' ),
				$oauth_discovery,
				$scope
			)
		);

		$probe_holder = [ 'result' => null ];
		if ( $probe ) {
			$graph->add(
				'connection_probe',
				[ 'endpoint' ],
				static function () use ( &$probe_holder, $loopback_cb, $scope ) {
					$probe_result = is_callable( $loopback_cb ) ? $loopback_cb() : McpLoopbackSelfTest::run();
					$probe_result = is_array( $probe_result ) ? $probe_result : [];
					$probe_holder['result'] = $probe_result;
					$probe_ok = true === ( $probe_result['ok'] ?? false );
					$summary  = $probe_ok
						? __( 'Live MCP loopback passed (initialize, tools/list, task-start).', 'stonewright' )
						: self::probe_failure_detail( $probe_result );
					return self::pass_or_problem( 'connection_probe', $probe_ok, __( 'MCP connection probe', 'stonewright' ), $summary, $scope );
				}
			);
			$graph->add(
				'waf',
				[ 'endpoint' ],
				static function () use ( &$probe_holder, $scope ) {
					$probe_result = is_array( $probe_holder['result'] ?? null ) ? $probe_holder['result'] : [];
					$waf_hit      = self::waf_blocked( $probe_result );
					$summary      = $waf_hit
						? __( 'The MCP endpoint returned HTTP 403 or 406, which often means a firewall or WAF blocked the loopback.', 'stonewright' )
						: __( 'No 403/406 block observed on the MCP loopback.', 'stonewright' );
					return self::pass_or_problem( 'waf', ! $waf_hit, __( 'WAF-ish blocks', 'stonewright' ), $summary, $scope );
				}
			);
			$graph->add(
				'bot_filter',
				[ 'endpoint' ],
				static fn() => self::bot_filter_check( $args, $endpoint, $scope )
			);
			$graph->add(
				'oauth_registration',
				[ 'oauth_discovery' ],
				static fn() => self::oauth_registration_check( $args, $scope )
			);
		} elseif ( $stdio ) {
			$graph->add( 'connection_probe', [], static fn() => DiagnosticCheck::info( 'connection_probe', __( 'MCP connection probe', 'stonewright' ), __( 'HTTP loopback skipped for local companion (stdio).', 'stonewright' ), [], $scope ) );
			$graph->add( 'waf', [], static fn() => DiagnosticCheck::info( 'waf', __( 'WAF-ish blocks', 'stonewright' ), __( 'Not checked for stdio; WAF-ish blocks apply to remote HTTP.', 'stonewright' ), [], $scope ) );
			$graph->add( 'bot_filter', [], static fn() => DiagnosticCheck::info( 'bot_filter', __( 'Bot / WAF user-agent filter', 'stonewright' ), __( 'Not checked for stdio; User-Agent probes apply to remote HTTP.', 'stonewright' ), [], $scope ) );
			$graph->add( 'oauth_registration', [], static fn() => DiagnosticCheck::info( 'oauth_registration', __( 'OAuth dynamic registration', 'stonewright' ), __( 'Not checked for stdio; OAuth registration applies to remote HTTP.', 'stonewright' ), [], $scope ) );
			$companion_url = trim( (string) get_option( 'stonewright_companion_url', '' ) );
			$graph->add(
				'companion_url',
				[],
				static function () use ( $companion_url, $scope ) {
					if ( '' !== $companion_url ) {
						return DiagnosticCheck::ok( 'companion_url', __( 'Local companion URL', 'stonewright' ), $companion_url, [], $scope );
					}
					return DiagnosticCheck::warning(
						'companion_url',
						__( 'Local companion URL', 'stonewright' ),
						__( 'No companion URL is configured.', 'stonewright' ),
						__( 'Set the local companion URL, then run diagnostics again.', 'stonewright' ),
						[],
						$scope
					);
				}
			);
		} else {
			$pending = __( 'Not run yet — click Run diagnostics', 'stonewright' );
			$graph->add( 'connection_probe', [], static fn() => DiagnosticCheck::info( 'connection_probe', __( 'MCP connection probe', 'stonewright' ), $pending, [], $scope ) );
			$graph->add( 'waf', [], static fn() => DiagnosticCheck::info( 'waf', __( 'WAF-ish blocks', 'stonewright' ), $pending, [], $scope ) );
			$graph->add( 'bot_filter', [], static fn() => DiagnosticCheck::info( 'bot_filter', __( 'Bot / WAF user-agent filter', 'stonewright' ), $pending, [], $scope ) );
			$graph->add( 'oauth_registration', [], static fn() => DiagnosticCheck::info( 'oauth_registration', __( 'OAuth dynamic registration', 'stonewright' ), $pending, [], $scope ) );
		}

		$result = $graph->run();

		return self::with_compat(
			[
				'ready'    => 0 === (int) $result['counts']['problem'],
				'method'   => $method,
				'counts'   => $result['counts'],
				'checks'   => $result['checks'],
				'versions' => [
					'plugin'             => defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : 'unknown',
					'companion_contract' => CompanionContract::EXPECTED_CONTRACT_VERSION,
					'wordpress'          => isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : 'unknown',
					'php'                => PHP_VERSION,
					'tool_count'         => $tool_count,
				],
			]
		);
	}

	/**
	 * Map legacy mode/method arguments onto the canonical method id.
	 *
	 * @param array{mode?:string,method?:string} $args
	 */
	public static function resolve_method( array $args ): string {
		$method = isset( $args['method'] ) ? sanitize_key( (string) $args['method'] ) : '';
		if ( in_array( $method, self::METHODS, true ) ) {
			return $method;
		}
		$mode = isset( $args['mode'] ) ? sanitize_key( (string) $args['mode'] ) : 'both';
		return match ( $mode ) {
			'http'  => 'oauth-http',
			'stdio' => 'application-password-stdio',
			default => 'not-sure',
		};
	}

	/**
	 * Compatibility adapter for existing AJAX consumers until Task 9 updates the UI.
	 *
	 * @param array{ready: bool, method: string, counts: array<string, int>, checks: list<array<string, mixed>>, versions: array<string, string|int>} $envelope
	 * @return array{ready: bool, method: string, counts: array<string, int>, checks: list<array<string, mixed>>, versions: array<string, string|int>, mode: string}
	 */
	private static function with_compat( array $envelope ): array {
		$checks = [];
		foreach ( $envelope['checks'] as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}
			if ( ! isset( $check['detail'] ) ) {
				$check['detail'] = (string) ( $check['summary'] ?? '' );
			}
			$copy = (string) ( $check['copy'] ?? '' );
			if ( '' !== $copy && ! isset( $check['ticket'] ) ) {
				$check['ticket'] = $copy;
			}
			$checks[] = $check;
		}
		$envelope['checks'] = $checks;
		$envelope['mode']   = self::legacy_mode( $envelope['method'] );
		return $envelope;
	}

	public static function legacy_mode( string $method ): string {
		return match ( $method ) {
			'oauth-http' => 'http',
			'application-password-stdio', 'stdio' => 'stdio',
			default => 'both',
		};
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

	private static function compact_tool_surface_check( string $surface, int $tool_count, string $scope ): DiagnosticCheck {
		$label   = __( 'Compact tool surface', 'stonewright' );
		$compact = in_array( $surface, [ 'bootstrap', 'essential' ], true );
		$over    = $tool_count > TokenSurfaceBudgets::ESSENTIAL_MAX_TOOLS;

		if ( 'full' === $surface ) {
			return DiagnosticCheck::info(
				'tool_budget',
				$label,
				sprintf(
					/* translators: %d: number of exposed MCP tools */
					__( 'Full surface selected — %d tools. Compact profiles reduce agent token cost.', 'stonewright' ),
					$tool_count
				),
				[],
				$scope
			);
		}

		if ( $compact && $over ) {
			return DiagnosticCheck::warning(
				'tool_budget',
				$label,
				sprintf(
					/* translators: 1: stored MCP surface, 2: tool count, 3: compact budget */
					__( 'Stored preference is %1$s but %2$d tools are exposed. Compact profiles stay at or under %3$d tools.', 'stonewright' ),
					$surface,
					$tool_count,
					TokenSurfaceBudgets::ESSENTIAL_MAX_TOOLS
				),
				__( 'Switch to a compact MCP surface or remove extra tools.', 'stonewright' ),
				[],
				$scope
			);
		}

		return self::pass_or_problem(
			'tool_budget',
			! $over,
			$label,
			sprintf(
				/* translators: %d: number of exposed MCP tools */
				__( '%d tools exposed in the current profile.', 'stonewright' ),
				$tool_count
			),
			$scope
		);
	}

	/**
	 * @param array{http?:callable} $args
	 */
	private static function bot_filter_check( array $args, string $endpoint, string $scope ): DiagnosticCheck {
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
			$summary = sprintf(
				/* translators: %s: User-Agent and HTTP status list */
				__( 'Hosting bot filter blocked MCP User-Agents: %s.', 'stonewright' ),
				implode( ', ', $hits )
			);
			return DiagnosticCheck::warning(
				'bot_filter',
				$label,
				$summary,
				__( 'Ask hosting to allow python-httpx, node, and Go-http-client, or the MCP path.', 'stonewright' ),
				[],
				$scope
			)->with_copy( self::hosting_ticket( $site, $endpoint, $hits ) );
		}

		if ( [] !== $transport_errors ) {
			return DiagnosticCheck::warning(
				'bot_filter',
				$label,
				sprintf(
					/* translators: %s: User-Agent and transport error list */
					__( 'Bot-filter probe failed to reach the MCP endpoint: %s.', 'stonewright' ),
					implode( '; ', $transport_errors )
				),
				__( 'Check DNS, TLS, and whether a login wall is blocking loopback.', 'stonewright' ),
				[],
				$scope
			);
		}

		if ( [] !== $server_errors && ! $reached ) {
			return DiagnosticCheck::warning(
				'bot_filter',
				$label,
				sprintf(
					/* translators: %s: User-Agent and HTTP error list */
					__( 'Bot-filter probe received an error from the MCP endpoint: %s.', 'stonewright' ),
					implode( '; ', $server_errors )
				),
				__( 'Inspect the MCP endpoint HTTP status, then run diagnostics again.', 'stonewright' ),
				[],
				$scope
			);
		}

		return DiagnosticCheck::ok(
			'bot_filter',
			$label,
			__( 'python-httpx, node, and Go-http-client reached the MCP endpoint without a 403/406 block.', 'stonewright' ),
			[],
			$scope
		);
	}

	/**
	 * @param array{http?:callable} $args
	 */
	private static function oauth_registration_check( array $args, string $scope ): DiagnosticCheck {
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
			return DiagnosticCheck::warning(
				'oauth_registration',
				$label,
				$response->get_error_message(),
				__( 'Confirm the site URL and TLS, then run diagnostics again.', 'stonewright' ),
				[],
				$scope
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $code || 503 === $code ) {
			$body = trim( (string) wp_remote_retrieve_body( $response ) );
			$summary = sprintf(
				/* translators: 1: HTTP status, 2: response body suffix */
				__( 'OAuth registration endpoint responded with HTTP %1$d%2$s', 'stonewright' ),
				$code,
				'' !== $body ? ': ' . $body : '.'
			);
			return DiagnosticCheck::warning(
				'oauth_registration',
				$label,
				$summary,
				__( 'Wait for the rate limit to expire, then run diagnostics again.', 'stonewright' ),
				[ 'http_status' => $code ],
				$scope
			);
		}

		return DiagnosticCheck::ok(
			'oauth_registration',
			$label,
			sprintf(
				/* translators: %d: HTTP status from the registration endpoint */
				__( 'OAuth registration endpoint responded with HTTP %d.', 'stonewright' ),
				$code
			),
			[ 'http_status' => $code ],
			$scope
		);
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

	private static function pass_or_problem( string $id, bool $passes, string $label, string $summary, string $scope ): DiagnosticCheck {
		if ( $passes ) {
			return DiagnosticCheck::ok( $id, $label, $summary, [], $scope );
		}

		return DiagnosticCheck::problem( $id, $label, $summary, $summary, $scope );
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
