<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\AbilityRegistry;

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
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']            = [];
		$GLOBALS['stonewright_test_ability_categories'] = [];
		$GLOBALS['stonewright_test_doing_it_wrong']     = [];
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
}
