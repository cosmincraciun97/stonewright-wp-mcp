<?php
/**
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ClientCatalog;
use Stonewright\WpMcp\Admin\ConnectClientConfig;

/**
 * @covers \Stonewright\WpMcp\Admin\ClientCatalog
 * @covers \Stonewright\WpMcp\Admin\ConnectClientConfig::clients
 */
final class ClientCatalogTest extends TestCase {

	protected function setUp(): void {
		ClientCatalog::reset_for_tests();
		if ( ! defined( 'STONEWRIGHT_DIR' ) ) {
			define( 'STONEWRIGHT_DIR', dirname( __DIR__, 3 ) . '/' );
		}
	}

	protected function tearDown(): void {
		ClientCatalog::reset_for_tests();
	}

	public function test_catalog_loads_at_least_ten_clients_with_required_keys(): void {
		$all = ClientCatalog::all();
		self::assertGreaterThanOrEqual( 10, count( $all ) );

		$required = [
			'slug',
			'label',
			'kind',
			'snippet_kind',
			'preferred_method',
			'config_paths',
			'config_path',
			'notes',
			'verified_against_docs_on',
			'secret_storage',
			'support_tier',
			'certification_tier',
			'evidence',
		];

		foreach ( $all as $client ) {
			foreach ( $required as $key ) {
				self::assertArrayHasKey( $key, $client, "missing {$key} on " . ( $client['slug'] ?? '?' ) );
			}
			self::assertNotSame( '', $client['slug'] );
			self::assertNotSame( '', $client['label'] );
			self::assertIsArray( $client['config_paths'] );
			self::assertNotEmpty( $client['config_paths'] );
			self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', (string) $client['verified_against_docs_on'] );
			self::assertSame( 'user-level', $client['secret_storage'] );
			self::assertContains(
				(string) $client['support_tier'],
				[ 'certified', 'compatible', 'community', 'unknown' ],
				'support_tier on ' . $client['slug']
			);
			self::assertContains(
				(string) $client['certification_tier'],
				[ 'tier-1', 'tier-2', 'compatible', 'experimental' ],
				'certification_tier on ' . $client['slug']
			);
			self::assertIsArray( $client['evidence'] );
			self::assertArrayHasKey( 'manual_smoke', $client['evidence'] );
			self::assertArrayHasKey( 'oauth_http', $client['evidence'] );
			self::assertArrayHasKey( 'stdio', $client['evidence'] );
			self::assertArrayHasKey( 'restart_required', $client['evidence'] );
			self::assertIsBool( $client['evidence']['restart_required'] );
		}
	}

	public function test_tier_one_clients_include_codex_claude_and_cursor_family(): void {
		$tier_one = array_values(
			array_map(
				static fn( array $c ): string => (string) $c['slug'],
				array_filter(
					ClientCatalog::all(),
					static fn( array $c ): bool => 'tier-1' === ( $c['certification_tier'] ?? '' )
				)
			)
		);
		sort( $tier_one );

		foreach ( [ 'claude-code', 'claude-desktop', 'codex', 'cursor', 'github-copilot', 'vscode-copilot' ] as $slug ) {
			self::assertContains( $slug, $tier_one );
		}

		$codex = ClientCatalog::get( 'codex' );
		self::assertIsArray( $codex );
		self::assertSame( 'tier-1', $codex['certification_tier'] );
		self::assertContains( $codex['evidence']['stdio'], [ 'compatible', 'certified' ] );
		self::assertSame( 'essential', $codex['default_profile'] );

		$antigravity = ClientCatalog::get( 'antigravity' );
		self::assertIsArray( $antigravity );
		self::assertSame( 'low-tools', $antigravity['default_profile'] );

		$generic = ClientCatalog::get( 'generic-mcp' );
		self::assertIsArray( $generic );
		self::assertSame( 'essential-static', $generic['default_profile'] );
	}

	public function test_get_returns_known_client_and_null_for_unknown(): void {
		$codex = ClientCatalog::get( 'codex' );
		self::assertIsArray( $codex );
		self::assertSame( 'codex', $codex['slug'] );
		self::assertSame( 'toml', $codex['snippet_kind'] );
		self::assertSame( 'codex mcp add', $codex['official_cli_add'] );

		self::assertNull( ClientCatalog::get( 'not-a-real-client' ) );
	}

	public function test_certified_support_requires_complete_acceptance_evidence(): void {
		$normalize = new \ReflectionMethod( ClientCatalog::class, 'normalize' );

		$client = [
			'slug'         => 'synthetic-client',
			'label'        => 'Synthetic Client',
			'support_tier' => 'certified',
			'evidence'     => [
				'manual_smoke'         => 'pending',
				'oauth_http'           => 'compatible',
				'stdio'                => 'compatible',
				'certification_report' => '',
			],
		];

		$without_evidence = $normalize->invoke( null, $client );
		self::assertIsArray( $without_evidence );
		self::assertSame( 'compatible', $without_evidence['support_tier'] );

		$client['evidence'] = [
			'manual_smoke'         => 'pass',
			'oauth_http'           => 'certified',
			'stdio'                => 'compatible',
			'certification_report' => 'docs/releases/example-client-report.md',
		];
		$with_evidence = $normalize->invoke( null, $client );
		self::assertIsArray( $with_evidence );
		self::assertSame( 'certified', $with_evidence['support_tier'] );
	}

	public function test_clients_are_sorted_by_label(): void {
		$labels = array_map(
			static fn( array $c ): string => (string) $c['label'],
			ClientCatalog::all()
		);
		$sorted = $labels;
		usort( $sorted, 'strcasecmp' );
		self::assertSame( $sorted, $labels );
	}

	public function test_connect_client_config_consumes_catalog(): void {
		$from_config = ConnectClientConfig::clients();
		$slugs       = array_column( $from_config, 'slug' );
		self::assertContains( 'claude-code', $slugs );
		self::assertContains( 'codex', $slugs );
		self::assertContains( 'cursor', $slugs );
		self::assertContains( 'generic-mcp', $slugs );
		foreach ( $from_config as $row ) {
			self::assertArrayHasKey( 'config_path', $row );
			self::assertArrayHasKey( 'notes', $row );
			self::assertNotSame( '', $row['notes'] );
		}
	}
}
