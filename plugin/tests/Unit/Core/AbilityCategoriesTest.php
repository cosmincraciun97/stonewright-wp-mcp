<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Core\PluginRegistration;

/**
 * Every ability names a category, and WordPress refuses an ability whose category is not
 * registered. The categories are registered once on their own hook, and a category that
 * WordPress or another plugin already registered is left as it is.
 *
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 */
final class AbilityCategoriesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']            = [];
		$GLOBALS['stonewright_test_ability_categories'] = [];
		$GLOBALS['stonewright_test_doing_it_wrong']     = [];
		$GLOBALS['stonewright_test_actions']            = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']            = [];
		$GLOBALS['stonewright_test_ability_categories'] = [];
		$GLOBALS['stonewright_test_doing_it_wrong']     = [];
		$GLOBALS['stonewright_test_actions']            = [];
	}

	public function test_every_category_an_ability_declares_is_registered(): void {
		AbilityRegistry::register_categories();

		$unregistered = [];
		foreach ( AbilityRegistry::all_abilities() as $ability ) {
			if ( ! wp_has_ability_category( $ability['category'] ) ) {
				$unregistered[ $ability['category'] ][] = $ability['name'];
			}
		}

		self::assertSame( [], $unregistered, 'Abilities declare categories that are not registered.' );
		self::assertSame( [], $GLOBALS['stonewright_test_doing_it_wrong'] );
	}

	public function test_categories_that_wordpress_registered_first_are_not_registered_again(): void {
		$core = [ 'label' => 'Site', 'description' => 'Abilities that retrieve or modify site information and settings.' ];
		$GLOBALS['stonewright_test_ability_categories'] = [ 'site' => $core, 'user' => [ 'label' => 'User', 'description' => 'User abilities.' ] ];

		AbilityRegistry::register_categories();

		self::assertSame( [], $GLOBALS['stonewright_test_doing_it_wrong'], 'A registered category was registered a second time.' );
		self::assertSame( $core, $GLOBALS['stonewright_test_ability_categories']['site'] );
		self::assertArrayHasKey( 'user', $GLOBALS['stonewright_test_ability_categories'] );
		self::assertArrayHasKey( 'elementor', $GLOBALS['stonewright_test_ability_categories'] );
	}

	public function test_registering_twice_does_not_raise_a_notice(): void {
		AbilityRegistry::register_categories();
		AbilityRegistry::register_categories();

		self::assertSame( [], $GLOBALS['stonewright_test_doing_it_wrong'] );
	}

	public function test_a_category_that_a_plugin_registers_on_the_same_hook_is_left_to_that_plugin(): void {
		self::boot_plugin_hooks();
		// Elementor registers its own `elementor` category on the hook at the default priority, after this plugin has booted.
		$elementor = [ 'label' => 'Elementor', 'description' => 'Elementor page builder data, global classes, and variables.' ];
		add_action(
			'wp_abilities_api_categories_init',
			static function () use ( $elementor ): void {
				wp_register_ability_category( 'elementor', $elementor );
			}
		);

		do_action( 'wp_abilities_api_categories_init' );

		self::assertSame( [], $GLOBALS['stonewright_test_doing_it_wrong'], 'The category was registered twice.' );
		self::assertSame( $elementor, $GLOBALS['stonewright_test_ability_categories']['elementor'] );
		foreach ( AbilityRegistry::all_abilities() as $ability ) {
			self::assertTrue( wp_has_ability_category( $ability['category'] ), $ability['name'] . ' has no registered category.' );
		}
	}

	public function test_every_category_is_registered_when_no_other_plugin_registers_elementor(): void {
		self::boot_plugin_hooks();

		do_action( 'wp_abilities_api_categories_init' );

		self::assertSame( [], $GLOBALS['stonewright_test_doing_it_wrong'] );
		self::assertArrayHasKey( 'elementor', $GLOBALS['stonewright_test_ability_categories'] );
		foreach ( AbilityRegistry::all_abilities() as $ability ) {
			self::assertTrue( wp_has_ability_category( $ability['category'] ), $ability['name'] . ' has no registered category.' );
		}
	}

	private static function boot_plugin_hooks(): void {
		$ref      = new \ReflectionClass( PluginRegistration::class );
		$instance = $ref->newInstanceWithoutConstructor();
		$ref->getConstructor()?->invoke( $instance, dirname( __DIR__, 3 ) . '/stonewright.php' );
		$ref->getMethod( 'register_hooks' )->invoke( $instance );
	}
}