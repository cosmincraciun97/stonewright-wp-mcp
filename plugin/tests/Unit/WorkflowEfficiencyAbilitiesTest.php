<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\ApplyBundle;
use Stonewright\WpMcp\Abilities\ElementorV3\CapabilitiesSummary;
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

	public function test_elementor_capabilities_summary_is_compact_and_actionable(): void {
		$result = ( new CapabilitiesSummary() )->execute( [] );

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'renderer_limits', $result );
		self::assertArrayHasKey( 'first_pass_rules', $result );
		self::assertContains( 'Prefer native widgets; do not use Elementor HTML widgets unless explicitly allowed.', $result['first_pass_rules'] );
		self::assertContains( 'For visual work, verify external Playwright/browser MCP before the first write.', $result['first_pass_rules'] );
		self::assertContains( 'For repeated cards or grids, use a validated spec or bundle first pass instead of many single-widget calls.', $result['first_pass_rules'] );
		self::assertSame( 'stonewright/elementor-v3-build-page-from-spec', $result['primary_write_tool'] );
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
