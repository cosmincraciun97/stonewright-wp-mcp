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
		$GLOBALS['stonewright_test_transients'] = [
			GitHubUpdater::CACHE_KEY => [
				'version'           => '1.0.0-beta.99',
				'package'           => 'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-beta.99/stonewright-1.0.0-beta.99.zip',
				'companion_package' => 'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-beta.99/stonewright-companion-1.0.0-beta.99.tgz',
				'checksums'         => 'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-beta.99/SHA256SUMS.txt',
				'url'               => 'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-beta.99',
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
	}

	public function test_report_detects_outdated_configured_bridge_and_builds_secret_free_prompt(): void {
		$transport = static fn( string $url, array $args ): array => [
			'response' => [ 'code' => 200 ],
			'body'     => (string) wp_json_encode(
				[
					'status'           => 'ok',
					'contract_version' => '1.0.0',
					'version'          => '1.0.0-beta.1',
				]
			),
		];

		$report = CompanionUpdateStatus::report( $transport );

		self::assertTrue( $report['ok'] );
		self::assertTrue( $report['plugin_update_available'] );
		self::assertSame( 'outdated', $report['companion_status'] );
		self::assertSame( '1.0.0-beta.1', $report['bridge']['version'] );
		self::assertStringContainsString( 'stonewright-companion-1.0.0-beta.99.tgz', $report['companion_package'] );
		self::assertStringContainsString( 'refresh_required_tool_names', $report['update_prompt'] );
		self::assertStringNotContainsString( 'Application Password:', $report['update_prompt'] );
		self::assertStringContainsString( 'cannot replace a local stdio', $report['boundary'] );
	}

	public function test_report_marks_local_stdio_unverified_when_bridge_is_unreachable(): void {
		$transport = static fn( string $url, array $args ): \WP_Error => new \WP_Error( 'unreachable', 'No bridge.' );

		$report = CompanionUpdateStatus::report( $transport );

		self::assertSame( 'unverified', $report['companion_status'] );
		self::assertFalse( $report['bridge']['reachable'] );
		self::assertStringContainsString( 'private to the AI client', $report['bridge']['detail'] );
	}

	public function test_report_marks_an_ahead_bridge_as_a_version_mismatch(): void {
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
