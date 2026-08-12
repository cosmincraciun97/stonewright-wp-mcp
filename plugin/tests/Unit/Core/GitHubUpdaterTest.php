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

	public function test_installed_channel_distinguishes_stable_and_prerelease_versions(): void {
		self::assertSame( 'stable', GitHubUpdater::installed_channel( '1.2.3' ) );
		self::assertSame( 'beta', GitHubUpdater::installed_channel( '1.2.3-beta.4' ) );
		self::assertSame( 'beta', GitHubUpdater::installed_channel( '2.0.0-rc.1' ) );
	}

	public function test_select_release_is_channel_strict_and_chooses_highest_eligible_version(): void {
		$releases = $this->releases_fixture();
		$stable   = GitHubUpdater::select_release( $releases, 'stable' );
		$beta     = GitHubUpdater::select_release( $releases, 'beta' );

		self::assertIsArray( $stable );
		self::assertSame( '1.2.0', $stable['version'] );
		self::assertIsArray( $beta );
		self::assertSame( '1.3.0-beta.10', $beta['version'] );
	}

	public function test_select_release_rejects_malformed_incomplete_and_cross_channel_candidates(): void {
		$releases = $this->releases_fixture();
		self::assertNull( GitHubUpdater::select_release( [ $releases[4] ], 'stable' ) );
		self::assertNull( GitHubUpdater::select_release( [ $releases[5] ], 'beta' ) );
		self::assertNull( GitHubUpdater::select_release( [ $releases[6] ], 'beta' ) );
		self::assertNull( GitHubUpdater::select_release( [ $releases[0] ], 'beta' ) );
		self::assertNull( GitHubUpdater::select_release( [ $releases[1] ], 'stable' ) );
	}

	public function test_select_release_requires_exact_asset_names_and_trusted_hosts(): void {
		$release = $this->releases_fixture()[2];
		$release['assets'][0]['name'] = 'stonewright-latest.zip';
		self::assertNull( GitHubUpdater::select_release( [ $release ], 'beta' ) );

		$release = $this->releases_fixture()[2];
		$release['assets'][0]['browser_download_url'] = 'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.3.0-beta.10/other.zip';
		self::assertNull( GitHubUpdater::select_release( [ $release ], 'beta' ) );
	}

	public function test_parse_release_extracts_exact_packages(): void {
		$parsed = GitHubUpdater::parse_release( $this->releases_fixture()[2] );
		self::assertIsArray( $parsed );
		self::assertSame( '1.3.0-beta.10', $parsed['version'] );
		self::assertStringEndsWith( '/stonewright-1.3.0-beta.10.zip', $parsed['package'] );
		self::assertStringEndsWith( '/stonewright-companion-1.3.0-beta.10.tgz', $parsed['companion_package'] );
	}

	public function test_fetch_release_list_selects_installed_channel_and_caches_with_channel(): void {
		$releases = $this->releases_fixture();
		$GLOBALS['stonewright_test_wp_remote_get'] = static fn( string $url ): array => [
			'response' => [ 'code' => 200 ],
			'body'     => (string) wp_json_encode( $releases ),
		];

		$parsed = GitHubUpdater::fetch_latest_release( false, '1.0.0-beta.1' );
		self::assertIsArray( $parsed );
		self::assertSame( '1.3.0-beta.10', $parsed['version'] );
		self::assertSame(
			[ 'channel' => 'beta', 'release' => $parsed ],
			get_transient( GitHubUpdater::cache_key( 'beta' ) )
		);
		self::assertStringContainsString( '/releases?per_page=', $GLOBALS['stonewright_test_wp_remote_get_calls'][0]['url'] );
	}

	public function test_cache_cannot_cross_channels(): void {
		$beta = GitHubUpdater::select_release( $this->releases_fixture(), 'beta' );
		self::assertIsArray( $beta );
		set_transient(
			GitHubUpdater::cache_key( 'beta' ),
			[ 'channel' => 'beta', 'release' => $beta ],
			GitHubUpdater::CACHE_TTL
		);
		$releases = $this->releases_fixture();
		$GLOBALS['stonewright_test_wp_remote_get'] = static fn( string $url ): array => [
			'response' => [ 'code' => 200 ],
			'body'     => (string) wp_json_encode( $releases ),
		];

		$stable = GitHubUpdater::fetch_latest_release( false, '1.0.0' );
		self::assertIsArray( $stable );
		self::assertSame( '1.2.0', $stable['version'] );
		self::assertCount( 1, $GLOBALS['stonewright_test_wp_remote_get_calls'] );
	}

	public function test_force_refresh_replaces_same_channel_cache(): void {
		set_transient(
			GitHubUpdater::cache_key( 'beta' ),
			[ 'channel' => 'beta', 'release' => [
				'version' => '1.0.0-beta.1',
				'package' => 'https://example.test/old.zip',
				'companion_package' => 'https://example.test/old.tgz',
				'checksums' => '',
				'url' => 'https://example.test/old',
			] ],
			GitHubUpdater::CACHE_TTL
		);
		$releases = $this->releases_fixture();
		$GLOBALS['stonewright_test_wp_remote_get'] = static fn( string $url ): array => [
			'response' => [ 'code' => 200 ],
			'body'     => (string) wp_json_encode( $releases ),
		];

		$parsed = GitHubUpdater::fetch_latest_release( true, '1.0.0-beta.1' );
		self::assertSame( '1.3.0-beta.10', $parsed['version'] );
	}

	public function test_inject_update_follows_beta_installed_channel(): void {
		$this->set_installed_version( '1.0.0-beta.1' );
		$this->cache_release( 'beta' );
		$result = GitHubUpdater::inject_update( (object) [ 'response' => [], 'no_update' => [] ] );
		$plugin = GitHubUpdater::plugin_basename();
		self::assertSame( '1.3.0-beta.10', $result->response[ $plugin ]->new_version );
	}

	public function test_inject_update_follows_stable_installed_channel(): void {
		$this->set_installed_version( '1.0.0' );
		$this->cache_release( 'stable' );
		$result = GitHubUpdater::inject_update( (object) [ 'response' => [], 'no_update' => [] ] );
		$plugin = GitHubUpdater::plugin_basename();
		self::assertSame( '1.2.0', $result->response[ $plugin ]->new_version );
	}

	public function test_inject_update_skips_when_disabled(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_disable_update_check'] = static fn(): bool => true;
		$result = GitHubUpdater::inject_update( (object) [ 'response' => [], 'no_update' => [] ] );
		self::assertSame( [], $result->response );
	}

	public function test_register_hooks_update_plugins_filter(): void {
		GitHubUpdater::register();
		self::assertArrayHasKey( 'site_transient_update_plugins', $GLOBALS['stonewright_test_filters'] );
	}

	private function set_installed_version( string $version ): void {
		$GLOBALS['stonewright_test_filters']['stonewright_installed_version'] = static fn(): string => $version;
	}

	private function cache_release( string $channel ): void {
		$release = GitHubUpdater::select_release( $this->releases_fixture(), $channel );
		self::assertIsArray( $release );
		set_transient(
			GitHubUpdater::cache_key( $channel ),
			[ 'channel' => $channel, 'release' => $release ],
			GitHubUpdater::CACHE_TTL
		);
	}

	/** @return array<int, array<string, mixed>> */
	private function releases_fixture(): array {
		$path = dirname( __DIR__, 2 ) . '/fixtures/github/releases-list.json';
		$raw  = file_get_contents( $path );
		self::assertNotFalse( $raw );
		$data = json_decode( $raw, true );
		self::assertIsArray( $data );
		return $data;
	}
}
