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
		self::assertStringNotContainsString( 'earliest eligible removal', $guide );
		self::assertStringNotContainsString( 'beta.3', $guide );
	}

	public function test_the_guides_name_the_sunset_version_the_abilities_report(): void {
		/** @var list<class-string> $classes */
		$classes = include dirname( __DIR__, 3 ) . '/includes/Abilities/ElementorWidgets/_class_list.php';
		$sunsets = [];
		foreach ( $classes as $class ) {
			$meta = ( new $class() )->meta();
			$sunsets[ (string) ( $meta['sunset'] ?? '' ) ] = true;
		}
		self::assertCount( 1, $sunsets, 'Every widget ability reports the same sunset version.' );
		$sunset = (string) array_key_first( $sunsets );
		self::assertNotSame( '', $sunset );

		foreach ( [ 'migration-elementor-v3-tools.md', 'elementor-v3-editor-adapter.md' ] as $file ) {
			$doc = file_get_contents( dirname( __DIR__, 4 ) . '/docs/' . $file );
			self::assertIsString( $doc );
			// The prose wraps, so compare with whitespace collapsed.
			$flat = (string) preg_replace( '/\s+/', ' ', $doc );
			self::assertStringContainsString( 'abilities are removed in ' . $sunset . ', the sunset version', $flat, $file );
		}
	}
}
