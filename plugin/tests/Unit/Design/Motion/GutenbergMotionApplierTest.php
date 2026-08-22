<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design\Motion;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\MotionApplyGutenberg;
use Stonewright\WpMcp\Design\Motion\GutenbergMotionApplier;
use Stonewright\WpMcp\Design\Motion\MotionPlanCompiler;
use Stonewright\WpMcp\Design\Motion\MotionPresetRegistry;

/**
 * @covers \Stonewright\WpMcp\Design\Motion\GutenbergMotionApplier
 * @covers \Stonewright\WpMcp\Abilities\Design\MotionApplyGutenberg
 */
final class GutenbergMotionApplierTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']['edit_post'] = true;
		$GLOBALS['stonewright_test_user_logged_in']         = true;
		$GLOBALS['stonewright_test_current_user_id']        = 42;
		$GLOBALS['stonewright_test_posts']                  = self::posts();
	}

	public function test_builds_update_operations_with_merged_classes(): void {
		$parsed = [
			self::block( 'core/paragraph', [ 'className' => 'is-style-lead' ] ),
		];

		$result = GutenbergMotionApplier::build_operations( $parsed, self::targets(), self::plan() );

		self::assertNotInstanceOf( \WP_Error::class, $result );
		self::assertCount( 1, $result['operations'] );

		$op = $result['operations'][0];
		self::assertSame( 'update', $op['action'] );
		self::assertSame( [0], $op['path'] );
		self::assertSame(
			'is-style-lead stw-motion-target--hero-copy stw-motion-fade-up',
			$op['attrs']['className']
		);
		self::assertTrue( $result['runtime_needed'] );
		self::assertContains( 'hero-copy', $result['resolved'] );
	}

	public function test_renderer_mismatch_is_refused(): void {
		$plan            = self::plan();
		$plan['renderer'] = 'elementor-v3';

		$result = GutenbergMotionApplier::build_operations( [], self::targets(), $plan );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_motion_renderer_mismatch', $result->get_error_code() );
	}

	public function test_duplicate_targets_are_refused(): void {
		$targets = [
			[ 'target_id' => 'hero-copy', 'path' => [0] ],
			[ 'target_id' => 'hero-copy', 'path' => [0] ],
		];

		$result = GutenbergMotionApplier::build_operations( self::tree(), $targets, self::plan() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_motion_target_invalid', $result->get_error_code() );
	}

	public function test_unresolvable_path_fails_the_dry_run(): void {
		$targets = [ [ 'target_id' => 'hero-copy', 'path' => [5] ] ];

		$result = GutenbergMotionApplier::build_operations( self::tree(), $targets, self::plan() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_motion_target_unresolved', $result->get_error_code() );
	}

	public function test_plan_target_without_requested_path_is_refused(): void {
		$result = GutenbergMotionApplier::build_operations( self::tree(), [], self::plan() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_motion_target_missing_from_page', $result->get_error_code() );
	}

	public function test_non_allowlisted_class_is_refused(): void {
		$plan                               = self::plan();
		$plan['operations'][0]['classes'][] = 'my-custom-css-class';

		$result = GutenbergMotionApplier::build_operations( self::tree(), self::targets(), $plan );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_motion_class_not_allowlisted', $result->get_error_code() );
	}

	public function test_already_applied_plan_resolves_to_noop(): void {
		$parsed = [
			self::block(
				'core/paragraph',
				[ 'className' => 'stw-motion-target--hero-copy stw-motion-fade-up' ]
			),
		];

		$result = GutenbergMotionApplier::build_operations( $parsed, self::targets(), self::plan() );

		self::assertNotInstanceOf( \WP_Error::class, $result );
		self::assertSame( [], $result['operations'] );
		self::assertContains( 'hero-copy:noop', $result['resolved'] );
	}

	public function test_ability_dry_run_returns_expected_hash_receipt(): void {
		$GLOBALS['stonewright_test_user_caps']['edit_post'] = true;

		$ability = new MotionApplyGutenberg();
		self::assertTrue( $ability->permission_callback( [ 'post_id' => 501 ] ) );

		$post    = $GLOBALS['stonewright_test_posts'][501];
		$before  = hash( 'sha256', (string) $post->post_content );

		$out = $ability->execute(
			[
				'post_id' => 501,
				'dry_run' => true,
				'plan'    => self::plan(),
				'targets' => self::targets(),
			]
		);

		self::assertNotInstanceOf( \WP_Error::class, $out );
		self::assertTrue( $out['ok'] );
		self::assertTrue( $out['dry_run'] );
		self::assertSame( $before, $out['before_hash'] );
		self::assertSame( $before, $out['write_receipt']['expected_content_hash'] );
		self::assertTrue( $out['assets']['js'] );
	}

	public function test_ability_write_queues_static_blocks_through_the_finalizer(): void {
		$post    = $GLOBALS['stonewright_test_posts'][501];
		$before  = hash( 'sha256', (string) $post->post_content );

		$ability = new MotionApplyGutenberg();
		$out     = $ability->execute(
			[
				'post_id'               => 501,
				'dry_run'               => false,
				'expected_content_hash' => $before,
				'confirmation_token'    => '',
				'plan'                  => self::plan(),
				'targets'               => self::targets(),
			]
		);

		self::assertNotInstanceOf( \WP_Error::class, $out );
		if ( is_wp_error( $out ) ) {
			self::fail( $out->get_error_code() . ': ' . $out->get_error_message() );
		}
		self::assertTrue( $out['ok'] );

		// Static core blocks queue through the browser finalizer: the second,
		// explicitly approved operation with its own snapshot and readback.
		self::assertTrue( $out['queued'] );
		self::assertSame( 'queued_finalizer', $out['verification_status'] );
		self::assertNotSame( [], $out['change_ids'] );
		self::assertSame( $before, $out['before_hash'] );

		// Content is NOT silently rewritten outside the approved finalizer flow.
		$updated = $GLOBALS['stonewright_test_posts'][501];
		self::assertSame( $post->post_content, (string) $updated->post_content );
	}

	/* ------------------------------ Helpers ------------------------------ */

	private static function tree(): array {
		return [ self::block( 'core/paragraph', [] ) ];
	}

	private static function targets(): array {
		return [ [ 'target_id' => 'hero-copy', 'path' => [0] ] ];
	}

	private static function plan(): array {
		$manifest = MotionPresetRegistry::manifest();
		$plan = [
			'renderer'   => 'gutenberg-fse',
			'bindings'   => [
				'spec_hash' => str_repeat( 'a', 64 ),
				'registry_fingerprint' => MotionPresetRegistry::fingerprint(),
				'asset_checksums' => [
					'css' => $manifest['assets']['css']['sha256'],
					'js'  => $manifest['assets']['js']['sha256'],
				],
				'capability_fingerprint' => '',
				'direction' => null,
				'renderer' => 'gutenberg-fse',
				'target_map' => [
					[ 'id' => 'hero-copy', 'kind' => 'block', 'owner_section' => 0, 'marker' => 'stw-motion-target--hero-copy' ],
				],
			],
			'operations' => [
				[
					'op'        => 'add-classes',
					'target_id' => 'hero-copy',
					'classes'   => [ 'stw-motion-target--hero-copy', 'stw-motion-fade-up' ],
					'runtime'   => true,
					'trigger'   => 'viewport-enter',
					'playback'  => 'once',
					'tier'      => 'auto-css',
				],
			],
		];
		$plan['plan_hash'] = MotionPlanCompiler::plan_hash( $plan['bindings'], $plan['operations'] );
		return $plan;
	}

	private static function block( string $name, array $attrs ): array {
		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerHTML'    => '<p>Copy</p>',
			'innerContent' => [ '<p>Copy</p>' ],
			'innerBlocks'  => [],
		];
	}

	private static function posts(): array {
		return [
			501 => (object) [
				'ID'           => 501,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Motion page',
				'post_content' => '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->',
				'post_excerpt' => '',
				'meta'         => [],
			],
		];
	}
}
