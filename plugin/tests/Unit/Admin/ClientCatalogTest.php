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
		}
	}

	public function test_get_returns_known_client_and_null_for_unknown(): void {
		$codex = ClientCatalog::get( 'codex' );
		self::assertIsArray( $codex );
		self::assertSame( 'codex', $codex['slug'] );
		self::assertSame( 'toml', $codex['snippet_kind'] );
		self::assertSame( 'codex mcp add', $codex['official_cli_add'] );

		self::assertNull( ClientCatalog::get( 'not-a-real-client' ) );
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
