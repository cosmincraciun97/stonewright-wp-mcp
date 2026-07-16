<?php
/**
 * Parser / tokenization validation for generated MCP client snippets.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ClientCatalog;
use Stonewright\WpMcp\Admin\ConnectClientConfig;

/**
 * @covers \Stonewright\WpMcp\Admin\ConnectClientConfig::snippet_for
 */
final class ClientSnippetValidationTest extends TestCase {

	private const USER = 'fixture-admin';
	private const PASS = 'xxxx xxxx xxxx xxxx xxxx xxxx';

	protected function setUp(): void {
		ClientCatalog::reset_for_tests();
		if ( ! defined( 'STONEWRIGHT_DIR' ) ) {
			define( 'STONEWRIGHT_DIR', dirname( __DIR__, 3 ) . '/' );
		}
		$GLOBALS['stonewright_test_options'] = [
			'site_url' => 'https://example.test',
		];
	}

	protected function tearDown(): void {
		ClientCatalog::reset_for_tests();
		$GLOBALS['stonewright_test_options'] = [];
	}

	/**
	 * @return list<string>
	 */
	private function primary_clients(): array {
		return [ 'claude-code', 'claude-desktop', 'codex', 'cursor', 'vscode-copilot', 'gemini-cli' ];
	}

	public function test_primary_clients_generate_parseable_snippets(): void {
		foreach ( $this->primary_clients() as $slug ) {
			$snippet = ConnectClientConfig::snippet_for( $slug, self::USER, self::PASS, 'stdio' );
			self::assertIsArray( $snippet, "snippet_for failed for {$slug}" );
			self::assertNotInstanceOf( \WP_Error::class, $snippet );

			if ( isset( $snippet['command'] ) ) {
				self::assertIsString( $snippet['command'] );
				self::assertStringContainsString( 'claude mcp add', (string) $snippet['command'] );
				$this->assert_cli_tokenizes_safely( (string) $snippet['command'], $slug );
				continue;
			}

			if ( isset( $snippet['toml'] ) ) {
				$toml = (string) $snippet['toml'];
				self::assertStringContainsString( '[mcp_servers.stonewright]', $toml );
				self::assertStringContainsString( 'command = "npx"', $toml );
				self::assertStringContainsString( 'STONEWRIGHT_WP_USERNAME', $toml );
				self::assertStringContainsString( self::USER, $toml );
				// No unescaped bare newlines inside TOML string values.
				self::assertDoesNotMatchRegularExpression( '/=\s*"[^"]*\n[^"]*"/', $toml );
				continue;
			}

			// JSON-shaped configs (mcpServers / servers / context_servers).
			$json = wp_json_encode( $snippet );
			self::assertNotFalse( $json );
			$decoded = json_decode( (string) $json, true );
			self::assertIsArray( $decoded, "JSON round-trip failed for {$slug}" );
			self::assertSame( JSON_ERROR_NONE, json_last_error() );

			$servers = $decoded['mcpServers'] ?? $decoded['servers'] ?? $decoded['context_servers'] ?? null;
			self::assertIsArray( $servers, "{$slug} missing server map" );
			self::assertArrayHasKey( 'stonewright', $servers );
			$entry = $servers['stonewright'];
			self::assertIsArray( $entry );
			if ( isset( $entry['command'] ) ) {
				self::assertSame( 'npx', $entry['command'] );
				self::assertIsArray( $entry['args'] );
				self::assertContains( 'stonewright-mcp', $entry['args'] );
			}
		}
	}

	public function test_http_snippets_parse_as_json_with_authorization_header(): void {
		foreach ( [ 'cursor', 'claude-desktop', 'vscode-copilot' ] as $slug ) {
			$snippet = ConnectClientConfig::snippet_for( $slug, self::USER, self::PASS, 'http' );
			self::assertIsArray( $snippet );
			$json = wp_json_encode( $snippet );
			self::assertNotFalse( $json );
			$decoded = json_decode( (string) $json, true );
			self::assertIsArray( $decoded );
			$servers = $decoded['mcpServers'] ?? $decoded['servers'] ?? $decoded['context_servers'] ?? null;
			self::assertIsArray( $servers );
			$entry = $servers['stonewright'];
			self::assertArrayHasKey( 'url', $entry );
			self::assertArrayHasKey( 'headers', $entry );
			self::assertArrayHasKey( 'Authorization', $entry['headers'] );
			self::assertStringStartsWith( 'Basic ', $entry['headers']['Authorization'] );
		}
	}

	public function test_catalog_marks_user_level_secret_storage(): void {
		foreach ( $this->primary_clients() as $slug ) {
			$client = ClientCatalog::get( $slug );
			self::assertIsArray( $client );
			self::assertSame( 'user-level', $client['secret_storage'] );
			self::assertStringContainsStringIgnoringCase( 'user-level', $client['notes'] );
		}
	}

	private function assert_cli_tokenizes_safely( string $command, string $slug ): void {
		// Reject unquoted shell metacharacters outside of already-quoted segments.
		// escapeshellarg should wrap values; bare `;` `|` `&` `$()` are not allowed.
		$dangerous = [ ';', '|', '&&', '$(', '`' ];
		// Strip single-quoted segments produced by escapeshellarg on POSIX.
		$stripped = preg_replace( "/'[^']*'/", "''", $command ) ?? $command;
		foreach ( $dangerous as $token ) {
			self::assertStringNotContainsString(
				$token,
				$stripped,
				"{$slug} CLI command contains unquoted shell metacharacter: {$token}"
			);
		}
		self::assertNotSame( '', trim( $command ) );
	}
}
