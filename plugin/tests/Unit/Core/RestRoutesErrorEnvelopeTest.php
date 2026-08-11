<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\RestRoutes;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ErrorPatterns;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * @covers \Stonewright\WpMcp\Core\RestRoutes::build_rest_error_envelope
 * @covers \Stonewright\WpMcp\Core\RestRoutes::audit_post_dispatch
 */
final class RestRoutesErrorEnvelopeTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		AuditLog::reset_request_state();
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_mode'            => 'development',
			'stonewright_enabled'         => true,
			'stonewright_error_patterns'  => [],
		];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['wpdb'] = $this->make_wpdb();
		delete_option( 'stonewright_error_patterns' );
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		AuditLog::reset_request_state();
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_options'] = [];
		delete_option( 'stonewright_error_patterns' );
	}

	public function test_wp_error_fields_preserved_for_abilities_run(): void {
		AuditLog::begin_request( '11111111-1111-1111-1111-111111111111' );
		$request = new \WP_REST_Request(
			'POST',
			'/stonewright/v1/abilities/run',
			[
				'name'  => 'stonewright/example',
				'input' => [ 'password' => 'secret-value' ],
			]
		);
		$error = new \WP_Error(
			'stonewright_permission_denied',
			'Permission denied for the requested ability.',
			[ 'status' => 403, 'retryable' => false ]
		);

		$envelope = RestRoutes::build_rest_error_envelope( $request, $error );

		self::assertSame( '/stonewright/v1/abilities/run', $envelope['route'] );
		self::assertSame( 'POST', $envelope['method'] );
		self::assertSame( 'stonewright/example', $envelope['target_ability'] );
		self::assertSame( 'blocked', $envelope['audit_status'] );
		self::assertSame( 403, $envelope['http_status'] );
		self::assertSame( 'stonewright_permission_denied', $envelope['error_code'] );
		self::assertStringContainsString( 'Permission denied', $envelope['public_message'] );
		self::assertFalse( $envelope['retryable'] );
		self::assertSame( 'error', $envelope['public']['outcome'] );
		self::assertSame( '11111111-1111-1111-1111-111111111111', $envelope['public']['correlation_id'] );
		self::assertStringNotContainsString( 'secret-value', wp_json_encode( $envelope ) );
	}

	public function test_existing_wp_error_never_becomes_unknown_error(): void {
		$request = new \WP_REST_Request( 'POST', '/stonewright/v1/abilities/run', [ 'name' => 'stonewright/demo' ] );
		$error   = new \WP_Error( 'stonewright_spec_invalid', 'Spec rejected at path /sections', [ 'status' => 400 ] );
		$envelope = RestRoutes::build_rest_error_envelope( $request, $error );
		self::assertSame( 'stonewright_spec_invalid', $envelope['error_code'] );
		self::assertNotSame( 'unknown_error', $envelope['error_code'] );
		self::assertNotSame( 'unknown error', strtolower( $envelope['public_message'] ) );
	}

	public function test_master_toggle_off_and_missing_ability_envelopes(): void {
		$disabled = new \WP_Error( 'stonewright_disabled', 'Master toggle is OFF.', [ 'status' => 403 ] );
		$missing  = new \WP_Error( 'stonewright_ability_not_found', 'Ability not found.', [ 'status' => 404 ] );
		$req      = new \WP_REST_Request( 'POST', '/stonewright/v1/abilities/run', [ 'name' => 'stonewright/x' ] );

		$e1 = RestRoutes::build_rest_error_envelope( $req, $disabled );
		$e2 = RestRoutes::build_rest_error_envelope( $req, $missing );

		self::assertSame( 'stonewright_disabled', $e1['error_code'] );
		self::assertSame( 'blocked', $e1['audit_status'] );
		self::assertSame( 'stonewright_ability_not_found', $e2['error_code'] );
		self::assertSame( 404, $e2['http_status'] );
	}

	public function test_validation_and_provider_failure_codes_preserved(): void {
		$req = new \WP_REST_Request( 'POST', '/stonewright/v1/abilities/run', [ 'name' => 'stonewright/custom-code-provider' ] );
		$validation = new \WP_Error( 'stonewright_php_candidate_invalid', 'Parse failed', [ 'status' => 400 ] );
		$provider   = new \WP_Error( 'stonewright_plugin_missing', 'WPCode inactive', [ 'status' => 503 ] );

		self::assertSame( 'stonewright_php_candidate_invalid', RestRoutes::build_rest_error_envelope( $req, $validation )['error_code'] );
		self::assertSame( 'stonewright_plugin_missing', RestRoutes::build_rest_error_envelope( $req, $provider )['error_code'] );
	}

	public function test_post_dispatch_records_structured_envelope_and_cause_key(): void {
		AuditLog::begin_request();
		$request = new \WP_REST_Request(
			'POST',
			'/stonewright/v1/abilities/run',
			[ 'name' => 'stonewright/example', 'input' => [] ]
		);
		$error = new \WP_Error(
			'stonewright_permission_denied',
			'Permission denied for the requested ability.',
			[ 'status' => 403 ]
		);

		RestRoutes::audit_post_dispatch( $error, null, $request );

		self::assertNotEmpty( $GLOBALS['wpdb']->inserts );
		$row  = $GLOBALS['wpdb']->inserts[0]['data'];
		$args = json_decode( (string) $row['sanitized_args'], true );
		self::assertSame( 'stonewright/example', $row['ability_name'] );
		self::assertSame( 'blocked', $row['result_status'] );
		self::assertIsArray( $args['error_envelope'] ?? null );
		self::assertSame( 'stonewright_permission_denied', $args['error_envelope']['error_code'] );
		self::assertSame( 'stonewright/example', $args['error_envelope']['target_ability'] );
		self::assertStringContainsString( 'stonewright/example', (string) ( $args['_meta']['cause_key'] ?? '' ) );
		self::assertStringContainsString( 'stonewright_permission_denied', (string) ( $args['_meta']['cause_key'] ?? '' ) );
		self::assertStringNotContainsString( 'route|error|', (string) ( $args['_meta']['cause_key'] ?? '' ) );
		// Cause must not be empty route|error|
		self::assertDoesNotMatchRegularExpression( '/\|error\|$/', (string) ( $args['_meta']['cause_key'] ?? '' ) );
	}

	public function test_success_does_not_resolve_unrelated_incident(): void {
		// Seed an open incident for a different ability/resource.
		$failure = \Stonewright\WpMcp\Security\AuditEvent::normalize(
			'stonewright/theme-file-patch',
			[
				'_meta' => [
					'error_code'          => 'stonewright_theme_write_smoke_failed',
					'resource_type'       => 'theme_file',
					'resource_ref'        => 'functions.php',
					'change_set_id'       => 'cs-theme-1',
					'verification_status' => 'failed',
				],
			],
			'error'
		);
		IncidentStore::observe( $failure );
		IncidentStore::observe( $failure );
		self::assertSame( 'open', IncidentStore::recent( 1 )[0]['state'] );

		AuditLog::begin_request();
		$request = new \WP_REST_Request(
			'POST',
			'/stonewright/v1/abilities/run',
			[ 'name' => 'stonewright/ping', 'input' => [] ]
		);
		$ok = new \WP_REST_Response( [ 'name' => 'stonewright/ping', 'result' => [ 'message' => 'pong' ] ], 200 );
		RestRoutes::audit_post_dispatch( $ok, null, $request );

		// Unrelated success must leave the theme-file incident open.
		self::assertSame( 'open', IncidentStore::recent( 1 )[0]['state'] );
		self::assertSame( 'stonewright/theme-file-patch', IncidentStore::recent( 1 )[0]['ability_name'] );
	}

	public function test_credentials_never_enter_envelope(): void {
		$request = new \WP_REST_Request(
			'POST',
			'/stonewright/v1/abilities/run',
			[
				'name'  => 'stonewright/php-execute',
				'input' => [
					'code'     => '<?php echo "token=super-secret";',
					'password' => 'hunter2',
				],
			]
		);
		$error = new \WP_Error(
			'stonewright_php_execute_failed',
			'Failed with Authorization: Bearer abc.def and password=hunter2',
			[ 'status' => 500, 'trace' => "\n#0 /secret/path.php" ]
		);
		$envelope = RestRoutes::build_rest_error_envelope( $request, $error );
		$json     = (string) wp_json_encode( $envelope );
		self::assertStringNotContainsString( 'super-secret', $json );
		self::assertStringNotContainsString( 'hunter2', $json );
		self::assertStringNotContainsString( 'abc.def', $json );
		self::assertStringNotContainsString( '/secret/path.php', $json );
		self::assertStringContainsString( '[redacted]', $envelope['public_message'] );
	}

	public function test_repeated_failure_groups_by_target_ability_cause(): void {
		AuditLog::begin_request();
		$request = new \WP_REST_Request(
			'POST',
			'/stonewright/v1/abilities/run',
			[ 'name' => 'stonewright/demo-ability', 'input' => [] ]
		);
		$error = new \WP_Error( 'stonewright_demo_failure', 'Demo failed', [ 'status' => 500 ] );

		RestRoutes::audit_post_dispatch( $error, null, $request );
		AuditLog::reset_request_state();
		AuditLog::begin_request();
		RestRoutes::audit_post_dispatch( $error, null, $request );

		$recurring = ErrorPatterns::recurring();
		self::assertNotEmpty( $recurring );
		self::assertSame( 'stonewright/demo-ability', $recurring[0]['ability'] );
		self::assertGreaterThanOrEqual( 2, $recurring[0]['count'] );
		self::assertSame( 'repair_proposed', $recurring[0]['state'] );
	}

	/** @return object */
	private function make_wpdb(): object {
		return new class() {
			public string $prefix = 'wp_';
			public int $insert_id = 0;
			/** @var list<array{table:string,data:array<string,mixed>}> */
			public array $inserts = [];

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int {
				++$this->insert_id;
				$this->inserts[] = [ 'table' => $table, 'data' => $data ];
				return 1;
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			public function get_var( string $query ): mixed {
				return null;
			}

			public function get_results( string $query, string $output = ARRAY_A ): array {
				return [];
			}

			public function get_row( string $query, string $output = ARRAY_A ): ?array {
				return null;
			}

			/** @param array<string, mixed> $data @param array<string, mixed> $where */
			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
				return 1;
			}
		};
	}
}
