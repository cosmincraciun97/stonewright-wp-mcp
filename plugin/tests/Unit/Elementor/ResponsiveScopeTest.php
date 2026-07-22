<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Schema\ResponsiveScope;

/**
 * @covers \Stonewright\WpMcp\Elementor\Schema\ResponsiveScope
 */
final class ResponsiveScopeTest extends TestCase {

	public function test_mobile_only_rejects_desktop_and_tablet_keys(): void {
		$result = ResponsiveScope::assert_settings_in_scope(
			[
				'title_mobile' => 'Hi',
				'title'        => 'Desktop leak',
			],
			[ 'mobile' ],
			[
				'title' => [ 'responsive' => true ],
			],
			'heading'
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_responsive_scope_violation', $result->get_error_code() );
	}

	public function test_mobile_only_allows_mobile_keys(): void {
		$result = ResponsiveScope::assert_settings_in_scope(
			[ 'title_mobile' => 'Hi mobile' ],
			[ 'mobile' ],
			[ 'title' => [ 'responsive' => true ] ],
			'heading'
		);
		self::assertTrue( $result );
	}

	public function test_non_responsive_control_returns_unsupported(): void {
		$result = ResponsiveScope::assert_settings_in_scope(
			[ 'html_mobile' => '<p>x</p>' ],
			[ 'mobile' ],
			[ 'html' => [ 'responsive' => false ] ],
			'html'
		);
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'unsupported_responsive_control', $result->get_error_code() );
	}

	public function test_non_target_breakpoint_hash_stable(): void {
		$settings = [
			'title'        => 'Desk',
			'title_tablet' => 'Tab',
			'title_mobile' => 'Mob',
		];
		$before = ResponsiveScope::hash_non_target_breakpoints( $settings, [ 'mobile' ] );
		$settings['title_mobile'] = 'Mob changed';
		$after = ResponsiveScope::hash_non_target_breakpoints( $settings, [ 'mobile' ] );
		self::assertSame( $before, $after );

		$settings['title_tablet'] = 'Tab changed';
		$after2 = ResponsiveScope::hash_non_target_breakpoints( $settings, [ 'mobile' ] );
		self::assertNotSame( $before, $after2 );
	}

	public function test_key_breakpoint_and_base_key(): void {
		self::assertSame( 'mobile', ResponsiveScope::key_breakpoint( 'padding_mobile' ) );
		self::assertSame( 'laptop', ResponsiveScope::key_breakpoint( 'font_size_laptop' ) );
		self::assertSame( 'desktop', ResponsiveScope::key_breakpoint( 'padding' ) );
		self::assertSame( 'padding', ResponsiveScope::base_key( 'padding_mobile' ) );
	}
}
