<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\V4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\SpecToElementorV4;
use Stonewright\WpMcp\Abilities\ElementorV4\CreateClass;
use Stonewright\WpMcp\Abilities\ElementorV4\CreateVariable;
use Stonewright\WpMcp\Abilities\ElementorV4\RenderFromSpec;
use Stonewright\WpMcp\Abilities\ElementorV4\UpdateClass;
use Stonewright\WpMcp\Abilities\ElementorV4\UpdateVariable;

/**
 * The Stonewright V4 writers say they are the fallback when a certified native ability exists.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\RenderFromSpec
 * @covers \Stonewright\WpMcp\Abilities\Design\SpecToElementorV4
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\CreateClass
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\UpdateClass
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\CreateVariable
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\UpdateVariable
 */
final class FallbackWriterDescriptionsTest extends TestCase {

	/** @return array<string,array{0:object,1:string}> */
	public static function writers(): array {
		return [
			'render from spec'  => [ new RenderFromSpec(), 'stonewright-elementor-native-execute' ],
			'spec to v4'        => [ new SpecToElementorV4(), 'stonewright-elementor-native-execute' ],
			'create class'      => [ new CreateClass(), 'clears generated CSS site-wide' ],
			'update class'      => [ new UpdateClass(), 'clears generated CSS site-wide' ],
			'create variable'   => [ new CreateVariable(), 'clears generated CSS site-wide' ],
			'update variable'   => [ new UpdateVariable(), 'clears generated CSS site-wide' ],
		];
	}

	/** @dataProvider writers */
	public function test_each_description_marks_the_ability_as_the_fallback_and_names_the_native_path_or_the_reason( object $ability, string $needle ): void {
		$description = $ability->description();

		self::assertStringStartsWith( 'Fallback', $description );
		self::assertStringContainsString( $needle, $description );
		self::assertStringContainsString( 'certified', $description );
		self::assertLessThan( 600, strlen( $description ), 'a description is read on every tool listing' );
	}

	public function test_the_original_behaviour_text_is_kept_in_each_description(): void {
		self::assertStringContainsString( 'dry_run=true (default) returns the tree without writing', ( new RenderFromSpec() )->description() );
		self::assertStringContainsString( 'verifies readback', ( new UpdateClass() )->description() );
		self::assertStringContainsString( 'Variables_Service', ( new CreateVariable() )->description() );
	}
}
