<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\GitHubUpdater;

/**
 * @covers \Stonewright\WpMcp\Core\GitHubUpdater
 */
final class GitHubUpdaterTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_transients']          = [];
		$GLOBALS['stonewright_test_filters']             = [];
		$GLOBALS['stonewright_test_wp_remote_get']       = null;
		$GLOBALS['stonewright_test_wp_remote_get_calls'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_transients']          = [];
		$GLOBALS['stonewright_test_filters']             = [];
		$GLOBALS['stonewright_test_wp_remote_get']       = null;
		$GLOBALS['stonewright_test_wp_remote_get_calls'] = [];
	}

	public function test_parse_release_extracts_version_and_package_from_fixture(): void {
		$raw = $this->fixture_json();
		$parsed = GitHubUpdater::parse_release( $raw );

		self::assertIsArray( $parsed );
		self::assertSame( '1.0.0-alpha.99', $parsed['version'] );
		self::assertSame(
			'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-alpha.99/stonewright-1.0.0-alpha.99.zip',
			$parsed['package']
		);
		self::assertSame(
			'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.99',
			$parsed['url']
		);
	}

	public function test_fetch_latest_release_populates_transient_from_http_fixture(): void {
		$raw = $this->fixture_json();
		$GLOBALS['stonewright_test_wp_remote_get'] = static function ( string $url ) use ( $raw ): array {
			return [
				'response' => [ 'code' => 200 ],
				'body'     => (string) wp_json_encode( $raw ),
			];
		};

		$parsed = GitHubUpdater::fetch_latest_release();

		self::assertIsArray( $parsed );
		self::assertSame( '1.0.0-alpha.99', $parsed['version'] );
		self::assertSame( $parsed, get_transient( GitHubUpdater::CACHE_KEY ) );
		self::assertNotEmpty( $GLOBALS['stonewright_test_wp_remote_get_calls'] );
	}

	public function test_fetch_latest_release_uses_cached_transient(): void {
		$cached = [
			'version' => '1.0.0-alpha.50',
			'package' => 'https://example.test/stonewright-1.0.0-alpha.50.zip',
			'url'     => 'https://example.test/release',
		];
		set_transient( GitHubUpdater::CACHE_KEY, $cached, 12 * HOUR_IN_SECONDS );

		$calls_before = count( $GLOBALS['stonewright_test_wp_remote_get_calls'] ?? [] );
		$parsed       = GitHubUpdater::fetch_latest_release();
		$calls_after  = count( $GLOBALS['stonewright_test_wp_remote_get_calls'] ?? [] );

		self::assertSame( $cached, $parsed );
		self::assertSame( $calls_before, $calls_after );
	}

	public function test_inject_update_adds_response_when_remote_is_newer(): void {
		set_transient(
			GitHubUpdater::CACHE_KEY,
			[
				'version' => '1.0.0-alpha.99',
				'package' => 'https://example.test/stonewright-1.0.0-alpha.99.zip',
				'url'     => 'https://example.test/release',
			],
			12 * HOUR_IN_SECONDS
		);

		$transient = (object) [
			'response'  => [],
			'no_update' => [],
			'checked'   => [],
		];

		$result = GitHubUpdater::inject_update( $transient );
		$plugin = GitHubUpdater::plugin_basename();

		self::assertObjectHasProperty( 'response', $result );
		self::assertArrayHasKey( $plugin, $result->response );
		self::assertSame( '1.0.0-alpha.99', $result->response[ $plugin ]->new_version );
		self::assertSame( 'https://example.test/stonewright-1.0.0-alpha.99.zip', $result->response[ $plugin ]->package );
	}

	public function test_inject_update_skips_when_disable_filter_is_true(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_disable_update_check'] = static fn(): bool => true;

		set_transient(
			GitHubUpdater::CACHE_KEY,
			[
				'version' => '1.0.0-alpha.99',
				'package' => 'https://example.test/stonewright-1.0.0-alpha.99.zip',
				'url'     => 'https://example.test/release',
			],
			12 * HOUR_IN_SECONDS
		);

		$transient = (object) [
			'response'  => [],
			'no_update' => [],
		];

		$result = GitHubUpdater::inject_update( $transient );
		self::assertSame( [], $result->response );
	}

	public function test_inject_update_reports_no_update_when_current_is_latest(): void {
		set_transient(
			GitHubUpdater::CACHE_KEY,
			[
				'version' => STONEWRIGHT_VERSION,
				'package' => 'https://example.test/stonewright.zip',
				'url'     => 'https://example.test/release',
			],
			12 * HOUR_IN_SECONDS
		);

		$transient = (object) [
			'response'  => [],
			'no_update' => [],
		];

		$result = GitHubUpdater::inject_update( $transient );
		$plugin = GitHubUpdater::plugin_basename();

		self::assertArrayNotHasKey( $plugin, $result->response );
		self::assertArrayHasKey( $plugin, $result->no_update );
	}

	public function test_register_hooks_update_plugins_filter(): void {
		$GLOBALS['stonewright_test_actions'] = [];
		$GLOBALS['stonewright_test_filters'] = [];
		// add_filter in bootstrap stores under stonewright_test_filters as single callback;
		// register should call add_filter for site_transient_update_plugins.
		GitHubUpdater::register();
		self::assertArrayHasKey( 'site_transient_update_plugins', $GLOBALS['stonewright_test_filters'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fixture_json(): array {
		$path = dirname( __DIR__, 2 ) . '/fixtures/github/latest-release.json';
		$raw  = file_get_contents( $path );
		self::assertNotFalse( $raw );
		$data = json_decode( $raw, true );
		self::assertIsArray( $data );
		return $data;
	}
}
