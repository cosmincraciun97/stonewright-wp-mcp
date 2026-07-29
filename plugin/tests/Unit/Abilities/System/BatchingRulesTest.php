<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\System;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\WorkflowPreflight;
use Stonewright\WpMcp\Security\GlobalRules;

/**
 * Batching guidance used to be prose typed into the preflight payload, which meant
 * it could drift away from the shipped rule registry without anything noticing, and
 * it was repeated in full on every task start.
 *
 * The registry is now the source: full mode carries the rule body once, sourced from
 * `GlobalRules`, and compact mode carries only the rule id so a client that already
 * cached the registry pays nothing for the reminder.
 *
 * @covers \Stonewright\WpMcp\Abilities\System\WorkflowPreflight
 */
final class BatchingRulesTest extends TestCase {

	private const RULE_ID = 'batch-related-mutations';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [
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

	/**
	 * @return array<string, mixed>
	 */
	private function fast_path( string $response_mode ): array {
		$result = ( new WorkflowPreflight() )->execute(
			[
				'task'         => 'Rebuild the timeline section of the careers page in Elementor',
				'surface'      => 'elementor',
				'intent'       => 'write',
				'responseMode' => $response_mode,
			]
		);

		self::assertIsArray( $result );
		self::assertIsArray( $result['fast_path'] );

		return $result['fast_path'];
	}

	public function test_the_referenced_rule_exists_in_the_shipped_registry(): void {
		// A payload that points at a rule id the registry does not carry would send
		// the client to rules-get for nothing.
		self::assertNotNull( GlobalRules::get( self::RULE_ID ) );
	}

	public function test_full_mode_names_the_rule_id_beside_the_rules_list(): void {
		$fast_path = $this->fast_path( 'full' );

		self::assertSame( self::RULE_ID, $fast_path['batching_rule_id'] );
		self::assertArrayHasKey( 'batching_rules', $fast_path );
	}

	public function test_batching_rules_stays_a_flat_list_of_strings(): void {
		$fast_path = $this->fast_path( 'full' );
		$rules     = $fast_path['batching_rules'];

		// The rule id is a sibling key, not an entry in this list: a client that
		// prints `batching_rules` must never find an array or an id inside it.
		self::assertIsArray( $rules );
		self::assertTrue( array_is_list( $rules ) );
		self::assertNotSame( [], $rules );
		foreach ( $rules as $rule ) {
			self::assertIsString( $rule );
		}
	}

	public function test_full_mode_carries_the_registry_rule_body(): void {
		$rule = GlobalRules::get( self::RULE_ID );
		self::assertIsArray( $rule );

		$rules = $this->fast_path( 'full' )['batching_rules'];

		// Sourced, not retyped. If the registry text changes, the payload follows.
		self::assertContains( $rule['rule'], $rules );
	}

	public function test_compact_mode_carries_the_id_without_the_bodies(): void {
		$fast_path = $this->fast_path( 'compact' );

		self::assertSame( self::RULE_ID, $fast_path['batching_rule_id'] );
		// Compact mode is a pointer: bodies come from rules-get, or from full mode.
		self::assertArrayNotHasKey( 'batching_rules', $fast_path );
	}
}
