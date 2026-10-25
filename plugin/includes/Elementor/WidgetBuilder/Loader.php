<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\WidgetBuilder;

use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Support\AgentNotices;
use Stonewright\WpMcp\Support\Logger;

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

	/** @var (callable(string):array<mixed>)|null Test seam over StaticGuard::scan(). */
	private static $scanner = null;

	/**
	 * Replaces the guard scan, for tests. Null restores StaticGuard::scan().
	 *
	 * @param (callable(string):array<mixed>)|null $scanner
	 */
	public static function set_scanner( ?callable $scanner ): void {
		self::$scanner = $scanner;
	}

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
			// If a registered widget's file was changed on disk it must still pass the guard. The verdict is
			// cached per slug and per sha256 of the bytes read here, so an unchanged file is scanned once and a
			// changed file always again; a cached rejection keeps the file out on every request. The require
			// below still reads the file from disk again, as it always did, so the gap between this read and
			// that include is the one it was before: the verdict covers the bytes read here.
			$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $content ) {
				continue;
			}
			$hash    = hash( 'sha256', $content );
			$verdict = ScanCache::verdict( $slug, $hash );
			if ( null === $verdict ) {
				$findings = self::scan( $content );
				$logged   = false;
			} else {
				$findings = $verdict['findings'];
				$logged   = $verdict['logged'];
			}
			if ( ! empty( $findings ) ) {
				if ( ! $logged ) {
					self::report_rejection( $slug, $hash, $findings );
					ScanCache::remember( $slug, $hash, $findings, true );
				}
				continue;
			}
			if ( null === $verdict ) {
				ScanCache::remember( $slug, $hash, [] );
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

	/**
	 * @return array<mixed> The guard's findings for the source; empty when it passes.
	 */
	private static function scan( string $content ): array {
		$scanner = self::$scanner ?? [ \Stonewright\WpMcp\Sandbox\StaticGuard::class, 'scan' ];
		return (array) $scanner( $content );
	}

	/**
	 * One log line and one agent notice (a key of its own per slug) for a rejected file version. The caller marks the verdict as
	 * logged, so a repeat request stays quiet.
	 *
	 * @param array<mixed> $findings
	 */
	private static function report_rejection( string $slug, string $hash, array $findings ): void {
		Logger::warning(
			'widget_rejected',
			[
				'slug'     => $slug,
				'sha256'   => substr( $hash, 0, 12 ),
				'findings' => array_slice( array_map( 'strval', array_filter( $findings, 'is_scalar' ) ), 0, 10 ),
			]
		);
		AgentNotices::push(
			'widget_rejected_' . substr( md5( $slug ), 0, 8 ),
			sprintf( 'Generated widget %s was not loaded: it failed the static code check. Rewrite or delete it.', $slug ),
			DAY_IN_SECONDS
		);
	}
}
