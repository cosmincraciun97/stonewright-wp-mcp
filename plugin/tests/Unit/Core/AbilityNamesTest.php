<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddGoogleMaps;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * WordPress accepts an ability name only as `namespace/name` made of lowercase letters,
 * digits and dashes. A name outside that rule is refused at registration and the tool is
 * missing from the MCP surface.
 *
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 */
final class AbilityNamesTest extends TestCase {

	private const ABILITIES_API_NAME_RULE = '/^[a-z0-9-]+\/[a-z0-9-]+$/';

	public function test_every_ability_name_satisfies_the_abilities_api_naming_rule(): void {
		$invalid = [];
		foreach ( AbilityRegistry::list() as $class ) {
			$name = ( new $class() )->name();
			if ( 1 !== preg_match( self::ABILITIES_API_NAME_RULE, $name ) ) {
				$invalid[] = $name;
			}
		}

		self::assertSame( [], $invalid, 'These ability names are refused by the Abilities API.' );
	}

	public function test_every_ability_name_is_unique(): void {
		$names = [];
		foreach ( AbilityRegistry::list() as $class ) {
			$names[] = ( new $class() )->name();
		}

		self::assertSame( [], array_keys( array_filter( array_count_values( $names ), static fn( int $count ): bool => $count > 1 ) ) );
	}

	public function test_widget_ability_names_turn_catalog_underscores_into_dashes(): void {
		$ability = new AddGoogleMaps();

		self::assertSame( 'stonewright/elementor-add-google-maps', $ability->name() );
		self::assertSame( 'stonewright-elementor-add-google-maps', AbilityRegistry::mcp_tool_name( $ability->name() ) );
	}

	public function test_the_widget_still_resolves_its_catalog_entry_by_the_catalog_slug(): void {
		$ability = new AddGoogleMaps();
		$method  = new \ReflectionMethod( $ability, 'slug' );

		self::assertSame( 'google_maps', $method->invoke( $ability ) );
		self::assertNotSame( '', $ability->label() );
	}
}
