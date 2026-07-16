<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

/**
 * Authenticated MCP loopback verifier for the Setup connection center.
 *
 * Mints a short-lived Application Password, exercises initialize → tools/list
 * → stonewright-task-start over HTTP, then always revokes the credential.
 * This proves live MCP auth works — unlike the local preflight checklist.
 */
final class McpLoopbackSelfTest {

	private const PROTOCOL_VERSION = '2024-11-05';
	private const CLIENT_NAME      = 'stonewright-loopback';
	private const CLIENT_VERSION   = '1.0.0';
	private const TASK_START_NAME  = 'stonewright-task-start';

	/**
	 * Run the loopback connection self-test.
	 *
	 * @param callable|null $transport Optional HTTP transport for tests.
	 *                                 Signature: function( string $method, string $url, array $args ): array|WP_Error
	 *                                 matching wp_remote_request return shape.
	 * @return array{
	 *   ok: bool,
	 *   steps: list<array{id: string, status: string, detail: string, fix: string, retryable: bool}>,
	 *   endpoint: string,
	 *   plugin_version: string
	 * }
	 */
	public static function run( ?callable $transport = null ): array {
		$endpoint = ConnectClientConfig::mcp_endpoint_url();
		$version  = defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : '0.0.0';
		$transport ??= static function ( string $method, string $url, array $args ): array|\WP_Error {
			$args['method'] = $method;
			return wp_remote_request( $url, $args );
		};

		$steps = [];
		$ok    = true;

		$credential = self::mint_credential();
		$steps[]    = $credential['step'];
		if ( 'passed' !== $credential['step']['status'] ) {
			$ok = false;
			$steps[] = self::skipped_step( 'initialize', __( 'Not run because credential mint failed.', 'stonewright' ) );
			$steps[] = self::skipped_step( 'tools_list', __( 'Not run because credential mint failed.', 'stonewright' ) );
			$steps[] = self::skipped_step( 'task_start', __( 'Not run because credential mint failed.', 'stonewright' ) );
			$steps[] = self::cleanup_credential( $credential['user_id'], $credential['uuid'] );
			if ( 'passed' !== $steps[ count( $steps ) - 1 ]['status'] ) {
				$ok = false;
			}

			return self::envelope( $ok, $steps, $endpoint, $version );
		}

		$session_id = '';
		$auth       = self::basic_auth_header( $credential['username'], $credential['password'] );

		// Drop plaintext as soon as the Authorization header is built.
		unset( $credential['password'] );

		$init = self::step_initialize( $transport, $endpoint, $auth );
		$steps[] = $init['step'];
		if ( 'passed' !== $init['step']['status'] ) {
			$ok = false;
			$steps[] = self::skipped_step( 'tools_list', __( 'Not run because initialize failed.', 'stonewright' ) );
			$steps[] = self::skipped_step( 'task_start', __( 'Not run because initialize failed.', 'stonewright' ) );
			$steps[] = self::cleanup_credential( $credential['user_id'], $credential['uuid'] );
			if ( 'passed' !== $steps[ count( $steps ) - 1 ]['status'] ) {
				$ok = false;
			}

			return self::envelope( $ok, $steps, $endpoint, $version );
		}
		$session_id = $init['session_id'];

		$tools = self::step_tools_list( $transport, $endpoint, $auth, $session_id );
		$steps[] = $tools['step'];
		if ( 'passed' !== $tools['step']['status'] ) {
			$ok = false;
			$steps[] = self::skipped_step( 'task_start', __( 'Not run because tools/list failed.', 'stonewright' ) );
			$steps[] = self::cleanup_credential( $credential['user_id'], $credential['uuid'] );
			if ( 'passed' !== $steps[ count( $steps ) - 1 ]['status'] ) {
				$ok = false;
			}

			return self::envelope( $ok, $steps, $endpoint, $version );
		}
		if ( '' !== $tools['session_id'] ) {
			$session_id = $tools['session_id'];
		}

		$task = self::step_task_start( $transport, $endpoint, $auth, $session_id );
		$steps[] = $task['step'];
		if ( 'passed' !== $task['step']['status'] ) {
			$ok = false;
		}

		$cleanup = self::cleanup_credential( $credential['user_id'], $credential['uuid'] );
		$steps[] = $cleanup;
		if ( 'passed' !== $cleanup['status'] ) {
			$ok = false;
		}

		return self::envelope( $ok, $steps, $endpoint, $version );
	}

	/**
	 * @param list<array{id: string, status: string, detail: string, fix: string, retryable: bool}> $steps
	 * @return array{ok: bool, steps: list<array{id: string, status: string, detail: string, fix: string, retryable: bool}>, endpoint: string, plugin_version: string}
	 */
	private static function envelope( bool $ok, array $steps, string $endpoint, string $version ): array {
		return [
			'ok'             => $ok,
			'steps'          => $steps,
			'endpoint'       => $endpoint,
			'plugin_version' => $version,
		];
	}

	/**
	 * @return array{step: array{id: string, status: string, detail: string, fix: string, retryable: bool}, user_id: int, uuid: string, username: string, password: string}
	 */
	private static function mint_credential(): array {
		$user     = wp_get_current_user();
		$user_id  = (int) $user->ID;
		$username = (string) $user->user_login;

		if ( $user_id <= 0 || '' === $username ) {
			return self::mint_result(
				self::step(
					'mint_credential',
					'failed',
					__( 'No authenticated WordPress user is available.', 'stonewright' ),
					__( 'Log in as an administrator and retry.', 'stonewright' ),
					true
				)
			);
		}

		if ( ! self::application_passwords_available( $user ) ) {
			return self::mint_result(
				self::step(
					'mint_credential',
					'failed',
					__( 'Application Passwords are not available for this user or site.', 'stonewright' ),
					__( 'Enable HTTPS (or Application Passwords) for this WordPress install, then retry.', 'stonewright' ),
					true
				),
				$user_id,
				'',
				$username
			);
		}

		if ( ! method_exists( '\WP_Application_Passwords', 'create_new_application_password' ) ) {
			return self::mint_result(
				self::step(
					'mint_credential',
					'failed',
					__( 'Application Passwords API is missing create support.', 'stonewright' ),
					__( 'Upgrade WordPress to a version that supports Application Passwords, then retry.', 'stonewright' ),
					false
				),
				$user_id,
				'',
				$username
			);
		}

		$name    = 'stonewright-connection-test-' . str_replace( '.', '', uniqid( '', true ) );
		$created = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			[ 'name' => $name ]
		);

		if ( is_wp_error( $created ) ) {
			return self::mint_result(
				self::step(
					'mint_credential',
					'failed',
					$created->get_error_message(),
					__( 'Resolve the Application Password error above, then retry Verify connection.', 'stonewright' ),
					true
				),
				$user_id,
				'',
				$username
			);
		}

		$password = is_array( $created ) ? (string) ( $created[0] ?? '' ) : '';
		$item     = is_array( $created ) && isset( $created[1] ) && is_array( $created[1] ) ? $created[1] : [];
		$uuid     = (string) ( $item['uuid'] ?? '' );

		if ( '' === $password || '' === $uuid ) {
			return self::mint_result(
				self::step(
					'mint_credential',
					'failed',
					__( 'WordPress did not return a usable Application Password.', 'stonewright' ),
					__( 'Generate an Application Password from your profile and retry.', 'stonewright' ),
					true
				),
				$user_id,
				'',
				$username
			);
		}

		return self::mint_result(
			self::step(
				'mint_credential',
				'passed',
				sprintf(
					/* translators: %s: application password name. */
					__( 'Created short-lived credential %s.', 'stonewright' ),
					$name
				),
				'',
				false
			),
			$user_id,
			$uuid,
			$username,
			$password
		);
	}

	/**
	 * @param array{id: string, status: string, detail: string, fix: string, retryable: bool} $step Step payload.
	 * @return array{step: array{id: string, status: string, detail: string, fix: string, retryable: bool}, user_id: int, uuid: string, username: string, password: string}
	 */
	private static function mint_result(
		array $step,
		int $user_id = 0,
		string $uuid = '',
		string $username = '',
		string $password = ''
	): array {
		return [
			'step'     => $step,
			'user_id'  => $user_id,
			'uuid'     => $uuid,
			'username' => $username,
			'password' => $password,
		];
	}

	/**
	 * @param callable $transport Transport callable.
	 * @return array{step: array{id: string, status: string, detail: string, fix: string, retryable: bool}, session_id: string}
	 */
	private static function step_initialize( callable $transport, string $endpoint, string $auth ): array {
		$payload = [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => [
				'protocolVersion' => self::PROTOCOL_VERSION,
				'capabilities'    => new \stdClass(),
				'clientInfo'      => [
					'name'    => self::CLIENT_NAME,
					'version' => self::CLIENT_VERSION,
				],
			],
		];

		$response = self::mcp_request( $transport, $endpoint, $auth, '', $payload );
		if ( is_wp_error( $response ) ) {
			return [
				'step'       => self::step(
					'initialize',
					'failed',
					$response->get_error_message(),
					__( 'Confirm the MCP endpoint is reachable from this server and retry.', 'stonewright' ),
					true
				),
				'session_id' => '',
			];
		}

		$code = (int) $response['code'];
		if ( 401 === $code || 403 === $code ) {
			return [
				'step'       => self::step(
					'initialize',
					'failed',
					sprintf(
						/* translators: %d: HTTP status code. */
						__( 'MCP initialize rejected authentication (HTTP %d).', 'stonewright' ),
						$code
					),
					__( 'Application Password authentication failed. Ensure HTTPS/Application Passwords work for this user, then retry Verify connection.', 'stonewright' ),
					true
				),
				'session_id' => '',
			];
		}

		if ( $code < 200 || $code >= 300 ) {
			return [
				'step'       => self::step(
					'initialize',
					'failed',
					sprintf(
						/* translators: %d: HTTP status code. */
						__( 'MCP initialize returned HTTP %d.', 'stonewright' ),
						$code
					),
					__( 'Confirm stonewright is registered on the MCP endpoint and the REST API is healthy.', 'stonewright' ),
					true
				),
				'session_id' => '',
			];
		}

		$body = $response['json'];
		if ( isset( $body['error'] ) && is_array( $body['error'] ) ) {
			$message = (string) ( $body['error']['message'] ?? __( 'JSON-RPC error.', 'stonewright' ) );
			return [
				'step'       => self::step(
					'initialize',
					'failed',
					$message,
					__( 'Inspect MCP server logs and confirm the WordPress MCP adapter is active.', 'stonewright' ),
					true
				),
				'session_id' => '',
			];
		}

		$result = is_array( $body['result'] ?? null ) ? $body['result'] : [];
		$has_server_info = isset( $result['serverInfo'] ) && is_array( $result['serverInfo'] );
		$has_protocol    = isset( $result['protocolVersion'] ) && '' !== (string) $result['protocolVersion'];
		if ( ! $has_server_info && ! $has_protocol ) {
			return [
				'step'       => self::step(
					'initialize',
					'failed',
					__( 'Initialize response lacked serverInfo and protocolVersion.', 'stonewright' ),
					__( 'Confirm the MCP endpoint implements the Streamable HTTP initialize handshake.', 'stonewright' ),
					true
				),
				'session_id' => '',
			];
		}

		$detail_parts = [];
		if ( $has_protocol ) {
			$detail_parts[] = 'protocolVersion=' . (string) $result['protocolVersion'];
		}
		if ( $has_server_info ) {
			$server = $result['serverInfo'];
			$name   = (string) ( $server['name'] ?? '' );
			$ver    = (string) ( $server['version'] ?? '' );
			if ( '' !== $name ) {
				$detail_parts[] = 'server=' . $name . ( '' !== $ver ? ( '/' . $ver ) : '' );
			}
		}

		return [
			'step'       => self::step(
				'initialize',
				'passed',
				implode( '; ', $detail_parts ) ?: __( 'Initialize succeeded.', 'stonewright' ),
				'',
				false
			),
			'session_id' => $response['session_id'],
		];
	}

	/**
	 * @param callable $transport Transport callable.
	 * @return array{step: array{id: string, status: string, detail: string, fix: string, retryable: bool}, session_id: string}
	 */
	private static function step_tools_list( callable $transport, string $endpoint, string $auth, string $session_id ): array {
		$payload = [
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/list',
			'params'  => new \stdClass(),
		];

		$response = self::mcp_request( $transport, $endpoint, $auth, $session_id, $payload );
		if ( is_wp_error( $response ) ) {
			return [
				'step'       => self::step(
					'tools_list',
					'failed',
					$response->get_error_message(),
					__( 'Confirm the MCP endpoint is reachable and retry.', 'stonewright' ),
					true
				),
				'session_id' => '',
			];
		}

		$code = (int) $response['code'];
		if ( $code < 200 || $code >= 300 ) {
			return [
				'step'       => self::step(
					'tools_list',
					'failed',
					sprintf(
						/* translators: %d: HTTP status code. */
						__( 'tools/list returned HTTP %d.', 'stonewright' ),
						$code
					),
					__( 'Confirm authenticated MCP sessions remain valid after initialize.', 'stonewright' ),
					true
				),
				'session_id' => $response['session_id'],
			];
		}

		$body = $response['json'];
		if ( isset( $body['error'] ) && is_array( $body['error'] ) ) {
			$message = (string) ( $body['error']['message'] ?? __( 'JSON-RPC error.', 'stonewright' ) );
			return [
				'step'       => self::step(
					'tools_list',
					'failed',
					$message,
					__( 'Inspect MCP adapter logs for tools/list failures.', 'stonewright' ),
					true
				),
				'session_id' => $response['session_id'],
			];
		}

		$result = is_array( $body['result'] ?? null ) ? $body['result'] : [];
		$tools  = is_array( $result['tools'] ?? null ) ? $result['tools'] : [];
		$names  = [];
		foreach ( $tools as $tool ) {
			if ( ! is_array( $tool ) ) {
				continue;
			}
			$raw = (string) ( $tool['name'] ?? '' );
			if ( '' === $raw ) {
				continue;
			}
			$names[] = self::normalize_tool_name( $raw );
		}

		if ( ! in_array( self::TASK_START_NAME, $names, true ) ) {
			return [
				'step'       => self::step(
					'tools_list',
					'failed',
					sprintf(
						/* translators: %s: required tool name. */
						__( 'tools/list did not include %s.', 'stonewright' ),
						self::TASK_START_NAME
					),
					__( 'Enable Stonewright abilities and ensure the active tool profile exposes stonewright-task-start, then reload the AI client and retry.', 'stonewright' ),
					true
				),
				'session_id' => $response['session_id'],
			];
		}

		return [
			'step'       => self::step(
				'tools_list',
				'passed',
				sprintf(
					/* translators: 1: tool count, 2: tool name. */
					__( 'Found %1$d tools including %2$s.', 'stonewright' ),
					count( $names ),
					self::TASK_START_NAME
				),
				'',
				false
			),
			'session_id' => $response['session_id'],
		];
	}

	/**
	 * @param callable $transport Transport callable.
	 * @return array{step: array{id: string, status: string, detail: string, fix: string, retryable: bool}}
	 */
	private static function step_task_start( callable $transport, string $endpoint, string $auth, string $session_id ): array {
		$payload = [
			'jsonrpc' => '2.0',
			'id'      => 3,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => self::TASK_START_NAME,
				'arguments' => [
					'task'    => 'setup connection verification',
					'surface' => 'system',
					'intent'  => 'inspect',
				],
			],
		];

		$response = self::mcp_request( $transport, $endpoint, $auth, $session_id, $payload );
		if ( is_wp_error( $response ) ) {
			return [
				'step' => self::step(
					'task_start',
					'failed',
					$response->get_error_message(),
					__( 'Confirm the MCP endpoint accepts tools/call and retry.', 'stonewright' ),
					true
				),
			];
		}

		$code = (int) $response['code'];
		if ( $code < 200 || $code >= 300 ) {
			return [
				'step' => self::step(
					'task_start',
					'failed',
					sprintf(
						/* translators: %d: HTTP status code. */
						__( 'tools/call for stonewright-task-start returned HTTP %d.', 'stonewright' ),
						$code
					),
					__( 'Confirm stonewright-task-start is registered and callable for this user.', 'stonewright' ),
					true
				),
			];
		}

		$body = $response['json'];
		if ( isset( $body['error'] ) && is_array( $body['error'] ) ) {
			$message = (string) ( $body['error']['message'] ?? __( 'JSON-RPC error.', 'stonewright' ) );
			return [
				'step' => self::step(
					'task_start',
					'failed',
					$message,
					__( 'Inspect ability permissions and MCP adapter logs for stonewright-task-start.', 'stonewright' ),
					true
				),
			];
		}

		$result = is_array( $body['result'] ?? null ) ? $body['result'] : [];
		if ( ! empty( $result['isError'] ) ) {
			return [
				'step' => self::step(
					'task_start',
					'failed',
					__( 'stonewright-task-start reported a tool error.', 'stonewright' ),
					__( 'Open Audit / ability logs for task-start failures, fix the underlying issue, then retry.', 'stonewright' ),
					true
				),
			];
		}

		$detail = __( 'stonewright-task-start completed without a hard error.', 'stonewright' );
		$blob   = wp_json_encode( $result );
		if ( is_string( $blob ) ) {
			$hints = [];
			if ( false !== stripos( $blob, 'profile' ) ) {
				$hints[] = 'profile';
			}
			if ( false !== stripos( $blob, 'version' ) ) {
				$hints[] = 'version';
			}
			if ( [] !== $hints ) {
				$detail = sprintf(
					/* translators: %s: comma-separated field hints found in the result. */
					__( 'stonewright-task-start succeeded (result includes %s).', 'stonewright' ),
					implode( ', ', $hints )
				);
			}
		}

		return [
			'step' => self::step(
				'task_start',
				'passed',
				$detail,
				'',
				false
			),
		];
	}

	/**
	 * @return array{id: string, status: string, detail: string, fix: string, retryable: bool}
	 */
	private static function cleanup_credential( int $user_id, string $uuid ): array {
		if ( $user_id <= 0 || '' === $uuid ) {
			return self::step(
				'cleanup',
				'passed',
				__( 'No short-lived credential to revoke.', 'stonewright' ),
				'',
				false
			);
		}

		if ( ! method_exists( '\WP_Application_Passwords', 'delete_application_password' ) ) {
			return self::step(
				'cleanup',
				'failed',
				__( 'Could not revoke the test Application Password (API missing).', 'stonewright' ),
				__( 'Manually revoke any stonewright-connection-test-* Application Password from your profile.', 'stonewright' ),
				true
			);
		}

		$deleted = \WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		if ( is_wp_error( $deleted ) ) {
			return self::step(
				'cleanup',
				'failed',
				$deleted->get_error_message(),
				__( 'Manually revoke the stonewright-connection-test Application Password from your profile.', 'stonewright' ),
				true
			);
		}

		if ( false === $deleted ) {
			return self::step(
				'cleanup',
				'failed',
				__( 'WordPress reported the test Application Password was not deleted.', 'stonewright' ),
				__( 'Manually revoke the stonewright-connection-test Application Password from your profile.', 'stonewright' ),
				true
			);
		}

		return self::step(
			'cleanup',
			'passed',
			__( 'Revoked the short-lived test Application Password.', 'stonewright' ),
			'',
			false
		);
	}

	/**
	 * @param callable             $transport Transport callable.
	 * @param array<string, mixed> $payload   JSON-RPC payload.
	 * @return array{code: int, json: array<string, mixed>, session_id: string, raw: string}|\WP_Error
	 */
	private static function mcp_request(
		callable $transport,
		string $endpoint,
		string $auth,
		string $session_id,
		array $payload
	): array|\WP_Error {
		$headers = [
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json, text/event-stream',
			'Authorization' => $auth,
		];
		if ( '' !== $session_id ) {
			$headers['Mcp-Session-Id'] = $session_id;
		}

		$body = wp_json_encode( $payload );
		if ( ! is_string( $body ) ) {
			return new \WP_Error(
				'stonewright_loopback_encode_failed',
				__( 'Failed to encode MCP JSON-RPC payload.', 'stonewright' )
			);
		}

		$args = [
			'timeout' => 20,
			'headers' => $headers,
			'body'    => $body,
		];

		$response = $transport( 'POST', $endpoint, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! is_array( $response ) ) {
			return new \WP_Error(
				'stonewright_loopback_bad_transport',
				__( 'MCP transport returned an unexpected response.', 'stonewright' )
			);
		}

		$code       = (int) wp_remote_retrieve_response_code( $response );
		$raw        = (string) wp_remote_retrieve_body( $response );
		$new_sess   = self::header_value( $response, 'mcp-session-id' );
		$parsed     = self::parse_json_rpc_body( $raw );

		return [
			'code'       => $code,
			'json'       => $parsed,
			'session_id' => '' !== $new_sess ? $new_sess : $session_id,
			'raw'        => $raw,
		];
	}

	/**
	 * @param array<string, mixed> $response wp_remote response.
	 */
	private static function header_value( array $response, string $header ): string {
		$value = wp_remote_retrieve_header( $response, $header );
		if ( is_array( $value ) ) {
			$value = (string) ( $value[0] ?? '' );
		}
		return trim( (string) $value );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function parse_json_rpc_body( string $raw ): array {
		$trimmed = trim( $raw );
		if ( '' === $trimmed ) {
			return [];
		}

		// Streamable HTTP may emit SSE frames; extract the first JSON object/data payload.
		if ( str_starts_with( $trimmed, 'event:' ) || str_contains( $trimmed, "\ndata:" ) ) {
			foreach ( preg_split( '/\r\n|\n|\r/', $trimmed ) ?: [] as $line ) {
				$line = trim( $line );
				if ( ! str_starts_with( $line, 'data:' ) ) {
					continue;
				}
				$json = trim( substr( $line, 5 ) );
				$decoded = json_decode( $json, true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		$decoded = json_decode( $trimmed, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	private static function basic_auth_header( string $username, string $password ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Basic auth encoding.
		return 'Basic ' . base64_encode( $username . ':' . $password );
	}

	private static function normalize_tool_name( string $name ): string {
		return str_replace( '/', '-', $name );
	}

	/**
	 * @return array{id: string, status: string, detail: string, fix: string, retryable: bool}
	 */
	private static function step( string $id, string $status, string $detail, string $fix, bool $retryable ): array {
		return [
			'id'        => $id,
			'status'    => $status,
			'detail'    => $detail,
			'fix'       => $fix,
			'retryable' => $retryable,
		];
	}

	/**
	 * @return array{id: string, status: string, detail: string, fix: string, retryable: bool}
	 */
	private static function skipped_step( string $id, string $detail ): array {
		return self::step(
			$id,
			'failed',
			$detail,
			__( 'Fix the earlier failed step, then retry Verify connection.', 'stonewright' ),
			true
		);
	}

	private static function application_passwords_available( object $user ): bool {
		if ( ! class_exists( '\WP_Application_Passwords' ) ) {
			return false;
		}

		if ( function_exists( 'wp_is_application_passwords_available' ) && ! wp_is_application_passwords_available() ) {
			return false;
		}

		return ! function_exists( 'wp_is_application_passwords_available_for_user' )
			|| (bool) wp_is_application_passwords_available_for_user( $user );
	}
}
