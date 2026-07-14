<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;

final class ElementorMigrationGuideTest extends TestCase {

	public function test_every_generated_widget_ability_has_an_exact_migration(): void {
		/** @var list<class-string> $classes */
		$classes = include dirname( __DIR__, 3 ) . '/includes/Abilities/ElementorWidgets/_class_list.php';
		$guide   = file_get_contents( dirname( __DIR__, 4 ) . '/docs/migration-elementor-v3-tools.md' );
		self::assertIsString( $guide );
		self::assertCount( 94, $classes );
		foreach ( $classes as $class ) {
			$ability = new $class();
			self::assertStringContainsString( '`' . $ability->name() . '`', $guide );
		}
		self::assertSame( 94, substr_count( $guide, '| `stonewright/elementor-add-' ) );
		self::assertStringContainsString( 'earliest removal release is alpha.67', $guide );
	}
}
