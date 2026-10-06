<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Runtime\PhpExecute;
use Stonewright\WpMcp\Admin\AuditLogPage;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * Successful rows are not errors: no root error code, no repair hint.
 *
 * @covers \Stonewright\WpMcp\Security\AuditEvent
 * @covers \Stonewright\WpMcp\Admin\AuditLogPage
 */
final class SuccessfulAuditRowTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_user_caps']       = [
			'read'           => true,
			'manage_options' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 17;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_mode'                 => 'development',
			'stonewright_essential_tools_mode' => true,
			'stonewright_disabled_abilities'   => [],
		];
		$_GET = [];
		AuditLog::reset_request_state();
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}
		$GLOBALS['stonewright_test_user_caps']    = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_options']      = [];
		$GLOBALS['stonewright_test_transients']   = [];
		$_GET = [];
		AuditLog::reset_request_state();
		IncidentStore::reset_for_tests();
	}

	public function test_successful_php_execute_row_has_no_root_error_code_or_repair_hint(): void {
		$result = ( new PhpExecute() )->execute( [ 'code' => 'return 21 * 2;' ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		$row = $this->audit_row( 'stonewright/php-execute' );
		self::assertSame( 'ok', $row['result_status'] );
		self::assertSame( 'SUCCESS', $row['outcome'] );
		self::assertSame( '', $row['root_error_code'] );
		self::assertSame( '', $row['remediation_code'] );
		self::assertSame( '', $row['error_code'] );
		$details = json_decode( (string) $row['redacted_details'], true );
		self::assertIsArray( $details );
		self::assertArrayNotHasKey( 'root_error_code', $details );
		self::assertArrayNotHasKey( 'remediation_code', $details );
		self::assertArrayNotHasKey( 'error_code', $details );

		$html = $this->render_rows( [ array_merge( $row, [ 'id' => '41' ] ) ] );
		self::assertStringNotContainsString( 'Fix the snippet', $html );
		self::assertStringNotContainsString( '>Repair<', $html );
	}

	public function test_successful_row_with_a_payload_named_code_never_becomes_an_error_code(): void {
		AuditLog::record(
			'stonewright/example-custom-code',
			[
				'code'  => '[redacted, length=14, sha256=0a1b2c3d]',
				'_meta' => [ 'execution_status' => 'ok' ],
			],
			'ok'
		);

		$row = $this->audit_row( 'stonewright/example-custom-code' );
		self::assertSame( 'SUCCESS', $row['outcome'] );
		self::assertSame( '', $row['root_error_code'] );
		self::assertStringNotContainsString( 'redactedlength', (string) $row['redacted_details'] );
	}

	public function test_failed_row_keeps_its_root_error_code_and_repair_hint(): void {
		$result = ( new PhpExecute() )->execute( [ 'code' => 'throw new \RuntimeException( "synthetic failure" );' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		$row = $this->audit_row( 'stonewright/php-execute' );
		self::assertSame( 'error', $row['result_status'] );
		self::assertNotSame( '', $row['root_error_code'] );
		self::assertStringStartsWith( 'stonewright_', (string) $row['root_error_code'] );
		self::assertStringNotContainsString( 'redacted', (string) $row['root_error_code'] );
	}

	public function test_legacy_successful_row_with_a_stored_code_shows_no_repair_hint(): void {
		$html = $this->render_rows(
			[
				[
					'id'               => '40',
					'ability_name'     => 'stonewright/php-execute',
					'user_id'          => '17',
					'result_status'    => 'ok',
					'category'         => 'RUNTIME',
					'outcome'          => 'SUCCESS',
					'root_error_code'  => 'stonewright_redactedlength14sha2560a1b2c3d',
					'redacted_details' => wp_json_encode( [ 'root_error_code' => 'stonewright_redactedlength14sha2560a1b2c3d' ] ),
					'sanitized_args'   => '{}',
					'created_at'       => '2026-09-01 10:00:00',
				],
			]
		);

		self::assertStringNotContainsString( 'Fix the snippet', $html );
		self::assertStringNotContainsString( '>Repair<', $html );
	}

	public function test_legacy_successful_row_with_a_stored_incident_id_shows_no_incident_link(): void {
		$incident_id = hash( 'sha256', 'synthetic-incident' );

		$html = $this->render_rows( [ $this->stored_row( 'ok', 'SUCCESS', $incident_id ) ] );

		self::assertStringContainsString( 'stonewright/example-content-update', $html );
		self::assertStringNotContainsString( 'Incident:', $html );
		self::assertStringNotContainsString( substr( $incident_id, 0, 12 ), $html );
	}

	public function test_failed_row_keeps_its_incident_link(): void {
		$incident_id = hash( 'sha256', 'synthetic-incident' );

		$html = $this->render_rows( [ $this->stored_row( 'error', 'FAILED', $incident_id ) ] );

		self::assertStringContainsString( 'Incident:', $html );
		self::assertStringContainsString( 'incident_id=' . $incident_id, $html );
	}

	/** @return array<string, mixed> */
	private function stored_row( string $status, string $outcome, string $incident_id ): array {
		return [
			'id'             => '42',
			'ability_name'   => 'stonewright/example-content-update',
			'user_id'        => '17',
			'result_status'  => $status,
			'category'       => 'WRITE',
			'outcome'        => $outcome,
			'incident_id'    => $incident_id,
			'sanitized_args' => '{}',
			'created_at'     => '2026-09-01 10:00:00',
		];
	}

	/** @return array<string, mixed> */
	private function audit_row( string $ability ): array {
		foreach ( array_reverse( $GLOBALS['stonewright_test_wpdb_inserts'] ) as $insert ) {
			if ( str_contains( (string) $insert['table'], 'stonewright_audit_log' ) && $ability === ( $insert['data']['ability_name'] ?? '' ) ) {
				return $insert['data'];
			}
		}
		self::fail( 'No audit row recorded for ' . $ability );
	}

	/** @param list<array<string, mixed>> $rows */
	private function render_rows( array $rows ): string {
		$GLOBALS['wpdb'] = new class( $rows ) {
			public $prefix = 'wp_';

			/** @param list<array<string, mixed>> $rows */
			public function __construct( private array $rows ) {
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			public function get_var( string $query = '' ): int {
				return count( $this->rows );
			}

			/** @return list<array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return str_contains( $query, 'stonewright_audit_log' ) && ! str_contains( $query, 'GROUP BY' ) ? $this->rows : [];
			}
		};

		ob_start();
		AuditLogPage::render();
		return (string) ob_get_clean();
	}
}
