<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddIconList;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddPriceList;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddReadMore;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddSearch;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * An ability recorded as a write needs the task context token even when its name holds a
 * read-only marker such as -list, -read or -search.
 *
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 */
final class AbilityRegistryWriteContextGateTest extends TestCase {

	/** @var list<string> */
	private const WRITES_WITH_READ_MARKER_IN_NAME = [
		'stonewright/elementor-add-icon-list',
		'stonewright/elementor-add-price-list',
		'stonewright/elementor-add-read-more',
		'stonewright/elementor-add-search',
	];

	protected function setUp(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [
			'stonewright_mode'                      => 'development',
			'stonewright_enabled'                   => true,
			'stonewright_disabled_abilities'        => [],
			'stonewright_essential_extra_abilities' => [],
			'stonewright_mcp_surface'               => 'full',
			'stonewright_essential_tools_mode'      => false,
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [];
	}

	public function test_write_abilities_with_a_read_marker_in_the_name_require_the_context_token_in_their_schema(): void {
		$by_name = [];
		foreach ( AbilityRegistry::enabled_abilities() as $row ) {
			$by_name[ $row['name'] ] = $row;
		}

		foreach ( self::WRITES_WITH_READ_MARKER_IN_NAME as $name ) {
			self::assertArrayHasKey( $name, $by_name, $name );
			$schema = $by_name[ $name ]['input_schema'];
			self::assertArrayHasKey( 'stonewright_context_token', $schema['properties'], $name );
			self::assertContains( 'stonewright_context_token', $schema['required'], $name );
		}
	}

	public function test_write_abilities_with_a_read_marker_in_the_name_are_refused_without_the_context_token(): void {
		$abilities = [ new AddIconList(), new AddPriceList(), new AddReadMore(), new AddSearch() ];

		foreach ( $abilities as $ability ) {
			$result = AbilityRegistry::execute_with_context_guard( $ability, [ 'post_id' => 1 ] );

			self::assertInstanceOf( \WP_Error::class, $result, $ability->name() );
			self::assertStringContainsString( 'context', $result->get_error_code(), $ability->name() );
		}
	}

	public function test_read_ability_with_a_read_marker_in_the_name_still_takes_no_context_token(): void {
		$by_name = [];
		foreach ( AbilityRegistry::enabled_abilities() as $row ) {
			$by_name[ $row['name'] ] = $row;
		}

		self::assertArrayHasKey( 'stonewright/acf-field-group-list', $by_name );
		self::assertArrayNotHasKey( 'stonewright_context_token', $by_name['stonewright/acf-field-group-list']['input_schema']['properties'] );
	}
}
