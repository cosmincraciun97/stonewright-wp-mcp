<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ClientCatalog;
use Stonewright\WpMcp\Admin\Connect\ClientInstructions;
use Stonewright\WpMcp\Admin\ConnectClientConfig;

/**
 * OAuth payloads, client labels and the documented command forms of the connect
 * snippets.
 *
 * @covers \Stonewright\WpMcp\Admin\ConnectClientConfig
 */
final class ConnectClientConfigOAuthTest extends TestCase {

	private const URL  = 'https://example.test/wp-json/mcp/stonewright-oauth';
	private const NAME = 'stonewright-example-test';

	protected function setUp(): void {
		ClientCatalog::reset_for_tests();
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mcp_surface' => 'essential' ];
	}

	protected function tearDown(): void {
		ClientCatalog::reset_for_tests();
		$GLOBALS['stonewright_test_options'] = [];
		unset( $GLOBALS['stonewright_test_site_url'] );
	}

	public function test_claude_code_password_command_uses_the_positional_http_form(): void {
		$result = ConnectClientConfig::snippet_for( 'claude-code', 'fixture-admin', 'pw', 'http' );

		self::assertIsArray( $result );
		self::assertSame(
			"claude mcp add --transport http 'stonewright-example-test' 'https://example.test/wp-json/mcp/stonewright' --header \"Authorization: Basic " . base64_encode( 'fixture-admin:pw' ) . '"',
			$result['command']
		);
		self::assertStringNotContainsString( '--url', $result['command'] );
	}

	public function test_command_quoting_does_not_depend_on_the_server_platform(): void {
		$result = ConnectClientConfig::snippet_for( 'claude-code', "o'neil", 'pw' );

		self::assertIsArray( $result );
		self::assertStringContainsString( "--env STONEWRIGHT_WP_USERNAME='o'\\''neil'", $result['command'] );
		self::assertStringContainsString( "--env STONEWRIGHT_WP_URL='https://example.test/'", $result['command'] );
	}

	public function test_the_suggested_server_name_is_shared_with_the_connect_panel(): void {
		self::assertSame( self::NAME, ConnectClientConfig::mcp_server_name() );
	}

	public function test_oauth_labels_come_from_the_client_instructions(): void {
		self::assertSame( ClientInstructions::labels(), ConnectClientConfig::oauth_labels() );
		self::assertSame( 'Claude Code', ConnectClientConfig::oauth_labels()['claude-code'] );
	}

	public function test_every_oauth_label_is_a_known_snippet_client(): void {
		foreach ( array_keys( ConnectClientConfig::oauth_labels() ) as $slug ) {
			self::assertIsArray( ConnectClientConfig::snippet_for( $slug, 'fixture-admin', 'pw' ), $slug );
		}
	}

	public function test_oauth_config_summarises_a_client_with_a_configuration_file(): void {
		$config = ConnectClientConfig::oauth_config_for( 'vscode', self::URL, self::NAME );

		self::assertSame( 'code', $config['kind'] );
		self::assertSame( 'VS Code with GitHub Copilot', $config['label'] );
		self::assertSame( 'json', $config['format'] );
		self::assertSame( [ 'mcpServers' => [ self::NAME => [ 'type' => 'http', 'url' => self::URL ] ] ], json_decode( (string) $config['code'], true ) );
		self::assertStringContainsString( '~/.copilot/mcp-config.json', (string) $config['location'] );
		self::assertStringContainsString( 'code --add-mcp', (string) $config['note'] );
		self::assertStringContainsString( 'browser', (string) $config['hint'] );
		self::assertStringStartsWith( 'vscode:mcp/install?', (string) $config['links'][0]['url'] );
	}

	public function test_claude_code_oauth_config_lists_the_documented_commands(): void {
		$config = ConnectClientConfig::oauth_config_for( 'claude-code', self::URL, self::NAME );
		$lines  = explode( "\n", (string) $config['note'] );

		self::assertSame( 'claude mcp add --transport http stonewright-example-test https://example.test/wp-json/mcp/stonewright-oauth', $lines[0] );
		self::assertSame( 'claude mcp login stonewright-example-test', $lines[1] );
		self::assertSame( ClientCatalog::DEFAULT_OAUTH_REAUTH_ACTION, end( $lines ) );
	}

	public function test_oauth_config_of_a_hosted_client_lists_its_app_steps(): void {
		$config = ConnectClientConfig::oauth_config_for( 'claude-ai', self::URL, self::NAME );

		self::assertSame( 'steps', $config['kind'] );
		self::assertArrayNotHasKey( 'code', $config );
		self::assertStringContainsString( 'Customize > Connectors', implode( "\n", $config['steps'] ) );
	}

	public function test_oauth_config_of_a_client_without_published_sign_in_is_a_notice(): void {
		$config = ConnectClientConfig::oauth_config_for( 'cline', self::URL, self::NAME );

		self::assertSame( 'notice', $config['kind'] );
		self::assertStringContainsString( 'Application Password', (string) $config['message'] );
	}

	public function test_vs_code_password_snippets_name_the_files_that_read_their_format(): void {
		foreach ( [ 'vscode-copilot', 'github-copilot' ] as $slug ) {
			$client = ClientCatalog::get( $slug );

			self::assertIsArray( $client, $slug );
			self::assertArrayHasKey( 'servers', (array) ConnectClientConfig::snippet_for( $slug, 'fixture-admin', 'pw' ), $slug );
			self::assertStringContainsString( 'MCP: Open User Configuration', (string) $client['config_path'], $slug );
			self::assertStringContainsString( '.vscode/mcp.json', (string) $client['config_path'], $slug );
			foreach ( $client['config_paths'] as $platform => $path ) {
				self::assertStringContainsString( 'MCP: Open User Configuration', (string) $path, $slug . ' ' . $platform );
			}
		}
	}

	public function test_cursor_password_snippets_link_to_their_own_entry(): void {
		foreach ( [ 'stdio', 'http' ] as $transport ) {
			$snippet = ConnectClientConfig::snippet_for( 'cursor', 'fixture-admin', 'xxxx xxxx', $transport );

			self::assertIsArray( $snippet );
			self::assertSame( 1, preg_match( '/^cursor:\/\/anysphere\.cursor-deeplink\/mcp\/install\?name=stonewright-example-test&config=([^&]+)$/', (string) $snippet['deeplink'], $match ), $transport );
			self::assertSame( $snippet['mcpServers'][ self::NAME ], json_decode( (string) base64_decode( $match[1], true ), true ), $transport );
		}
	}
}
