<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design\Motion;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\MotionCapabilities;
use Stonewright\WpMcp\Design\Motion\MotionCapabilityResolver;

/**
 * @covers \Stonewright\WpMcp\Design\Motion\MotionCapabilityResolver
 * @covers \Stonewright\WpMcp\Design\Motion\Providers\GutenbergCapabilities
 * @covers \Stonewright\WpMcp\Design\Motion\Providers\ElementorV3Capabilities
 * @covers \Stonewright\WpMcp\Design\Motion\Providers\ElementorV4Capabilities
 * @covers \Stonewright\WpMcp\Abilities\Design\MotionCapabilities
 */
final class MotionCapabilityResolverTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_transients']['stonewright_motion_capability_test'] );
	}

	public function test_missing_elementor_runtime_reports_unsupported_never_invented_values(): void {
		$digest = ( new MotionCapabilityResolver( [ 'wordpress_version' => '6.9', 'elementor' => null ] ) )->digest();

		self::assertTrue( $digest['ok'] );
		self::assertFalse( $digest['renderers']['elementor-v3']['available'] );
		self::assertFalse( $digest['renderers']['elementor-v4']['available'] );
		self::assertSame( [], $digest['renderers']['elementor-v4']['native_motion_capabilities'] );
		self::assertSame( '' , $digest['versions']['elementor_core'] );

		$reasons = array_column( $digest['unsupported'], 'reason' );
		self::assertContains( 'elementor_not_active', $reasons );
	}

	public function test_gutenberg_is_always_available_with_css_first_capabilities(): void {
		$digest  = ( new MotionCapabilityResolver( [ 'wordpress_version' => '6.9', 'elementor' => null ] ) )->digest();
		$guten   = $digest['renderers']['gutenberg-fse'];

		self::assertTrue( $guten['available'] );
		$caps = array_column( $guten['native_motion_capabilities'], 'status', 'capability' );
		self::assertSame( 'available', $caps['css-transitions-keyframes'] );
		self::assertSame( 'progressive-enhancement', $caps['css-scroll-driven-timelines'] );
		self::assertSame( 'available', $caps['interactivity-api'] );
		self::assertSame( [ 'desktop', 'tablet', 'mobile' ], $guten['device_support']['devices'] );
	}

	public function test_interactivity_api_unsupported_before_wordpress_6_5(): void {
		$digest = ( new MotionCapabilityResolver( [ 'wordpress_version' => '6.4.3', 'elementor' => null ] ) )->digest();
		$caps   = array_column( $digest['renderers']['gutenberg-fse']['native_motion_capabilities'], 'status', 'capability' );

		self::assertSame( 'unsupported', $caps['interactivity-api'] );
	}

	public function test_v3_without_pro_marks_motion_effects_pro_required(): void {
		$digest = ( new MotionCapabilityResolver( [ 'wordpress_version' => '6.9', 'elementor' => self::v3( false ) ] ) )->digest();
		$v3     = $digest['renderers']['elementor-v3'];

		self::assertTrue( $v3['available'] );
		self::assertFalse( $v3['pro_active'] );
		$statuses = array_column( $v3['native_motion_capabilities'], 'status', 'capability' );
		self::assertSame( 'available', $statuses['entrance_animations'] );
		self::assertSame( 'unsupported', $statuses['motion_effects'] );
		self::assertContains(
			'pro_required',
			array_column( $digest['unsupported'], 'reason' )
		);
	}

	public function test_v3_with_pro_exposes_motion_effects(): void {
		$digest    = ( new MotionCapabilityResolver( [ 'wordpress_version' => '6.9', 'elementor' => self::v3( true ) ] ) )->digest();
		$statuses  = array_column( $digest['renderers']['elementor-v3']['native_motion_capabilities'], 'status', 'capability' );

		self::assertSame( 'available', $statuses['motion_effects'] );
		self::assertNotContains( 'pro_required', array_column( $digest['unsupported'], 'reason' ) );
	}

	public function test_v4_reports_interactions_separately_from_settings_and_styles(): void {
		$digest = ( new MotionCapabilityResolver( [ 'wordpress_version' => '6.9', 'elementor' => self::v4( true ) ] ) )->digest();
		$v4     = $digest['renderers']['elementor-v4'];

		self::assertTrue( $v4['available'] );
		self::assertTrue( $v4['interactions_store_separate'] );
		$caps = array_column( $v4['native_motion_capabilities'], 'status', 'capability' );
		self::assertSame( 'available', $caps['interactions'] );
		self::assertSame( [ 'load', 'scrollIn', 'hover' ], $v4['interaction_triggers'] );
		self::assertSame( 'breakpoint-exclusions', $v4['device_support']['mode'] );
		self::assertFalse( $v4['write_adapter_ready'] );
		self::assertContains( 'official_v4_write_primitives_missing', array_column( $digest['unsupported'], 'reason' ) );
		self::assertArrayHasKey( 'style_system', $v4 );
	}

	public function test_v4_without_interactions_module_is_unsupported(): void {
		$digest = ( new MotionCapabilityResolver( [ 'wordpress_version' => '6.9', 'elementor' => self::v4( false ) ] ) )->digest();
		$v4     = $digest['renderers']['elementor-v4'];

		self::assertSame( [], $v4['native_motion_capabilities'] );
		self::assertContains(
			'interactions_module_not_detected',
			array_column( $digest['unsupported'], 'reason' )
		);
	}

	public function test_v4_without_atomic_module_is_refused(): void {
		$digest = ( new MotionCapabilityResolver(
			[
				'wordpress_version' => '6.9',
				'elementor'         => array_merge( self::v3( false ), [ 'atomic_module' => false, 'interactions' => false ] ),
			]
		) )->digest();

		self::assertFalse( $digest['renderers']['elementor-v4']['available'] );
		self::assertContains(
			'atomic_module_not_detected',
			array_column( $digest['unsupported'], 'reason' )
		);
	}

	public function test_digest_is_deterministic_for_identical_input(): void {
		$first  = ( new MotionCapabilityResolver( [ 'wordpress_version' => '6.9', 'elementor' => self::v4( true ) ] ) )->digest();
		$second = ( new MotionCapabilityResolver( [ 'wordpress_version' => '6.9', 'elementor' => self::v4( true ) ] ) )->digest();

		self::assertSame( $first, $second );
	}

	public function test_fingerprint_changes_when_runtime_version_changes(): void {
		$a = MotionCapabilityResolver::fingerprint( [ 'version' => '6.7' ], self::v4( true ) );
		$b = MotionCapabilityResolver::fingerprint( [ 'version' => '6.9' ], self::v4( true ) );
		$c = MotionCapabilityResolver::fingerprint( [ 'version' => '6.9' ], self::v4( true ) );

		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $a );
		self::assertNotSame( $a, $b );
		self::assertSame( $b, $c );
	}

	public function test_full_mode_expands_detail_while_summary_stays_bounded(): void {
		$summary = ( new MotionCapabilityResolver( [ 'wordpress_version' => '6.9', 'elementor' => self::v4( true ) ] ) )->digest();
		$full    = ( new MotionCapabilityResolver( [ 'wordpress_version' => '6.9', 'elementor' => self::v4( true ) ] ) )->digest( MotionCapabilityResolver::MODE_FULL );

		self::assertArrayNotHasKey( 'detail', $summary['renderers']['elementor-v4'] );
		self::assertArrayHasKey( 'detail', $full['renderers']['elementor-v4'] );
	}

	public function test_ability_executes_read_only_digest(): void {
		// Live-runtime path: Elementor stubs are absent here, so only the shape matters.
		$GLOBALS['stonewright_test_user_caps']['edit_posts'] = true;
		$ability = new MotionCapabilities();

		self::assertTrue( $ability->permission_callback( [] ) );
		$result = $ability->execute( [] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['read_only'] );
		self::assertArrayHasKey( 'schema_fingerprint', $result );
		self::assertArrayHasKey( 'approval_requirements', $result );
	}

	public function test_ability_rejects_callers_without_edit_posts(): void {
		$GLOBALS['stonewright_test_user_caps']['edit_posts'] = false;
		$ability = new MotionCapabilities();

		self::assertFalse( $ability->permission_callback( [] ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function v3( bool $pro ): array {
		return [
			'active'        => true,
			'core_version'  => '3.32.0',
			'pro_active'    => $pro,
			'pro_version'   => $pro ? '3.32.0' : '',
			'atomic_module' => false,
			'interactions'  => false,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function v4( bool $interactions ): array {
		return [
			'active'        => true,
			'core_version'  => '3.32.0',
			'pro_active'    => false,
			'pro_version'   => '',
			'atomic_module' => true,
			'interactions'  => $interactions,
			'interaction_triggers' => $interactions ? [ 'load', 'scrollIn', 'hover' ] : [],
			'interaction_schema_fingerprint' => $interactions ? str_repeat( 'd', 64 ) : '',
			'breakpoint_exclusions_supported' => $interactions,
			'v4_write_primitives' => [
				'document_mutator' => false,
				'interactions_applier' => false,
				'plain_values_resolver' => true,
				'interactions_schema' => $interactions,
			],
			'v4_write_adapter_ready' => false,
		];
	}
}
