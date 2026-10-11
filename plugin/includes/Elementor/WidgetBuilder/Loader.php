<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\WidgetBuilder;

use Stonewright\WpMcp\Sandbox\SandboxFiles;

/**
 * Loads Stonewright-built Elementor widgets at runtime.
 *
 * Widget files live in SandboxFiles::draft_dir(), NOT in mu-plugins, stored
 * as widget-<slug>.draft so no web server can run them. A file is loaded only
 * when its slug is on the registered-widgets list (RegisteredWidgets, filled by
 * stonewright/elementor-widget-register and by create-custom-widget with
 * activate=true): a file that is merely present in the draft folder is not a
 * registered widget. The loader require_once's each one on the
 * elementor/widgets/register hook.
 *
 * The generated file's self-contained registration closure then fires
 * and adds the widget class to Elementor's widget manager.
 *
 * Skipped:
 *  - slugs that are not registered, and registered slugs without an active file;
 *  - *.pending.draft: not yet approved via stonewright/elementor-widget-register.
 */
final class Loader {

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
	 * Require_once the active file of every registered widget.
	 *
	 * Called on elementor/widgets/register at priority 20.
	 * Passes $widgets_manager directly to the file's register_with_manager()
	 * function so that widgets register immediately — no secondary add_action
	 * hook needed in the generated file.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager The Elementor widget manager.
	 */
	public static function load_widgets( \Elementor\Widgets_Manager $widgets_manager ): void {
		foreach ( RegisteredWidgets::all() as $slug ) {
			$file = SandboxFiles::stored_path( 'widget-' . $slug . '.php' );
			if ( ! is_file( $file ) ) {
				continue;
			}

			// I1 — StaticGuard re-scan before require_once.
			// If a registered widget's file was changed on disk it must still pass the guard.
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
			$fn = 'stonewright_register_widget_' . str_replace( '-', '_', $slug ) . '_with_manager';
			if ( function_exists( $fn ) ) {
				$fn( $widgets_manager );
			}
		}
	}
}
