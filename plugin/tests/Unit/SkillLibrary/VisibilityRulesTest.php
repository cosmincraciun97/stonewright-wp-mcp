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

	/** @dataProvider stored_constraint_shapes */
	public function test_stored_constraint_shapes_report_unavailable_requirements( array $constraints, array $present, array $missing ): void {
		$compatible = static fn( array $constraint ): bool => in_array( (string) array_key_first( $constraint ), $present, true );
		$this->assertSame( $missing, VisibilityRules::missing( [ 'version_constraints' => $constraints ], $compatible ) );
		$record = [ 'enabled' => true, 'enable_agentic' => true, 'status' => 'active', 'version_constraints' => $constraints ];
		$this->assertSame( [] === $missing, VisibilityRules::eligible( $record, 'agentic', $compatible ) );
	}

	public static function stored_constraint_shapes(): array {
		return [
			'no constraint' => [ [], [], [] ],
			'required plugin present' => [ [ 'elementor' => 'required' ], [ 'elementor' ], [] ],
			'required plugin absent' => [ [ 'elementor' => 'required' ], [], [ 'elementor' ] ],
			'version expression unmet' => [ [ 'elementor' => '>=3.16' ], [], [ 'elementor' ] ],
			'any one listed plugin present' => [ [ 'any_of' => 'acf|acpt|pods' ], [ 'pods' ], [] ],
			'no listed plugin present' => [ [ 'any_of' => 'acf|acpt|pods' ], [ 'elementor' ], [ 'acf|acpt|pods' ] ],
			'digit-leading plugin slugs present' => [ [ '3d-viewer' => 'required', 'any_of' => '2fa-guard|redirection' ], [ '3d-viewer', '2fa-guard' ], [] ],
			'digit-leading plugin slugs absent' => [ [ '3d-viewer' => 'required', 'any_of' => '2fa-guard|redirection' ], [], [ '3d-viewer', '2fa-guard|redirection' ] ],
		];
	}

	public function test_any_of_asks_for_each_alternative_until_one_is_present(): void {
		$asked = [];
		$missing = VisibilityRules::missing(
			[ 'version_constraints' => [ 'any_of' => 'acf|acpt|pods' ] ],
			static function ( array $constraint ) use ( &$asked ): bool {
				$asked[] = $constraint;
				return isset( $constraint['acpt'] );
			}
		);
		$this->assertSame( [], $missing );
		$this->assertSame( [ [ 'acf' => 'required' ], [ 'acpt' => 'required' ] ], $asked );
	}
}
