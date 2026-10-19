<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Connect;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ClientCatalog;
use Stonewright\WpMcp\Admin\Connect\ClientInstructions;

/**
 * Per-client OAuth setup data, following each client's published instructions.
 *
 * @covers \Stonewright\WpMcp\Admin\Connect\ClientInstructions
 */
final class ClientInstructionsTest extends TestCase {

	private const URL   = 'https://example.test/wp-json/mcp/stonewright-oauth';
	private const PLAIN = 'https://example.test/index.php?rest_route=/mcp/stonewright-oauth';
	private const NAME  = 'stonewright-example-test';

	private const DOCUMENTED = [ 'antigravity', 'antigravity-cli', 'chatgpt', 'claude-ai', 'claude-code', 'claude-desktop', 'codex', 'codex-cli', 'cursor', 'gemini-cli', 'generic-mcp', 'github-copilot', 'grok-build', 'vscode-copilot', 'windsurf', 'zed' ];

	protected function setUp(): void {
		ClientCatalog::reset_for_tests();
	}

	protected function tearDown(): void {
		ClientCatalog::reset_for_tests();
	}

	private static function guide( string $slug, string $url = self::URL ): array {
		return ClientInstructions::for_client( $slug, $url, self::NAME );
	}

	/** @return array<string, string> Snippet key => code. */
	private static function codes( array $guide ): array {
		return array_column( $guide['snippets'], 'code', 'key' );
	}

	/** @return array<string, string> Snippet key => location. */
	private static function locations( array $guide ): array {
		return array_column( $guide['snippets'], 'location', 'key' );
	}

	private static function text( array $guide ): string {
		return implode( "\n", array_merge( $guide['steps'], [ $guide['sign_in'] ], $guide['limits'] ) );
	}

	public function test_every_catalog_client_gets_a_complete_guide_sorted_by_label_with_the_catch_all_last(): void {
		$guides = ClientInstructions::all( self::URL, self::NAME );
		$slugs  = array_column( $guides, 'slug' );
		$labels = array_column( array_slice( $guides, 0, -1 ), 'label' );
		$sorted = $labels;
		usort( $sorted, 'strcasecmp' );

		self::assertEqualsCanonicalizing( ClientCatalog::slugs(), $slugs );
		self::assertCount( count( ClientCatalog::slugs() ), $slugs );
		self::assertSame( 'generic-mcp', end( $slugs ) );
		self::assertSame( $sorted, $labels );
		foreach ( $guides as $guide ) {
			$slug = $guide['slug'];
			self::assertSame( [ 'slug', 'label', 'documented', 'reach', 'tag', 'steps', 'snippets', 'links', 'sign_in', 'limits', 'step_parts', 'sign_in_parts', 'managed' ], array_keys( $guide ), $slug );
			self::assertNotSame( '', $guide['label'], $slug );
			self::assertNotSame( '', $guide['tag'], $slug );
			self::assertContains( $guide['reach'], [ ClientInstructions::REACH_LOCAL, ClientInstructions::REACH_HOSTED, ClientInstructions::REACH_PUBLIC ], $slug );
			self::assertNotSame( [], $guide['steps'], $slug );
			foreach ( $guide['snippets'] as $snippet ) {
				self::assertSame( [ 'key', 'title', 'format', 'location', 'code' ], array_keys( $snippet ), $slug );
				self::assertContains( $snippet['format'], [ 'shell', 'json', 'toml' ], $slug );
				self::assertMatchesRegularExpression( '/^[a-z]+$/', $snippet['key'], $slug );
				self::assertNotSame( '', $snippet['location'], $slug );
				self::assertStringNotContainsString( '<your-application-password>', $snippet['code'], $slug );
				self::assertStringNotContainsString( 'Authorization', $snippet['code'], $slug );
				if ( 'json' === $snippet['format'] ) {
					self::assertIsArray( json_decode( $snippet['code'], true ), $slug . ' ' . $snippet['key'] );
				}
			}
		}
	}

	/**
	 * Every value a person has to type or paste, per client, in reading order: the step number (0 for the
	 * sign-in line) is the key, the kinds the values.
	 *
	 * @return array<string, array<int, list<string>>>
	 */
	private static function expected_values(): array {
		return [
			'claude-ai'      => [ 2 => [ 'name', 'url' ] ],
			'claude-desktop' => [ 3 => [ 'name', 'url' ] ],
			'claude-code'    => [ 3 => [ 'name' ] ],
			'codex'          => [
				2 => [ 'name', 'url' ],
				3 => [ 'name', 'url' ],
			],
			'zed'            => [ 1 => [ 'url' ] ],
			'chatgpt'        => [ 2 => [ 'name' ], 3 => [ 'url' ] ],
			'gemini-cli'     => [ 0 => [ 'name' ] ],
			'generic-mcp'    => [ 1 => [ 'url' ] ],
			'cline'          => [ 2 => [ 'url' ] ],
			'roo-code'       => [ 2 => [ 'url' ] ],
		];
	}

	/** @return array<int, list<string>> Step number (0 is the sign-in line) => kinds of the values in it. */
	private static function value_kinds( array $guide ): array {
		$found = [];
		foreach ( array_merge( [ $guide['sign_in_parts'] ], $guide['step_parts'] ) as $index => $parts ) {
			$kinds = [];
			foreach ( $parts as $part ) {
				if ( ClientInstructions::PART_TEXT !== $part['kind'] ) {
					$kinds[] = $part['kind'];
				}
			}
			if ( [] !== $kinds ) {
				$found[ $index ] = $kinds;
			}
		}

		return $found;
	}

	public function test_step_parts_repeat_the_steps_and_mark_the_values_to_copy(): void {
		foreach ( array_merge( self::DOCUMENTED, [ 'cline', 'roo-code' ] ) as $slug ) {
			$guide = self::guide( $slug );

			self::assertCount( count( $guide['steps'] ), $guide['step_parts'], $slug );
			foreach ( $guide['step_parts'] as $index => $parts ) {
				self::assertSame( $guide['steps'][ $index ], implode( '', array_column( $parts, 'value' ) ), $slug . ' step ' . ( $index + 1 ) );
			}
			self::assertSame( $guide['sign_in'], implode( '', array_column( $guide['sign_in_parts'], 'value' ) ), $slug );
			foreach ( array_merge( [ $guide['sign_in_parts'] ], $guide['step_parts'] ) as $parts ) {
				foreach ( $parts as $part ) {
					self::assertSame( [ 'kind', 'value' ], array_keys( $part ), $slug );
					if ( ClientInstructions::PART_NAME === $part['kind'] ) {
						self::assertSame( self::NAME, $part['value'], $slug );
					} elseif ( ClientInstructions::PART_URL === $part['kind'] ) {
						self::assertSame( self::URL, $part['value'], $slug );
					} else {
						self::assertSame( ClientInstructions::PART_TEXT, $part['kind'], $slug );
						self::assertNotSame( '', $part['value'], $slug );
					}
				}
			}
			self::assertSame( self::expected_values()[ $slug ] ?? [], self::value_kinds( $guide ), $slug );
		}
	}

	public function test_a_translation_may_reorder_the_placeholders(): void {
		$step = new \ReflectionMethod( ClientInstructions::class, 'step' );
		$name = [ 'kind' => ClientInstructions::PART_NAME, 'value' => 'site-a' ];
		$url  = [ 'kind' => ClientInstructions::PART_URL, 'value' => 'https://example.test/mcp' ];

		$result = $step->invoke( null, 'URL %2$s, 100%% sure, name %1$s.', [ $name, $url ] );

		self::assertSame( 'URL https://example.test/mcp, 100% sure, name site-a.', $result['text'] );
		self::assertSame(
			[
				[ 'kind' => 'text', 'value' => 'URL ' ],
				$url,
				[ 'kind' => 'text', 'value' => ', 100% sure, name ' ],
				$name,
				[ 'kind' => 'text', 'value' => '.' ],
			],
			$result['parts']
		);
		self::assertSame( [ [ 'kind' => 'text', 'value' => 'No value here.' ] ], $step->invoke( null, 'No value here.', [] )['parts'] );
		self::assertSame( [ $url ], $step->invoke( null, '%s', [ $url ] )['parts'] );
	}

	public function test_only_clients_with_published_instructions_are_documented(): void {
		foreach ( self::DOCUMENTED as $slug ) {
			self::assertTrue( ClientInstructions::documented( $slug ), $slug );
			self::assertTrue( self::guide( $slug )['documented'], $slug );
		}
		foreach ( [ 'amazon-q', 'cline', 'kilo-code', 'opencode', 'roo-code' ] as $slug ) {
			$guide = self::guide( $slug );
			self::assertFalse( $guide['documented'], $slug );
			self::assertSame( [], $guide['snippets'], $slug );
			self::assertStringContainsString( 'Application Password', self::text( $guide ), $slug );
		}
	}

	public function test_aliases_resolve_to_their_canonical_guide(): void {
		self::assertSame( 'codex', self::guide( 'chatgpt-desktop' )['slug'] );
		self::assertSame( 'grok-build', self::guide( 'grok-cli' )['slug'] );
		self::assertSame( 'vscode-copilot', self::guide( 'vscode' )['slug'] );
		self::assertSame( 'claude-desktop', self::guide( 'claude' )['slug'] );
	}

	public function test_labels_cover_every_documented_client(): void {
		$labels = ClientInstructions::labels();

		self::assertSame( self::DOCUMENTED, array_keys( $labels ) );
		self::assertSame( 'Claude Code', $labels['claude-code'] );
		self::assertSame( 'VS Code with GitHub Copilot', $labels['vscode-copilot'] );
		self::assertSame( 'Windsurf (Devin Desktop)', $labels['windsurf'] );
		self::assertSame( 'Codex and the ChatGPT desktop app', $labels['codex'] );
		self::assertSame( $labels['claude-code'], self::guide( 'claude-code' )['label'] );
	}

	public function test_claude_code_uses_the_positional_http_form_and_its_login_command(): void {
		$guide = self::guide( 'claude-code' );
		$codes = self::codes( $guide );

		self::assertSame( 'claude mcp add --transport http stonewright-example-test https://example.test/wp-json/mcp/stonewright-oauth', $codes['add'] );
		self::assertSame( 'claude mcp login stonewright-example-test', $codes['login'] );
		self::assertSame( 'claude mcp login stonewright-example-test --no-browser', $codes['ssh'] );
		self::assertSame( [ 'mcpServers' => [ self::NAME => [ 'type' => 'http', 'url' => self::URL ] ] ], json_decode( $codes['project'], true ) );
		self::assertSame( '.mcp.json', self::locations( $guide )['project'] );
		self::assertStringNotContainsString( '--url', implode( "\n", $codes ) );
		self::assertStringContainsString( '--scope user', self::text( $guide ) );
		self::assertStringContainsString( '/mcp', $guide['sign_in'] );
		self::assertStringContainsString( 'HTTPS', self::text( $guide ) );
		self::assertSame( ClientInstructions::REACH_LOCAL, $guide['reach'] );
	}

	public function test_commands_quote_urls_that_shells_would_reinterpret(): void {
		$codes = self::codes( self::guide( 'claude-code', self::PLAIN ) );

		self::assertSame( 'claude mcp add --transport http stonewright-example-test "https://example.test/index.php?rest_route=/mcp/stonewright-oauth"', $codes['add'] );
		self::assertSame(
			"codex mcp add stonewright-example-test --url 'https://example.test/it'\\''s-\$HOME/mcp'",
			self::codes( self::guide( 'codex', "https://example.test/it's-\$HOME/mcp" ) )['add']
		);
	}

	public function test_codex_follows_the_shared_codex_configuration(): void {
		$guide = self::guide( 'codex' );
		$codes = self::codes( $guide );

		self::assertSame( 'codex mcp add stonewright-example-test --url https://example.test/wp-json/mcp/stonewright-oauth', $codes['add'] );
		self::assertSame( 'codex mcp login stonewright-example-test', $codes['login'] );
		self::assertSame( "[mcp_servers.stonewright-example-test]\nurl = \"https://example.test/wp-json/mcp/stonewright-oauth\"", $codes['config'] );
		self::assertStringContainsString( '~/.codex/config.toml', self::locations( $guide )['config'] );
		self::assertStringContainsString( 'Settings > MCP servers', self::text( $guide ) );
		self::assertStringContainsString( 'Authenticate', self::text( $guide ) );
		self::assertSame( $codes, self::codes( self::guide( 'codex-cli' ) ) );
	}

	public function test_toml_values_are_escaped(): void {
		$code = self::codes( self::guide( 'grok-build', 'https://example.test/a"b\\c' ) )['config'];

		self::assertStringContainsString( 'url = "https://example.test/a\\"b\\\\c"', $code );
	}

	public function test_cursor_has_the_documented_install_link_and_config_file(): void {
		$guide = self::guide( 'cursor' );
		$codes = self::codes( $guide );

		self::assertSame( [ 'mcpServers' => [ self::NAME => [ 'url' => self::URL ] ] ], json_decode( $codes['config'], true ) );
		self::assertStringContainsString( '~/.cursor/mcp.json', self::locations( $guide )['config'] );
		self::assertSame( 'agent mcp login stonewright-example-test', $codes['login'] );
		self::assertCount( 1, $guide['links'] );
		self::assertSame( [ 'cursor' ], $guide['links'][0]['schemes'] );
		self::assertSame(
			'cursor://anysphere.cursor-deeplink/mcp/install?name=stonewright-example-test&config=' . base64_encode( '{"url":"https://example.test/wp-json/mcp/stonewright-oauth"}' ),
			$guide['links'][0]['url']
		);
	}

	public function test_cursor_install_link_carries_the_base64_server_object(): void {
		$link = ClientInstructions::cursor_install_link( 'site a', [ 'url' => self::URL ] );

		self::assertStringStartsWith( 'cursor://anysphere.cursor-deeplink/mcp/install?name=site%20a&config=', $link );
		self::assertSame( 1, preg_match( '/[?&]config=([^&]+)$/', $link, $match ) );
		self::assertSame( [ 'url' => self::URL ], json_decode( (string) base64_decode( $match[1], true ), true ) );
		self::assertSame( 'cursor://anysphere.cursor-deeplink/mcp/install?name=stonewright-example-com&config=eyJ1cmwiOiJodHRwczovL2V4YW1wbGUuY29tL3dwLWpzb24vbWNwL3N0b25ld3JpZ2h0LW9hdXRoIn0=', ClientInstructions::cursor_install_link( 'stonewright-example-com', [ 'url' => 'https://example.com/wp-json/mcp/stonewright-oauth' ] ) );
	}

	public function test_vs_code_uses_the_current_configuration_location_and_install_link(): void {
		$guide     = self::guide( 'vscode-copilot' );
		$codes     = self::codes( $guide );
		$locations = self::locations( $guide );
		$server    = [ 'type' => 'http', 'url' => self::URL ];

		self::assertSame( [ 'mcpServers' => [ self::NAME => $server ] ], json_decode( $codes['config'], true ) );
		self::assertStringContainsString( '~/.copilot/mcp-config.json', $locations['config'] );
		self::assertStringContainsString( '.mcp.json', $locations['config'] );
		self::assertSame( [ 'servers' => [ self::NAME => $server ] ], json_decode( $codes['legacy'], true ) );
		self::assertStringContainsString( '.vscode/mcp.json', $locations['legacy'] );
		self::assertSame( 'code --add-mcp "{\"name\":\"stonewright-example-test\",\"type\":\"http\",\"url\":\"https://example.test/wp-json/mcp/stonewright-oauth\"}"', $codes['cli'] );
		self::assertSame(
			'vscode:mcp/install?%7B%22name%22%3A%22stonewright-example-com%22%2C%22type%22%3A%22http%22%2C%22url%22%3A%22https%3A%2F%2Fexample.com%2Fwp-json%2Fmcp%2Fstonewright-oauth%22%7D',
			ClientInstructions::vscode_install_link( 'stonewright-example-com', 'https://example.com/wp-json/mcp/stonewright-oauth' )
		);
		self::assertSame( [ 'vscode', 'vscode-insiders' ], array_merge( ...array_column( $guide['links'], 'schemes' ) ) );
		self::assertStringStartsWith( 'vscode-insiders:mcp/install?', $guide['links'][1]['url'] );
		self::assertStringContainsString( 'browser', $guide['sign_in'] );
		self::assertSame( $codes, self::codes( self::guide( 'github-copilot' ) ) );
	}

	public function test_hosted_clients_are_set_up_in_their_apps_and_cannot_reach_local_sites(): void {
		foreach ( [ 'claude-ai', 'claude-desktop', 'chatgpt' ] as $slug ) {
			$guide = self::guide( $slug );
			self::assertSame( ClientInstructions::REACH_HOSTED, $guide['reach'], $slug );
			self::assertSame( [], $guide['snippets'], $slug );
			self::assertStringContainsString( self::URL, self::text( $guide ), $slug );
			self::assertMatchesRegularExpression( '/public internet|public HTTPS/', self::text( $guide ), $slug );
		}
		self::assertStringContainsString( 'Customize > Connectors', self::text( self::guide( 'claude-ai' ) ) );
		self::assertStringContainsString( 'Connect', self::guide( 'claude-ai' )['sign_in'] );
		self::assertSame( 'https://claude.ai/customize/connectors', self::guide( 'claude-desktop' )['links'][0]['url'] );
		self::assertStringContainsString( 'claude_desktop_config.json', self::text( self::guide( 'claude-desktop' ) ) );
		self::assertStringContainsString( 'Add custom MCP server', self::text( self::guide( 'chatgpt' ) ) );
		self::assertStringContainsString( 'first', self::guide( 'chatgpt' )['sign_in'] );
	}

	public function test_gemini_cli_needs_a_public_https_site(): void {
		$guide = self::guide( 'gemini-cli' );
		$codes = self::codes( $guide );

		self::assertSame( ClientInstructions::REACH_PUBLIC, $guide['reach'] );
		self::assertSame( 'gemini mcp add --transport http -s user stonewright-example-test https://example.test/wp-json/mcp/stonewright-oauth', $codes['add'] );
		self::assertSame( [ 'mcpServers' => [ self::NAME => [ 'httpUrl' => self::URL ] ] ], json_decode( $codes['config'], true ) );
		self::assertStringContainsString( '~/.gemini/settings.json', self::locations( $guide )['config'] );
		self::assertStringContainsString( '/mcp auth stonewright-example-test', $guide['sign_in'] );
		self::assertStringContainsString( 'HTTPS', self::text( $guide ) );
		self::assertStringContainsString( 'public', self::text( $guide ) );
	}

	public function test_editor_and_cli_clients_use_their_documented_keys_and_files(): void {
		$windsurf = self::guide( 'windsurf' );
		self::assertSame( 'devin mcp add -s user stonewright-example-test https://example.test/wp-json/mcp/stonewright-oauth', self::codes( $windsurf )['add'] );
		self::assertSame( 'devin mcp login stonewright-example-test', self::codes( $windsurf )['login'] );
		self::assertSame( [ 'mcpServers' => [ self::NAME => [ 'url' => self::URL, 'transport' => 'http' ] ] ], json_decode( self::codes( $windsurf )['config'], true ) );
		self::assertStringContainsString( '~/.config/devin/mcp_config.json', self::locations( $windsurf )['config'] );

		$zed = self::guide( 'zed' );
		self::assertSame( [ 'context_servers' => [ self::NAME => [ 'url' => self::URL ] ] ], json_decode( self::codes( $zed )['config'], true ) );
		self::assertStringContainsString( 'Authenticate', $zed['sign_in'] );

		foreach ( [ 'antigravity', 'antigravity-cli' ] as $slug ) {
			$antigravity = self::guide( $slug );
			self::assertSame( [ 'mcpServers' => [ self::NAME => [ 'serverUrl' => self::URL ] ] ], json_decode( self::codes( $antigravity )['config'], true ), $slug );
			self::assertStringContainsString( '~/.gemini/config/mcp_config.json', self::locations( $antigravity )['config'], $slug );
			self::assertStringContainsString( 'Authenticate', $antigravity['sign_in'], $slug );
		}

		$grok = self::guide( 'grok-build' );
		self::assertSame( 'grok mcp add --transport http stonewright-example-test https://example.test/wp-json/mcp/stonewright-oauth', self::codes( $grok )['add'] );
		self::assertSame( 'grok mcp doctor stonewright-example-test', self::codes( $grok )['doctor'] );
		self::assertSame( "[mcp_servers.stonewright-example-test]\nurl = \"https://example.test/wp-json/mcp/stonewright-oauth\"\nenabled = true", self::codes( $grok )['config'] );
		self::assertStringContainsString( '/mcps', $grok['sign_in'] );
	}

	public function test_generic_clients_only_need_the_url(): void {
		$guide = self::guide( 'generic-mcp' );

		self::assertSame( [], $guide['snippets'] );
		self::assertStringContainsString( self::URL, self::text( $guide ) );
		self::assertStringContainsString( 'Streamable HTTP', self::text( $guide ) );
	}

	/** The chips of a managed section in reading order: who, admin steps, local lines, note. */
	private static function managed_kinds( array $managed ): array {
		$found = [];
		foreach ( array_merge( [ $managed['who'] ], $managed['admin'], array_column( array_filter( $managed['local'], static fn ( array $item ): bool => 'text' === $item['type'] ), 'step' ), [ $managed['note'] ?? [ 'parts' => [] ] ] ) as $step ) {
			$found[] = array_values( array_map( static fn ( array $part ): string => $part['kind'], array_filter( $step['parts'], static fn ( array $part ): bool => 'text' !== $part['kind'] ) ) );
		}

		return array_values( array_filter( $found ) );
	}

	public function test_clients_without_documented_organization_controls_have_no_managed_section(): void {
		foreach ( [ 'zed', 'grok-build' ] as $slug ) {
			self::assertNull( self::guide( $slug )['managed'], $slug );
		}
		foreach ( array_diff( self::DOCUMENTED, [ 'zed', 'grok-build' ] ) as $slug ) {
			self::assertIsArray( self::guide( $slug )['managed'], $slug );
		}
		self::assertIsArray( self::guide( 'cline' )['managed'] );
	}

	public function test_managed_sections_name_the_plans_and_map_placeholders_to_values(): void {
		$expected = [
			'claude-ai'       => [ 'Team, Enterprise', [ [ 'url' ] ] ],
			'claude-desktop'  => [ 'Team, Enterprise', [ [ 'url' ] ] ],
			'claude-code'     => [ 'Team, Enterprise', [ [ 'url' ] ] ],
			'chatgpt'         => [ 'Business, Enterprise, Edu', [ [ 'name', 'url' ] ] ],
			'codex'           => [ 'Business, Enterprise', [ [ 'name', 'url' ] ] ],
			'codex-cli'       => [ 'Business, Enterprise', [ [ 'name', 'url' ] ] ],
			'cursor'          => [ 'Enterprise', [ [ 'name' ] ] ],
			'vscode-copilot'  => [ 'Copilot Business, Enterprise', [ [ 'url' ] ] ],
			'github-copilot'  => [ 'Copilot Business, Enterprise', [ [ 'url' ] ] ],
			'windsurf'        => [ 'Teams, Enterprise', [ [ 'name', 'url' ] ] ],
			'gemini-cli'      => [ 'Enterprise', [ [ 'name', 'url' ] ] ],
			'antigravity'     => [ 'Gemini Enterprise', [ [ 'url' ] ] ],
			'antigravity-cli' => [ 'Gemini Enterprise', [ [ 'url' ] ] ],
			'generic-mcp'     => [ '', [ [ 'url' ] ] ],
			'cline'           => [ '', [ [ 'url' ] ] ],
		];
		foreach ( $expected as $slug => [ $plans, $kinds ] ) {
			$managed = self::guide( $slug )['managed'];

			self::assertSame( $plans, $managed['plans'], $slug );
			self::assertSame( $kinds, self::managed_kinds( $managed ), $slug );
			foreach ( array_merge( [ $managed['who'] ], $managed['admin'] ) as $step ) {
				self::assertNotSame( '', $step['text'], $slug );
				self::assertSame( $step['text'], implode( '', array_column( $step['parts'], 'value' ) ), $slug );
				foreach ( $step['parts'] as $part ) {
					if ( ClientInstructions::PART_NAME === $part['kind'] ) {
						self::assertSame( self::NAME, $part['value'], $slug );
					} elseif ( ClientInstructions::PART_URL === $part['kind'] ) {
						self::assertSame( self::URL, $part['value'], $slug );
					}
				}
			}
		}
	}

	public function test_only_the_claude_connector_guides_link_to_the_organization_settings(): void {
		foreach ( ClientInstructions::all( self::URL, self::NAME ) as $guide ) {
			$link = $guide['managed']['admin_link'] ?? null;
			if ( in_array( $guide['slug'], [ 'claude-ai', 'claude-desktop' ], true ) ) {
				self::assertSame( [ 'https' ], $link['schemes'], $guide['slug'] );
				self::assertSame( 'https://claude.ai/admin-settings/connectors', $link['url'], $guide['slug'] );
				self::assertSame( 'Open organization connector settings', $link['label'], $guide['slug'] );
			} else {
				self::assertNull( $link, $guide['slug'] );
			}
		}
	}

	public function test_claude_desktop_can_switch_to_an_application_password_and_add_claude_code_locally(): void {
		$local = self::guide( 'claude-desktop' )['managed']['local'];

		self::assertSame( [ 'text', 'switch', 'text', 'snippet' ], array_column( $local, 'type' ) );
		self::assertSame( 'claude-desktop', $local[1]['slug'] );
		self::assertSame( 'Use Application Password instead', $local[1]['label'] );
		self::assertSame( self::codes( self::guide( 'claude-code' ) )['add'], $local[3]['snippet']['code'] );
		self::assertSame( 'shell', $local[3]['snippet']['format'] );
		self::assertSame( [ 'text' ], array_column( self::guide( 'claude-ai' )['managed']['local'], 'type' ) );
		self::assertStringStartsWith( 'Admins can turn off local MCP servers', self::guide( 'claude-desktop' )['managed']['note']['text'] );
	}

	public function test_copilot_has_no_local_route_only_a_note(): void {
		foreach ( [ 'vscode-copilot', 'github-copilot' ] as $slug ) {
			$managed = self::guide( $slug )['managed'];

			self::assertSame( [], $managed['local'], $slug );
			self::assertSame( 'There is no local workaround while the policy is off.', $managed['note']['text'], $slug );
		}
	}

	public function test_the_admin_message_names_the_client_the_server_and_where_to_add_it(): void {
		$message = self::guide( 'windsurf' )['managed']['message'];

		self::assertStringStartsWith( "Hello,\n\nPlease add the Stonewright MCP server for our WordPress site in Windsurf (Devin Desktop).\n\nName: stonewright-example-test\nServer URL: " . self::URL . "\nSign-in: OAuth.", $message );
		self::assertStringContainsString( "\nWhere: Settings > Enterprise > Devin Desktop > MCP allowlist (the Server ID must match the name)\n\nThank you!", $message );
		self::assertStringNotContainsString( 'public internet', $message );
		self::assertStringEndsWith( 'Thank you!', $message );

		foreach ( [ 'claude-ai', 'claude-desktop', 'chatgpt' ] as $slug ) {
			$hosted = self::guide( $slug )['managed']['message'];
			self::assertStringContainsString( self::URL, $hosted, $slug );
			self::assertStringContainsString( self::NAME, $hosted, $slug );
			self::assertMatchesRegularExpression( '/\n.+ connects from its own cloud, so this site must be reachable from the public internet\.\n\nThank you!$/', $hosted, $slug );
		}
		foreach ( ClientInstructions::all( self::URL, self::NAME ) as $guide ) {
			if ( null !== $guide['managed'] ) {
				self::assertStringContainsString( self::URL, $guide['managed']['message'], $guide['slug'] );
				self::assertStringContainsString( 'Name: ' . self::NAME, $guide['managed']['message'], $guide['slug'] );
				self::assertStringContainsString( 'in ' . $guide['label'] . '.', $guide['managed']['message'], $guide['slug'] );
			}
		}
	}

	public function test_server_names_are_reduced_to_portable_characters(): void {
		self::assertSame( 'stonewright-site-a', ClientInstructions::server_name( 'Stonewright Site_A!' ) );
		self::assertSame( 'stonewright', ClientInstructions::server_name( '***' ) );
		self::assertStringStartsWith( 'claude mcp add --transport http stonewright-site-a ', self::codes( ClientInstructions::for_client( 'claude-code', self::URL, 'Stonewright Site_A!' ) )['add'] );
	}
}
