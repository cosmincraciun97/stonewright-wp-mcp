<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\WidgetIntentResolve;

/**
 * @covers \Stonewright\WpMcp\Abilities\Design\WidgetIntentResolve
 */
final class WidgetIntentResolveAbilityTest extends TestCase {

	public function test_ability_detects_intent_from_prompt(): void {
		$result = ( new WidgetIntentResolve() )->execute(
			[
				'prompt' => 'Construieste o galerie foto cu 8 imagini in Elementor.',
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['matched'] );
		$this->assertSame( 'image-gallery', $result['intent'] );
		$this->assertSame( 'image-gallery', $result['widget'] );
		$this->assertSame( 'image-gallery', $result['recommendations'][0]['slug'] );
	}

	public function test_ability_detects_intent_from_figma_node(): void {
		$result = ( new WidgetIntentResolve() )->execute(
			[
				'figma_node' => [
					'type'     => 'FRAME',
					'name'     => 'Newsletter form',
					'children' => [
						[ 'type' => 'TEXT', 'characters' => 'Nume *' ],
						[ 'type' => 'TEXT', 'characters' => 'Prenume *' ],
						[ 'type' => 'TEXT', 'characters' => 'Email *' ],
						[ 'type' => 'TEXT', 'characters' => 'Interes *' ],
						[ 'type' => 'TEXT', 'characters' => 'Aboneaza-te la newsletter' ],
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['matched'] );
		$this->assertSame( 'newsletter-form', $result['intent'] );
		$this->assertSame( 'form', $result['widget'] );
	}

	public function test_ability_returns_ranked_catalog_recommendations_for_prompt(): void {
		$result = ( new WidgetIntentResolve() )->execute(
			[
				'prompt' => 'header sticky pe mobile cu hamburger menu si linkuri',
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['matched'] );
		$this->assertSame( 'nav-menu', $result['recommendations'][0]['slug'] );
		$this->assertSame( 'stonewright/elementor-add-nav-menu', $result['recommendations'][0]['ability'] );
	}
}
