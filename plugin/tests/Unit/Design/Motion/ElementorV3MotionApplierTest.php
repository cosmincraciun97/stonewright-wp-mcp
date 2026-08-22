<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design\Motion;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\MotionApplyElementorV3;
use Stonewright\WpMcp\Design\Motion\ElementorV3MotionApplier;
use Stonewright\WpMcp\Design\Motion\MotionPlanCompiler;
use Stonewright\WpMcp\Design\Motion\MotionPresetRegistry;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/**
 * @covers \Stonewright\WpMcp\Design\Motion\ElementorV3MotionApplier
 * @covers \Stonewright\WpMcp\Abilities\Design\MotionApplyElementorV3
 */
final class ElementorV3MotionApplierTest extends TestCase {

	public function test_builds_batch_mutate_update_operations(): void {
		$result = ElementorV3MotionApplier::build_operations(
			[ [ 'target_id' => 'hero-copy', 'element_id' => 'elem123', 'widget_type' => 'heading' ] ],
			[ 'hero-copy' => self::evidence() ],
			self::plan()
		);

		self::assertNotInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'update_element', $result['operations'][0]['action'] );
		self::assertSame( 'elem123', $result['operations'][0]['element_id'] );
		self::assertSame( [ '_animation' => 'fadeInUp' ], $result['operations'][0]['settings'] );
		self::assertSame( [ 'elem123' ], $result['touched_element_ids'] );
	}

	public function test_renderer_mismatch_is_refused(): void {
		$plan            = self::plan();
		$plan['renderer'] = 'gutenberg-fse';

		$result = ElementorV3MotionApplier::build_operations( [], [], $plan );

		self::assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_missing_evidence_is_refused_not_guessed(): void {
		$result = ElementorV3MotionApplier::build_operations(
			[ [ 'target_id' => 'hero-copy', 'element_id' => 'elem123', 'widget_type' => 'heading' ] ],
			[],
			self::plan()
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_motion_evidence_missing', $result->get_error_code() );
	}

	public function test_unknown_control_key_is_refused_against_live_schema(): void {
		$result = ElementorV3MotionApplier::build_operations(
			[ [ 'target_id' => 'hero-copy', 'element_id' => 'elem123', 'widget_type' => 'heading' ] ],
			[ 'hero-copy' => self::evidence( 'align', 'center' ) ],
			self::plan()
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_motion_control_outside_capability', $result->get_error_code() );
	}

	public function test_stale_schema_evidence_is_refused(): void {
		$evidence                = self::evidence();
		$evidence['schema_hash'] = str_repeat( '0', 64 );

		$result = ElementorV3MotionApplier::build_operations(
			[ [ 'target_id' => 'hero-copy', 'element_id' => 'elem123', 'widget_type' => 'heading' ] ],
			[ 'hero-copy' => $evidence ],
			self::plan()
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_motion_schema_evidence_stale', $result->get_error_code() );
	}

	public function test_pro_control_without_pro_runtime_is_refused(): void {
		// The heading schema carries no Pro controls in the stub catalog; the
		// refusal path is exercised through a digest that claims Pro absent.
		$result = ElementorV3MotionApplier::build_operations(
			[ [ 'target_id' => 'hero-copy', 'element_id' => 'elem123', 'widget_type' => 'heading' ] ],
			[ 'hero-copy' => self::evidence() ],
			self::plan(),
			[ 'renderers' => [ 'elementor-v3' => [ 'pro_active' => false ] ] ]
		);

		self::assertNotInstanceOf( \WP_Error::class, $result );
	}

	public function test_ability_dry_run_returns_planned_patches_without_write(): void {
		$GLOBALS['stonewright_test_user_caps']['edit_post'] = true;
		$GLOBALS['stonewright_test_posts']                  = [
			1 => (object) [
				'ID'           => 1,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'V3 page',
				'post_content' => 'body',
				'meta'         => [],
			],
		];

		$ability = new MotionApplyElementorV3();
		self::assertTrue( $ability->permission_callback( [ 'post_id' => 1 ] ) );

		$out = $ability->execute(
			[
				'post_id'  => 1,
				'dry_run'  => true,
				'plan'     => self::plan(),
				'targets'  => [ [ 'target_id' => 'hero-copy', 'element_id' => 'elem123', 'widget_type' => 'heading' ] ],
				'evidence' => [ 'hero-copy' => self::evidence() ],
			]
		);

		self::assertNotInstanceOf( \WP_Error::class, $out );
		self::assertTrue( $out['ok'] );
		self::assertTrue( $out['dry_run'] );
		self::assertSame( 'elem123', $out['touched_element_ids'][0] );
		self::assertArrayHasKey( 'planned_operations', $out['mutation_result'] );
	}

	public function test_ability_write_requires_a_current_tree_hash(): void {
		$GLOBALS['stonewright_test_user_caps']['edit_post'] = true;
		$GLOBALS['stonewright_test_posts'] = [
			1 => (object) [ 'ID' => 1, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'V3 page', 'post_content' => 'body', 'meta' => [] ],
		];

		$out = ( new MotionApplyElementorV3() )->execute(
			[
				'post_id'  => 1,
				'dry_run'  => false,
				'plan'     => self::plan(),
				'targets'  => [ [ 'target_id' => 'hero-copy', 'element_id' => 'elem123', 'widget_type' => 'heading' ] ],
				'evidence' => [ 'hero-copy' => self::evidence() ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $out );
		self::assertSame( 'stonewright_motion_expected_tree_hash_required', $out->get_error_code() );
	}

	private static function plan(): array {
		$manifest = MotionPresetRegistry::manifest();
		$plan = [
			'renderer'   => 'elementor-v3',
			'bindings'   => [
				'spec_hash' => str_repeat( 'a', 64 ),
				'registry_fingerprint' => MotionPresetRegistry::fingerprint(),
				'asset_checksums' => [
					'css' => $manifest['assets']['css']['sha256'],
					'js'  => $manifest['assets']['js']['sha256'],
				],
				'capability_fingerprint' => '',
				'direction' => null,
				'renderer' => 'elementor-v3',
				'target_map' => [],
			],
			'operations' => [
				[
					'op'                       => 'settings-evidence-patch',
					'target_id'                => 'hero-copy',
					'capability'               => 'entrance_animations',
					'semantic_effect'          => 'fade-up',
					'requires_schema_evidence' => true,
					'pro_required'             => false,
				],
			],
		];
		$plan['plan_hash'] = MotionPlanCompiler::plan_hash( $plan['bindings'], $plan['operations'] );
		return $plan;
	}

	private static function evidence( string $control = '_animation', string $value = 'fadeInUp' ): array {
		$schema = WidgetSchemaRepository::get( 'heading', true );
		self::assertNotInstanceOf( \WP_Error::class, $schema );
		return [
			'control_key'         => $control,
			'value'               => $value,
			'capability'          => 'entrance_animations',
			'semantic_effect'     => 'fade-up',
			'schema_hash'         => (string) $schema['schema_hash'],
			'runtime_fingerprint' => (string) $schema['runtime_fingerprint'],
			'source_plugin'       => (string) $schema['source_plugin'],
			'source_version'      => (string) $schema['source_version'],
		];
	}
}
