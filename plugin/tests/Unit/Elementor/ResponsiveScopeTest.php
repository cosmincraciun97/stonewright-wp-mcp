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

	/**
	 * Elementor's add_responsive_control() stores the responsive metadata as an
	 * array of breakpoint bounds. An empty array means "every breakpoint", so a
	 * plain boolean cast reports the most common responsive control as fixed.
	 *
	 * @dataProvider responsive_metadata_shapes
	 *
	 * @param array<string, mixed> $control Raw Elementor control array.
	 */
	public function test_control_is_responsive_reads_every_metadata_shape( array $control, bool $expected ): void {
		self::assertSame( $expected, ResponsiveScope::control_is_responsive( $control ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: bool}>
	 */
	public static function responsive_metadata_shapes(): array {
		return [
			'empty array means all breakpoints' => [ [ 'responsive' => [] ], true ],
			'bounded array'                     => [ [ 'responsive' => [ 'max' => 'tablet' ] ], true ],
			'boolean true'                      => [ [ 'responsive' => true ], true ],
			'legacy is_responsive array'        => [ [ 'is_responsive' => [] ], true ],
			'legacy is_responsive boolean'      => [ [ 'is_responsive' => true ], true ],
			'explicit false'                    => [ [ 'responsive' => false ], false ],
			'absent'                            => [ [ 'type' => 'text' ], false ],
		];
	}

	public function test_responsive_allowlist_covers_controls_without_metadata(): void {
		// Elementor omits responsive metadata on several core layout controls even
		// though the suffixed keys are native. Both schema repositories must agree.
		self::assertTrue( ResponsiveScope::control_is_responsive( [ 'type' => 'choose' ], 'flex_direction' ) );
		self::assertTrue( ResponsiveScope::control_is_responsive( [ 'key' => 'padding' ] ) );
		self::assertFalse( ResponsiveScope::control_is_responsive( [ 'type' => 'text' ], 'css_id' ) );
	}

	/**
	 * @dataProvider visibility_controls
	 */
	public function test_visibility_switchers_carry_no_breakpoint( string $key ): void {
		self::assertTrue( ResponsiveScope::is_visibility_control( $key ) );
		self::assertNull( ResponsiveScope::key_breakpoint( $key ) );
		// The control is standalone, so its name is its own base name.
		self::assertSame( $key, ResponsiveScope::base_key( $key ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function visibility_controls(): array {
		$cases = [];
		foreach ( ResponsiveScope::visibility_controls() as $name ) {
			$cases[ $name ] = [ $name ];
		}
		return $cases;
	}

	public function test_visibility_detection_is_exact(): void {
		self::assertSame(
			[
				'hide_desktop',
				'hide_widescreen',
				'hide_laptop',
				'hide_tablet_extra',
				'hide_tablet',
				'hide_mobile_extra',
				'hide_mobile',
			],
			ResponsiveScope::visibility_controls()
		);

		// Arbitrary hide_*/show_* keys are ordinary controls, not switchers.
		foreach ( [ 'hide_title', 'hide_on_scroll', 'show_mobile', 'hidden_mobile' ] as $key ) {
			self::assertFalse( ResponsiveScope::is_visibility_control( $key ), $key );
		}
		self::assertSame( 'mobile', ResponsiveScope::key_breakpoint( 'show_mobile' ) );
		self::assertSame( 'desktop', ResponsiveScope::key_breakpoint( 'hide_title' ) );
	}

	public function test_scope_assertion_allows_standalone_visibility_switchers(): void {
		$result = ResponsiveScope::assert_settings_in_scope(
			[
				'title_mobile' => 'Hi mobile',
				'hide_desktop' => 'hidden',
				'hide_tablet'  => 'hidden',
			],
			[ 'mobile' ],
			[
				'title'        => [ 'responsive' => [] ],
				'hide_desktop' => [ 'type' => 'switcher' ],
				'hide_tablet'  => [ 'type' => 'switcher' ],
			],
			'heading'
		);

		self::assertTrue( $result );
	}

	public function test_scope_assertion_accepts_array_valued_responsive_metadata(): void {
		$result = ResponsiveScope::assert_settings_in_scope(
			[ 'padding_mobile' => [ 'size' => 8 ] ],
			[ 'mobile' ],
			[ 'padding' => [ 'type' => 'dimensions', 'responsive' => [] ] ],
			'heading'
		);

		self::assertTrue( $result );
	}

	public function test_non_target_hash_ignores_visibility_switchers(): void {
		$settings = [
			'title'        => 'Desk',
			'title_mobile' => 'Mob',
			'hide_desktop' => '',
			'hide_tablet'  => '',
		];
		$before = ResponsiveScope::hash_non_target_breakpoints( $settings, [ 'mobile' ] );

		$settings['hide_desktop'] = 'hidden';
		$settings['hide_tablet']  = 'hidden';
		self::assertSame( $before, ResponsiveScope::hash_non_target_breakpoints( $settings, [ 'mobile' ] ) );

		$settings['title'] = 'Desk changed';
		self::assertNotSame( $before, ResponsiveScope::hash_non_target_breakpoints( $settings, [ 'mobile' ] ) );
	}

	public function test_desktop_scope_accepts_base_keys_and_rejects_suffixed_keys(): void {
		self::assertTrue(
			ResponsiveScope::assert_settings_in_scope(
				[ 'padding' => [ 'size' => 8 ] ],
				[ 'desktop' ],
				[ 'padding' => [ 'type' => 'dimensions', 'responsive' => [] ] ],
				'heading'
			)
		);

		$leak = ResponsiveScope::assert_settings_in_scope(
			[ 'padding_tablet' => [ 'size' => 8 ] ],
			[ 'desktop' ],
			[ 'padding' => [ 'type' => 'dimensions', 'responsive' => [] ] ],
			'heading'
		);
		self::assertInstanceOf( \WP_Error::class, $leak );
		self::assertSame( 'stonewright_responsive_scope_violation', $leak->get_error_code() );
	}

	public function test_every_registered_breakpoint_has_a_suffix_and_round_trips(): void {
		foreach ( ResponsiveScope::breakpoint_suffixes() as $name => $suffix ) {
			if ( 'base' === $name ) {
				continue;
			}
			$key      = 'padding' . $suffix;
			$expected = '' === $suffix ? 'desktop' : $name;
			self::assertSame( $expected, ResponsiveScope::key_breakpoint( $key ), $key );
			self::assertSame( 'padding', ResponsiveScope::base_key( $key ), $key );
		}
	}
}
