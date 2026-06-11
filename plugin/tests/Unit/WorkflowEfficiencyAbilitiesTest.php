<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
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
		self::assertContains( 'stonewright/elementor-v3-capabilities-summary', $names );
		self::assertContains( 'stonewright/media-upload-batch', $names );
		self::assertContains( 'stonewright/elementor-v3-apply-bundle', $names );
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
		self::assertContains( 'stonewright/media-upload-batch', $result['fast_path']['recommended_tools'] );
		self::assertContains( 'Build the first page pass with stonewright/elementor-v3-build-page-from-spec or stonewright/elementor-v3-apply-bundle; avoid dozens of single-widget calls for repeated cards.', $result['fast_path']['batching_rules'] );
		self::assertContains( 'Install external Playwright MCP before visual work and restart the AI client so the browser tools appear.', $result['fast_path']['visual_setup'] );
		self::assertContains( 'Use a WordPress Application Password for HTTP MCP authentication.', $result['auth_guidance'] );
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
		self::assertContains( 'stonewright/wp-cli-discover', $result['fast_path']['recommended_tools'] );
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

		self::assertArrayHasKey( 'call_sequence', $result['fast_path'] );
		$tools = array_column( $result['fast_path']['call_sequence'], 'tool' );
		self::assertContains( 'stonewright-workflow-preflight', $tools );
		self::assertContains( 'stonewright-context-bootstrap', $tools );
		self::assertContains( 'stonewright-widget-intent-resolve', $tools );
		self::assertContains( 'stonewright-elementor-widget-implementation-guide', $tools );
		self::assertContains( 'stonewright-elementor-v3-build-page-from-spec', $tools );
		self::assertArrayHasKey( 'visual_build_gate', $result['fast_path'] );
		self::assertTrue( $result['fast_path']['visual_build_gate']['blocks_completion_without_evidence'] );
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
		self::assertContains( 'Before uploading assets, audit existing media and reuse matching filenames, alt text, dimensions, and crops.', $result['fast_path']['quality_gates'] );

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
		self::assertContains( 'stonewright-security-issue-confirmation-token', $tools );
	}

	public function test_elementor_capabilities_summary_is_compact_and_actionable(): void {
		$result = ( new CapabilitiesSummary() )->execute( [] );

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'renderer_limits', $result );
		self::assertArrayHasKey( 'first_pass_rules', $result );
		self::assertContains( 'Prefer native widgets; do not use Elementor HTML widgets unless explicitly allowed.', $result['first_pass_rules'] );
		self::assertContains( 'For visual work, verify external Playwright/browser MCP before the first write.', $result['first_pass_rules'] );
		self::assertContains( 'For repeated cards or grids, use a validated spec or bundle first pass instead of many single-widget calls.', $result['first_pass_rules'] );
		self::assertContains( 'For every widget used, call stonewright/elementor-v3-get-widget-schema and inspect Content, Style, and Advanced controls before writing settings.', $result['first_pass_rules'] );
		self::assertContains( 'Name major parent containers semantically; do not over-name every inner utility container.', $result['first_pass_rules'] );
		self::assertArrayHasKey( 'advanced_controls', $result );
		self::assertContains( 'position_absolute', $result['advanced_controls'] );
		self::assertContains( 'z_index', $result['advanced_controls'] );
		self::assertContains( 'motion_effects', $result['advanced_controls'] );
		self::assertContains( 'mask', $result['advanced_controls'] );
		self::assertContains( 'css_id', $result['advanced_controls'] );
		self::assertSame( 'stonewright/elementor-v3-build-page-from-spec', $result['primary_write_tool'] );
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
