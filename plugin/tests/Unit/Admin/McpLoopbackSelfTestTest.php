<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\McpLoopbackSelfTest;

/**
 * @covers \Stonewright\WpMcp\Admin\McpLoopbackSelfTest
 */
final class McpLoopbackSelfTestTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_current_user_id']    = 7;
		$GLOBALS['stonewright_test_current_user_login'] = 'admin';
		$GLOBALS['stonewright_test_app_passwords']      = [];
		unset(
			$GLOBALS['stonewright_test_app_password_error'],
			$GLOBALS['stonewright_test_app_password_delete_error'],
			$GLOBALS['stonewright_test_next_app_password']
		);
		$GLOBALS['stonewright_test_next_app_password'] = 'loopback-secret-never-return';
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_current_user_id']    = 0;
		$GLOBALS['stonewright_test_current_user_login'] = 'admin';
		$GLOBALS['stonewright_test_app_passwords']      = [];
		unset(
			$GLOBALS['stonewright_test_app_password_error'],
			$GLOBALS['stonewright_test_app_password_delete_error'],
			$GLOBALS['stonewright_test_next_app_password']
		);
	}

	public function test_happy_path_all_steps_passed(): void {
		$transport = $this->transport_sequence(
			[
				$this->http_json(
					200,
					[
						'jsonrpc' => '2.0',
						'id'      => 1,
						'result'  => [
							'protocolVersion' => '2024-11-05',
							'serverInfo'      => [
								'name'    => 'stonewright',
								'version' => '1.0.0',
							],
						],
					],
					[ 'mcp-session-id' => 'sess-1' ]
				),
				$this->http_json(
					200,
					[
						'jsonrpc' => '2.0',
						'id'      => 2,
						'result'  => [
							'tools' => [
								[ 'name' => 'stonewright-task-start' ],
								[ 'name' => 'stonewright/ping' ],
							],
						],
					]
				),
				$this->http_json(
					200,
					[
						'jsonrpc' => '2.0',
						'id'      => 3,
						'result'  => [
							'content' => [
								[
									'type' => 'text',
									'text' => wp_json_encode(
										[
											'profile' => 'essential',
											'version' => '1.0.0',
										]
									),
								],
							],
						],
					]
				),
			]
		);

		$result = McpLoopbackSelfTest::run( $transport );

		self::assertTrue( $result['ok'] );
		self::assertSame(
			[ 'mint_credential', 'initialize', 'tools_list', 'task_start', 'cleanup' ],
			array_column( $result['steps'], 'id' )
		);
		self::assertSame(
			[ 'passed', 'passed', 'passed', 'passed', 'passed' ],
			array_column( $result['steps'], 'status' )
		);
		self::assertStringContainsString( 'mcp/stonewright', $result['endpoint'] );
		self::assertNotSame( '', $result['plugin_version'] );
		self::assertStringNotContainsString( 'loopback-secret-never-return', wp_json_encode( $result ) );
		self::assertSame( [], $GLOBALS['stonewright_test_app_passwords'][7] ?? [] );
	}

	public function test_tools_list_missing_task_start_fails_with_fix(): void {
		$transport = $this->transport_sequence(
			[
				$this->http_json(
					200,
					[
						'jsonrpc' => '2.0',
						'id'      => 1,
						'result'  => [ 'protocolVersion' => '2024-11-05' ],
					]
				),
				$this->http_json(
					200,
					[
						'jsonrpc' => '2.0',
						'id'      => 2,
						'result'  => [
							'tools' => [
								[ 'name' => 'stonewright-ping' ],
							],
						],
					]
				),
			]
		);

		$result = McpLoopbackSelfTest::run( $transport );

		self::assertFalse( $result['ok'] );
		$tools = $this->step_by_id( $result['steps'], 'tools_list' );
		self::assertSame( 'failed', $tools['status'] );
		self::assertStringContainsString( 'stonewright-task-start', $tools['detail'] );
		self::assertStringContainsString( 'Enable Stonewright abilities', $tools['fix'] );
		self::assertSame( 'failed', $this->step_by_id( $result['steps'], 'task_start' )['status'] );
		self::assertSame( 'passed', $this->step_by_id( $result['steps'], 'cleanup' )['status'] );
		self::assertSame( [], $GLOBALS['stonewright_test_app_passwords'][7] ?? [] );
	}

	public function test_initialize_http_401_fails_with_credential_fix(): void {
		$transport = $this->transport_sequence(
			[
				$this->http_json(
					401,
					[
						'code'    => 'rest_not_logged_in',
						'message' => 'You are not currently logged in.',
					]
				),
			]
		);

		$result = McpLoopbackSelfTest::run( $transport );

		self::assertFalse( $result['ok'] );
		$init = $this->step_by_id( $result['steps'], 'initialize' );
		self::assertSame( 'failed', $init['status'] );
		self::assertStringContainsString( '401', $init['detail'] );
		self::assertStringContainsString( 'Application Password authentication failed', $init['fix'] );
		self::assertTrue( $init['retryable'] );
		self::assertSame( 'passed', $this->step_by_id( $result['steps'], 'cleanup' )['status'] );
		self::assertSame( [], $GLOBALS['stonewright_test_app_passwords'][7] ?? [] );
	}

	public function test_cleanup_always_attempted_even_after_tools_failure(): void {
		$transport = $this->transport_sequence(
			[
				$this->http_json(
					200,
					[
						'jsonrpc' => '2.0',
						'id'      => 1,
						'result'  => [ 'protocolVersion' => '2024-11-05' ],
					]
				),
				[
					'response' => [ 'code' => 500 ],
					'headers'  => [],
					'body'     => 'server error',
				],
			]
		);

		$result = McpLoopbackSelfTest::run( $transport );

		self::assertFalse( $result['ok'] );
		self::assertSame( 'failed', $this->step_by_id( $result['steps'], 'tools_list' )['status'] );
		self::assertSame( 'passed', $this->step_by_id( $result['steps'], 'cleanup' )['status'] );
		self::assertSame( [], $GLOBALS['stonewright_test_app_passwords'][7] ?? [] );
	}

	public function test_slash_tool_name_normalized_to_hyphen(): void {
		$transport = $this->transport_sequence(
			[
				$this->http_json(
					200,
					[
						'jsonrpc' => '2.0',
						'id'      => 1,
						'result'  => [ 'protocolVersion' => '2024-11-05' ],
					]
				),
				$this->http_json(
					200,
					[
						'jsonrpc' => '2.0',
						'id'      => 2,
						'result'  => [
							'tools' => [
								[ 'name' => 'stonewright/task-start' ],
							],
						],
					]
				),
				$this->http_json(
					200,
					[
						'jsonrpc' => '2.0',
						'id'      => 3,
						'result'  => [ 'content' => [] ],
					]
				),
			]
		);

		$result = McpLoopbackSelfTest::run( $transport );

		self::assertTrue( $result['ok'] );
		self::assertSame( 'passed', $this->step_by_id( $result['steps'], 'tools_list' )['status'] );
	}

	/**
	 * @param list<array<string, mixed>> $responses Ordered transport responses.
	 */
	private function transport_sequence( array $responses ): callable {
		$i = 0;
		return static function ( string $method, string $url, array $args ) use ( &$i, $responses ): array|\WP_Error {
			self::assertSame( 'POST', $method );
			self::assertStringContainsString( 'mcp/stonewright', $url );
			self::assertArrayHasKey( 'headers', $args );
			self::assertArrayHasKey( 'Authorization', $args['headers'] );
			self::assertStringStartsWith( 'Basic ', (string) $args['headers']['Authorization'] );
			// Never leak the raw secret into encoded JSON of the whole suite via accidental dumps:
			// Authorization is base64, not plaintext password.
			self::assertStringNotContainsString( 'loopback-secret-never-return', (string) $args['headers']['Authorization'] );

			if ( ! isset( $responses[ $i ] ) ) {
				return new \WP_Error( 'stonewright_test_transport_exhausted', 'No more mock responses.' );
			}
			$response = $responses[ $i ];
			++$i;
			return $response;
		};
	}

	/**
	 * @param array<string, string> $headers
	 * @param array<string, mixed>  $json
	 * @return array{response: array{code: int}, headers: array<string, string>, body: string}
	 */
	private function http_json( int $code, array $json, array $headers = [] ): array {
		return [
			'response' => [ 'code' => $code ],
			'headers'  => $headers,
			'body'     => (string) wp_json_encode( $json ),
		];
	}

	/**
	 * @param list<array{id: string, status: string, detail: string, fix: string, retryable: bool}> $steps
	 * @return array{id: string, status: string, detail: string, fix: string, retryable: bool}
	 */
	private function step_by_id( array $steps, string $id ): array {
		foreach ( $steps as $step ) {
			if ( $step['id'] === $id ) {
				return $step;
			}
		}
		self::fail( 'Step not found: ' . $id );
	}
}
