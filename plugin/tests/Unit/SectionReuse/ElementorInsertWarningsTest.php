<?php
/**
 * Extract warns about what the insert of an Elementor section will refuse.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseExtract;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\Security\IncidentStore;

require_once __DIR__ . '/SectionFixtures.php';
require_once __DIR__ . '/ReuseWidgetRegistry.php';

/**
 * @covers \Stonewright\WpMcp\SectionReuse\ElementorInsertWarnings
 * @covers \Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseExtract
 */
final class ElementorInsertWarningsTest extends TestCase {

	private const SOURCE = 10;

	protected function setUp(): void {
		WidgetSchemaRepository::reset_request_cache();
		IncidentStore::reset_for_tests();
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => true );
		$GLOBALS['stonewright_test_options']           = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_transients']        = [];
		$GLOBALS['stonewright_test_user_caps']         = [ 'edit_posts' => true, 'read' => true ];
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );
		$GLOBALS['stonewright_test_user_logged_in']    = true;
		$GLOBALS['stonewright_test_current_user_id']   = 7;
	}

	protected function tearDown(): void {
		ReuseWidgetRegistry::restore();
		WidgetSchemaRepository::reset_request_cache();
		ReferenceCatalog::set_provider( null );
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_options']           = [];
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_transients']        = [];
		$GLOBALS['stonewright_test_user_caps']         = [];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_logged_in']    = false;
	}

	/**
	 * @param list<array<string, mixed>> $tree
	 * @return list<array<string, mixed>> The warnings extract returns for the first section.
	 */
	private static function warnings( array $tree ): array {
		$GLOBALS['stonewright_test_posts'] = [ self::SOURCE => SectionFixtures::post( self::SOURCE, 'page', 'publish', 'Source', '', SectionFixtures::elementor_meta( $tree ) ) ];
		$result = ( new SectionReuseExtract() )->execute( [ 'post_id' => self::SOURCE, 'locator' => [ 'kind' => 'element', 'id' => (string) $tree[0]['id'] ] ] );
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() : '' );

		return $result['warnings'];
	}

	/** @param list<array<string, mixed>> $warnings @return array<string, mixed>|null */
	private static function warning( array $warnings, string $code ): ?array {
		foreach ( $warnings as $warning ) {
			if ( $code === $warning['code'] ) {
				return $warning;
			}
		}

		return null;
	}

	public function test_classes_the_site_has_not_approved_are_named(): void {
		$GLOBALS['stonewright_test_options']['stonewright_approved_css_classes'] = [ 'hero-card' ];
		$hero = SectionFixtures::v3_hero();
		$hero['settings']['css_classes']                         = 'hero-card hero-wide';
		$hero['elements'][0]['elements'][2]['settings']['_css_classes'] = 'cta-big hero-wide';

		$warning = self::warning( self::warnings( [ $hero ] ), 'css_classes_not_approved' );

		self::assertNotNull( $warning );
		self::assertSame( [ 'cta-big', 'hero-wide' ], $warning['items'] );
		self::assertSame( 2, $warning['count'] );
		self::assertStringContainsString( 'stonewright_approved_css_classes', $warning['detail'], 'It says how a site allows them.' );
	}

	public function test_no_warning_when_every_class_is_approved_or_there_are_none(): void {
		$GLOBALS['stonewright_test_options']['stonewright_approved_css_classes'] = [ 'hero-card', 'hero-wide' ];
		$hero = SectionFixtures::v3_hero();
		$hero['settings']['css_classes'] = 'hero-card hero-wide';

		self::assertNull( self::warning( self::warnings( [ $hero ] ), 'css_classes_not_approved' ) );
		self::assertNull( self::warning( self::warnings( [ SectionFixtures::v3_hero() ] ), 'css_classes_not_approved' ) );
	}

	public function test_custom_css_is_reported_as_needing_a_human_grant(): void {
		$hero = SectionFixtures::v3_hero();
		$hero['elements'][0]['elements'][0]['settings']['custom_css'] = 'selector { color: red; }';

		$warning = self::warning( self::warnings( [ $hero ] ), 'custom_css_needs_approval' );

		self::assertNotNull( $warning );
		self::assertSame( [ 'custom_css' ], $warning['items'] );
		self::assertStringContainsString( 'custom-code', $warning['detail'] );
	}

	public function test_an_empty_style_custom_css_key_of_a_v4_section_is_not_custom_css(): void {
		$v4 = SectionFixtures::v4_features( 'v', 1 );

		self::assertNull( self::warning( self::warnings( [ $v4 ] ), 'custom_css_needs_approval' ), 'Atomic style variants carry a null custom_css key.' );

		$key = array_key_first( $v4['styles'] );
		$v4['styles'][ $key ]['variants'][0]['custom_css'] = [ 'raw' => 'c2VsZWN0b3I=' ];
		self::assertNotNull( self::warning( self::warnings( [ $v4 ] ), 'custom_css_needs_approval' ) );
	}

	public function test_an_html_widget_is_reported_as_refused_unless_allowed(): void {
		$hero = SectionFixtures::v3_hero();
		$hero['elements'][] = [ 'id' => 'h000099', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => '<p>Embed</p>' ], 'elements' => [] ];

		$off = self::warning( self::warnings( [ $hero ] ), 'html_widgets' );
		self::assertNotNull( $off );
		self::assertSame( [ 'html' ], $off['items'] );
		self::assertStringContainsString( 'disabled', $off['detail'] );

		$GLOBALS['stonewright_test_options']['stonewright_allow_html_widgets'] = true;
		$on = self::warning( self::warnings( [ $hero ] ), 'html_widgets' );
		self::assertNotNull( $on );
		self::assertStringContainsString( 'allow_html_widget', $on['detail'] );
	}

	public function test_placeholder_widgets_are_named(): void {
		ReuseWidgetRegistry::install( [ 'form' ] );
		$hero = SectionFixtures::v3_hero();
		$hero['elements'][] = [ 'id' => 'h000098', 'elType' => 'widget', 'widgetType' => 'form', 'settings' => [], 'elements' => [] ];

		$warning = self::warning( self::warnings( [ $hero ] ), 'placeholder_widgets' );

		self::assertNotNull( $warning );
		self::assertSame( [ 'form' ], $warning['items'] );
		self::assertStringContainsString( 'not active', $warning['detail'] );
	}

	public function test_a_clean_section_adds_nothing(): void {
		$codes = array_column( self::warnings( [ SectionFixtures::v3_hero() ] ), 'code' );

		foreach ( [ 'css_classes_not_approved', 'custom_css_needs_approval', 'html_widgets', 'placeholder_widgets' ] as $code ) {
			self::assertNotContains( $code, $codes );
		}
	}
}
