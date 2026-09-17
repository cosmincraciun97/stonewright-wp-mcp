<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Core\RestRoutes;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * @covers \Stonewright\WpMcp\Core\RestRoutes
 */
final class RestRoutesAuditTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		AuditLog::reset_request_state();
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		AuditLog::reset_request_state();
		IncidentStore::reset_for_tests();
	}

	public function test_http_200_ok_false_with_failed_step_is_not_audit_ok(): void {
		$request  = new \WP_REST_Request( 'POST', '/stonewright/v1/admin/connection-verify' );
		$response = new \WP_REST_Response(
			[
				'ok'    => false,
				'steps' => [
					[
						'id'     => 'initialize',
						'status' => 'failed',
					],
				],
			],
			200
		);

		$envelope = RestRoutes::build_rest_error_envelope( $request, $response );

		self::assertNotSame( 'ok', $envelope['audit_status'] );
		self::assertNotSame( '', $envelope['error_code'] );
		self::assertNotSame( 'unknown_error', $envelope['error_code'] );
	}

	public function test_nested_ok_false_is_not_treated_as_stonewright_envelope_failure(): void {
		$request  = new \WP_REST_Request( 'POST', '/stonewright/v1/abilities/run', [ 'name' => 'stonewright/example-update' ] );
		$response = new \WP_REST_Response(
			[
				'name'   => 'stonewright/example-update',
				'result' => [
					'ok'         => false,
					'error_code' => 'stonewright_widget_not_connected',
				],
				'widget' => [
					'ok'     => false,
					'nested' => [ 'ok' => false ],
				],
			],
			200
		);

		$envelope = RestRoutes::build_rest_error_envelope( $request, $response );

		self::assertSame( 'ok', $envelope['audit_status'] );
		self::assertSame( '', $envelope['error_code'] );
	}

	public function test_official_top_level_failure_shapes_are_detected_without_deep_recursion(): void {
		$request = new \WP_REST_Request( 'POST', '/stonewright/v1/admin/connection-verify' );

		$is_error = RestRoutes::build_rest_error_envelope(
			$request,
			new \WP_REST_Response( [ 'isError' => true, 'error' => [ 'code' => 'stonewright_mcp_initialize_failed' ] ], 200 )
		);
		$startup = RestRoutes::build_rest_error_envelope(
			$request,
			new \WP_REST_Response( [ 'startup_ready' => false, 'error_code' => 'stonewright_mcp_not_ready' ], 200 )
		);
		$receipt = RestRoutes::build_rest_error_envelope(
			$request,
			new \WP_REST_Response(
				[
					'receipt' => [
						'ok'              => false,
						'root_error_code' => 'stonewright_elementor_readback_failed',
					],
				],
				200
			)
		);
		$root = RestRoutes::build_rest_error_envelope(
			$request,
			new \WP_REST_Response( [ 'root_error_code' => 'stonewright_step_initialize_failed' ], 200 )
		);

		foreach ( [ $is_error, $startup, $receipt, $root ] as $envelope ) {
			self::assertNotSame( 'ok', $envelope['audit_status'] );
			self::assertNotSame( '', $envelope['error_code'] );
			self::assertNotSame( 'unknown_error', $envelope['error_code'] );
		}
		self::assertSame( 'stonewright_mcp_initialize_failed', $is_error['error_code'] );
		self::assertSame( 'stonewright_mcp_not_ready', $startup['error_code'] );
		self::assertSame( 'stonewright_elementor_readback_failed', $receipt['error_code'] );
		self::assertSame( 'stonewright_step_initialize_failed', $root['error_code'] );
	}

	public function test_successful_verification_response_is_verify_success_not_write(): void {
		AuditLog::begin_request();
		$request  = new \WP_REST_Request( 'POST', '/stonewright/v1/admin/connection-verify' );
		$response = new \WP_REST_Response( [ 'ok' => true ], 200 );

		RestRoutes::audit_post_dispatch( $response, null, $request );

		self::assertNotEmpty( $GLOBALS['stonewright_test_wpdb_inserts'] );
		$row = $GLOBALS['stonewright_test_wpdb_inserts'][0]['data'];
		self::assertSame( 'ok', $row['result_status'] ?? null );
		self::assertSame( 'VERIFY', $row['category'] ?? null );
		self::assertSame( 'SUCCESS', $row['outcome'] ?? null );
		self::assertNotSame( 'WRITE', $row['category'] ?? null );
	}

	public function test_ability_then_rest_dispatch_records_one_row(): void {
		$kernel = new class() extends AbilityKernel {
			public function name(): string {
				return 'stonewright/example-update';
			}
			public function label(): string {
				return 'Example';
			}
			public function description(): string {
				return 'Dedupe fixture.';
			}
			public function category(): string {
				return 'test';
			}
			public function execute( array $args ): array|\WP_Error {
				return $this->audit( $args, static fn (): array => [ 'ok' => true ] );
			}
		};

		AuditLog::begin_request();
		$kernel->execute( [ 'post_id' => 9 ] );
		RestRoutes::audit_post_dispatch(
			new \WP_REST_Response( [ 'name' => 'stonewright/example-update', 'result' => [ 'ok' => true ] ], 200 ),
			null,
			new \WP_REST_Request( 'POST', '/stonewright/v1/abilities/run', [ 'name' => 'stonewright/example-update' ] )
		);

		self::assertCount( 1, $GLOBALS['stonewright_test_wpdb_inserts'] );
	}

	public function test_finalizer_heartbeat_is_not_a_mutation_audit_event_but_result_is(): void {
		$method = new ReflectionMethod( RestRoutes::class, 'is_stonewright_mutation' );

		$heartbeat = new \WP_REST_Request( 'POST', '/stonewright/v1/block-finalizer/heartbeat' );
		$result = new \WP_REST_Request( 'POST', '/stonewright/v1/block-finalizer/result' );

		self::assertFalse( $method->invoke( null, $heartbeat ) );
		self::assertTrue( $method->invoke( null, $result ) );
	}

	public function test_free_form_rest_bodies_are_hashed_before_auditing(): void {
		$params = [
			'name'       => 'runtime-helper.php',
			'contents'   => '<?php $password = "do-not-log-me";',
			'old_string' => 'token=old-secret',
			'new_string' => 'token=new-secret',
			'nested'     => [
				'text' => 'token=secret-inside-instructions',
				'mode' => 'development',
			],
		];

		$method  = new ReflectionMethod( RestRoutes::class, 'compact_audit_params' );
		$summary = $method->invoke( null, $params );
		$encoded = wp_json_encode( $summary );

		self::assertIsArray( $summary );
		self::assertSame( 'runtime-helper.php', $summary['name'] );
		self::assertSame( 'development', $summary['nested']['mode'] );
		self::assertTrue( $summary['contents']['redacted'] );
		self::assertSame( strlen( $params['contents'] ), $summary['contents']['bytes'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $summary['contents']['sha256'] );
		self::assertStringNotContainsString( 'do-not-log-me', (string) $encoded );
		self::assertStringNotContainsString( 'old-secret', (string) $encoded );
		self::assertStringNotContainsString( 'new-secret', (string) $encoded );
		self::assertStringNotContainsString( 'secret-inside-instructions', (string) $encoded );
	}
}
