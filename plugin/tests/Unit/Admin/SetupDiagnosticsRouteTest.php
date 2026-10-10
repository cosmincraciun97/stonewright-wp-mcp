<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\SetupDiagnostics;
use Stonewright\WpMcp\Core\McpAbilitiesCompatibilityPreflight;
use Stonewright\WpMcp\Core\McpRegistrationState;

/**
 * The setup report checks the canonical route /mcp/stonewright and the OAuth route
 * /mcp/stonewright-oauth as separate checks with their own registration checks, and
 * reports the connection as information: the configuration was verified, the
 * connection was not tested.
 *
 * @covers \Stonewright\WpMcp\Admin\SetupDiagnostics
 */
final class SetupDiagnosticsRouteTest extends TestCase {

	private const CANONICAL = 'https://example.test/wp-json/mcp/stonewright';
	private const OAUTH = 'https://example.test/wp-json/mcp/stonewright-oauth';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = '1';
		$GLOBALS['stonewright_test_transients'] = [];
		McpRegistrationState::reset_for_tests();
		self::runtime( true, [] );
	}

	protected function tearDown(): void {
		McpAbilitiesCompatibilityPreflight::reset_for_tests();
		McpRegistrationState::reset_for_tests();
		unset( $GLOBALS['stonewright_test_options']['stonewright_enabled'] );
		$GLOBALS['stonewright_test_transients'] = [];
	}

	/**
	 * The runtime check reads the cached preflight; give it a compatible or blocked one.
	 *
	 * @param list<string> $blocking
	 */
	private static function runtime( bool $compatible, array $blocking ): void {
		$property = new \ReflectionProperty( McpAbilitiesCompatibilityPreflight::class, 'current' );
		$property->setValue(
			null,
			[
				'compatible'       => $compatible,
				'adapter'          => [ 'selected_owner' => 'plugin:stonewright', 'selected_version' => '0.6.1', 'selection_state' => 'selected' ],
				'blocking_reasons' => $blocking,
			]
		);
	}

	private static function register_both(): void {
		McpRegistrationState::record( 'stonewright', [ 'state' => 'registered' ] );
		McpRegistrationState::record( 'stonewright-oauth', [ 'state' => 'registered' ] );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, array<string, mixed>> Check id => check.
	 */
	private static function checks( array $args ): array {
		$checks = [];
		foreach ( SetupDiagnostics::report( $args )['checks'] as $check ) {
			$checks[ (string) $check['id'] ] = $check;
		}
		return $checks;
	}

	public function test_both_routes_are_checked_separately_and_both_present(): void {
		self::register_both();

		$checks = self::checks( [ 'endpoint' => self::CANONICAL, 'rest_routes' => [ '/mcp/stonewright' => [], '/mcp/stonewright-oauth' => [] ] ] );

		self::assertSame( 'ok', $checks['mcp_route']['status'] );
		self::assertSame( 'ok', $checks['mcp_route_oauth']['status'] );
		self::assertSame( '/mcp/stonewright', $checks['mcp_route']['evidence']['route'] );
		self::assertSame( '/mcp/stonewright-oauth', $checks['mcp_route_oauth']['evidence']['route'] );
		self::assertSame( 'Selected target /mcp/stonewright is present in the REST catalog.', $checks['mcp_route']['summary'] );
		self::assertSame( 'REST catalog includes /mcp/stonewright-oauth.', $checks['mcp_route_oauth']['summary'] );
		self::assertSame( 'yes', $checks['mcp_route']['evidence']['selected'] );
		self::assertSame( 'no', $checks['mcp_route_oauth']['evidence']['selected'] );
		self::assertSame( 'ok', $checks['mcp_server_registration']['status'] );
		self::assertSame( 'ok', $checks['mcp_oauth_registration']['status'] );
	}

	public function test_a_missing_oauth_route_does_not_fail_the_canonical_route(): void {
		self::register_both();

		$checks = self::checks( [ 'endpoint' => self::CANONICAL, 'rest_routes' => [ '/mcp/stonewright' => [] ] ] );

		self::assertSame( 'ok', $checks['mcp_route']['status'] );
		self::assertSame( 'problem', $checks['mcp_route_oauth']['status'] );
		self::assertSame( 'REST catalog does not include /mcp/stonewright-oauth.', $checks['mcp_route_oauth']['summary'] );
		self::assertSame( 'no', $checks['mcp_route_oauth']['evidence']['present'] );
	}

	public function test_a_missing_canonical_route_does_not_fail_the_oauth_route(): void {
		self::register_both();

		$checks = self::checks( [ 'endpoint' => self::OAUTH, 'rest_routes' => [ '/mcp/stonewright-oauth' => [] ] ] );

		self::assertSame( 'problem', $checks['mcp_route']['status'] );
		self::assertSame( 'REST catalog does not include /mcp/stonewright.', $checks['mcp_route']['summary'] );
		self::assertSame( 'ok', $checks['mcp_route_oauth']['status'] );
		self::assertSame( 'Selected target /mcp/stonewright-oauth is present in the REST catalog.', $checks['mcp_route_oauth']['summary'] );
		self::assertSame( 'yes', $checks['mcp_route_oauth']['evidence']['selected'] );
	}

	public function test_the_selected_route_is_the_one_the_endpoint_names(): void {
		self::register_both();

		$oauth = self::checks( [ 'endpoint' => self::OAUTH, 'rest_routes' => [] ] );
		self::assertSame( 'Selected target /mcp/stonewright-oauth is missing from the REST catalog.', $oauth['mcp_route_oauth']['summary'] );
		self::assertSame( 'yes', $oauth['mcp_route_oauth']['evidence']['selected'] );
		self::assertSame( 'no', $oauth['mcp_route']['evidence']['selected'] );

		$canonical = self::checks( [ 'endpoint' => self::CANONICAL, 'rest_routes' => [] ] );
		self::assertSame( 'Selected target /mcp/stonewright is missing from the REST catalog.', $canonical['mcp_route']['summary'] );
		self::assertSame( 'yes', $canonical['mcp_route']['evidence']['selected'] );
		self::assertSame( 'no', $canonical['mcp_route_oauth']['evidence']['selected'] );
	}

	public function test_a_failed_oauth_registration_is_its_own_problem_and_skips_only_its_route(): void {
		McpRegistrationState::record( 'stonewright', [ 'state' => 'registered' ] );
		McpRegistrationState::record( 'stonewright-oauth', [ 'state' => 'failed', 'error_code' => 'server_rejected', 'message' => 'The OAuth server was rejected.' ] );

		$checks = self::checks( [ 'endpoint' => self::CANONICAL, 'rest_routes' => [ '/mcp/stonewright' => [] ] ] );

		self::assertSame( 'ok', $checks['mcp_server_registration']['status'] );
		self::assertSame( 'problem', $checks['mcp_oauth_registration']['status'] );
		self::assertSame( 'The OAuth server was rejected.', $checks['mcp_oauth_registration']['summary'] );
		self::assertSame( 'server_rejected', $checks['mcp_oauth_registration']['evidence']['error_code'] );
		self::assertSame( 'stonewright-oauth', $checks['mcp_oauth_registration']['evidence']['server_id'] );
		self::assertSame( 'ok', $checks['mcp_route']['status'] );
		self::assertSame( 'skipped', $checks['mcp_route_oauth']['status'] );
		self::assertSame( [ 'endpoint', 'mcp_oauth_registration' ], $checks['mcp_route_oauth']['depends_on'] );
	}

	public function test_a_failed_canonical_registration_skips_only_the_canonical_route(): void {
		McpRegistrationState::record( 'stonewright', [ 'state' => 'blocked', 'error_code' => 'adapter_blocked' ] );
		McpRegistrationState::record( 'stonewright-oauth', [ 'state' => 'registered' ] );

		$checks = self::checks( [ 'endpoint' => self::CANONICAL, 'rest_routes' => [ '/mcp/stonewright' => [], '/mcp/stonewright-oauth' => [] ] ] );

		self::assertSame( 'problem', $checks['mcp_server_registration']['status'] );
		self::assertSame( 'MCP server stonewright registration failed.', $checks['mcp_server_registration']['summary'] );
		self::assertSame( 'skipped', $checks['mcp_route']['status'] );
		self::assertSame( 'ok', $checks['mcp_oauth_registration']['status'] );
		self::assertSame( 'ok', $checks['mcp_route_oauth']['status'] );
	}

	public function test_without_a_route_catalog_both_routes_are_information(): void {
		self::register_both();

		$checks = self::checks( [ 'endpoint' => self::CANONICAL ] );

		foreach ( [ 'mcp_route', 'mcp_route_oauth' ] as $id ) {
			self::assertSame( 'info', $checks[ $id ]['status'], $id );
			self::assertSame( 'REST routes have not been initialized in this request.', $checks[ $id ]['summary'] );
			self::assertArrayNotHasKey( 'present', $checks[ $id ]['evidence'] );
		}
	}

	public function test_the_connection_is_information_never_a_pass(): void {
		self::register_both();

		$report = SetupDiagnostics::report( [ 'endpoint' => self::CANONICAL, 'rest_routes' => [ '/mcp/stonewright' => [], '/mcp/stonewright-oauth' => [] ] ] );
		$connection = array_values( array_filter( $report['checks'], static fn ( array $check ): bool => 'connection' === $check['id'] ) );

		self::assertCount( 1, $connection );
		self::assertSame( 'info', $connection[0]['status'] );
		self::assertSame( 'Configuration was verified; the connection has not been tested.', $connection[0]['summary'] );
		self::assertSame( 0, $report['counts']['problem'] );
	}

	public function test_a_blocked_runtime_skips_the_connection_instead_of_reporting_it(): void {
		self::runtime( false, [ 'adapter_unavailable' ] );

		$checks = self::checks( [ 'endpoint' => self::CANONICAL, 'rest_routes' => [] ] );

		self::assertSame( 'problem', $checks['mcp_runtime']['status'] );
		self::assertSame( 'skipped', $checks['connection']['status'] );
	}
}
