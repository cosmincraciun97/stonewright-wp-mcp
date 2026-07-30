<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\WidgetBuilder;

use Stonewright\WpMcp\Sandbox\SandboxFiles;

/**
 * Loads Stonewright-built Elementor widgets at runtime.
 *
 * Widget files live in SandboxFiles::draft_dir(), NOT in mu-plugins.
 * This loader scans draft_dir for files matching the pattern
 * widget-<slug>.php (without the .pending suffix) on the
 * elementor/widgets/register hook and require_once's each one.
 *
 * The generated file's self-contained registration closure then fires
 * and adds the widget class to Elementor's widget manager.
 *
 * Skipped files:
 *  - *.pending.php — not yet approved via widget_register.
 *  - Any filename that does not match ^widget-[a-z0-9_-]+\.php$ exactly.
 */
final class Loader {

	/** Pattern for active (non-pending) widget files. */
	private const ACTIVE_PATTERN = '/^widget-[a-z0-9_-]+\.php$/';

	/** Pattern that identifies a pending file — must be excluded. */
	private const PENDING_SUFFIX = '.pending.php';

	private static bool $registered = false;

	/**
	 * Wire the Elementor hook. Safe to call multiple times.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		// elementor/widgets/register is the canonical hook since Elementor 3.5.
		add_action( 'elementor/widgets/register', [ self::class, 'load_widgets' ], 20 );
	}

	/**
	 * Scan draft_dir and require_once every active widget file.
	 *
	 * Called on elementor/widgets/register at priority 20.
	 * Passes $widgets_manager directly to the file's register_with_manager()
	 * function so that widgets register immediately — no secondary add_action
	 * hook needed in the generated file.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager The Elementor widget manager.
	 */
	public static function load_widgets( \Elementor\Widgets_Manager $widgets_manager ): void {
		$draft_dir = SandboxFiles::draft_dir();

		$files = glob( $draft_dir . '/widget-*.php' );
		if ( false === $files ) {
			return;
		}

		foreach ( $files as $file ) {
			$base = basename( $file );

			// Skip pending files — they are not yet approved.
			if ( str_ends_with( $base, self::PENDING_SUFFIX ) ) {
				continue;
			}

			// Enforce strict name pattern.
			if ( ! preg_match( self::ACTIVE_PATTERN, $base ) ) {
				continue;
			}

			// I1 — StaticGuard re-scan before require_once.
			// If an attacker drops a file directly into draft_dir it must be rejected.
			$content = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$findings = \Stonewright\WpMcp\Sandbox\StaticGuard::scan( $content );
			if ( ! empty( $findings ) ) {
				error_log( '[stonewright] widget loader rejected ' . $file . ': ' . wp_json_encode( $findings ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				continue;
			}

			// M3 — Wrap require_once in try/catch for parse errors.
			try {
				require_once $file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
			} catch ( \Throwable $e ) {
				error_log( '[stonewright] widget loader parse error in ' . $file . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				continue;
			}

			// C1 — After requiring the file, call register_with_manager() directly
			// instead of relying on a secondary add_action hook in the generated file.
			// The generated function is named stonewright_register_widget_<safe_slug>.
			$slug_part = preg_replace( '/^widget-(.+)\.php$/', '$1', $base );
			if ( null !== $slug_part ) {
				$safe_fn_slug = str_replace( '-', '_', $slug_part );
				$fn           = 'stonewright_register_widget_' . $safe_fn_slug . '_with_manager';
				if ( function_exists( $fn ) ) {
					$fn( $widgets_manager );
				}
			}
		}
	}
}
