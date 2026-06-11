<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\ContextBootstrap;
use Stonewright\WpMcp\Context\ContextToken;

/**
 * @covers \Stonewright\WpMcp\Abilities\System\ContextBootstrap
 * @covers \Stonewright\WpMcp\Context\ContextBuilder
 * @covers \Stonewright\WpMcp\Context\ContextToken
 */
final class ContextBootstrapTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_caps'] = [ 'read' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_memory_enabled' => true,
			'stonewright_custom_instructions_enabled' => true,
			'stonewright_custom_instructions' => 'Always use native Elementor widgets.',
		];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['wpdb'] = $this->make_wpdb();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_transients'] = [];
	}

	public function test_returns_token_full_matching_skill_and_relevant_memory(): void {
		$result = ( new ContextBootstrap() )->execute(
			[
				'task'    => 'Build an Elementor hero using native widgets, not HTML.',
				'surface' => 'elementor',
				'intent'  => 'write',
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertStringStartsWith( 'swctx_', (string) $result['context_token'] );
		self::assertNotEmpty( $result['matched_skill_playbooks'] );
		self::assertSame( 'stonewright-elementor-v3-builder', $result['matched_skill_playbooks'][0]['slug'] );
		self::assertStringContainsString( 'Use native Elementor widgets', $result['matched_skill_playbooks'][0]['content'] );
		self::assertNotEmpty( $result['memory_entries'] );
		self::assertSame( 'no-html-widgets', $result['memory_entries'][0]['memory_key'] );
		self::assertContains( 'Call stonewright/widget-intent-resolve before choosing Elementor widgets.', $result['required_followups'] );
		self::assertContains( 'Before building design-derived pages, plan Elementor kit colors/typography first; if site-wide changes are approved, update the active kit before writing page elements.', $result['required_followups'] );
		self::assertSame( 'stonewright-context-bootstrap', $result['mcp_tool_naming']['examples']['stonewright/context-bootstrap'] );
		self::assertSame( 'playwright', $result['recommended_external_mcps'][0]['id'] );
		self::assertSame( [ '-y', '@playwright/mcp@latest', '--caps=testing,vision,devtools' ], $result['recommended_external_mcps'][0]['args'] );
		self::assertContains( 'Install external Playwright MCP before visual work: claude mcp add playwright -- npx -y @playwright/mcp@latest --caps=testing,vision,devtools', $result['recommended_external_mcps'][0]['setup_steps'] );
		self::assertContains( 'Restart the AI client after adding the MCP server so the tool list refreshes.', $result['recommended_external_mcps'][0]['setup_steps'] );
		self::assertContains( 'Verify a Playwright/browser tool is visible before the first Stonewright write.', $result['recommended_external_mcps'][0]['setup_steps'] );
		self::assertIsArray( $result['visual_quality_contract'] );
		self::assertTrue( $result['visual_quality_contract']['hard_stop_if_browser_unavailable'] );
		self::assertSame( 'before_first_write', $result['visual_quality_contract']['playwright_mcp_gate']['timing'] );
		self::assertContains( 'elementor', $result['visual_quality_contract']['playwright_mcp_gate']['required_surfaces'] );
		self::assertContains( 'figma', $result['visual_quality_contract']['playwright_mcp_gate']['task_keywords'] );
		self::assertContains( 'Extract measured tokens from the reference screenshot before writing: canvas size, section bounds, max widths, colors, typography, spacing, and asset crop bounds.', $result['visual_quality_contract']['required_steps'] );
		self::assertContains( 'Before any visual write, verify Playwright/browser MCP is connected; if not, install it, restart the client, and stop until the tool appears.', $result['visual_quality_contract']['required_steps'] );
		self::assertContains( 'Before the first Elementor write, create a global-style plan: reusable color/typography tokens, Elementor kit updates if approved, and page-local values that should remain local.', $result['visual_quality_contract']['required_steps'] );
		self::assertContains( 'Before full-page screenshots, scroll through the page or otherwise preload lazy-loaded media so missing assets are not mistaken for layout failures.', $result['visual_quality_contract']['required_steps'] );
		self::assertContains( 'Fail the implementation if document.documentElement.scrollWidth is greater than document.documentElement.clientWidth by more than 1px at desktop, tablet, or mobile viewport.', $result['visual_quality_contract']['required_steps'] );
		self::assertContains( 'Horizontal scrollbar or page content wider than viewport.', $result['visual_quality_contract']['failure_patterns'] );
		self::assertContains( 'WordPress page title or theme chrome visible when Elementor Canvas/no header/footer was requested.', $result['visual_quality_contract']['failure_patterns'] );
		self::assertContains( 'When a task needs browser testing, screenshots, or visual inspection, ensure the external Playwright MCP is installed and connected before implementation.', $result['required_followups'] );
		self::assertContains( 'If the external Playwright MCP is unavailable during a visual implementation task, stop before writing and tell the user the exact MCP setup command plus restart requirement.', $result['required_followups'] );
		self::assertContains( 'For design-derived backgrounds, create an asset selection plan and never use a full-page screenshot as a section background.', $result['required_followups'] );
		self::assertContains( 'Before declaring a visual task done, verify no horizontal overflow with document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1 at all requested breakpoints.', $result['required_followups'] );
		self::assertContains( 'If SVG uploads are blocked, do not create sandbox or mu-plugin workarounds without explicit user approval.', $result['required_followups'] );

		$verified = ContextToken::verify( (string) $result['context_token'], 'stonewright/elementor-add-heading' );
		self::assertTrue( $verified );
	}

	private function make_wpdb(): object {
		return new class() {
			public string $prefix = 'wp_';

			public function get_var( string $query ): string {
				return 'table_exists';
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				if ( str_contains( $query, 'stonewright_skills' ) ) {
					return [
						[
							'id'             => '1',
							'slug'           => 'stonewright-manual-elementor-playbook',
							'title'          => 'Manual Elementor Playbook',
							'description'    => 'Build Elementor pages using native widgets',
							'content'        => '# Manual Playbook',
							'enabled'        => '1',
							'enable_agentic' => '0',
							'enable_prompt'  => '1',
							'source'         => 'user',
						],
						[
							'id'             => '2',
							'slug'           => 'stonewright-elementor-v3-builder',
							'title'          => 'Elementor V3 Builder',
							'description'    => 'Build Elementor pages using native widgets',
							'content'        => '# Elementor V3 Builder' . "\n\n" . 'Use native Elementor widgets and configure Style and Advanced.',
							'enabled'        => '1',
							'enable_agentic' => '1',
							'enable_prompt'  => '1',
							'source'         => 'builtin',
						],
					];
				}

				return [
					[
						'id'          => '9',
						'type'        => 'feedback',
						'scope'       => 'elementor',
						'memory_key'  => 'no-html-widgets',
						'name'        => 'No HTML widgets',
						'value_json'  => wp_json_encode( 'Do not use Elementor HTML widgets unless explicitly requested.' ),
						'confidence'  => '1.0000',
						'created_at'  => '2026-05-25 00:00:00',
						'updated_at'  => '2026-05-25 00:00:00',
					],
				];
			}
		};
	}
}
