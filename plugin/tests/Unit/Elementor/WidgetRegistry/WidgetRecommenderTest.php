<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\WidgetRegistry;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetRecommender;

/**
 * @covers \Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetRecommender
 */
final class WidgetRecommenderTest extends TestCase {

	public function test_recommends_native_gallery_for_photo_grid_prompt(): void {
		$recommendations = WidgetRecommender::recommend( 'fa o galerie foto grid cu 8 imagini responsive', 5 );

		self::assertNotEmpty( $recommendations );
		self::assertSame( 'image-gallery', $recommendations[0]['slug'] );
		self::assertSame( 'stonewright/elementor-v3-batch-mutate', $recommendations[0]['ability'] );
		self::assertSame( 'stonewright/elementor-add-image-gallery', $recommendations[0]['legacy_ability'] );
		self::assertNotContains( 'html', array_column( $recommendations, 'slug' ) );
	}

	public function test_recommends_video_for_aftermovie_prompt(): void {
		$recommendations = WidgetRecommender::recommend( 'sectiune aftermovie video cu poster si buton play', 5 );

		self::assertNotEmpty( $recommendations );
		self::assertSame( 'video', $recommendations[0]['slug'] );
		self::assertNotContains( 'html', array_column( $recommendations, 'slug' ) );
	}

	public function test_recommends_nav_menu_for_mobile_header_prompt(): void {
		$recommendations = WidgetRecommender::recommend( 'header sticky pe mobile cu hamburger menu si linkuri', 5 );

		self::assertNotEmpty( $recommendations );
		self::assertSame( 'nav-menu', $recommendations[0]['slug'] );
	}

	public function test_html_widget_is_excluded_unless_explicitly_allowed(): void {
		$without_allow = WidgetRecommender::recommend( 'adauga cod html custom embed script', 8 );
		self::assertNotContains( 'html', array_column( $without_allow, 'slug' ) );

		$with_allow = WidgetRecommender::recommend(
			'adauga cod html custom embed script',
			8,
			[ 'allow_html_widget' => true ]
		);
		self::assertSame( 'html', $with_allow[0]['slug'] );
	}
}
