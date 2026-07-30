<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Site\DiscoverShortcodes;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Abilities\Site\DiscoverShortcodes
 */
final class SiteShortcodesDiscoverTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [ 'read' => true ];
		$GLOBALS['shortcode_tags']                  = [
			'gallery'        => 'gallery_shortcode',
			'contact-form-7' => [ 'WPCF7_ContactForm', 'shortcode' ],
			'object-card'    => [ new class() {
				public function render(): string {
					return '';
				}
			}, 'render' ],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['shortcode_tags']                  = [];
	}

	public function test_discovers_registered_shortcodes_with_safe_callback_summary(): void {
		$ability = new DiscoverShortcodes();

		$result = $ability->execute(
			[
				'search'            => 'contact',
				'include_callbacks' => true,
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 1, $result['count'] );
		self::assertSame( 'contact-form-7', $result['shortcodes'][0]['tag'] );
		self::assertSame( 'class_method', $result['shortcodes'][0]['callback_type'] );
		self::assertSame( 'WPCF7_ContactForm::shortcode', $result['shortcodes'][0]['callback_name'] );
	}

	public function test_registry_exposes_shortcode_discovery_as_read_only_site_ability(): void {
		$ability = AbilityRegistry::ability_by_name( 'stonewright/site-shortcodes-discover' );

		self::assertInstanceOf( DiscoverShortcodes::class, $ability );
		self::assertSame( 'site', $ability->category() );
		self::assertTrue( $ability->permission_callback( [] ) );
	}
}
