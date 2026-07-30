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
		unset( $GLOBALS['stonewright_test_memory_rows'] );
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
		unset( $GLOBALS['stonewright_test_memory_rows'] );
	}

	public function test_description_marks_task_start_as_canonical(): void {
		$description = ( new ContextBootstrap() )->description();

		self::assertStringContainsString( 'Compatibility full-context bootstrap', $description );
		self::assertStringContainsString( 'stonewright/task-start as the canonical first call', $description );
		self::assertStringNotContainsString( 'MUST be called at the start', $description );
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
		self::assertArrayNotHasKey( 'value', $result['memory_entries'][0] );
		self::assertSame( 'stonewright/memory-get', $result['memory_entries'][0]['body_tool'] );
		self::assertContains( 'Call stonewright/design-native-plan with normalized DesignEvidence before choosing or writing Elementor widgets.', $result['required_followups'] );
		self::assertContains( 'Before building design-derived pages, plan Elementor kit colors/typography first; if site-wide changes are approved, update the active kit before writing page elements.', $result['required_followups'] );
		self::assertSame( 'stonewright-context-bootstrap', $result['mcp_tool_naming']['examples']['stonewright/context-bootstrap'] );
		self::assertSame( 'stonewright-tool-profile', $result['mcp_tool_naming']['examples']['stonewright/tool-profile'] );
		self::assertArrayHasKey( 'tool_profile_hint', $result );
		self::assertSame( 'elementor-design', $result['tool_profile_hint']['profile'] );
		self::assertContains( 'stonewright/tool-profile', $result['tool_profile_hint']['call_after_bootstrap'] );
		self::assertContains( 'Use fast_path.tool_profile from stonewright/task-start before making a separate stonewright/tool-profile call; call tool-profile only to switch or verify a compact profile.', $result['required_followups'] );
		self::assertSame( 'playwright', $result['recommended_external_mcps'][0]['id'] );
		self::assertSame( [ '-y', '@playwright/mcp@latest', '--caps=testing,vision,devtools' ], $result['recommended_external_mcps'][0]['args'] );
		self::assertContains( 'Install external Playwright MCP before visual work: claude mcp add playwright -- npx -y @playwright/mcp@latest --caps=testing,vision,devtools', $result['recommended_external_mcps'][0]['setup_steps'] );
		self::assertContains( 'Restart the AI client after adding the MCP server so the tool list refreshes.', $result['recommended_external_mcps'][0]['setup_steps'] );
		self::assertContains( 'Verify a Playwright/browser tool is visible before the first Stonewright write.', $result['recommended_external_mcps'][0]['setup_steps'] );
		self::assertIsArray( $result['visual_quality_contract'] );
		self::assertTrue( $result['visual_quality_contract']['hard_stop_if_browser_unavailable'] );
		self::assertSame( 2, $result['visual_quality_contract']['section_batching']['max_sections_per_pass'] );
		self::assertSame( 1, $result['visual_quality_contract']['section_batching']['preferred_sections_per_pass'] );
		self::assertTrue( $result['visual_quality_contract']['section_batching']['auto_continue_when_batch_passes'] );
		self::assertContains( 'figma', $result['visual_quality_contract']['section_batching']['applies_to_sources'] );
		self::assertContains( 'image', $result['visual_quality_contract']['section_batching']['applies_to_sources'] );
		self::assertContains( 'prompt', $result['visual_quality_contract']['section_batching']['applies_to_sources'] );
		self::assertContains( 'design_system', $result['visual_quality_contract']['section_batching']['applies_to_sources'] );
		self::assertSame( [ 'desktop', 'tablet', 'mobile' ], $result['visual_quality_contract']['section_batching']['breakpoints']['elementor'] );
		self::assertSame( 'before_first_write', $result['visual_quality_contract']['playwright_mcp_gate']['timing'] );
		self::assertContains( 'elementor', $result['visual_quality_contract']['playwright_mcp_gate']['required_surfaces'] );
		self::assertContains( 'figma', $result['visual_quality_contract']['playwright_mcp_gate']['task_keywords'] );
		self::assertIsArray( $result['visual_build_gate'] );
		self::assertArrayHasKey( 'design_implementation_contract', $result );
		self::assertSame( 'design_evidence', $result['design_implementation_contract']['sequence'][0] );
		self::assertSame( 'loop-grid', $result['design_implementation_contract']['native_widget_map']['dynamic_cards'] );
		self::assertContains( 'invented_border_radius_shadow_filter', $result['design_implementation_contract']['hard_failures'] );
		self::assertTrue( $result['visual_build_gate']['blocks_completion_without_evidence'] );
		self::assertContains( 'Call stonewright-task-start before Figma, browser, or write tools; context-bootstrap and workflow-preflight are compatibility paths only.', $result['visual_build_gate']['required_before_discovery'] );
		self::assertContains( 'figma_token_table', $result['visual_build_gate']['evidence_required_before_first_write'] );
		self::assertContains( 'existing_media_asset_audit', $result['visual_build_gate']['evidence_required_before_first_write'] );
		self::assertContains( 'section_implementation_plan', $result['visual_build_gate']['evidence_required_before_first_write'] );
		self::assertContains( 'section_reference_screenshots', $result['visual_build_gate']['evidence_required_before_first_write'] );
		self::assertContains( 'section_batch_plan', $result['visual_build_gate']['evidence_required_before_first_write'] );
		self::assertContains( 'desktop_screenshot_diff', $result['visual_build_gate']['evidence_required_before_completion'] );
		self::assertContains( 'tablet_screenshot_diff', $result['visual_build_gate']['evidence_required_before_completion'] );
		self::assertContains( 'mobile_screenshot_diff', $result['visual_build_gate']['evidence_required_before_completion'] );
		self::assertContains( 'logged_out_viewport_checks', $result['visual_build_gate']['evidence_required_before_completion'] );
		self::assertSame( 'reference_screenshots', $result['visual_build_gate']['source_authority']['primary'] );
		self::assertContains( 'figma_layer_structure', $result['visual_build_gate']['source_authority']['not_authoritative'] );
		self::assertContains( 'Do not copy the Figma layer tree as the WordPress or Elementor tree when the visual screenshot implies a different, cleaner structure.', $result['visual_build_gate']['completion_stop_conditions'] );
		self::assertContains( 'Do not implement more than two visual page sections in one write-and-verify batch.', $result['visual_build_gate']['completion_stop_conditions'] );
		self::assertContains( 'Do not declare pixel-perfect or responsive unless logged-out desktop, tablet, and mobile viewport checks pass without theme/admin chrome contamination.', $result['visual_build_gate']['completion_stop_conditions'] );
		self::assertArrayHasKey( 'figma_token_table', $result['visual_build_gate']['evidence_template'] );
		self::assertContains( 'section_id', $result['visual_build_gate']['evidence_template']['figma_token_table'] );
		self::assertArrayHasKey( 'section_reference_screenshots', $result['visual_build_gate']['evidence_template'] );
		self::assertContains( 'reference_image', $result['visual_build_gate']['evidence_template']['section_reference_screenshots'] );
		self::assertArrayHasKey( 'section_batch_plan', $result['visual_build_gate']['evidence_template'] );
		self::assertContains( 'section_ids', $result['visual_build_gate']['evidence_template']['section_batch_plan'] );
		self::assertContains( 'max_sections', $result['visual_build_gate']['evidence_template']['section_batch_plan'] );
		self::assertArrayHasKey( 'screenshot_diff', $result['visual_build_gate']['evidence_template'] );
		self::assertContains( 'delta_px', $result['visual_build_gate']['evidence_template']['screenshot_diff'] );
		self::assertContains( 'Extract measured tokens from the reference screenshot before writing: canvas size, section bounds, max widths, colors, typography, spacing, and asset crop bounds.', $result['visual_quality_contract']['required_steps'] );
		self::assertContains( 'Treat the Figma layer tree as extraction context, not implementation authority; build the WordPress structure that best matches the visual reference images.', $result['visual_quality_contract']['required_steps'] );
		self::assertContains( 'For long designs, capture section reference screenshots and compare section-by-section before attempting full-page signoff.', $result['visual_quality_contract']['required_steps'] );
		self::assertContains( 'Before uploading assets, audit existing WordPress media by filename, alt text, dimensions, and likely source layer so already-downloaded assets are reused.', $result['visual_quality_contract']['required_steps'] );
		self::assertContains( 'Before the first write, produce a section-by-section plan mapping Figma nodes to native Elementor widgets, containers, breakpoints, assets, and any approved CSS classes.', $result['visual_quality_contract']['required_steps'] );
		self::assertContains( 'Implement visual pages in batches of one section at a time, or two sections only when they are simple and tightly coupled.', $result['visual_quality_contract']['required_steps'] );
		self::assertContains( 'After each section batch, verify desktop, tablet, and mobile breakpoints before starting the next batch.', $result['visual_quality_contract']['required_steps'] );
		self::assertContains( 'Auto-continue to the next section batch when screenshots, overflow checks, and diagnostics pass; do not wait for user approval between batches.', $result['visual_quality_contract']['required_steps'] );
		self::assertContains( 'Before any visual write, verify Playwright/browser MCP is connected; if not, install it, restart the client, and stop until the tool appears.', $result['visual_quality_contract']['required_steps'] );
		self::assertContains( 'Before the first Elementor write, create a global-style plan: reusable color/typography tokens, Elementor kit updates if approved, and page-local values that should remain local.', $result['visual_quality_contract']['required_steps'] );
		self::assertContains( 'Before full-page screenshots, scroll through the page or otherwise preload lazy-loaded media so missing assets are not mistaken for layout failures.', $result['visual_quality_contract']['required_steps'] );
		self::assertContains( 'Fail the implementation if document.documentElement.scrollWidth is greater than document.documentElement.clientWidth by more than 1px at desktop, tablet, or mobile viewport.', $result['visual_quality_contract']['required_steps'] );
		self::assertContains( 'Verify the public logged-out page at desktop, tablet, and mobile sizes; admin bars, editor chrome, and authenticated-only UI do not count as responsive proof.', $result['visual_quality_contract']['required_steps'] );
		self::assertContains( 'Horizontal scrollbar or page content wider than viewport.', $result['visual_quality_contract']['failure_patterns'] );
		self::assertContains( 'WordPress page title or theme chrome visible when Elementor Canvas/no header/footer was requested.', $result['visual_quality_contract']['failure_patterns'] );
		self::assertContains( 'Declaring pixel-perfect without a reference-to-live screenshot delta list for every requested breakpoint.', $result['visual_quality_contract']['failure_patterns'] );
		self::assertContains( 'Mirroring broken design-tool grouping instead of reproducing the visible layout in the reference screenshot.', $result['visual_quality_contract']['failure_patterns'] );
		self::assertContains( 'When a task needs browser testing, screenshots, or visual inspection, ensure the external Playwright MCP is installed and connected before implementation.', $result['required_followups'] );
		self::assertContains( 'If the external Playwright MCP is unavailable during a visual implementation task, stop before writing and tell the user the exact MCP setup command plus restart requirement.', $result['required_followups'] );
		self::assertContains( 'For design-derived backgrounds, create an asset selection plan and never use a full-page screenshot as a section background.', $result['required_followups'] );
		self::assertContains( 'Before declaring a visual task done, verify no horizontal overflow with document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1 at all requested breakpoints.', $result['required_followups'] );
		self::assertContains( 'For pixel-perfect tasks, report the visual_build_gate evidence before completion: token table, asset audit, section plan, screenshot deltas, and logged-out viewport checks.', $result['required_followups'] );
		self::assertContains( 'If SVG uploads are blocked, do not create sandbox or mu-plugin workarounds without explicit user approval.', $result['required_followups'] );

		$verified = ContextToken::verify( (string) $result['context_token'], 'stonewright/elementor-add-heading' );
		self::assertTrue( $verified );
		self::assertSame( 'plugin', $result['target_context']['backend'] );
		self::assertSame( 'plugin-site', $result['target_context']['memory_backend'] );
		self::assertSame( ContextToken::site_fingerprint(), $result['target_context']['site_fingerprint'] );
		self::assertSame( $result['context_token'], $result['target_context']['context_token'] );
	}

	public function test_task_start_excludes_stale_and_unrelated_memory_bodies(): void {
		$GLOBALS['stonewright_test_memory_rows'] = [
			[
				'id' => '31', 'type' => 'feedback', 'scope' => 'elementor', 'topic' => 'button links',
				'memory_key' => 'button-links', 'name' => 'Button links', 'value_json' => wp_json_encode( 'Buttons need URLs.' ),
				'confidence' => '1.0', 'status' => 'active', 'precedence' => '10', 'expires_at' => '', 'updated_at' => '2026-07-14 00:00:00', 'created_at' => '2026-07-14 00:00:00',
			],
			[
				'id' => '32', 'type' => 'feedback', 'scope' => 'elementor', 'topic' => 'button links',
				'memory_key' => 'old-button-links', 'name' => 'Old buttons', 'value_json' => wp_json_encode( 'Use placeholders.' ),
				'confidence' => '1.0', 'status' => 'stale', 'precedence' => '999', 'expires_at' => '', 'updated_at' => '2026-07-14 00:00:00', 'created_at' => '2026-07-14 00:00:00',
			],
			[
				'id' => '33', 'type' => 'project', 'scope' => 'woocommerce', 'topic' => 'shipping',
				'memory_key' => 'shipping', 'name' => 'Shipping', 'value_json' => wp_json_encode( 'Unrelated body.' ),
				'confidence' => '1.0', 'status' => 'active', 'precedence' => '0', 'expires_at' => '', 'updated_at' => '2026-07-14 00:00:00', 'created_at' => '2026-07-14 00:00:00',
			],
		];

		$result = ( new ContextBootstrap() )->execute( [ 'task' => 'Fix Elementor button links', 'surface' => 'elementor', 'intent' => 'read' ] );

		self::assertIsArray( $result );
		self::assertSame( [ 'button-links' ], array_column( $result['memory_entries'], 'memory_key' ) );
		self::assertArrayNotHasKey( 'value', $result['memory_entries'][0] );
	}

	public function test_task_start_activates_only_compact_top_three_expertise_refs(): void {
		$ability = new ContextBootstrap();
		$result  = $ability->execute(
			[
				'task'         => 'Update a WordPress post, media item, and taxonomy assignment.',
				'surface'      => 'wordpress',
				'intent'       => 'write',
				'responseMode' => 'compact',
			]
		);

		self::assertIsArray( $result );
		self::assertLessThanOrEqual( 3, count( $result['expertise_packs'] ) );
		self::assertContains( 'wordpress-core', array_column( $result['expertise_packs'], 'id' ) );
		self::assertLessThan( 450, (int) ceil( strlen( wp_json_encode( $result['expertise_packs'] ) ?: '' ) / 4 ) );
		self::assertArrayNotHasKey( 'workflow', $result['expertise_packs'][0] );

		$first  = $result['expertise_packs'][0];
		$cached = $ability->execute(
			[
				'task'         => 'Update a WordPress post, media item, and taxonomy assignment.',
				'surface'      => 'wordpress',
				'intent'       => 'write',
				'responseMode' => 'compact',
				'knownHashes'  => [ 'expertise' => [ $first['id'] => $first['hash'] ] ],
			]
		);
		self::assertIsArray( $cached );
		$cached_by_id = array_column( $cached['expertise_packs'], null, 'id' );
		self::assertTrue( $cached_by_id[ $first['id'] ]['cached'] );
		self::assertArrayNotHasKey( 'trigger', $cached_by_id[ $first['id'] ] );
	}

	public function test_compact_mode_returns_hashes_and_delta_refs(): void {
		$result = ( new ContextBootstrap() )->execute(
			[
				'task'         => 'Build an Elementor hero using native widgets, not HTML.',
				'surface'      => 'elementor',
				'intent'       => 'write',
				'responseMode' => 'compact',
				'knownHashes'  => [
					'instructions' => 'stale',
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'compact', $result['response_mode'] );
		self::assertArrayHasKey( 'payload_hashes', $result );
		self::assertArrayHasKey( 'changed_keys', $result );
		self::assertContains( 'instructions', $result['changed_keys'] );
		self::assertArrayHasKey( 'instructions', $result['deltas'] );
		self::assertArrayHasKey( 'hash', $result['deltas']['instructions'] );
		self::assertNotEmpty( $result['matched_skill_playbooks'] );
		self::assertArrayHasKey( 'content_hash', $result['matched_skill_playbooks'][0] );
		self::assertArrayNotHasKey( 'content', $result['matched_skill_playbooks'][0] );
		self::assertNotEmpty( $result['memory_entries'] );
		self::assertArrayHasKey( 'value_hash', $result['memory_entries'][0] );
		self::assertArrayNotHasKey( 'value', $result['memory_entries'][0] );
		self::assertArrayHasKey( 'hash', $result['design_implementation_contract'] );
		self::assertArrayNotHasKey( 'sequence', $result['design_implementation_contract'] );
	}

	public function test_compact_first_call_stays_under_task_start_budgets_without_hash_deltas(): void {
		$result = ( new ContextBootstrap() )->execute(
			[
				'task'         => 'Update an existing post title and excerpt.',
				'surface'      => 'wordpress',
				'intent'       => 'write',
				'responseMode' => 'compact',
			]
		);

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'payload_hashes', $result );
		self::assertLessThan( 2800, strlen( wp_json_encode( $result ) ?: '' ) );
	}

	public function test_returns_specialization_guidance_for_field_and_catalog_tasks(): void {
		$result = ( new ContextBootstrap() )->execute(
			[
				'task'    => 'Create ACF fields and WooCommerce product variations.',
				'surface' => 'wordpress',
				'intent'  => 'write',
			]
		);

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'specializations', $result );
		$ids = array_column( $result['specializations'], 'id' );
		self::assertContains( 'acf', $ids );
		self::assertContains( 'woocommerce', $ids );
		self::assertContains( 'For ACF, ACPT, Meta Box, ASE, Pods, WooCommerce, or custom field work, call stonewright/task-start and follow the returned specialization guidance before writing.', $result['required_followups'] );
	}

	public function test_returns_exempt_visual_contract_for_non_visual_tasks(): void {
		$result = ( new ContextBootstrap() )->execute(
			[
				'task'    => 'Run wp cli command to list options.',
				'surface' => 'wp-cli',
				'intent'  => 'read',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'exempt', $result['visual_quality_contract']['status'] );
		self::assertSame( 'Non-visual task', $result['visual_quality_contract']['reason'] );
		self::assertSame( 'exempt', $result['visual_build_gate']['status'] );
		self::assertSame( [], $result['recommended_external_mcps'] );
		self::assertStringNotContainsString( 'visual_build_gate', $result['instructions'] );
		self::assertStringNotContainsString( 'reference token table', $result['instructions'] );
		self::assertNotContains( 'For pixel-perfect tasks, report the visual_build_gate evidence before completion: token table, asset audit, section plan, screenshot deltas, and logged-out viewport checks.', $result['required_followups'] );
	}

	public function test_elementor_discovery_without_visual_reference_uses_compact_visual_stub(): void {
		$result = ( new ContextBootstrap() )->execute(
			[
				'task'    => 'List Elementor widgets and summarize their schema availability.',
				'surface' => 'elementor',
				'intent'  => 'read',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'exempt', $result['visual_quality_contract']['status'] );
		self::assertSame( 'exempt', $result['visual_build_gate']['status'] );
		self::assertContains( 'Call stonewright/design-native-plan with normalized DesignEvidence before choosing or writing Elementor widgets.', $result['required_followups'] );
		self::assertNotContains( 'Before declaring a visual task done, verify no horizontal overflow with document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1 at all requested breakpoints.', $result['required_followups'] );
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
				if ( isset( $GLOBALS['stonewright_test_memory_rows'] ) && is_array( $GLOBALS['stonewright_test_memory_rows'] ) ) {
					return $GLOBALS['stonewright_test_memory_rows'];
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
