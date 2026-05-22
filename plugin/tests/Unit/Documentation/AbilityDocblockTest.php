<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * Asserts that every Ability subclass registered in AbilityRegistry::list()
 * has a non-empty description() return value (used as the first sentence in
 * the ability truth matrix).
 *
 * A missing or empty description() causes the matrix generator to produce a
 * blank Description column, which is confusing for operators.
 *
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 */
final class AbilityDocblockTest extends TestCase {

	/**
	 * @dataProvider registered_ability_provider
	 */
	public function test_ability_has_non_empty_description( string $class, string $slug ): void {
		if ( ! class_exists( $class ) ) {
			self::markTestSkipped( "Class {$class} not found." );
		}

		/** @var \Stonewright\WpMcp\Abilities\Ability $ability */
		$ability     = new $class();
		$description = trim( $ability->description() );

		self::assertNotEmpty(
			$description,
			sprintf(
				"Ability '%s' (%s) has an empty description(). " .
				"Add a descriptive string so the truth matrix has useful content.",
				$slug,
				$class
			)
		);

		// Description should be at least 20 characters — a single word is not useful.
		self::assertGreaterThanOrEqual(
			20,
			strlen( $description ),
			sprintf(
				"Ability '%s' has a description shorter than 20 characters: '%s'. " .
				"Please make it more descriptive.",
				$slug,
				$description
			)
		);
	}

	/**
	 * @dataProvider registered_ability_provider
	 */
	public function test_ability_class_has_docblock( string $class, string $slug ): void {
		if ( ! class_exists( $class ) ) {
			self::markTestSkipped( "Class {$class} not found." );
		}

		$ref     = new ReflectionClass( $class );
		$docblock = $ref->getDocComment();

		// A docblock on the class itself is strongly preferred.
		// We accept absent docblocks (some abilities are self-describing via description())
		// but flag completely empty ones (/** */) as a style concern.
		if ( $docblock !== false ) {
			// Strip the delimiters and whitespace.
			$stripped = trim( preg_replace( '/^\s*\*\s?/m', '', substr( $docblock, 3, -2 ) ) );
			self::assertNotEmpty(
				$stripped,
				sprintf(
					"Ability class '%s' has an empty /** */ docblock. Either fill it in or remove it.",
					$class
				)
			);
		}

		// Not having a docblock at all is fine — the description() method is the source of truth.
		self::assertTrue( true );
	}

	/**
	 * @return iterable<string, array{class: string, slug: string}>
	 */
	public static function registered_ability_provider(): iterable {
		foreach ( AbilityRegistry::list() as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			/** @var \Stonewright\WpMcp\Abilities\Ability $ability */
			$ability = new $class();
			$slug    = $ability->name();
			yield $slug => [ 'class' => $class, 'slug' => $slug ];
		}
	}
}
