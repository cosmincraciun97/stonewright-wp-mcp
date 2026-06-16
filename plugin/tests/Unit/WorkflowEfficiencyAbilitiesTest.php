<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\ImplementationContract;
use Stonewright\WpMcp\Abilities\ElementorV3\ApplyBundle;
use Stonewright\WpMcp\Abilities\ElementorV3\CapabilitiesSummary;
use Stonewright\WpMcp\Abilities\ElementorV3\GetWidgetSchema;
use Stonewright\WpMcp\Abilities\Media\UploadMediaBatch;
use Stonewright\WpMcp\Abilities\System\WorkflowPreflight;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Abilities\System\WorkflowPreflight
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\CapabilitiesSummary
 * @covers \Stonewright\WpMcp\Abilities\Media\UploadMediaBatch
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\ApplyBundle
 */
final class WorkflowEfficiencyAbilitiesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_mode' => 'development',
		];
		$GLOBALS['stonewright_test_user_caps'] = [
			'read'               => true,
			'edit_posts'         => true,
			'upload_files'       => true,
			'edit_theme_options' => true,
		];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_media_handle_sideload'] = null;
		$GLOBALS['stonewright_test_download_url'] = null;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_media_handle_sideload'] = null;
		$GLOBALS['stonewright_test_download_url'] = null;
	}

	public function test_registry_exposes_efficiency_abilities(): void {
		$names = array_map(
			static fn ( string $class ): string => ( new $class() )->name(),
			AbilityRegistry::list()
		);

		self::assertContains( 'stonewright/workflow-preflight', $names );
		self::assertContains( 'stonewright/tool-profile', $names );
		self::assertContains( 'stonewright/elementor-v3-capabilities-summary', $names );
		self::assertContains( 'stonewright/elementor-v3-container-schema', $names );
		self::assertContains( 'stonewright/design-implementation-contract', $names );
		self::assertContains( 'stonewright/media-upload-batch', $names );
		self::assertContains( 'stonewright/elementor-v3-apply-bundle', $names );
	}

	public function test_tool_profile_returns_compact_elementor_design_fast_path(): void {
		$ability = AbilityRegistry::ability_by_name( 'stonewright/tool-profile' );

		self::assertNotNull( $ability );

		$result = $ability->execute(
			[
				'profile'   => 'elementor-design',
				'task'      => 'Build a pixel perfect design in Elementor from a visual reference.',
				'surface'   => 'elementor',
				'intent'    => 'write',
				'max_tools' => 40,
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'elementor-design', $result['profile'] );
		self::assertSame( 40, $result['max_tools'] );
		self::assertLessThanOrEqual( 40, $result['tool_count'] );
		self::assertTrue( $result['under_limit'] );
		self::assertContains( 'stonewright/context-bootstrap', $result['recommended_tools'] );
		self::assertContains( 'stonewright/workflow-preflight', $result['recommended_tools'] );
		self::assertContains( 'stonewright/tool-profile', $result['recommended_tools'] );
		self::assertContains( 'stonewright/security-create-one-time-link', $result['recommended_tools'] );
		self::assertContains( 'stonewright/design-implementation-contract', $result['recommended_tools'] );
		self::assertContains( 'stonewright/elementor-v3-capabilities-summary', $result['recommended_tools'] );
		self::assertContains( 'stonewright/elementor-v3-container-schema', $result['recommended_tools'] );
		self::assertContains( 'stonewright/elementor-v3-build-page-from-spec', $result['recommended_tools'] );
		self::assertContains( 'stonewright/elementor-v3-batch-mutate', $result['recommended_tools'] );
		self::assertContains( 'stonewright/media-upload-batch', $result['recommended_tools'] );
		self::assertContains( 'stonewright/content-bulk-upsert-posts', $result['recommended_tools'] );
		self::assertContains( 'stonewright-wp-cli-batch-run', $result['recommended_mcp_tools'] );
		self::assertContains( 'Use profile tools before full ability discovery when the client has a strict tool cap.', $result['token_rules'] );
		self::assertContains( 'Use responseMode=summary for WP-CLI and batch tools unless full JSON is needed for the next write.', $result['token_rules'] );

		foreach ( $result['tools'] as $tool ) {
			self::assertArrayHasKey( 'ability', $tool );
			self::assertArrayHasKey( 'mcp_tool', $tool );
			self::assertArrayHasKey( 'why', $tool );
			self::assertStringStartsWith( 'stonewright/', $tool['ability'] );
			self::assertStringNotContainsString( '/', $tool['mcp_tool'] );
		}
	}

	public function test_tool_profile_reports_required_profile_tools_hidden_by_settings(): void {
		$GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] = [
			'stonewright/elementor-v3-build-page-from-spec',
		];

		$ability = AbilityRegistry::ability_by_name( 'stonewright/tool-profile' );

		self::assertNotNull( $ability );

		$result = $ability->execute(
			[
				'profile'   => 'elementor-design',
				'task'      => 'Build a pixel perfect design in Elementor from a Figma reference.',
				'surface'   => 'elementor',
				'intent'    => 'write',
				'max_tools' => 40,
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertNotContains( 'stonewright/elementor-v3-build-page-from-spec', $result['recommended_tools'] );
		self::assertContains( 'stonewright/elementor-v3-build-page-from-spec', $result['missing_profile_tools'] );
		self::assertContains( 'stonewright-elementor-v3-build-page-from-spec', $result['missing_mcp_tools'] );
		self::assertContains( 'Use stonewright/system-abilities-list to inspect disabled or gated abilities before falling back to slower single-call workflows.', $result['recovery_hints'] );
		self::assertGreaterThanOrEqual( 1, $result['counts']['missing'] );
	}

	public function test_tool_profile_auto_routes_content_model_work_to_compact_wp_cli_path(): void {
		$ability = AbilityRegistry::ability_by_name( 'stonewright/tool-profile' );

		self::assertNotNull( $ability );

		$result = $ability->execute(
			[
				'profile'   => 'auto',
				'task'      => 'Create CPT UI post types and ACF fields, then add repeated speaker records.',
				'surface'   => 'wordpress',
				'intent'    => 'write',
				'max_tools' => 30,
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'content-model', $result['profile'] );
		self::assertLessThanOrEqual( 30, $result['tool_count'] );
		self::assertContains( 'stonewright/site-plugins-list', $result['recommended_tools'] );
		self::assertContains( 'stonewright/skills-get', $result['recommended_tools'] );
		self::assertContains( 'stonewright/wp-cli-status', $result['recommended_tools'] );
		self::assertContains( 'stonewright/wp-cli-discover', $result['recommended_tools'] );
		self::assertContains( 'stonewright/wp-cli-batch-run', $result['recommended_tools'] );
		self::assertContains( 'stonewright/content-bulk-upsert-posts', $result['recommended_tools'] );
		self::assertContains( 'stonewright-skills-get', $result['recommended_mcp_tools'] );
		self::assertContains( 'Discover plugin command groups once, then batch repeated CPT, field, post, meta, term, option, cache, and rewrite work.', $result['workflow_rules'] );
	}

	public function test_tool_profile_low_tools_stays_under_strict_client_caps(): void {
		$ability = AbilityRegistry::ability_by_name( 'stonewright/tool-profile' );

		self::assertNotNull( $ability );

		$result = $ability->execute(
			[
				'profile'   => 'low-tools',
				'task'      => 'Build Elementor sections, add repeated CPT rows, and keep Antigravity under its tool cap.',
				'surface'   => 'elementor',
				'intent'    => 'write',
				'max_tools' => 30,
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'low-tools', $result['profile'] );
		self::assertLessThanOrEqual( 24, $result['profile_tool_count'] );
		self::assertTrue( $result['under_limit'] );
		self::assertContains( 'low-tools', $result['profiles_available'] );
		self::assertContains( 'stonewright/context-bootstrap', $result['recommended_tools'] );
		self::assertContains( 'stonewright/workflow-preflight', $result['recommended_tools'] );
		self::assertContains( 'stonewright/content-bulk-upsert-posts', $result['recommended_tools'] );
		self::assertContains( 'stonewright/media-upload-batch', $result['recommended_tools'] );
		self::assertContains( 'stonewright/design-implementation-contract', $result['recommended_tools'] );
		self::assertContains( 'stonewright/elementor-v3-build-page-from-spec', $result['recommended_tools'] );
		self::assertContains( 'stonewright/elementor-v3-batch-mutate', $result['recommended_tools'] );
		self::assertContains( 'stonewright/gutenberg-apply-to-post', $result['recommended_tools'] );
		self::assertContains( 'stonewright-wp-cli-batch-run', $result['recommended_mcp_tools'] );
		self::assertNotContains( 'stonewright/system-abilities-list', $result['recommended_tools'] );
		self::assertNotContains( 'stonewright/content-create-page', $result['recommended_tools'] );
		self::assertNotContains( 'stonewright/media-list', $result['recommended_tools'] );
		self::assertNotContains( 'stonewright/design-validate-spec', $result['recommended_tools'] );
		self::assertNotContains( 'stonewright/blocks-get-schema', $result['recommended_tools'] );
		self::assertNotContains( 'stonewright/elementor-describe-widget', $result['recommended_tools'] );
		self::assertContains( 'Use low-tools for Antigravity, Gemini API, or other strict tool-cap clients before switching to a specialist profile.', $result['token_rules'] );
	}

	public function test_workflow_preflight_returns_single_call_fast_path(): void {
		$result = ( new WorkflowPreflight() )->execute(
			[
				'task'    => 'Build a Figma page in Elementor',
				'surface' => 'elementor',
				'intent'  => 'write',
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'development', $result['mode'] );
		self::assertArrayHasKey( 'context_token', $result );
		self::assertArrayHasKey( 'elementor', $result );
		self::assertArrayHasKey( 'fast_path', $result );
		self::assertContains( 'stonewright/tool-profile', $result['fast_path']['recommended_tools'] );
		self::assertContains( 'stonewright-tool-profile', $result['fast_path']['recommended_mcp_tools'] );
		self::assertContains( 'stonewright/media-upload-batch', $result['fast_path']['recommended_tools'] );
		self::assertContains( 'Implement visual pages in write-and-verify batches of one section, or two sections only when they are simple and tightly coupled.', $result['fast_path']['batching_rules'] );
		self::assertContains( 'After each batch, verify desktop, tablet, and mobile screenshots plus overflow before starting the next batch.', $result['fast_path']['batching_rules'] );
		self::assertContains( 'Auto-continue to the next section batch when screenshots, diagnostics, and overflow checks pass; do not wait for user approval between passing batches.', $result['fast_path']['batching_rules'] );
		self::assertContains( 'Install external Playwright MCP before visual work and restart the AI client so the browser tools appear.', $result['fast_path']['visual_setup'] );
		self::assertContains( 'Use a WordPress Application Password for HTTP MCP authentication.', $result['auth_guidance'] );
	}

	public function test_workflow_preflight_keeps_non_visual_elementor_discovery_compact(): void {
		$result = ( new WorkflowPreflight() )->execute(
			[
				'task'    => 'List Elementor widgets and summarize schema availability.',
				'surface' => 'elementor',
				'intent'  => 'read',
			]
		);

		self::assertIsArray( $result );
		self::assertFalse( $result['fast_path']['task_profile']['needs_visual_check'] );
		self::assertSame( 'exempt', $result['fast_path']['visual_build_gate']['status'] );
		self::assertSame( [], $result['fast_path']['visual_setup'] );
		self::assertNotContains( 'stonewright/media-upload-batch', $result['fast_path']['recommended_tools'] );
	}

	public function test_workflow_preflight_returns_plugin_specialization_fast_path(): void {
		$result = ( new WorkflowPreflight() )->execute(
			[
				'task'    => 'Audit ACF field groups, Pods fields, and WooCommerce product variations.',
				'surface' => 'wordpress',
				'intent'  => 'write',
			]
		);

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'specializations', $result['fast_path'] );

		$ids = array_column( $result['fast_path']['specializations'], 'id' );
		self::assertContains( 'acf', $ids );
		self::assertContains( 'pods', $ids );
		self::assertContains( 'woocommerce', $ids );
		self::assertContains( 'stonewright/site-plugins-list', $result['fast_path']['recommended_tools'] );
		self::assertContains( 'stonewright/skills-get', $result['fast_path']['recommended_tools'] );
		self::assertContains( 'stonewright/wp-cli-discover', $result['fast_path']['recommended_tools'] );
		self::assertContains( 'stonewright/wp-cli-batch-run', $result['fast_path']['recommended_tools'] );
		self::assertContains( 'stonewright-skills-get', $result['fast_path']['recommended_mcp_tools'] );
		self::assertContains( 'Use stonewright-wp-cli-batch-run with responseMode=summary for repeated CPT UI, ACF, post, meta, term, option, and plugin command work.', $result['fast_path']['batching_rules'] );
	}

	public function test_workflow_preflight_omits_elementor_summary_for_content_model_tasks(): void {
		$result = ( new WorkflowPreflight() )->execute(
			[
				'task'    => 'Create CPT UI post types, ACF fields, and repeated speaker rows.',
				'surface' => 'wordpress',
				'intent'  => 'write',
			]
		);

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'elementor', $result );
		self::assertArrayHasKey( 'included', $result['elementor'] );
		self::assertSame( false, $result['elementor']['included'] );
		self::assertSame( 'stonewright/elementor-v3-capabilities-summary', $result['elementor']['request_tool'] );
		self::assertArrayNotHasKey( 'native_widgets', $result['elementor'] );
		self::assertArrayNotHasKey( 'design_implementation_contract', $result['elementor'] );
		self::assertContains( 'stonewright/content-bulk-upsert-posts', $result['fast_path']['recommended_tools'] );
		self::assertContains( 'stonewright/wp-cli-batch-run', $result['fast_path']['recommended_tools'] );
	}

	public function test_workflow_preflight_returns_task_aware_mcp_call_sequence(): void {
		$result = ( new WorkflowPreflight() )->execute(
			[
				'task'    => 'Build a visual Elementor landing page from a design with native widgets.',
				'surface' => 'elementor',
				'intent'  => 'write',
			]
		);

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'task_profile', $result['fast_path'] );
		self::assertSame( 'elementor', $result['fast_path']['task_profile']['surface'] );
		self::assertSame( 'write', $result['fast_path']['task_profile']['intent'] );
		self::assertTrue( $result['fast_path']['task_profile']['is_write'] );
		self::assertTrue( $result['fast_path']['task_profile']['needs_visual_check'] );

		self::assertArrayHasKey( 'recommended_mcp_tools', $result['fast_path'] );
		self::assertContains( 'stonewright-widget-intent-resolve', $result['fast_path']['recommended_mcp_tools'] );
		self::assertContains( 'stonewright-elementor-widget-implementation-guide', $result['fast_path']['recommended_mcp_tools'] );
		self::assertContains( 'stonewright-elementor-v3-container-schema', $result['fast_path']['recommended_mcp_tools'] );

		self::assertArrayHasKey( 'call_sequence', $result['fast_path'] );
		$tools = array_column( $result['fast_path']['call_sequence'], 'tool' );
		self::assertContains( 'stonewright-workflow-preflight', $tools );
		self::assertContains( 'stonewright-context-bootstrap', $tools );
		self::assertContains( 'stonewright-tool-profile', $tools );
		self::assertContains( 'stonewright-widget-intent-resolve', $tools );
		self::assertContains( 'stonewright-elementor-widget-implementation-guide', $tools );
		self::assertContains( 'stonewright-elementor-v3-container-schema', $tools );
		self::assertContains( 'stonewright-elementor-v3-build-page-from-spec', $tools );
		self::assertArrayHasKey( 'visual_build_gate', $result['fast_path'] );
		self::assertTrue( $result['fast_path']['visual_build_gate']['blocks_completion_without_evidence'] );
		self::assertSame( 2, $result['fast_path']['visual_build_gate']['section_batching']['max_sections_per_pass'] );
		self::assertTrue( $result['fast_path']['visual_build_gate']['section_batching']['auto_continue_when_batch_passes'] );
		self::assertContains( 'figma_token_table', $result['fast_path']['visual_build_gate']['evidence_required_before_first_write'] );
		self::assertContains( 'existing_media_asset_audit', $result['fast_path']['visual_build_gate']['evidence_required_before_first_write'] );
		self::assertContains( 'section_implementation_plan', $result['fast_path']['visual_build_gate']['evidence_required_before_first_write'] );
		self::assertContains( 'section_reference_screenshots', $result['fast_path']['visual_build_gate']['evidence_required_before_first_write'] );
		self::assertContains( 'desktop_screenshot_diff', $result['fast_path']['visual_build_gate']['evidence_required_before_completion'] );
		self::assertContains( 'logged_out_viewport_checks', $result['fast_path']['visual_build_gate']['evidence_required_before_completion'] );
		self::assertSame( 'reference_screenshots', $result['fast_path']['visual_build_gate']['source_authority']['primary'] );
		self::assertContains( 'figma_layer_structure', $result['fast_path']['visual_build_gate']['source_authority']['not_authoritative'] );
		self::assertContains( 'Do not copy the Figma layer tree as the WordPress or Elementor tree when the visual screenshot implies a different, cleaner structure.', $result['fast_path']['visual_build_gate']['completion_stop_conditions'] );
		self::assertContains( 'Do not declare pixel-perfect or responsive unless logged-out desktop, tablet, and mobile viewport checks pass without theme/admin chrome contamination.', $result['fast_path']['visual_build_gate']['completion_stop_conditions'] );
		self::assertContains( 'Provide visual_build_gate evidence before signoff: Figma token table, media reuse audit, section plan, screenshot deltas, and logged-out viewport checks.', $result['fast_path']['quality_gates'] );
		self::assertContains( 'Use design-tool structure for tokens and asset hints, but match implementation structure to the captured reference screenshots.', $result['fast_path']['quality_gates'] );
		self::assertContains( 'For long visual designs, capture multiple section reference screenshots and compare each section before full-page signoff.', $result['fast_path']['quality_gates'] );
		self::assertContains( 'Never write more than two visual page sections in a single implementation batch.', $result['fast_path']['quality_gates'] );
		self::assertContains( 'For design-derived visual specs, set style_policy=strict and include style_source or style._source for any measured border, radius, shadow, or filter values; do not invent card chrome.', $result['fast_path']['quality_gates'] );
		self::assertContains( 'Before uploading assets, audit existing media and reuse matching filenames, alt text, dimensions, and crops.', $result['fast_path']['quality_gates'] );
		self::assertArrayNotHasKey( 'design_implementation_contract', $result['fast_path'] );
		self::assertArrayHasKey( 'design_contract_ref', $result['fast_path'] );
		self::assertFalse( $result['fast_path']['design_contract_ref']['inlined'] );
		self::assertSame( 'stonewright/design-implementation-contract', $result['fast_path']['design_contract_ref']['ability'] );
		self::assertSame( 'stonewright-design-implementation-contract', $result['fast_path']['design_contract_ref']['mcp_tool'] );
		self::assertSame( 'global_styles_first', $result['fast_path']['design_contract_ref']['sequence'][0] );
		self::assertSame( 1, $result['fast_path']['design_contract_ref']['section_batch']['default_sections_per_pass'] );
		self::assertSame( 2, $result['fast_path']['design_contract_ref']['section_batch']['max_sections_per_pass'] );
		self::assertSame( 'stonewright/elementor-v3-build-page-from-spec', $result['fast_path']['design_contract_ref']['section_batch']['primary_write_tool'] );
		self::assertContains( 'stonewright/elementor-v3-update-kit-colors', $result['fast_path']['design_contract_ref']['global_style_tools'] );
		self::assertContains( 'stonewright/elementor-v3-update-kit-typography', $result['fast_path']['design_contract_ref']['global_style_tools'] );

		foreach ( $result['fast_path']['call_sequence'] as $call ) {
			self::assertIsArray( $call );
			self::assertArrayHasKey( 'ability', $call );
			self::assertArrayHasKey( 'tool', $call );
			self::assertArrayHasKey( 'args', $call );
			self::assertStringStartsWith( 'stonewright/', $call['ability'] );
			self::assertStringNotContainsString( '/', $call['tool'] );
		}
	}

	public function test_workflow_preflight_includes_confirmation_token_step_for_production_safe_destructive_tasks(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new WorkflowPreflight() )->execute(
			[
				'task'    => 'Delete obsolete WooCommerce product variations.',
				'surface' => 'wordpress',
				'intent'  => 'delete',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'production-safe', $result['mode'] );
		self::assertArrayHasKey( 'task_profile', $result['fast_path'] );
		self::assertTrue( $result['fast_path']['task_profile']['is_destructive'] );
		self::assertContains( 'stonewright/security-issue-confirmation-token', $result['fast_path']['recommended_tools'] );
		self::assertContains( 'stonewright-security-issue-confirmation-token', $result['fast_path']['recommended_mcp_tools'] );

		$tools = array_column( $result['fast_path']['call_sequence'], 'tool' );
		self::assertContains( 'stonewright-site-plugins-list', $tools );
		self::assertContains( 'stonewright-wp-cli-status', $tools );
		self::assertContains( 'stonewright-wp-cli-discover', $tools );
		self::assertContains( 'stonewright-wp-cli-batch-run', $tools );
		self::assertContains( 'stonewright-security-issue-confirmation-token', $tools );
	}

	public function test_workflow_preflight_can_inline_design_contract_when_requested(): void {
		$result = ( new WorkflowPreflight() )->execute(
			[
				'task'                    => 'Build a visual Elementor landing page from a design with native widgets.',
				'surface'                 => 'elementor',
				'intent'                  => 'write',
				'include_design_contract' => true,
			]
		);

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'design_contract_ref', $result['fast_path'] );
		self::assertTrue( $result['fast_path']['design_contract_ref']['inlined'] );
		self::assertArrayHasKey( 'design_implementation_contract', $result['fast_path'] );
		self::assertSame( 'global_styles_first', $result['fast_path']['design_implementation_contract']['sequence'][0] );
		self::assertSame( 'image-gallery', $result['fast_path']['design_implementation_contract']['native_widget_map']['gallery'] );
		self::assertSame( 'loop-grid', $result['fast_path']['design_implementation_contract']['native_widget_map']['dynamic_cards'] );
		self::assertSame( 'summary', $result['fast_path']['design_implementation_contract']['token_efficiency']['wp_cli_response_mode'] );
	}

	public function test_elementor_capabilities_summary_is_compact_and_actionable(): void {
		$result = ( new CapabilitiesSummary() )->execute( [] );

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'renderer_limits', $result );
		self::assertArrayHasKey( 'first_pass_rules', $result );
		self::assertContains( 'Prefer native widgets; do not use Elementor HTML widgets unless explicitly allowed.', $result['first_pass_rules'] );
		self::assertContains( 'For visual work, verify external Playwright/browser MCP before the first write.', $result['first_pass_rules'] );
		self::assertContains( 'For design-derived pages, implement at most two sections per write-and-verify batch; prefer one dense section per batch.', $result['first_pass_rules'] );
		self::assertContains( 'Auto-continue to the next section batch only after desktop, tablet, and mobile checks pass.', $result['first_pass_rules'] );
		self::assertContains( 'For repeated cards or grids, use a validated spec first pass; use stonewright/elementor-v3-batch-mutate for surgical add/update/move/remove edits on an existing page.', $result['first_pass_rules'] );
		self::assertContains( 'Use build-page-from-spec dry_run before writes when the agent needs element_count, diagnostics, or a no-write preview.', $result['first_pass_rules'] );
		self::assertContains( 'Set style_policy=strict for design-derived visual specs and include style_source or style._source before applying borders, radius, shadows, or filters.', $result['first_pass_rules'] );
		self::assertContains( 'For every widget used, call stonewright/elementor-v3-get-widget-schema and inspect Content, Style, and Advanced controls before writing settings.', $result['first_pass_rules'] );
		self::assertContains( 'Name major parent containers semantically; do not over-name every inner utility container.', $result['first_pass_rules'] );
		self::assertArrayHasKey( 'advanced_controls', $result );
		self::assertContains( 'position_absolute', $result['advanced_controls'] );
		self::assertContains( 'z_index', $result['advanced_controls'] );
		self::assertContains( 'motion_effects', $result['advanced_controls'] );
		self::assertContains( 'mask', $result['advanced_controls'] );
		self::assertContains( 'css_id', $result['advanced_controls'] );
		self::assertSame( 'stonewright/elementor-v3-build-page-from-spec', $result['primary_write_tool'] );
		self::assertSame( 'stonewright/elementor-v3-batch-mutate', $result['mutation_batch_tool'] );
		self::assertSame( 'stonewright-wp-cli-batch-run', $result['wp_cli_batch_tool'] );
		self::assertSame( 'stonewright/elementor-v3-container-schema', $result['container_schema_tool'] );
		self::assertSame( 'summary', $result['default_response_mode'] );
		self::assertArrayHasKey( 'design_implementation_contract', $result );
		self::assertSame( 'global_styles_first', $result['design_implementation_contract']['sequence'][0] );
		self::assertContains( 'no_full_page_screenshot_backgrounds', $result['design_implementation_contract']['hard_failures'] );
	}

	public function test_container_schema_returns_compact_safe_container_controls(): void {
		$ability = AbilityRegistry::ability_by_name( 'stonewright/elementor-v3-container-schema' );

		self::assertNotNull( $ability );

		$result = $ability->execute( [] );

		self::assertIsArray( $result );
		self::assertSame( 'container', $result['element_type'] );
		self::assertFalse( $result['include_controls'] );
		self::assertArrayHasKey( 'core_controls', $result );
		self::assertContains( 'flex_direction', $result['core_controls']['layout'] );
		self::assertContains( 'flex_justify_content', $result['core_controls']['layout'] );
		self::assertContains( 'padding', $result['core_controls']['advanced'] );
		self::assertSame( 'flex_justify_content', $result['safe_aliases']['justify_content'] );
		self::assertSame( 'flex_align_items', $result['safe_aliases']['align_items'] );
		self::assertContains( 'flex_wrap', $result['blocked_settings'] );
		self::assertContains( '_flex_size', $result['blocked_settings'] );
		self::assertContains( 'Use flex_justify_content, flex_align_items, and flex_align_content instead of unprefixed flex shorthands.', $result['usage_rules'] );
		self::assertSame( 'stonewright/elementor-v3-batch-mutate', $result['write_tools']['surgical'] );
	}

	public function test_design_implementation_contract_is_compact_and_native_widget_first(): void {
		$result = ( new ImplementationContract() )->execute( [] );

		self::assertIsArray( $result );
		self::assertSame( '1.0.0', $result['version'] );
		self::assertSame(
			[
				'global_styles_first',
				'section_reference_tokens',
				'native_widget_map',
				'one_section_build',
				'screenshot_delta',
				'next_section_or_surgical_batch',
			],
			$result['sequence']
		);
		self::assertSame( 1, $result['section_batch']['default_sections_per_pass'] );
		self::assertSame( 2, $result['section_batch']['max_sections_per_pass'] );
		self::assertSame( 'stonewright/elementor-v3-build-page-from-spec', $result['section_batch']['primary_write_tool'] );
		self::assertSame( 'stonewright/elementor-v3-batch-mutate', $result['section_batch']['surgical_fix_tool'] );
		self::assertSame( 'image-gallery', $result['native_widget_map']['gallery'] );
		self::assertSame( 'countdown', $result['native_widget_map']['countdown'] );
		self::assertSame( 'loop-grid', $result['native_widget_map']['dynamic_cards'] );
		self::assertContains( 'invented_border_radius_shadow_filter', $result['hard_failures'] );
		self::assertContains( 'html_widget_without_explicit_allow_html_widget', $result['hard_failures'] );
		self::assertSame( 'summary', $result['token_efficiency']['wp_cli_response_mode'] );
		self::assertContains( 'stonewright/wp-cli-batch-run', $result['token_efficiency']['batch_tools'] );
	}

	public function test_widget_schema_groups_controls_by_editor_tab_and_adds_advanced_guidance(): void {
		$ability = new GetWidgetSchema();
		$schema  = $ability->output_schema();

		self::assertArrayHasKey( 'properties', $schema );
		self::assertArrayHasKey( 'tab_groups', $schema['properties'] );
		self::assertArrayHasKey( 'research_guidance', $schema['properties'] );

		$result = $ability->execute( [ 'name' => 'Contract' ] );

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'tab_groups', $result );
		self::assertArrayHasKey( 'Content', $result['tab_groups'] );
		self::assertArrayHasKey( 'Style', $result['tab_groups'] );
		self::assertArrayHasKey( 'Advanced', $result['tab_groups'] );
		self::assertSame( 1, $result['tab_groups']['Content']['count'] );
		self::assertSame( 'title', $result['tab_groups']['Content']['controls'][0]['name'] );
		self::assertContains( 'position_absolute', $result['tab_groups']['Advanced']['global_controls'] );
		self::assertContains( 'css_classes', $result['tab_groups']['Advanced']['global_controls'] );
		self::assertSame( 'Research official Elementor documentation online when this widget schema lacks enough Content or Style controls for the requested design.', $result['research_guidance'] );
	}

	public function test_media_upload_batch_returns_per_item_results(): void {
		$GLOBALS['stonewright_test_download_url'] = static fn ( string $url ): string => sys_get_temp_dir() . '/stonewright-batch-upload.tmp';
		file_put_contents( sys_get_temp_dir() . '/stonewright-batch-upload.tmp', 'image' );
		$GLOBALS['stonewright_test_media_handle_sideload'] = static fn (): int => 7101;

		$result = ( new UploadMediaBatch() )->execute(
			[
				'items' => [
					[
						'url'      => 'https://cdn.example.test/a.png',
						'filename' => 'a.png',
						'alt'      => 'A',
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 1, $result['uploaded'] );
		self::assertSame( 0, $result['failed'] );
		self::assertTrue( $result['items'][0]['ok'] );
		self::assertSame( 7101, $result['items'][0]['id'] );
	}

	public function test_apply_bundle_declares_per_write_shape(): void {
		$schema = ( new ApplyBundle() )->input_schema();

		self::assertSame( 'array', $schema['properties']['writes']['type'] );
		self::assertContains( 'writes', $schema['required'] );
		self::assertArrayHasKey( 'post_id', $schema['properties']['writes']['items']['properties'] );
		self::assertArrayHasKey( 'spec', $schema['properties']['writes']['items']['properties'] );
	}
}
