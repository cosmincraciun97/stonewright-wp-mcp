<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ConfigurationPage;
use Stonewright\WpMcp\Admin\McpLoopbackSelfTest;
use Stonewright\WpMcp\Admin\RestApi;
use Stonewright\WpMcp\Admin\SetupDiagnostics;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationLifecycle;
use Stonewright\WpMcp\Authorization\WordPress\HttpSurface;
use Stonewright\WpMcp\Core\McpAbilitiesCompatibilityPreflight;
use Stonewright\WpMcp\Core\McpRegistrationState;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * What the setup report says about the effective state of Stonewright: the domain lock, the enabled switch,
 * the checks that depend on them, and the wording of the rows that fail.
 *
 * @covers \Stonewright\WpMcp\Admin\SetupDiagnostics
 * @covers \Stonewright\WpMcp\Admin\Diagnostics\AbilitiesState
 * @covers \Stonewright\WpMcp\Admin\Diagnostics\DiagnosticGraph
 */
final class SetupDiagnosticsStateTest extends TestCase {

	private const ENDPOINT = 'https://example.test/wp-json/mcp/stonewright';

	private HttpRig $http;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_transients']                     = [];
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = '1';
		$GLOBALS['stonewright_test_home_url']                       = 'https://example.test/';
		McpRegistrationState::reset_for_tests();
		$property = new \ReflectionProperty( McpAbilitiesCompatibilityPreflight::class, 'current' );
		$property->setValue( null, [ 'compatible' => true, 'adapter' => [ 'selected_owner' => 'plugin:stonewright', 'selected_version' => '0.6.1', 'selection_state' => 'selected' ], 'blocking_reasons' => [] ] );
		$this->http = new HttpRig();
		HttpSurface::use_site( $this->http->site );
		AuthorizationLifecycle::use_storage( $this->http->storage );
	}

	protected function tearDown(): void {
		HttpSurface::use_site( null );
		AuthorizationLifecycle::use_storage( null );
		McpAbilitiesCompatibilityPreflight::reset_for_tests();
		McpRegistrationState::reset_for_tests();
		StorageRig::reset_globals();
		unset(
			$GLOBALS['stonewright_test_options']['stonewright_enabled'],
			$GLOBALS['stonewright_test_options']['stonewright_locked_domain'],
			$GLOBALS['stonewright_test_home_url'],
			$GLOBALS['stonewright_test_app_passwords_supported'],
			$GLOBALS['stonewright_test_app_passwords_available'],
			$GLOBALS['stonewright_test_app_passwords_available_for_user'],
			$GLOBALS['stonewright_test_environment_type']
		);
		$GLOBALS['stonewright_test_transients'] = [];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, array<string, mixed>> Check id => check.
	 */
	private static function checks( array $args = [] ): array {
		$checks = [];
		foreach ( SetupDiagnostics::report( $args + [ 'endpoint' => self::ENDPOINT ] )['checks'] as $check ) {
			$checks[ (string) $check['id'] ] = $check;
		}
		return $checks;
	}

	private static function lock_to( string $origin ): void {
		$GLOBALS['stonewright_test_options']['stonewright_locked_domain'] = $origin;
	}

	// -------------------------------------------------------------------------
	// Domain lock.
	// -------------------------------------------------------------------------

	public function test_a_domain_lock_mismatch_is_named_with_both_addresses_and_the_remedy(): void {
		self::lock_to( 'https://old-site.example.com/' );

		$check = self::checks()['domain_lock'];

		self::assertSame( 'problem', $check['status'] );
		self::assertStringContainsString( 'https://old-site.example.com/', (string) $check['summary'] );
		self::assertStringContainsString( 'https://example.test/', (string) $check['summary'] );
		self::assertStringContainsString( 'Review and rebind this site', (string) $check['remedy'] );
		self::assertStringContainsString( 'Restore prior domain binding', (string) $check['remedy'] );
		self::assertSame( 'link', $check['action']['type'] );
		self::assertStringContainsString( 'stonewright-domain-lock', (string) $check['action']['target'] );
		self::assertNotSame( $check['summary'], $check['remedy'] );
	}

	public function test_the_abilities_row_never_passes_while_the_domain_lock_blocks_them(): void {
		self::lock_to( 'https://old-site.example.com/' );

		$checks = self::checks();

		self::assertNotContains( $checks['plugin']['status'], [ 'ok', 'info' ] );
		self::assertSame( 'skipped', $checks['plugin']['status'] );
		self::assertStringContainsString( 'Domain lock', (string) $checks['plugin']['summary'] );
		self::assertSame( 'skipped', $checks['tool_surface']['status'] );
		self::assertSame( 'skipped', $checks['mcp_runtime']['status'] );
	}

	public function test_a_matching_lock_passes_and_an_unset_lock_is_not_a_problem(): void {
		self::lock_to( 'https://example.test/' );
		$matching = self::checks();
		self::assertSame( 'ok', $matching['domain_lock']['status'] );
		self::assertSame( 'ok', $matching['plugin']['status'] );

		unset( $GLOBALS['stonewright_test_options']['stonewright_locked_domain'] );
		$unset = self::checks();
		self::assertSame( 'info', $unset['domain_lock']['status'] );
		self::assertSame( 'ok', $unset['plugin']['status'] );
	}

	public function test_a_lock_mismatch_is_one_problem_and_not_a_cascade_of_them(): void {
		self::lock_to( 'https://old-site.example.com/' );

		$report = SetupDiagnostics::report( [ 'endpoint' => self::ENDPOINT, 'method' => 'oauth-http', 'probe' => true, 'loopback' => static fn (): array => [ 'ok' => true, 'steps' => [] ], 'http' => static fn (): array => [ 'response' => [ 'code' => 404 ], 'body' => '', 'headers' => [] ] ] );
		$by_id  = [];
		foreach ( $report['checks'] as $check ) {
			$by_id[ (string) $check['id'] ] = $check['status'];
		}

		self::assertSame( 'problem', $by_id['domain_lock'] );
		self::assertSame( 'skipped', $by_id['oauth_challenge'] );
		self::assertSame( 'skipped', $by_id['oauth_registration'] );
	}

	public function test_the_setup_preflight_names_the_lock_and_does_not_pass_the_abilities(): void {
		self::lock_to( 'https://old-site.example.com/' );

		$data   = RestApi::handle_connection_test( new \WP_REST_Request( 'POST', '/stonewright/v1/connection-test' ) )->get_data();
		$checks = [];
		foreach ( (array) $data['checks'] as $check ) {
			$checks[ (string) $check['id'] ] = $check;
		}

		self::assertFalse( $data['ready'] );
		self::assertSame( 'error', $checks['domain_lock']['status'] );
		self::assertStringContainsString( 'https://old-site.example.com/', (string) $checks['domain_lock']['detail'] );
		self::assertStringContainsString( 'https://example.test/', (string) $checks['domain_lock']['detail'] );
		self::assertStringContainsString( 'rebind', strtolower( (string) $checks['domain_lock']['fix'] ) );
		self::assertSame( 'error', $checks['abilities_enabled']['status'] );
		self::assertStringContainsString( 'domain lock', strtolower( (string) $checks['abilities_enabled']['detail'] ) );
		self::assertSame( 'error', $checks['tool_surface']['status'] );
	}

	public function test_the_setup_preflight_passes_the_lock_row_when_nothing_is_wrong(): void {
		self::lock_to( 'https://example.test/' );

		$data   = RestApi::handle_connection_test( new \WP_REST_Request( 'POST', '/stonewright/v1/connection-test' ) )->get_data();
		$checks = [];
		foreach ( (array) $data['checks'] as $check ) {
			$checks[ (string) $check['id'] ] = $check;
		}

		self::assertSame( 'ok', $checks['domain_lock']['status'] );
		self::assertSame( 'ok', $checks['abilities_enabled']['status'] );
	}

	public function test_verify_connection_advice_names_the_lock_instead_of_telling_the_user_to_enable_abilities(): void {
		self::lock_to( 'https://old-site.example.com/' );
		$result = McpLoopbackSelfTest::run( self::task_start_missing_transport() );

		self::assertFalse( $result['ok'] );
		$fix = '';
		foreach ( $result['steps'] as $step ) {
			if ( 'tools_list' === $step['id'] ) {
				$fix = (string) $step['fix'];
			}
		}
		self::assertStringContainsString( 'domain lock', strtolower( $fix ) );
		self::assertStringContainsString( 'rebind', strtolower( $fix ) );
		self::assertStringNotContainsString( 'Enable Stonewright abilities', $fix );
	}

	public function test_verify_connection_advice_keeps_the_enable_hint_when_nothing_blocks_the_abilities(): void {
		$result = McpLoopbackSelfTest::run( self::task_start_missing_transport() );

		$fix = '';
		foreach ( $result['steps'] as $step ) {
			if ( 'tools_list' === $step['id'] ) {
				$fix = (string) $step['fix'];
			}
		}
		self::assertStringContainsString( 'Enable Stonewright abilities', $fix );
	}

	/** A transport whose tools/list has no task-start tool. */
	private static function task_start_missing_transport(): callable {
		$GLOBALS['stonewright_test_current_user_id']    = 7;
		$GLOBALS['stonewright_test_current_user_login'] = 'admin';
		$GLOBALS['stonewright_test_app_passwords']      = [];
		$replies = [
			[ 'code' => 200, 'body' => [ 'jsonrpc' => '2.0', 'id' => 1, 'result' => [ 'protocolVersion' => '2024-11-05', 'serverInfo' => [ 'name' => 'Stonewright' ] ] ] ],
			[ 'code' => 202, 'body' => [] ],
			[ 'code' => 200, 'body' => [ 'jsonrpc' => '2.0', 'id' => 2, 'result' => [ 'tools' => [ [ 'name' => 'stonewright-ping' ] ] ] ] ],
			[ 'code' => 200, 'body' => [ 'jsonrpc' => '2.0', 'id' => 3, 'result' => [] ] ],
		];
		$index   = 0;

		return static function () use ( &$index, $replies ): array {
			$reply = $replies[ min( $index, count( $replies ) - 1 ) ];
			++$index;

			return [
				'response' => [ 'code' => $reply['code'] ],
				'headers'  => [ 'content-type' => 'application/json', 'mcp-session-id' => 'session-1' ],
				'body'     => (string) wp_json_encode( $reply['body'] ),
			];
		};
	}

	// -------------------------------------------------------------------------
	// Wording of failing rows (B8) and the effective state of the enabled switch.
	// -------------------------------------------------------------------------

	public function test_a_failing_row_never_prints_the_same_sentence_as_cause_and_remedy(): void {
		unset( $GLOBALS['stonewright_test_options']['stonewright_enabled'] );
		$GLOBALS['stonewright_test_environment_type'] = 'local';

		$report = SetupDiagnostics::report( [ 'endpoint' => self::ENDPOINT ] );
		$failed = 0;
		foreach ( $report['checks'] as $check ) {
			if ( 'problem' !== $check['status'] ) {
				continue;
			}
			++$failed;
			self::assertNotSame( (string) $check['summary'], (string) $check['remedy'], (string) $check['id'] );
		}
		self::assertGreaterThan( 0, $failed );
	}

	public function test_the_abilities_row_for_an_operator_switch_has_a_cause_and_a_different_remedy(): void {
		unset( $GLOBALS['stonewright_test_options']['stonewright_enabled'] );

		$check = self::checks()['plugin'];

		self::assertSame( 'problem', $check['status'] );
		self::assertStringContainsString( 'turned off', (string) $check['summary'] );
		self::assertStringContainsString( 'Enable Stonewright', (string) $check['remedy'] );
	}

	public function test_application_passwords_off_on_a_local_site_names_the_filter_or_setting_not_the_environment_type(): void {
		$GLOBALS['stonewright_test_environment_type']          = 'local';
		$GLOBALS['stonewright_test_app_passwords_available']   = false;

		$check = self::checks()['application_passwords'];

		self::assertSame( 'problem', $check['status'] );
		self::assertStringNotContainsString( 'WP_ENVIRONMENT_TYPE', (string) $check['summary'] . (string) $check['remedy'] );
		self::assertStringContainsString( 'wp_is_application_passwords_available', (string) $check['remedy'] );
		self::assertNotSame( $check['summary'], $check['remedy'] );
	}

	public function test_application_passwords_off_on_a_plain_http_production_site_keeps_the_environment_hint(): void {
		$GLOBALS['stonewright_test_environment_type']        = 'production';
		$GLOBALS['stonewright_test_app_passwords_supported'] = false;
		$GLOBALS['stonewright_test_app_passwords_available'] = false;

		$check = self::checks()['application_passwords'];

		self::assertSame( 'problem', $check['status'] );
		self::assertStringContainsString( 'WP_ENVIRONMENT_TYPE', (string) $check['remedy'] );
	}

	public function test_application_passwords_off_for_one_user_says_so(): void {
		$GLOBALS['stonewright_test_app_passwords_available_for_user'] = false;

		$check = self::checks()['application_passwords'];

		self::assertSame( 'problem', $check['status'] );
		self::assertStringContainsString( 'current user', (string) $check['summary'] );
		self::assertStringContainsString( 'wp_is_application_passwords_available_for_user', (string) $check['remedy'] );
	}

	// -------------------------------------------------------------------------
	// Skipped rows read in words (B5).
	// -------------------------------------------------------------------------

	public function test_a_skipped_row_has_a_human_label_and_names_its_prerequisite_in_words(): void {
		unset( $GLOBALS['stonewright_test_options']['stonewright_enabled'] );

		$checks = self::checks();

		foreach ( [ 'mcp_runtime', 'tool_surface', 'tool_budget', 'mcp_server_registration', 'mcp_oauth_registration', 'connection' ] as $id ) {
			self::assertSame( 'skipped', $checks[ $id ]['status'], $id );
			self::assertSame( $checks[ $id ]['label'] === $id, false, $id . ' shows its graph id as a label' );
			self::assertStringNotContainsString( 'Skipped: plugin', (string) $checks[ $id ]['summary'] );
			self::assertDoesNotMatchRegularExpression( '/\b(plugin|mcp_runtime|mcp_server_registration|tool_surface)\b/', (string) $checks[ $id ]['summary'], $id );
		}
		self::assertSame( 'MCP runtime', $checks['mcp_runtime']['label'] );
		self::assertSame( 'Tool surface', $checks['tool_surface']['label'] );
		self::assertStringContainsString( 'Stonewright abilities', (string) $checks['mcp_runtime']['summary'] );
		self::assertStringContainsString( 'MCP runtime', (string) $checks['mcp_server_registration']['summary'] );
	}

	public function test_with_stonewright_off_the_oauth_checks_are_skipped_not_reported_as_problems(): void {
		unset( $GLOBALS['stonewright_test_options']['stonewright_enabled'] );
		$http = static fn (): array => [ 'response' => [ 'code' => 404 ], 'body' => '', 'headers' => [] ];

		$checks = self::checks( [ 'method' => 'oauth-http', 'probe' => true, 'http' => $http, 'loopback' => static fn (): array => [ 'ok' => false, 'steps' => [] ] ] );

		self::assertSame( 'problem', $checks['plugin']['status'] );
		self::assertSame( 'skipped', $checks['oauth_challenge']['status'] );
		self::assertSame( 'skipped', $checks['oauth_registration']['status'] );
		self::assertSame( 'OAuth challenge', $checks['oauth_challenge']['label'] );
		self::assertSame( 'OAuth dynamic registration', $checks['oauth_registration']['label'] );
		self::assertStringContainsString( 'Stonewright abilities', (string) $checks['oauth_challenge']['summary'] );
	}

	public function test_with_stonewright_on_the_oauth_checks_still_run(): void {
		$http = static fn (): array => [ 'response' => [ 'code' => 404 ], 'body' => '', 'headers' => [] ];

		$checks = self::checks( [ 'method' => 'oauth-http', 'probe' => true, 'http' => $http, 'loopback' => static fn (): array => [ 'ok' => true, 'steps' => [] ] ] );

		self::assertSame( 'problem', $checks['oauth_challenge']['status'] );
		self::assertSame( 'problem', $checks['oauth_registration']['status'] );
	}

	// -------------------------------------------------------------------------
	// Registration checks evaluate (B9).
	// -------------------------------------------------------------------------

	public function test_the_registration_checks_boot_the_rest_server_and_read_what_was_recorded(): void {
		$booted = 0;
		$boot   = static function () use ( &$booted ): void {
			++$booted;
			McpRegistrationState::record( 'stonewright', [ 'state' => 'registered' ] );
			McpRegistrationState::record( 'stonewright-oauth', [ 'state' => 'failed', 'error_code' => 'server_rejected', 'message' => 'The OAuth server was rejected.' ] );
		};

		$checks = self::checks( [ 'rest_boot' => $boot, 'rest_routes' => [ '/mcp/stonewright' => [] ] ] );

		self::assertSame( 1, $booted, 'The REST server is booted once for both registration rows.' );
		self::assertSame( 'ok', $checks['mcp_server_registration']['status'] );
		self::assertSame( 'problem', $checks['mcp_oauth_registration']['status'] );
		self::assertSame( 'The OAuth server was rejected.', $checks['mcp_oauth_registration']['summary'] );
	}

	public function test_a_registration_that_cannot_be_checked_here_says_why_and_names_the_covering_checks(): void {
		$checks = self::checks( [ 'rest_boot' => static function (): void {}, 'rest_routes' => [ '/mcp/stonewright' => [], '/mcp/stonewright-oauth' => [] ] ] );

		foreach ( [ 'mcp_server_registration', 'mcp_oauth_registration' ] as $id ) {
			self::assertSame( 'info', $checks[ $id ]['status'], $id );
			self::assertStringContainsString( 'could not be checked', (string) $checks[ $id ]['summary'], $id );
			self::assertStringContainsString( 'MCP route', (string) $checks[ $id ]['summary'], $id );
			self::assertStringContainsString( 'connection probe', (string) $checks[ $id ]['summary'], $id );
			self::assertSame( 'not_checkable', $checks[ $id ]['evidence']['state'], $id );
		}
	}

	public function test_an_unrecorded_registration_after_the_rest_server_booted_is_a_problem(): void {
		$GLOBALS['stonewright_test_did_actions']['rest_api_init'] = 1;
		try {
			$checks = self::checks( [ 'rest_boot' => static function (): void {}, 'rest_routes' => [] ] );
		} finally {
			unset( $GLOBALS['stonewright_test_did_actions']['rest_api_init'] );
		}

		self::assertSame( 'problem', $checks['mcp_server_registration']['status'] );
	}

	// -------------------------------------------------------------------------
	// A finished run is told apart from a run that has not happened (B4).
	// -------------------------------------------------------------------------

	public function test_the_ajax_run_returns_the_support_report_text_without_storing_it(): void {
		$GLOBALS['stonewright_test_user_caps']                      = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = '1';
		$_POST                                                      = [ 'mode' => 'not-sure' ];

		ConfigurationPage::handle_ajax_run_diagnostics();

		$response = $GLOBALS['stonewright_test_json_response'] ?? [];
		self::assertTrue( $response['success'] ?? false );
		$data = (array) $response['data'];
		self::assertIsString( $data['report_text'] ?? null );
		self::assertStringStartsWith( 'Stonewright support report', (string) $data['report_text'] );
		self::assertStringContainsString( '[ok] plugin', (string) $data['report_text'] );
		self::assertStringContainsString( 'Stonewright abilities: Enabled.', (string) $data['report_text'] );
		self::assertArrayNotHasKey( 'report_text', (array) get_option( 'stonewright_diagnostics_last', [] ), 'The stored report keeps the checks only.' );
		$_POST = [];
		unset( $GLOBALS['stonewright_test_json_response'] );
	}

	public function test_the_checks_that_have_not_run_are_marked_and_a_finished_run_has_none(): void {
		$before = self::checks();
		self::assertSame( 'not_run', $before['connection_probe']['evidence']['state'] );
		self::assertSame( 'not_run', $before['waf']['evidence']['state'] );

		$after = self::checks( [ 'method' => 'oauth-http', 'probe' => true, 'http' => static fn (): array => [ 'response' => [ 'code' => 200 ], 'body' => '{}', 'headers' => [] ], 'loopback' => static fn (): array => [ 'ok' => true, 'steps' => [] ] ] );
		foreach ( $after as $id => $check ) {
			self::assertNotSame( 'not_run', $check['evidence']['state'] ?? '', $id );
		}
	}
}
