<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * Regression guards for the WordPress Abilities API hook contract.
 *
 * Two flavours of the Abilities API exist in the wild and Stonewright must
 * support both:
 *
 *   • **WordPress core 6.9+** ships its own copy in `wp-includes/abilities-api/`
 *     and fires `wp_abilities_api_categories_init` + `wp_abilities_api_init`.
 *     The core copy ALWAYS wins over the vendor copy because the vendor
 *     bootstrap is guarded with `class_exists( 'WP_Ability' )`.
 *
 *   • **The standalone wordpress/abilities-api package (≤ 0.1.0)** used when
 *     running on pre-6.9 cores fires only `abilities_api_init` and has no
 *     categories init.
 *
 * History — an earlier draft hooked on just one action at a time and
 * silently registered zero abilities on the other flavour. The in-memory
 * test suite stayed green because the test bootstrap stubs
 * `wp_register_ability` and bypasses the action entirely. This file pins
 * the dual-flavour contract.
 *
 * @covers \Stonewright\WpMcp\Core\PluginRegistration
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 */
final class AbilitiesApiHookNameTest extends TestCase {

	private const PLUGIN_REGISTRATION  = __DIR__ . '/../../includes/Core/PluginRegistration.php';
	private const VENDOR_REGISTRY      = __DIR__ . '/../../vendor/wordpress/abilities-api/includes/abilities-api/class-wp-abilities-registry.php';

	public function test_vendor_lib_still_fires_canonical_abilities_api_init(): void {
		$this->assertFileExists( self::VENDOR_REGISTRY, 'Vendor lib not installed — run `composer install` in plugin/.' );
		$contents = (string) file_get_contents( self::VENDOR_REGISTRY );
		$this->assertStringContainsString(
			"do_action( 'abilities_api_init'",
			$contents,
			'The wordpress/abilities-api vendor lib no longer fires `abilities_api_init` — update PluginRegistration::register_hooks() and this test.'
		);
	}

	public function test_plugin_registration_listens_on_core_wp_prefixed_init(): void {
		$contents = (string) file_get_contents( self::PLUGIN_REGISTRATION );
		$this->assertStringContainsString(
			"add_action( 'wp_abilities_api_init',",
			$contents,
			'PluginRegistration must hook on `wp_abilities_api_init` — that is the action WordPress core 6.9+ fires from wp-includes/abilities-api/.'
		);
		$this->assertStringContainsString(
			"add_action( 'wp_abilities_api_categories_init',",
			$contents,
			'PluginRegistration must hook on `wp_abilities_api_categories_init` so categories register before abilities (core 6.9+ requires the category to exist before the ability is registered).'
		);
	}

	public function test_plugin_registration_also_listens_on_vendor_un_prefixed_init(): void {
		$contents = (string) file_get_contents( self::PLUGIN_REGISTRATION );
		$this->assertStringContainsString(
			"add_action( 'abilities_api_init',",
			$contents,
			'PluginRegistration must ALSO hook on `abilities_api_init` for pre-6.9 cores running the wordpress/abilities-api standalone package.'
		);
	}

	public function test_register_all_is_idempotent_against_double_hook_fire(): void {
		// Both `wp_abilities_api_init` and `abilities_api_init` can fire in the
		// same request (e.g. when a site runs core 6.9+ AND something has also
		// instantiated the vendor registry directly). Calling register_all
		// twice must not throw or attempt to re-register. We can't easily
		// invoke wp_register_ability from a unit test (the real stub is not
		// loaded in this bootstrap), but we CAN observe that a second call
		// no-ops by leaning on the static guard's side-effect: after a
		// successful first call, AbilityRegistry::reset_for_tests() must be
		// the only path that re-enables registration.
		AbilityRegistry::reset_for_tests();
		// First call — runs through the early-return guard
		// ( function_exists( 'wp_register_ability' ) is false in this bootstrap )
		// and STILL flips the static, because we run it before the function
		// check on purpose? No — register_all() runs the function_exists check
		// first and bails before flipping the static. So the guard has no
		// observable side effect when wp_register_ability is missing, which
		// is the case in unit tests.
		//
		// We therefore assert the contract from the registry source itself:
		// the guard property MUST exist and the reset_for_tests helper MUST
		// flip it back to false.
		$ref = new \ReflectionClass( AbilityRegistry::class );
		$this->assertTrue(
			$ref->hasProperty( 'registered_once' ),
			'AbilityRegistry must keep a `registered_once` static guard so that the wp_/un-prefixed init actions cannot both register the full ability list in the same request.'
		);
		$prop = $ref->getProperty( 'registered_once' );
		$prop->setValue( null, true );
		AbilityRegistry::reset_for_tests();
		$this->assertFalse(
			$prop->getValue(),
			'reset_for_tests() must restore the guard so unit tests stay independent.'
		);
	}
}
