<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Elementor\Provider\NativeAvailability;

/**
 * stonewright-elementor-native-execute is reachable on the essential surface and near the top of the
 * elementor-design profile when native Elementor is available, and stays where it was when it is not.
 *
 * @covers \Stonewright\WpMcp\Elementor\Provider\NativeAvailability
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 * @covers \Stonewright\WpMcp\Abilities\System\ToolProfile
 */
final class NativeSurfaceReachabilityTest extends TestCase {

	private const NATIVE = 'stonewright/elementor-native-execute';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'development' ];
	}

	protected function tearDown(): void {
		NativeAvailability::use_report_for_tests( null );
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_it_is_on_the_essential_surface_only_when_native_elementor_is_available(): void {
		NativeAvailability::use_report_for_tests( [ 'state' => 'not_installed', 'certified' => [] ] );
		self::assertNotContains( self::NATIVE, AbilityRegistry::essential_ability_names_for_test() );

		NativeAvailability::use_report_for_tests( [ 'state' => 'available_uncertified', 'certified' => [] ] );
		self::assertNotContains( self::NATIVE, AbilityRegistry::essential_ability_names_for_test() );

		NativeAvailability::use_report_for_tests( [ 'state' => 'available', 'certified' => [ 'elementor/get-page-structure' ] ] );
		$names = AbilityRegistry::essential_ability_names_for_test();
		self::assertContains( self::NATIVE, $names );
		self::assertSame( $names, array_values( array_unique( $names ) ) );
	}

	public function test_the_ability_is_registered_before_native_elementor_is_known_to_be_available_and_listed_only_when_it_is(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = true;
		// Registration runs inside wp_abilities_api_init, where the live report is not available yet; the server lists its tools later.
		NativeAvailability::use_report_for_tests( [ 'state' => 'no_abilities_registered', 'certified' => [] ] );
		$registered = AbilityRegistry::registration_ability_names_for_test();
		self::assertContains( self::NATIVE, $registered, 'it is registered whether or not native Elementor is available yet' );
		self::assertNotContains( self::NATIVE, AbilityRegistry::mcp_server_ability_names(), 'but the server does not list a tool that cannot run' );

		NativeAvailability::use_report_for_tests( [ 'state' => 'available', 'certified' => [ 'elementor/build-composition' ] ] );
		self::assertContains( self::NATIVE, AbilityRegistry::mcp_server_ability_names() );
		self::assertSame( [], array_values( array_diff( AbilityRegistry::mcp_server_ability_names(), AbilityRegistry::registration_ability_names_for_test() ) ), 'the server never lists a tool that was not registered' );
	}

	public function test_the_registered_set_is_never_narrower_than_the_listed_set_on_any_surface(): void {
		$GLOBALS['stonewright_test_options']['stonewright_enabled'] = true;
		foreach ( [ 'essential', 'bootstrap', 'full' ] as $surface ) {
			foreach ( [ [ 'state' => 'not_installed', 'certified' => [] ], [ 'state' => 'available', 'certified' => [ 'elementor/build-composition' ] ] ] as $report ) {
				NativeAvailability::use_report_for_tests( $report );
				$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = $surface;

				self::assertSame( [], array_values( array_diff( AbilityRegistry::mcp_server_ability_names(), AbilityRegistry::registration_ability_names_for_test() ) ), $surface . ' / ' . $report['state'] );
			}
		}
	}

	public function test_available_state_without_a_certified_ability_does_not_count_as_available(): void {
		NativeAvailability::use_report_for_tests( [ 'state' => 'available', 'certified' => [] ] );

		self::assertFalse( NativeAvailability::is_available() );
		self::assertNotContains( self::NATIVE, AbilityRegistry::essential_ability_names_for_test() );
	}

	public function test_the_live_report_is_not_read_before_or_during_ability_registration(): void {
		NativeAvailability::use_report_for_tests( null );
		unset( $GLOBALS['stonewright_test_did_actions']['wp_abilities_api_init'] );
		self::assertSame( 'no_abilities_registered', NativeAvailability::report()['state'], 'before the hook has run' );
		self::assertFalse( NativeAvailability::is_available() );

		$GLOBALS['stonewright_test_did_actions']['wp_abilities_api_init']  = 1;
		$GLOBALS['stonewright_test_doing_action']['wp_abilities_api_init'] = true;
		self::assertSame( 'no_abilities_registered', NativeAvailability::report()['state'], 'while the hook runs' );

		$GLOBALS['stonewright_test_doing_action']['wp_abilities_api_init'] = false;
		self::assertNotSame( 'available', NativeAvailability::report()['state'], 'no Elementor in the test runtime' );
		unset( $GLOBALS['stonewright_test_did_actions']['wp_abilities_api_init'], $GLOBALS['stonewright_test_doing_action']['wp_abilities_api_init'] );
	}

	public function test_the_elementor_design_profile_lists_it_near_the_top_when_available(): void {
		NativeAvailability::use_report_for_tests( [ 'state' => 'available', 'certified' => [ 'elementor/build-composition' ] ] );

		$names = ToolProfile::profile_tools( 'elementor-design' );
		$index = array_search( self::NATIVE, $names, true );

		self::assertIsInt( $index );
		self::assertLessThan( 20, $index );
		self::assertGreaterThan( array_search( 'stonewright/elementor-provider-discovery', $names, true ), $index );
		self::assertLessThan( array_search( 'stonewright/elementor-v3-build-page-from-spec', $names, true ), $index );
		self::assertSame( 1, count( array_keys( $names, self::NATIVE, true ) ) );
	}

	public function test_the_elementor_design_profile_keeps_it_in_its_old_place_when_unavailable(): void {
		NativeAvailability::use_report_for_tests( [ 'state' => 'not_installed', 'certified' => [] ] );

		$names = ToolProfile::profile_tools( 'elementor-design' );

		self::assertContains( self::NATIVE, $names );
		self::assertGreaterThan( array_search( 'stonewright/elementor-v3-build-page-from-spec', $names, true ), array_search( self::NATIVE, $names, true ) );
		self::assertCount( 84, $names );
	}

	public function test_the_workflow_rules_name_the_native_route_for_a_v4_document(): void {
		$result = ( new ToolProfile() )->execute( [ 'action' => 'activate', 'profile' => 'elementor-design' ] );
		self::assertIsArray( $result );
		$rules = implode( "\n", $result['workflow_rules'] );

		self::assertStringContainsString( 'stonewright-elementor-native-execute', $rules );
		self::assertStringContainsString( 'V4', $rules );
		self::assertStringContainsString( 'Stonewright V4 writers', $rules );
		self::assertStringContainsString( 'V3', $rules );
		self::assertStringContainsString( 'never call elementor/*', strtolower( $rules ) );
	}
}
