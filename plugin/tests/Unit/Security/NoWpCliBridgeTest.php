<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Ability;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 */
class NoWpCliBridgeTest extends TestCase {

	public function test_companion_wp_cli_bridge_is_not_registered_as_an_ability(): void {
		$ability_names = [];

		foreach ( AbilityRegistry::list() as $class ) {
			if ( ! is_a( $class, Ability::class, true ) ) {
				continue;
			}

			$ability_names[] = ( new $class() )->name();
		}

		self::assertNotContains( 'stonewright/system-run-wpcli', $ability_names );
	}
}
