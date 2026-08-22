<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Motion;

/**
 * Conditional motion asset loader.
 *
 * Zero assets on pages without motion markers. The CSS ships whenever any
 * bundled preset class appears; the JS runtime loads only when a trigger that
 * needs it (viewport-enter, stagger orchestration) is present. Handles are
 * versioned by the registry fingerprint so a preset or asset change busts
 * caches deterministically. Editor contexts never autoplay entrances: the
 * runtime is frontend-only.
 */
final class MotionAssetLoader {

	public const STYLE_HANDLE = 'stonewright-motion-core';
	public const SCRIPT_HANDLE = 'stonewright-motion-runtime';

	private static bool $registered = false;

	/**
	 * Hooks the late conditional enqueue. Safe to call from the plugin boot.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_for_request' ] );
	}

	/**
	 * True when the content references at least one allowlisted preset class.
	 */
	public static function content_uses_motion( string $content ): bool {
		foreach ( array_keys( MotionPresetRegistry::presets() ) as $slug ) {
			if ( self::has_class_token( $content, 'stw-motion-' . $slug ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when the content needs the JS runtime. All entrance presets
	 * (load/viewport-enter) and stagger orchestration reveal through the
	 * runtime; without it content stays fully visible (static-first).
	 * Hover/focus presets resolve in pure CSS.
	 */
	public static function content_needs_runtime( string $content ): bool {
		foreach ( MotionPresetRegistry::presets() as $slug => $preset ) {
			if ( ! empty( $preset['requires_runtime'] ) && self::has_class_token( $content, 'stw-motion-' . $slug ) ) {
				return true;
			}
		}
		if ( preg_match( '/(?<![A-Za-z0-9_-])stw-motion-(?:duration|delay|stagger-interval)--[0-9]+(?![A-Za-z0-9_-])/', $content ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Frontend entry point: inspects the main queried content once.
	 */
	public static function enqueue_for_request(): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		if ( $post_id <= 0 ) {
			return;
		}

		$content = (string) get_post_field( 'post_content', $post_id );
		self::enqueue_for_content( $content );
	}

	/**
	 * Enqueues exactly the assets the given rendered content requires.
	 *
	 * @return array{css:bool, js:bool, fingerprint:string}
	 */
	public static function enqueue_for_content( string $content ): array {
		$fingerprint = MotionPresetRegistry::fingerprint();

		if ( ! self::content_uses_motion( $content ) ) {
			return [ 'css' => false, 'js' => false, 'fingerprint' => $fingerprint ];
		}

		wp_enqueue_style(
			self::STYLE_HANDLE,
			self::asset_url( MotionPresetRegistry::manifest()['assets']['css']['path'] ?? '' ),
			[],
			$fingerprint
		);

		$js = false;
		if ( self::content_needs_runtime( $content ) ) {
			wp_enqueue_script(
				self::SCRIPT_HANDLE,
				self::asset_url( MotionPresetRegistry::manifest()['assets']['js']['path'] ?? '' ),
				[],
				$fingerprint,
				false
			);
			$js = true;
		}

		return [ 'css' => true, 'js' => $js, 'fingerprint' => $fingerprint ];
	}

	private static function asset_url( string $relative_path ): string {
		if ( defined( 'STONEWRIGHT_URL' ) && '' !== (string) constant( 'STONEWRIGHT_URL' ) ) {
			return (string) constant( 'STONEWRIGHT_URL' ) . $relative_path;
		}
		return $relative_path;
	}

	private static function has_class_token( string $content, string $class ): bool {
		return 1 === preg_match(
			'/(?<![A-Za-z0-9_-])' . preg_quote( $class, '/' ) . '(?![A-Za-z0-9_-])/',
			$content
		);
	}
}
