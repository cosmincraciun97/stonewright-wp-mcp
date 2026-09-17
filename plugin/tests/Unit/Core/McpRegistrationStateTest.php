<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\McpRegistrationState;
use Stonewright\WpMcp\Core\ServerRegistration;

/**
 * @covers \Stonewright\WpMcp\Core\McpRegistrationState
 */
final class McpRegistrationStateTest extends TestCase {

	protected function tearDown(): void {
		McpRegistrationState::reset_for_tests();
		unset( $GLOBALS['stonewright_test_did_actions']['rest_api_init'] );
	}

	public function test_report_is_not_checked_before_rest_api_init(): void {
		$report = McpRegistrationState::report();

		self::assertFalse( $report['rest_registry_ready'] );
		self::assertSame( 'not_checked', $report['servers'][0]['state'] );
		self::assertSame( ServerRegistration::SERVER_ID, $report['servers'][0]['server_id'] );
		self::assertSame( 'not_checked', $report['servers'][1]['state'] );
		self::assertSame( ServerRegistration::OAUTH_SERVER_ID, $report['servers'][1]['server_id'] );
	}

	public function test_report_is_failed_when_rest_initialized_without_record(): void {
		$GLOBALS['stonewright_test_did_actions']['rest_api_init'] = 1;

		$report = McpRegistrationState::report();

		self::assertTrue( $report['rest_registry_ready'] );
		self::assertSame( 'failed', $report['servers'][0]['state'] );
		self::assertSame( 'server_not_recorded', $report['servers'][0]['error_code'] );
	}

	public function test_record_persists_sanitized_result_for_request(): void {
		McpRegistrationState::record(
			'stonewright',
			[
				'state'      => 'failed',
				'error_code' => 'invalid_transport',
				'message'    => 'The selected MCP transport contract is incompatible.',
				'owner'      => 'plugin:provider-a',
				'version'    => '0.6.1',
			]
		);

		$report = McpRegistrationState::report();
		$by_id = array_column( $report['servers'], null, 'server_id' );

		self::assertSame( 'failed', $by_id['stonewright']['state'] );
		self::assertSame( 'invalid_transport', $by_id['stonewright']['error_code'] );
		self::assertSame( 'The selected MCP transport contract is incompatible.', $by_id['stonewright']['message'] );
		self::assertSame( 'plugin:provider-a', $by_id['stonewright']['owner'] );
		self::assertSame( '0.6.1', $by_id['stonewright']['version'] );
		self::assertSame( 'not_checked', $by_id['stonewright-oauth']['state'] );
	}

	public function test_unknown_state_is_recorded_as_failed(): void {
		McpRegistrationState::record( 'stonewright', [ 'state' => 'exploded' ] );

		$report = McpRegistrationState::report();
		self::assertSame( 'failed', $report['servers'][0]['state'] );
	}

	public function test_reset_for_tests_clears_request_state(): void {
		McpRegistrationState::record( 'stonewright', [ 'state' => 'registered' ] );
		McpRegistrationState::reset_for_tests();

		self::assertSame( 'not_checked', McpRegistrationState::report()['servers'][0]['state'] );
	}
}
