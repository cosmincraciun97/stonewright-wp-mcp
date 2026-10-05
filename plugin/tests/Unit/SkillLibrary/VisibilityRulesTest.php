<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SkillLibrary;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\SkillLibrary\VisibilityRules;

/** @covers \Stonewright\WpMcp\SkillLibrary\VisibilityRules */
final class VisibilityRulesTest extends TestCase {

	public function test_runtime_matching_requires_every_visibility_gate(): void {
		$record = [ 'enabled' => true, 'enable_agentic' => true, 'enable_prompt' => false, 'status' => 'active', 'version_constraints' => [ 'elementor' => '>=3.16' ] ];
		$available = static fn( array $constraints ): bool => true;
		$this->assertTrue( VisibilityRules::eligible( $record, 'agentic', $available ) );
		$this->assertFalse( VisibilityRules::eligible( $record, 'prompt', $available ) );
		$this->assertFalse( VisibilityRules::eligible( $record, 'agentic', static fn( array $constraints ): bool => false ) );
		foreach ( [ 'draft', 'stale', 'retired', 'trashed' ] as $status ) {
			$this->assertFalse( VisibilityRules::eligible( array_replace( $record, [ 'status' => $status ] ), 'agentic', $available ) );
		}
		$this->assertFalse( VisibilityRules::eligible( array_replace( $record, [ 'enabled' => false ] ), 'agentic', $available ) );
		$this->assertTrue( VisibilityRules::eligible( array_replace( $record, [ 'unmapped_private_data' => [ 'trashed' => true ], 'trashed_at' => 'Unmapped private metadata' ] ), 'all', $available ) );
	}

	public function test_missing_components_use_the_runtime_compatibility_contract(): void {
		$record = [ 'version_constraints' => [ 'elementor' => '>=3.16', 'woocommerce' => 'required' ] ];
		$missing = VisibilityRules::missing( $record, static fn( array $constraints ): bool => ! isset( $constraints['woocommerce'] ) );
		$this->assertSame( [ 'woocommerce' ], $missing );
	}
}
