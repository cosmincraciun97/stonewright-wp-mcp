<?php
/**
 * The refusal of unapproved CSS classes says which classes and how a site allows them.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\ElementorCustomCssGate;

/**
 * @covers \Stonewright\WpMcp\Elementor\ElementorCustomCssGate
 */
final class ElementorCustomCssGateClassMessageTest extends TestCase {

	protected function setUp(): void {
		ElementorCustomCssGate::reset();
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_approved_css_classes' => [ 'sw-header' ] ];
	}

	protected function tearDown(): void {
		ElementorCustomCssGate::reset();
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_the_message_names_the_rejected_classes_and_the_way_to_allow_them(): void {
		$result = ElementorCustomCssGate::assert_incoming( [ '_css_classes' => 'sw-header hero-wide cta-big' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		$message = $result->get_error_message();
		self::assertStringContainsString( 'hero-wide', $message );
		self::assertStringContainsString( 'cta-big', $message );
		self::assertStringNotContainsString( 'sw-header', $message, 'An approved class is not reported as rejected.' );
		self::assertStringContainsString( 'stonewright_approved_css_classes', $message );
	}

	public function test_a_long_list_is_cut_in_the_message_but_complete_in_the_data(): void {
		$classes = [];
		for ( $i = 1; $i <= 30; $i++ ) {
			$classes[] = 'class-' . $i;
		}

		$result = ElementorCustomCssGate::assert_incoming( [ '_css_classes' => implode( ' ', $classes ) ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertCount( 30, $result->get_error_data()['rejected_classes'] );
		self::assertStringContainsString( 'class-1', $result->get_error_message() );
		self::assertStringContainsString( 'more', $result->get_error_message() );
		self::assertLessThan( 700, strlen( $result->get_error_message() ) );
	}

	public function test_the_empty_css_key_filter_keeps_a_value_and_drops_an_empty_one(): void {
		$kept = ElementorCustomCssGate::without_empty_css_keys( [ 'a' => [ 'custom_css' => null, 'b' => [ 'custom_css' => 'x', 'raw' => [] ] ], 'custom_css' => '' ] );

		self::assertSame( [ 'a' => [ 'b' => [ 'custom_css' => 'x', 'raw' => [] ] ] ], $kept );
	}
}
