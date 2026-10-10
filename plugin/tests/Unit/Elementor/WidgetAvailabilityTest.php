<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\WidgetAvailability;

/**
 * @covers \Stonewright\WpMcp\Elementor\WidgetAvailability
 */
final class WidgetAvailabilityTest extends TestCase {

	public function test_requirement_follows_the_catalog_source(): void {
		self::assertSame( '', WidgetAvailability::requirement( 'heading' ) );
		self::assertSame( 'elementor-pro', WidgetAvailability::requirement( 'archive-posts' ) );
		self::assertSame( 'woocommerce', WidgetAvailability::requirement( 'wc-add-to-cart' ) );
		self::assertSame( '', WidgetAvailability::requirement( 'a-widget-from-another-plugin' ) );
	}

	public function test_free_widgets_and_unknown_widgets_are_never_refused(): void {
		self::assertNull( WidgetAvailability::refusal( 'heading' ) );
		self::assertNull( WidgetAvailability::refusal( 'a-widget-from-another-plugin' ) );
	}

	public function test_a_pro_widget_is_refused_without_elementor_pro(): void {
		$error = WidgetAvailability::refusal( 'archive-posts' );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_widget_unavailable', $error->get_error_code() );
		self::assertSame( [ 'elementor-pro' ], $error->get_error_data()['missing'] );
		self::assertFalse( $error->get_error_data()['has_pro'] );
		self::assertStringContainsString( 'archive-posts', $error->get_error_message() );
	}

	public function test_a_woocommerce_widget_names_every_missing_plugin(): void {
		$error = WidgetAvailability::refusal( 'wc-add-to-cart' );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( [ 'elementor-pro', 'woocommerce' ], $error->get_error_data()['missing'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_pro_and_woocommerce_widgets_pass_once_their_plugins_are_active(): void {
		define( 'ELEMENTOR_PRO_VERSION', '3.0.0' );

		self::assertTrue( WidgetAvailability::has_pro() );
		self::assertNull( WidgetAvailability::refusal( 'archive-posts' ) );

		$error = WidgetAvailability::refusal( 'wc-add-to-cart' );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( [ 'woocommerce' ], $error->get_error_data()['missing'] );

		define( 'WC_VERSION', '9.0.0' );
		self::assertNull( WidgetAvailability::refusal( 'wc-add-to-cart' ) );
	}
}
