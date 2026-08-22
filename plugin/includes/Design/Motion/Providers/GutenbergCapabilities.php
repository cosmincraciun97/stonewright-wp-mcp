<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Motion\Providers;

/**
 * Read-only Gutenberg/FSE motion capability descriptor.
 *
 * WordPress core has no generic animation block support: CSS transitions and
 * keyframes are the native path, scroll-driven timelines are progressive
 * enhancement, WAAPI is imperative-only, and the Interactivity API is a
 * state/actions engine — never a general motion motor.
 */
final class GutenbergCapabilities {

	/**
	 * @param array{version:string} $wordpress
	 * @return array<string, mixed>
	 */
	public static function describe( array $wordpress ): array {
		$version = (string) ( $wordpress['version'] ?? '' );
		$major   = self::major_version( $version );

		$capabilities = [
			[
				'capability'   => 'css-transitions-keyframes',
				'status'       => 'available',
				'triggers'     => [ 'load', 'viewport-enter', 'hover', 'focus-visible' ],
				'description'  => 'Bundled static-first presets; content stays visible without JavaScript.',
			],
			[
				'capability'   => 'css-scroll-driven-timelines',
				'status'       => 'progressive-enhancement',
				'triggers'     => [ 'viewport-progress' ],
				'description'  => 'Gated behind @supports (animation-timeline: view()) with static fallback; not Baseline in every browser.',
			],
			[
				'capability'   => 'web-animations-api',
				'status'       => 'available',
				'triggers'     => [ 'viewport-enter', 'state-change' ],
				'description'  => 'Imperative control only where justified; cancel/revert on removal and reduced-motion change.',
			],
			[
				'capability'   => 'interactivity-api',
				'status'       => $major >= 7 || ( 6 === $major && self::minor_version( $version ) >= 5 ) ? 'available' : 'unsupported',
				'triggers'     => [ 'state-change' ],
				'description'  => 'State/actions/directives for interactive blocks, including play/pause state. Not a general motion motor.',
			],
		];

		return [
			'architecture'              => 'block-editor-fse',
			'available'                 => true,
			'native_motion_capabilities'=> $capabilities,
			'device_support'            => [
				'mode'    => 'full',
				'devices' => [ 'desktop', 'tablet', 'mobile' ],
				'method'  => 'CSS media queries per device.',
			],
			'reduced_motion_support'    => [
				'supported' => true,
				'method'    => 'prefers-reduced-motion media query with semantic replacement states.',
			],
			'write_path'                => 'stonewright/blocks-batch-mutate consolidated assignments; snapshot before write.',
			'notes'                     => [
				'Script Modules pipeline for Interactivity-backed blocks is a separate spike, not an assumption.',
			],
		];
	}

	private static function major_version( string $version ): int {
		return (int) ( explode( '.', $version )[0] ?? 0 );
	}

	private static function minor_version( string $version ): int {
		return (int) ( explode( '.', $version )[1] ?? 0 );
	}
}
