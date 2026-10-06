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
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		AuditLog::reset_request_state();
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
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

	public function test_finalizer_claim_polling_is_not_audited_but_its_denials_are(): void {
		$method = new ReflectionMethod( RestRoutes::class, 'is_stonewright_mutation' );
		$claim  = new \WP_REST_Request( 'POST', '/stonewright/v1/block-finalizer/claim', [ 'lease_id' => 'synthetic-lease' ] );

		self::assertFalse( $method->invoke( null, $claim ) );
		self::assertTrue( $method->invoke( null, new \WP_REST_Request( 'POST', '/stonewright/v1/block-finalizer/cancel' ) ) );

		// A routine poll, empty or carrying work, leaves no row.
		RestRoutes::audit_pre_dispatch( null, null, $claim );
		RestRoutes::audit_post_dispatch( new \WP_REST_Response( [ 'items' => [], 'counts' => [ 'queued' => 0, 'failed' => 0 ], 'retryable' => true ], 200 ), null, $claim );
		RestRoutes::audit_post_dispatch( new \WP_REST_Response( [ 'items' => [ [ 'id' => 'change-1' ] ], 'retryable' => true ], 200 ), null, $claim );
		self::assertSame( [], $GLOBALS['stonewright_test_wpdb_inserts'] );

		// A refused poll is a security event and stays visible, without the request parameters.
		AuditLog::reset_request_state();
		RestRoutes::audit_post_dispatch( new \WP_Error( 'stonewright_queue_forbidden', 'Queue access is unavailable.', [ 'status' => 403 ] ), null, $claim );
		self::assertCount( 1, $GLOBALS['stonewright_test_wpdb_inserts'] );
		$row = $GLOBALS['stonewright_test_wpdb_inserts'][0]['data'];
		self::assertSame( 'blocked', $row['result_status'] );
		self::assertSame( 'stonewright_queue_forbidden', $row['error_code'] );
		self::assertStringNotContainsString( 'synthetic-lease', (string) $row['sanitized_args'] );
		self::assertStringContainsString( 'block-finalizer/claim', (string) $row['sanitized_args'] );
	}

	public function test_long_html_parameter_is_summarized_in_the_audit_row_even_for_an_anonymous_denial(): void {
		$html    = '<p>' . str_repeat( 'x', 4000 ) . '</p>';
		$request = new \WP_REST_Request(
			'POST',
			'/stonewright/v1/block-finalizer/result',
			[
				'change_id' => 'change-1',
				'html'      => $html,
				'other'     => str_repeat( 'y', 700 ),
			]
		);
		$denied  = new \WP_Error( 'stonewright_queue_forbidden', 'Queue access is unavailable.', [ 'status' => 403 ] );

		AuditLog::begin_request();
		RestRoutes::audit_post_dispatch( $denied, null, $request );

		self::assertCount( 1, $GLOBALS['stonewright_test_wpdb_inserts'] );
		$stored = (string) $GLOBALS['stonewright_test_wpdb_inserts'][0]['data']['sanitized_args'];
		self::assertLessThan( 3000, strlen( $stored ), 'The audit row must not carry the request payload.' );
		self::assertStringNotContainsString( 'xxxxxxxxxx', $stored );
		self::assertStringNotContainsString( 'yyyyyyyyyy', $stored );
		$params = json_decode( $stored, true )['params'] ?? [];
		self::assertSame( 'change-1', $params['change_id'] );
		self::assertTrue( $params['html']['redacted'] );
		self::assertSame( strlen( $html ), $params['html']['bytes'] );
		self::assertSame( hash( 'sha256', $html ), $params['html']['sha256'] );
		self::assertSame( 700, $params['other']['bytes'] );
	}

	public function test_resource_reference_taken_from_a_parameter_is_bounded_in_the_audit_row(): void {
		foreach ( [ 'id', 'post_id', 'name', 'slug', 'ability' ] as $key ) {
			// Identical denials are coalesced, so each round starts without the previous one's record.
			$GLOBALS['stonewright_test_wpdb_inserts'] = [];
			$GLOBALS['stonewright_test_transients']   = [];
			AuditLog::reset_request_state();
			$request = new \WP_REST_Request( 'POST', '/stonewright/v1/block-finalizer/result', [ $key => str_repeat( 'r', 5000 ) ] );

			RestRoutes::audit_post_dispatch( new \WP_Error( 'stonewright_queue_forbidden', 'Queue access is unavailable.', [ 'status' => 403 ] ), null, $request );

			self::assertCount( 1, $GLOBALS['stonewright_test_wpdb_inserts'], $key );
			$stored = (string) $GLOBALS['stonewright_test_wpdb_inserts'][0]['data']['sanitized_args'];
			self::assertLessThan( 3000, strlen( $stored ), $key . ' must not be copied whole into the audit row.' );
			self::assertStringContainsString( $key . '=', $stored );
		}
	}

	public function test_audit_summary_bounds_parameter_names_too(): void {
		$method  = new ReflectionMethod( RestRoutes::class, 'compact_audit_params' );
		$first   = str_repeat( 'k', 5000 ) . 'one';
		$second  = str_repeat( 'k', 5000 ) . 'two';
		$summary = $method->invoke(
			null,
			[
				$first   => 'a',
				$second  => [ str_repeat( 'n', 400 ) . 'x' => 'b' ],
				'normal' => 'c',
			]
		);

		self::assertCount( 3, $summary, 'Two different long names must stay two different entries.' );
		self::assertSame( 'c', $summary['normal'] );
		$encoded = (string) wp_json_encode( $summary );
		self::assertLessThan( 600, strlen( $encoded ) );
		foreach ( array_keys( $summary ) as $name ) {
			self::assertLessThanOrEqual( 96, strlen( (string) $name ) );
		}
	}

	public function test_audit_summary_bounds_every_long_string_at_any_depth(): void {
		$method  = new ReflectionMethod( RestRoutes::class, 'compact_audit_params' );
		$exactly = str_repeat( 'a', 512 );
		$over    = str_repeat( 'b', 513 );
		$summary = $method->invoke(
			null,
			[
				'html'   => '<p>short</p>',
				'HTML'   => '<p>case</p>',
				'at_cap' => $exactly,
				'over'   => $over,
				'list'   => [ 'ok', $over ],
				'nested' => [ 'deep' => [ 'deeper' => [ 'blob' => $over, 'fine' => 'short', 'number' => 7, 'flag' => true ] ] ],
			]
		);

		self::assertTrue( $summary['html']['redacted'] );
		self::assertSame( strlen( '<p>short</p>' ), $summary['html']['bytes'] );
		self::assertTrue( $summary['HTML']['redacted'] );
		self::assertSame( $exactly, $summary['at_cap'] );
		self::assertSame( [ 'redacted' => true, 'sha256' => hash( 'sha256', $over ), 'bytes' => 513 ], $summary['over'] );
		self::assertSame( 'ok', $summary['list'][0] );
		self::assertSame( 513, $summary['list'][1]['bytes'] );
		self::assertSame( 513, $summary['nested']['deep']['deeper']['blob']['bytes'] );
		self::assertSame( 'short', $summary['nested']['deep']['deeper']['fine'] );
		self::assertSame( 7, $summary['nested']['deep']['deeper']['number'] );
		self::assertTrue( $summary['nested']['deep']['deeper']['flag'] );
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
