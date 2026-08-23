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
		self::assertSame( '1.3.0-beta.30', $beta['version'] );
	}

	public function test_select_release_accepts_supported_beta_published_as_latest(): void {
		$release               = $this->releases_fixture()[2];
		$release['prerelease'] = false;
		$release['body']       = "Release channel: `supported`\n";

		$selected = GitHubUpdater::select_release( [ $release ], 'beta' );

		self::assertIsArray( $selected );
		self::assertSame( '1.3.0-beta.10', $selected['version'] );
	}

	public function test_stable_release_with_hyphenated_build_metadata_is_not_treated_as_a_prerelease(): void {
		$release = $this->release_with_version( $this->releases_fixture()[0], '1.2.3+build-1' );

		self::assertNull( GitHubUpdater::release_rejection_reason( $release, 'stable' ) );
		self::assertSame( '1.2.3+build-1', GitHubUpdater::select_release( [ $release ], 'stable' )['version'] ?? null );

		$release['body'] = "Release channel: `supported`\n";
		self::assertSame( 'release_channel_version_incompatible', GitHubUpdater::release_rejection_reason( $release, 'beta' ) );

		$release['body']       = "Release channel: `preview`\n";
		$release['prerelease'] = true;
		self::assertSame( 'release_channel_version_incompatible', GitHubUpdater::release_rejection_reason( $release, 'beta' ) );
	}

	/**
	 * @dataProvider allowed_release_channel_cases
	 */
	public function test_select_release_accepts_only_allowed_release_channel_combinations( string $channel, int $fixture_index, string $expected_version ): void {
		$selected = GitHubUpdater::select_release( [ $this->releases_fixture()[ $fixture_index ] ], $channel );

		self::assertIsArray( $selected );
		self::assertSame( $expected_version, $selected['version'] );
	}

	/**
	 * @dataProvider rejected_release_channel_cases
	 */
	public function test_select_release_rejects_invalid_release_metadata_with_a_deterministic_reason( array $release, string $channel, string $reason ): void {
		self::assertNull( GitHubUpdater::select_release( [ $release ], $channel ) );
		self::assertSame( $reason, GitHubUpdater::release_rejection_reason( $release, $channel ) );
	}

	public function test_select_release_rejects_malformed_incomplete_and_cross_channel_candidates(): void {
		$releases = $this->releases_fixture();
		self::assertNull( GitHubUpdater::select_release( [ $releases[4] ], 'stable' ) );
		self::assertNull( GitHubUpdater::select_release( [ $releases[5] ], 'beta' ) );
		$releases[2]['prerelease'] = false;
		self::assertNull( GitHubUpdater::select_release( [ $releases[2] ], 'beta' ) );
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
		self::assertSame( '1.3.0-beta.30', $parsed['version'] );
		self::assertSame(
			[ 'schema_version' => GitHubUpdater::CACHE_SCHEMA_VERSION, 'channel' => 'beta', 'release' => $parsed ],
			get_transient( GitHubUpdater::cache_key( 'beta' ) )
		);
		self::assertStringContainsString( '/releases?per_page=', $GLOBALS['stonewright_test_wp_remote_get_calls'][0]['url'] );
	}

	/**
	 * @dataProvider release_lookup_failure_cases
	 */
	public function test_release_metadata_reports_exact_safe_failure_reason( mixed $response, string $code, string $message, string $action, ?int $http_status ): void {
		$GLOBALS['stonewright_test_wp_remote_get'] = static fn( string $url ): mixed => $response;

		$result = GitHubUpdater::release_metadata( true, '1.0.0-beta.1' );

		self::assertFalse( $result['ok'] );
		self::assertSame( 'unavailable', $result['status'] );
		self::assertNull( $result['release'] );
		self::assertSame( $code, $result['reason']['code'] );
		self::assertSame( $message, $result['reason']['message'] );
		self::assertSame( $action, $result['reason']['action'] );
		self::assertSame( $http_status, $result['reason']['http_status'] ?? null );
		self::assertStringNotContainsString( 'private upstream detail', wp_json_encode( $result ) );
	}

	public function test_release_metadata_distinguishes_no_compatible_release_from_a_lookup_failure(): void {
		$stable_release = $this->releases_fixture()[0];
		$GLOBALS['stonewright_test_wp_remote_get'] = static fn( string $url ): array => [
			'response' => [ 'code' => 200 ],
			'body'     => (string) wp_json_encode( [ $stable_release ] ),
		];

		$result = GitHubUpdater::release_metadata( true, '1.0.0-beta.1' );

		self::assertTrue( $result['ok'] );
		self::assertSame( 'not_available', $result['status'] );
		self::assertNull( $result['release'] );
		self::assertSame( 'no_compatible_release', $result['reason']['code'] );
		self::assertSame( 'No compatible Stonewright release exists for the installed channel.', $result['reason']['message'] );
		self::assertSame( 'Stay on the current version or publish a release for this channel.', $result['reason']['action'] );
	}

	public function test_release_metadata_reports_missing_required_artifacts_separately(): void {
		$release = $this->releases_fixture()[2];
		array_pop( $release['assets'] );
		$GLOBALS['stonewright_test_wp_remote_get'] = static fn( string $url ): array => [
			'response' => [ 'code' => 200 ],
			'body'     => (string) wp_json_encode( [ $release ] ),
		];

		$result = GitHubUpdater::release_metadata( true, '1.0.0-beta.1' );

		self::assertFalse( $result['ok'] );
		self::assertSame( 'unavailable', $result['status'] );
		self::assertNull( $result['release'] );
		self::assertSame( 'missing_required_artifacts', $result['reason']['code'] );
		self::assertSame( 'The compatible Stonewright release is missing required plugin or companion artifacts.', $result['reason']['message'] );
		self::assertSame( 'Do not update. Publish both required packages, then try again.', $result['reason']['action'] );
	}

	public function test_release_metadata_marks_an_intentionally_disabled_check_as_neutral(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_disable_update_check'] = static fn(): bool => true;

		$result = GitHubUpdater::release_metadata();

		self::assertTrue( $result['ok'] );
		self::assertSame( 'disabled', $result['status'] );
		self::assertNull( $result['release'] );
		self::assertSame( 'release_check_disabled', $result['reason']['code'] );
		self::assertCount( 0, $GLOBALS['stonewright_test_wp_remote_get_calls'] );
	}

	public function test_fetch_latest_release_remains_a_backward_compatible_release_only_view(): void {
		$releases = $this->releases_fixture();
		$GLOBALS['stonewright_test_wp_remote_get'] = static fn( string $url ): array => [
			'response' => [ 'code' => 200 ],
			'body'     => (string) wp_json_encode( $releases ),
		];

		$metadata = GitHubUpdater::release_metadata( true, '1.0.0-beta.1' );
		$release  = GitHubUpdater::fetch_latest_release( false, '1.0.0-beta.1' );

		self::assertTrue( $metadata['ok'] );
		self::assertSame( 'available', $metadata['status'] );
		self::assertSame( $metadata['release'], $release );
	}

	public function test_legacy_unversioned_release_cache_is_refetched_and_replaced(): void {
		$legacy = GitHubUpdater::select_release( $this->releases_fixture(), 'beta' );
		self::assertIsArray( $legacy );
		set_transient(
			GitHubUpdater::cache_key( 'beta' ),
			[ 'channel' => 'beta', 'release' => $legacy ],
			GitHubUpdater::CACHE_TTL
		);
		$GLOBALS['stonewright_test_wp_remote_get'] = fn( string $url ): array => [
			'response' => [ 'code' => 200 ],
			'body'     => (string) wp_json_encode( $this->releases_fixture() ),
		];

		$parsed = GitHubUpdater::fetch_latest_release( false, '1.0.0-beta.1' );

		self::assertIsArray( $parsed );
		self::assertCount( 1, $GLOBALS['stonewright_test_wp_remote_get_calls'] );
		self::assertSame(
			[ 'schema_version' => GitHubUpdater::CACHE_SCHEMA_VERSION, 'channel' => 'beta', 'release' => $parsed ],
			get_transient( GitHubUpdater::cache_key( 'beta' ) )
		);
	}

	public function test_current_release_cache_schema_is_reused_without_a_network_request(): void {
		$release = GitHubUpdater::select_release( $this->releases_fixture(), 'beta' );
		self::assertIsArray( $release );
		set_transient(
			GitHubUpdater::cache_key( 'beta' ),
			[ 'schema_version' => GitHubUpdater::CACHE_SCHEMA_VERSION, 'channel' => 'beta', 'release' => $release ],
			GitHubUpdater::CACHE_TTL
		);

		$parsed = GitHubUpdater::fetch_latest_release( false, '1.0.0-beta.1' );

		self::assertSame( $release, $parsed );
		self::assertCount( 0, $GLOBALS['stonewright_test_wp_remote_get_calls'] );
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
		self::assertSame( '1.3.0-beta.30', $parsed['version'] );
	}

	public function test_inject_update_follows_beta_installed_channel(): void {
		$this->set_installed_version( '1.0.0-beta.1' );
		$this->cache_release( 'beta' );
		$result = GitHubUpdater::inject_update( (object) [ 'response' => [], 'no_update' => [] ] );
		$plugin = GitHubUpdater::plugin_basename();
		self::assertSame( '1.3.0-beta.30', $result->response[ $plugin ]->new_version );
	}

	public function test_inject_update_adds_supported_beta_latest_release_to_the_wordpress_update_transient(): void {
		$this->set_installed_version( '1.0.0-beta.1' );
		$supported_beta = $this->releases_fixture()[6];
		$GLOBALS['stonewright_test_wp_remote_get'] = static fn( string $url ): array => [
			'response' => [ 'code' => 200 ],
			'body'     => (string) wp_json_encode( [ $supported_beta ] ),
		];

		$result = GitHubUpdater::inject_update( (object) [ 'response' => [], 'no_update' => [] ] );
		$plugin = GitHubUpdater::plugin_basename();

		self::assertSame( '1.3.0-beta.30', $result->response[ $plugin ]->new_version );
		self::assertStringEndsWith( '/stonewright-1.3.0-beta.30.zip', $result->response[ $plugin ]->package );
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

	/** @return iterable<string, array{string, int, string}> */
	public static function allowed_release_channel_cases(): iterable {
		yield 'supported beta published as latest' => [ 'beta', 6, '1.3.0-beta.30' ];
		yield 'preview prerelease' => [ 'beta', 2, '1.3.0-beta.10' ];
		yield 'stable release' => [ 'stable', 0, '1.2.0' ];
	}

	/** @return iterable<string, array{mixed, string, string, string, int|null}> */
	public static function release_lookup_failure_cases(): iterable {
		yield 'timeout' => [
			new \WP_Error( 'http_request_failed', 'cURL error 28: private upstream detail' ),
			'release_timeout',
			'GitHub Releases did not respond before the update check timed out.',
			'Check outbound HTTPS access and try again.',
			null,
		];
		yield 'transport error' => [
			new \WP_Error( 'http_request_failed', 'DNS failure: private upstream detail' ),
			'release_transport_error',
			'Stonewright could not reach GitHub Releases.',
			'Check outbound HTTPS and DNS, then try again.',
			null,
		];
		yield 'not found' => [
			[ 'response' => [ 'code' => 404 ], 'body' => 'private upstream detail' ],
			'release_http_not_found',
			'GitHub Releases could not find the Stonewright release feed.',
			'Verify the configured repository and release API URL, then try again.',
			404,
		];
		yield 'other HTTP error' => [
			[ 'response' => [ 'code' => 503 ], 'body' => 'private upstream detail' ],
			'release_http_error',
			'GitHub Releases returned an unexpected HTTP response.',
			'Try again later. If it persists, check GitHub service status and outbound proxy rules.',
			503,
		];
		yield 'invalid JSON' => [
			[ 'response' => [ 'code' => 200 ], 'body' => '{private upstream detail' ],
			'release_invalid_response',
			'GitHub Releases returned invalid release metadata.',
			'Try again. If it persists, verify the release API response schema.',
			null,
		];
		yield 'invalid schema' => [
			[ 'response' => [ 'code' => 200 ], 'body' => '{"message":"private upstream detail"}' ],
			'release_invalid_response',
			'GitHub Releases returned invalid release metadata.',
			'Try again. If it persists, verify the release API response schema.',
			null,
		];
	}

	/** @return iterable<string, array{array<string, mixed>, string, string}> */
	public function rejected_release_channel_cases(): iterable {
		$releases = $this->releases_fixture();

		$missing_declaration = $releases[2];
		unset( $missing_declaration['body'] );
		yield 'missing declaration' => [ $missing_declaration, 'beta', 'missing_release_channel' ];

		$unknown_declaration = $releases[2];
		$unknown_declaration['body'] = "Release channel: `other`\n";
		yield 'unknown declaration' => [ $unknown_declaration, 'beta', 'unknown_release_channel' ];

		$supported_prerelease = $releases[6];
		$supported_prerelease['prerelease'] = true;
		yield 'supported channel marked as GitHub prerelease' => [ $supported_prerelease, 'beta', 'github_prerelease_incompatible' ];

		$preview_latest = $releases[2];
		$preview_latest['prerelease'] = false;
		yield 'preview channel marked as GitHub latest' => [ $preview_latest, 'beta', 'github_prerelease_incompatible' ];

		$stable_prerelease_version = $releases[2];
		$stable_prerelease_version['body'] = "Release channel: `stable`\n";
		$stable_prerelease_version['prerelease'] = false;
		yield 'stable channel with prerelease semantic version' => [ $stable_prerelease_version, 'stable', 'release_channel_version_incompatible' ];

		$beta_stable_version = $releases[0];
		$beta_stable_version['body'] = "Release channel: `supported`\n";
		yield 'supported channel with stable semantic version' => [ $beta_stable_version, 'beta', 'release_channel_version_incompatible' ];

		$preview_stable_version = $releases[0];
		$preview_stable_version['body'] = "Release channel: `preview`\n";
		yield 'preview channel with stable semantic version' => [ $preview_stable_version, 'beta', 'release_channel_version_incompatible' ];

		$malformed_body = $releases[2];
		$malformed_body['body'] = "Release channel: preview\n";
		yield 'malformed release body' => [ $malformed_body, 'beta', 'malformed_release_channel' ];

		$missing_assets = $releases[2];
		array_pop( $missing_assets['assets'] );
		yield 'missing required package assets' => [ $missing_assets, 'beta', 'missing_required_assets' ];

		foreach ( [ 'v1.3.0-beta..30', 'v01.0.0', 'v1.0.0-beta.01', 'vv1.3.0-beta.30' ] as $tag ) {
			$malformed_semver             = $releases[2];
			$malformed_semver['tag_name'] = $tag;
			yield 'malformed semantic version ' . $tag => [ $malformed_semver, 'beta', 'invalid_semantic_version' ];
		}

		$duplicate_declaration = $releases[2];
		$duplicate_declaration['body'] = "Release channel: `preview`\nRelease channel: `preview`\n";
		yield 'duplicate release channel declaration' => [ $duplicate_declaration, 'beta', 'malformed_release_channel' ];

		$mixed_declarations = $releases[2];
		$mixed_declarations['body'] = "Release channel: `preview`\nRelease channel: `supported`\n";
		yield 'mixed release channel declarations' => [ $mixed_declarations, 'beta', 'malformed_release_channel' ];

		$valid_then_malformed = $releases[2];
		$valid_then_malformed['body'] = "Release channel: `preview`\nRelease channel: preview\n";
		yield 'valid declaration followed by malformed declaration' => [ $valid_then_malformed, 'beta', 'malformed_release_channel' ];
	}

	private function set_installed_version( string $version ): void {
		$GLOBALS['stonewright_test_filters']['stonewright_installed_version'] = static fn(): string => $version;
	}

	private function cache_release( string $channel ): void {
		$release = GitHubUpdater::select_release( $this->releases_fixture(), $channel );
		self::assertIsArray( $release );
		set_transient(
			GitHubUpdater::cache_key( $channel ),
			[ 'schema_version' => GitHubUpdater::CACHE_SCHEMA_VERSION, 'channel' => $channel, 'release' => $release ],
			GitHubUpdater::CACHE_TTL
		);
	}

	/**
	 * @param array<string, mixed> $release
 *
	 * @return array<string, mixed>
	 */
	private function release_with_version( array $release, string $version ): array {
		$tag                 = 'v' . $version;
		$release['tag_name'] = $tag;
		$release['html_url'] = 'https://github.com/' . GitHubUpdater::REPO . '/releases/tag/' . rawurlencode( $tag );
		$release['assets']   = [
			[
				'name'                 => 'stonewright-' . $version . '.zip',
				'browser_download_url' => 'https://github.com/' . GitHubUpdater::REPO . '/releases/download/' . rawurlencode( $tag ) . '/' . rawurlencode( 'stonewright-' . $version . '.zip' ),
			],
			[
				'name'                 => 'stonewright-companion-' . $version . '.tgz',
				'browser_download_url' => 'https://github.com/' . GitHubUpdater::REPO . '/releases/download/' . rawurlencode( $tag ) . '/' . rawurlencode( 'stonewright-companion-' . $version . '.tgz' ),
			],
		];

		return $release;
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
