<?php
/**
 * A section that holds the settings Elementor itself stores for a container, a heading and a button is copied as
 * it is: the live schema accepts every one of them.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Abilities\SectionReuse\SectionReuseExtract;
use Stonewright\WpMcp\Elementor\ElementorCustomCssGate;
use Stonewright\WpMcp\Elementor\Schema\ContainerSchemaRepository;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\SectionReuse\ReferenceCatalog;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Support\ElementorData;
use Stonewright\WpMcp\Tests\Unit\Elementor\Schema\Fixtures\ElementorControlFixtures;

require_once __DIR__ . '/SectionFixtures.php';

/**
 * @covers \Stonewright\WpMcp\SectionReuse\ElementorSectionInserter
 */
final class SectionInsertCommonSettingsTest extends TestCase {

	private const TARGET = 501;
	private const SOURCE = 10;

	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = ElementorControlFixtures::install();
		WidgetSchemaRepository::reset_request_cache();
		IncidentStore::reset_for_tests();
		ElementorCustomCssGate::reset();
		ReferenceCatalog::set_provider( static fn( string $type, string $id ): ?bool => true );
		$GLOBALS['stonewright_test_options']           = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_post_meta_calls']   = [];
		$GLOBALS['stonewright_test_wpdb_inserts']      = [];
		$GLOBALS['stonewright_test_transients']        = [];
		$GLOBALS['stonewright_test_user_caps']         = [ 'edit_post' => true, 'read_post' => true, 'edit_posts' => true, 'read' => true ];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_logged_in']    = true;
		$GLOBALS['stonewright_test_current_user_id']   = 1;
		self::seed( self::TARGET, 'draft', [ [ 'id' => 'root', 'elType' => 'container', 'isInner' => false, 'settings' => [ 'container_type' => 'flex' ], 'elements' => [] ] ] );
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->original_elementor;
		WidgetSchemaRepository::reset_request_cache();
		ElementorCustomCssGate::reset();
		ReferenceCatalog::set_provider( null );
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_posts']             = [];
		$GLOBALS['stonewright_test_post_meta_calls']   = [];
		$GLOBALS['stonewright_test_wpdb_inserts']      = [];
		$GLOBALS['stonewright_test_options']           = [];
		$GLOBALS['stonewright_test_user_caps']         = [];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_logged_in']    = false;
		$GLOBALS['stonewright_test_current_user_id']   = 0;
		$GLOBALS['stonewright_test_transients']        = [];
	}

	/** @param list<array<string, mixed>> $tree */
	private static function seed( int $id, string $status, array $tree ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = SectionFixtures::post( $id, 'page', $status, 'Page ' . $id, '', SectionFixtures::elementor_meta( $tree, defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' ) );
	}

	/** @param array<string, mixed> $container @param array<string, mixed> $heading @param array<string, mixed> $button @return array<string, mixed> */
	private static function hero( array $container, array $heading, array $button ): array {
		return [
			'id'       => 'c000001',
			'elType'   => 'container',
			'isInner'  => false,
			'settings' => $container,
			'elements' => [
				[ 'id' => 'h000002', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => $heading, 'elements' => [] ],
				[ 'id' => 'b000003', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => $button, 'elements' => [] ],
			],
		];
	}

	/** @return array<string, mixed> */
	private static function container_settings(): array {
		return [
			'container_type'        => 'flex',
			'content_width'         => 'full',
			'width'                 => [ 'unit' => '%', 'size' => 100, 'sizes' => [] ],
			'flex_direction'        => 'column',
			'flex_justify_content'  => 'center',
			'flex_gap'              => [ 'column' => '24', 'row' => '24', 'isLinked' => true, 'unit' => 'px' ],
			'flex_gap_mobile'       => [ 'column' => '12', 'row' => '12', 'isLinked' => true, 'unit' => 'px' ],
			'min_height'            => [ 'unit' => 'custom', 'size' => 'calc(100vh - 80px)', 'sizes' => [] ],
			'padding'               => [ 'unit' => 'px', 'top' => '80', 'right' => '24', 'bottom' => '80', 'left' => '24', 'isLinked' => false ],
			'padding_mobile'        => [ 'unit' => 'px', 'top' => '40', 'right' => '16', 'bottom' => '40', 'left' => '16', 'isLinked' => false ],
			'background_background' => 'classic',
			'background_color'      => '#0b1b2b',
			'border_radius'         => [ 'unit' => 'px', 'top' => '12', 'right' => '12', 'bottom' => '12', 'left' => '12', 'isLinked' => true ],
			'box_shadow_type'       => 'yes',
			'box_shadow_box_shadow' => [ 'horizontal' => 0, 'vertical' => 8, 'blur' => 24, 'spread' => 0, 'color' => 'rgba(0,0,0,0.25)' ],
			'box_shadow_box_shadow_position' => ' ',
			'_element_id'           => 'hero',
			'z_index'               => '',
		];
	}

	/** @return array<string, mixed> */
	private static function heading_settings(): array {
		return [
			'title'                 => 'Build something solid',
			'header_size'           => 'h1',
			'size'                  => 'default',
			'link'                  => [ 'url' => '', 'is_external' => '', 'nofollow' => '', 'custom_attributes' => '' ],
			'align'                 => 'center',
			'align_mobile'          => 'left',
			'typography_typography' => 'custom',
			'typography_font_weight' => '600',
			'typography_font_size'  => [ 'unit' => 'px', 'size' => 48, 'sizes' => [] ],
			'typography_font_size_mobile' => [ 'unit' => 'rem', 'size' => 2, 'sizes' => [] ],
			'typography_line_height' => [ 'unit' => 'em', 'size' => 1.15, 'sizes' => [] ],
			'title_color'           => '#ffffff',
			'__globals__'           => [ 'title_color' => 'globals/colors?id=primary', 'typography_typography' => 'globals/typography?id=primary' ],
			'_margin'               => [ 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '16', 'left' => '0', 'isLinked' => false ],
			'_element_width'        => 'initial',
			'_element_custom_width' => [ 'unit' => '%', 'size' => 70, 'sizes' => [] ],
			'hide_mobile'           => '',
		];
	}

	/** @return array<string, mixed> */
	private static function button_settings(): array {
		return [
			'text'                          => 'Book a call',
			'link'                          => [ 'url' => 'tel:+40123456789', 'is_external' => '', 'nofollow' => '', 'custom_attributes' => '' ],
			'size'                          => 'sm',
			'selected_icon'                 => [ 'value' => 'fas fa-phone', 'library' => 'fa-solid' ],
			'icon_align'                    => 'row-reverse',
			'align'                         => 'left',
			'align_mobile'                  => 'center',
			'typography_typography'         => 'custom',
			'typography_font_weight'        => '700',
			'button_text_color'             => '#ffffff',
			'background_background'         => 'classic',
			'background_color'              => '#0a5cff',
			'hover_color'                   => '#ffffff',
			'button_background_hover_color' => '#0847c4',
			'hover_animation'               => 'grow',
			'border_border'                 => 'solid',
			'border_width'                  => [ 'unit' => 'px', 'top' => '1', 'right' => '1', 'bottom' => '1', 'left' => '1', 'isLinked' => true ],
			'border_color'                  => '#0a5cff',
			'border_radius'                 => [ 'unit' => 'px', 'top' => '8', 'right' => '8', 'bottom' => '8', 'left' => '8', 'isLinked' => true ],
			'text_padding'                  => [ 'unit' => 'px', 'top' => '14', 'right' => '28', 'bottom' => '14', 'left' => '28', 'isLinked' => false ],
			'button_box_shadow_type'        => 'yes',
			'button_box_shadow_box_shadow'  => [ 'horizontal' => 0, 'vertical' => 4, 'blur' => 12, 'spread' => 0, 'color' => 'rgba(10,92,255,0.35)' ],
			'button_box_shadow_box_shadow_position' => '',
			'__globals__'                   => [ 'button_text_color' => 'globals/colors?id=accent' ],
		];
	}

	/** @return array<string, mixed> The portable section the extract ability returns for the source. */
	private static function section( array $container, array $heading, array $button ): array {
		self::seed( self::SOURCE, 'publish', [ self::hero( $container, $heading, $button ) ] );
		$GLOBALS['stonewright_test_user_can_callback'] = static fn( string $cap, mixed ...$args ): bool => in_array( $cap, [ 'edit_posts', 'read_post', 'edit_post' ], true );
		$result = ( new SectionReuseExtract() )->execute( [ 'post_id' => self::SOURCE, 'locator' => [ 'kind' => 'element', 'id' => 'c000001' ] ] );
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );

		return $result['section'];
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function insert( array $container, array $heading, array $button, bool $dry_run = true ): array|\WP_Error {
		return ( new BatchMutate() )->execute(
			[
				'post_id'    => self::TARGET,
				'dry_run'    => $dry_run,
				'operations' => [ [ 'action' => 'insert_section', 'op_id' => 'hero', 'parent_id' => 'root', 'section' => self::section( $container, $heading, $button ) ] ],
			]
		);
	}

	private static function why( array|\WP_Error $result ): string {
		return $result instanceof \WP_Error ? $result->get_error_code() . ': ' . $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data()['rejected_settings'] ?? [] ) : '';
	}

	public function test_a_section_with_the_settings_elementor_stores_for_a_container_a_heading_and_a_button_is_copied_as_it_is(): void {
		$result = self::insert( self::container_settings(), self::heading_settings(), self::button_settings() );

		self::assertIsArray( $result, self::why( $result ) );
		self::assertSame( 0, $result['failed'] );
	}

	public function test_the_copy_keeps_every_setting_and_changes_none(): void {
		$result = self::insert( self::container_settings(), self::heading_settings(), self::button_settings(), false );

		self::assertIsArray( $result, self::why( $result ) );
		$stored    = ElementorData::read( self::TARGET );
		$copy      = $stored[0]['elements'][0];
		self::assertSame( self::container_settings(), $copy['settings'] );
		self::assertSame( self::heading_settings(), $copy['elements'][0]['settings'] );
		self::assertSame( self::button_settings(), $copy['elements'][1]['settings'] );
	}

	public function test_a_copied_section_with_a_numeric_font_weight_is_still_a_copy_of_the_source(): void {
		$heading                           = self::heading_settings();
		$heading['typography_font_weight'] = 600;
		$result                            = self::insert( self::container_settings(), $heading, self::button_settings() );

		self::assertIsArray( $result, self::why( $result ) );
	}

	public function test_a_setting_of_a_hidden_control_is_still_refused_and_the_refusal_names_what_to_add(): void {
		$container = self::container_settings();
		$container['content_width'] = 'boxed';
		$result    = self::insert( $container, self::heading_settings(), self::button_settings() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_section_settings_not_reusable', $result->get_error_data()['cause_code'] );
		$paths = array_column( $result->get_error_data()['rejected_settings'], 'path' );
		self::assertContains( 'settings.width', $paths );
	}
}
