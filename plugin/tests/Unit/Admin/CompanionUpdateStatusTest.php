<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\CompanionUpdateStatus;
use Stonewright\WpMcp\Core\GitHubUpdater;

/**
 * @covers \Stonewright\WpMcp\Admin\CompanionUpdateStatus
 */
final class CompanionUpdateStatusTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_filters']    = [];
		$GLOBALS['stonewright_test_transients'] = [
			GitHubUpdater::cache_key( 'beta' ) => [
				'schema_version' => GitHubUpdater::CACHE_SCHEMA_VERSION,
				'channel'        => 'beta',
				'release'        => [
					'version'           => '1.0.0-beta.99',
					'package'           => 'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-beta.99/stonewright-1.0.0-beta.99.zip',
					'companion_package' => 'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-beta.99/stonewright-companion-1.0.0-beta.99.tgz',
					'checksums'         => 'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-beta.99/SHA256SUMS.txt',
					'url'               => 'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-beta.99',
				],
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_filters']    = [];
	}

	public function test_report_detects_outdated_configured_bridge_and_builds_secret_free_prompt(): void {
		$GLOBALS['stonewright_test_options']['stonewright_companion_url'] = 'http://127.0.0.1:8765';
		$transport = static fn( string $url, array $args ): array => [
			'response' => [ 'code' => 200 ],
			'body'     => (string) wp_json_encode(
					[
						'status'                     => 'ok',
						'contract_version'           => '1.0.0',
						'version'                    => '1.0.0-beta.2',
						'configured_package_version' => '1.0.0-beta.1',
				]
			),
		];

		$report = CompanionUpdateStatus::report( $transport );

		self::assertTrue( $report['ok'] );
		self::assertTrue( $report['plugin_update_available'] );
		self::assertSame( 'available', $report['latest_release']['status'] );
		self::assertSame( '1.0.0-beta.99', $report['latest_release']['version'] );
		self::assertNull( $report['latest_release']['error'] );
		self::assertSame( 'not_visible', $report['configured_companion']['status'] ?? null );
		self::assertSame( '', $report['configured_companion']['version'] );
		self::assertSame( '', $report['configured_companion']['package'] );
		self::assertSame( 'wordpress', $report['configured_companion']['source'] ?? null );
		self::assertStringContainsString( 'cannot inspect', strtolower( (string) ( $report['configured_companion']['reason'] ?? '' ) ) );
		self::assertSame( 'outdated', $report['running_companion']['status'] );
		self::assertSame( '1.0.0-beta.2', $report['running_companion']['version'] );
		self::assertSame( 'outdated', $report['companion_status'] );
		self::assertSame( 'reachable', $report['bridge']['state'] );
		self::assertSame( '1.0.0-beta.2', $report['bridge']['version'] );
		self::assertStringContainsString( 'stonewright-companion-1.0.0-beta.99.tgz', $report['companion_package'] );
		self::assertStringContainsString( 'refresh_required_tool_names', $report['update_prompt'] );
		self::assertStringNotContainsString( 'Application Password:', $report['update_prompt'] );
		self::assertStringContainsString( 'cannot replace a local stdio', $report['boundary'] );
	}

	public function test_report_does_not_probe_an_unconfigured_bridge(): void {
		$transport_calls = 0;
		$transport = static function ( string $url, array $args ) use ( &$transport_calls ): \WP_Error {
			++$transport_calls;
			return new \WP_Error( 'unexpected_probe', 'The transport must not be called.' );
		};

		$report = CompanionUpdateStatus::report( $transport );

		self::assertSame( 0, $transport_calls );
		self::assertSame( 'not_configured', $report['bridge']['state'] );
		self::assertFalse( $report['bridge']['configured'] );
		self::assertFalse( $report['bridge']['reachable'] );
		self::assertSame( 'not_visible', $report['running_companion']['status'] );
		self::assertSame( '', $report['running_companion']['version'] );
		self::assertSame( 'not_visible', $report['configured_companion']['status'] ?? null );
		self::assertSame( '', $report['configured_companion']['version'] );
		self::assertSame( '', $report['configured_companion']['package'] );
		self::assertStringContainsString( 'No HTTP bridge is configured', $report['bridge']['detail'] );
	}

	public function test_report_uses_only_explicit_bridge_attestation_for_configured_package(): void {
		$GLOBALS['stonewright_test_options']['stonewright_companion_url'] = 'http://127.0.0.1:8765';
		$GLOBALS['stonewright_test_options']['stonewright_companion_token'] = 'trusted-test-token';
		$transport = static fn( string $url, array $args ): array => [
			'response' => [ 'code' => 200 ],
			'body'     => (string) wp_json_encode(
				[
					'status'                     => 'ok',
					'version'                    => '1.0.0-beta.98',
					'configured_package_version' => '1.0.0-beta.97',
				]
			),
		];

		$report = CompanionUpdateStatus::report( $transport );

		self::assertSame( 'attested', $report['configured_companion']['status'] ?? null );
		self::assertSame( '1.0.0-beta.97', $report['configured_companion']['version'] );
		self::assertSame( 'http_bridge_attestation', $report['configured_companion']['source'] ?? null );
		self::assertSame( '', $report['configured_companion']['package'] );
		self::assertStringContainsString( 'explicit bridge health attestation', strtolower( (string) ( $report['configured_companion']['reason'] ?? '' ) ) );
	}

	public function test_report_returns_typed_actionable_release_error(): void {
		$GLOBALS['stonewright_test_transients'] = [
			GitHubUpdater::cache_key( 'beta' ) => [
				'schema_version' => GitHubUpdater::CACHE_SCHEMA_VERSION,
				'channel'        => 'beta',
				'result'         => [
					'ok'      => false,
					'status'  => 'unavailable',
					'release' => null,
					'reason'  => [
						'code'    => 'release_http_error',
						'message' => 'GitHub Releases returned an unexpected HTTP response.',
						'action'  => 'Try again later. If it persists, check GitHub service status and outbound proxy rules.',
						'http_status' => 503,
					],
				],
			],
		];

		$report = CompanionUpdateStatus::report();

		self::assertFalse( $report['ok'] );
		self::assertSame( 'unavailable', $report['latest_release']['status'] );
		self::assertSame( '', $report['latest_release']['version'] );
		self::assertSame( 'release_http_error', $report['latest_release']['error']['code'] );
		self::assertSame( 'GitHub Releases returned an unexpected HTTP response.', $report['latest_release']['error']['message'] );
		self::assertSame( 'Try again later. If it persists, check GitHub service status and outbound proxy rules.', $report['latest_release']['error']['action'] );
		self::assertSame( 503, $report['latest_release']['error']['http_status'] );
		self::assertSame( 'not_visible', $report['configured_companion']['status'] ?? null );
		self::assertSame( '', $report['configured_companion']['version'] );
		self::assertSame( '', $report['configured_companion']['package'] );
		self::assertSame( '', $report['companion_package'] );
		self::assertSame( '', $report['checksums'] );
		self::assertSame( '', $report['release_url'] );
		self::assertSame( '', $report['update_prompt'] );
	}

	public function test_report_marks_no_compatible_release_as_not_available_instead_of_unavailable(): void {
		$GLOBALS['stonewright_test_transients'] = [
			GitHubUpdater::cache_key( 'beta' ) => [
				'schema_version' => GitHubUpdater::CACHE_SCHEMA_VERSION,
				'channel'        => 'beta',
				'result'         => [
					'ok'      => true,
					'status'  => 'not_available',
					'release' => null,
					'reason'  => [
						'code'    => 'no_compatible_release',
						'message' => 'No compatible Stonewright release exists for the installed channel.',
						'action'  => 'Stay on the current version or publish a release for this channel.',
					],
				],
			],
		];

		$report = CompanionUpdateStatus::report();

		self::assertTrue( $report['ok'] );
		self::assertSame( 'not_available', $report['latest_release']['status'] );
		self::assertSame( 'no_compatible_release', $report['latest_release']['error']['code'] );
		self::assertFalse( $report['plugin_update_available'] );
		self::assertSame( '', $report['companion_package'] );
		self::assertSame( '', $report['checksums'] );
		self::assertSame( '', $report['release_url'] );
		self::assertSame( '', $report['update_prompt'] );
	}

	public function test_report_hides_release_actions_when_checks_are_disabled(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_disable_update_check'] = static fn(): bool => true;

		$report = CompanionUpdateStatus::report();

		self::assertSame( 'disabled', $report['latest_release']['status'] );
		self::assertSame( 'release_check_disabled', $report['latest_release']['error']['code'] );
		self::assertSame( '', $report['companion_package'] );
		self::assertSame( '', $report['checksums'] );
		self::assertSame( '', $report['release_url'] );
		self::assertSame( '', $report['update_prompt'] );
	}

	public function test_report_marks_an_ahead_bridge_as_a_version_mismatch(): void {
		$GLOBALS['stonewright_test_options']['stonewright_companion_url'] = 'http://127.0.0.1:8765';
		$transport = static fn( string $url, array $args ): array => [
			'response' => [ 'code' => 200 ],
			'body'     => (string) wp_json_encode(
				[
					'status'           => 'ok',
					'contract_version' => '2.0.0',
					'version'          => '2.0.0',
				]
			),
		];

		$report = CompanionUpdateStatus::report( $transport );

		self::assertSame( 'mismatch', $report['companion_status'] );
	}
}
