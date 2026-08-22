<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Motion;

/**
 * Versioned registry of bundled motion presets.
 *
 * Exactly the approved core set: original CSS, static-first, transform/opacity
 * only, no blur, no broad will-change, no remote assets. Preset classes are
 * product code with an explicit allowlist here — never user-supplied CSS.
 *
 * The manifest and its fingerprint bind plans, receipts, and asset versions
 * together: a preset change produces a new fingerprint and invalidates cached
 * capability digests and plan hashes.
 */
final class MotionPresetRegistry {

	public const VERSION = '1.0.0';

	private const CSS_PATH = 'assets/frontend/motion-core.css';
	private const JS_PATH  = 'assets/frontend/motion-core.js';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function presets(): array {
		return [
			'fade-in'          => [
				'label'             => 'Fade in',
				'class'             => 'stw-motion-fade-in',
				'triggers'          => [ 'load', 'viewport-enter' ],
				'requires_runtime'  => true,
				'reduced_motion'    => 'replace-with-fade',
				'rtl_aware'         => false,
				'focus_parity'      => false,
				'orchestration'     => false,
			],
			'fade-up'          => [
				'label'             => 'Fade up',
				'class'             => 'stw-motion-fade-up',
				'triggers'          => [ 'load', 'viewport-enter' ],
				'requires_runtime'  => true,
				'reduced_motion'    => 'replace-with-fade',
				'rtl_aware'         => false,
				'focus_parity'      => false,
				'orchestration'     => false,
			],
			'slide-in-inline'  => [
				'label'             => 'Slide in (inline direction)',
				'class'             => 'stw-motion-slide-in-inline',
				'triggers'          => [ 'load', 'viewport-enter' ],
				'requires_runtime'  => true,
				'reduced_motion'    => 'replace-with-fade',
				'rtl_aware'         => true,
				'focus_parity'      => false,
				'orchestration'     => false,
			],
			'scale-in-subtle'  => [
				'label'             => 'Scale in subtle',
				'class'             => 'stw-motion-scale-in-subtle',
				'triggers'          => [ 'load', 'viewport-enter' ],
				'requires_runtime'  => true,
				'reduced_motion'    => 'replace-with-fade',
				'rtl_aware'         => false,
				'focus_parity'      => false,
				'orchestration'     => false,
			],
			'card-lift'        => [
				'label'             => 'Card lift',
				'class'             => 'stw-motion-card-lift',
				'triggers'          => [ 'hover', 'focus-visible' ],
				'requires_runtime'  => false,
				'reduced_motion'    => 'static-end-state',
				'rtl_aware'         => false,
				'focus_parity'      => true,
				'orchestration'     => false,
			],
			'link-underline'   => [
				'label'             => 'Link underline',
				'class'             => 'stw-motion-link-underline',
				'triggers'          => [ 'hover', 'focus-visible' ],
				'requires_runtime'  => false,
				'reduced_motion'    => 'static-end-state',
				'rtl_aware'         => true,
				'focus_parity'      => true,
				'orchestration'     => false,
			],
			'stagger-reveal'   => [
				'label'             => 'Stagger reveal',
				'class'             => 'stw-motion-stagger-reveal',
				'triggers'          => [ 'viewport-enter' ],
				'requires_runtime'  => true,
				'reduced_motion'    => 'replace-with-fade',
				'rtl_aware'         => false,
				'focus_parity'      => false,
				// Orchestration over child presets, not its own keyframe.
				'orchestration'     => true,
				'child_effect'      => 'fade-up',
			],
		];
	}

	/**
	 * @return list<string>
	 */
	public static function slugs(): array {
		return array_keys( self::presets() );
	}

	public static function has( string $slug ): bool {
		return array_key_exists( $slug, self::presets() );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( string $slug ): ?array {
		return self::presets()[ $slug ] ?? null;
	}

	/**
	 * CSS variables presets may consume. Anything outside this allowlist is
	 * rejected at compile time.
	 *
	 * @return list<string>
	 */
	public static function css_variables_allowlist(): array {
		return [
			'--stw-motion-duration',
			'--stw-motion-delay',
			'--stw-motion-distance',
			'--stw-motion-easing',
			'--stw-motion-stagger-delay',
		];
	}

	/**
	 * Versioned asset manifest with content checksums. The JS runtime ships
	 * only because stagger-reveal/viewport-enter need it; pages whose motion
	 * resolves to CSS-only triggers never load it.
	 *
	 * @return array<string, mixed>
	 */
	public static function manifest(): array {
		return [
			'version'  => self::VERSION,
			'presets'  => self::presets(),
			'assets'   => [
				'css' => [
					'handle'  => 'stonewright-motion-core',
					'path'    => self::CSS_PATH,
					'exists'  => is_file( STONEWRIGHT_DIR . self::CSS_PATH ),
					'sha256'  => self::file_hash( self::CSS_PATH ),
					'budget_bytes_gzip' => 8192,
				],
				'js'  => [
					'handle'  => 'stonewright-motion-runtime',
					'path'    => self::JS_PATH,
					'exists'  => is_file( STONEWRIGHT_DIR . self::JS_PATH ),
					'sha256'  => self::file_hash( self::JS_PATH ),
					'budget_bytes_gzip' => 6144,
				],
			],
			'decisions' => [
				'uses_waapi'            => false,
				'uses_blur'             => false,
				'broad_will_change'     => false,
				'remote_assets'         => false,
				'editor_autoplay'       => false,
				'no_js_visibility'      => 'Static-first: initial hidden states apply only under html.stw-motion-js.',
				'reduced_motion_dynamic'=> 'Runtime toggles html.stw-motion-reduced live on matchMedia change; no reload.',
				'listener_dedup'        => 'Single init guard; observers disconnect on pagehide.',
			],
		];
	}

	/**
	 * Stable fingerprint over the whole manifest. Binds plan hashes and
	 * receipts to an exact preset + asset state.
	 */
	public static function fingerprint(): string {
		return hash( 'sha256', wp_json_encode( self::manifest() ) ?: '' );
	}

	/**
	 * @return string
	 */
	private static function file_hash( string $relative_path ): string {
		$absolute = STONEWRIGHT_DIR . $relative_path;
		if ( ! is_file( $absolute ) ) {
			return '';
		}
		$contents = file_get_contents( $absolute );
		if ( false === $contents ) {
			return '';
		}
		return hash( 'sha256', $contents );
	}
}
