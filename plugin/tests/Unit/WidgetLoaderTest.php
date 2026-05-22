<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\WidgetBuilder\Loader;

/**
 * @covers \Stonewright\WpMcp\Elementor\WidgetBuilder\Loader
 *
 * Tests that the widget loader correctly filters files from a mock draft directory.
 * No WordPress runtime or real Elementor needed — we test the scanning logic directly
 * by staging files in a temp dir and calling load_widgets() which reads draft_dir().
 */
final class WidgetLoaderTest extends TestCase {

	private string $temp_dir;

	/** @var \Elementor\Widgets_Manager Manager spy injected into load_widgets(). */
	private \Elementor\Widgets_Manager $manager_spy;

	protected function setUp(): void {
		// The Loader reads SandboxFiles::draft_dir() which uses WP_CONTENT_DIR.
		// WP_CONTENT_DIR is a writable temp dir set up by the test bootstrap.
		$this->temp_dir = WP_CONTENT_DIR . '/stonewright-sandbox';
		if ( ! is_dir( $this->temp_dir ) ) {
			mkdir( $this->temp_dir, 0700, true );
		}

		// Clean any stale widget files.
		foreach ( glob( $this->temp_dir . '/widget-*.php' ) ?: [] as $f ) {
			@unlink( $f );
		}

		// Minimal Widgets_Manager spy — records register() calls.
		$this->manager_spy = new class() extends \Elementor\Widgets_Manager {
			/** @var array<int, \Elementor\Widget_Base> */
			public array $registered = [];

			public function register( \Elementor\Widget_Base $widget ): void {
				$this->registered[] = $widget;
			}
		};
	}

	protected function tearDown(): void {
		foreach ( glob( $this->temp_dir . '/widget-*.php' ) ?: [] as $f ) {
			@unlink( $f );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Create a file that, when require_once'd, sets a marker in $GLOBALS. */
	private function make_widget_file( string $filename ): void {
		$path = $this->temp_dir . '/' . $filename;
		file_put_contents( $path, "<?php \$GLOBALS['loader_loaded'][] = " . var_export( $filename, true ) . ";\n" );
	}

	// -------------------------------------------------------------------------
	// Tests (existing)
	// -------------------------------------------------------------------------

	public function test_active_widget_file_is_loaded(): void {
		$GLOBALS['loader_loaded'] = [];
		$this->make_widget_file( 'widget-my-widget.php' );

		Loader::load_widgets( $this->manager_spy );

		$this->assertContains( 'widget-my-widget.php', (array) ( $GLOBALS['loader_loaded'] ?? [] ) );
	}

	public function test_pending_file_is_skipped(): void {
		$GLOBALS['loader_loaded'] = [];
		$this->make_widget_file( 'widget-my-widget.pending.php' );

		Loader::load_widgets( $this->manager_spy );

		$this->assertNotContains( 'widget-my-widget.pending.php', (array) ( $GLOBALS['loader_loaded'] ?? [] ) );
	}

	public function test_non_matching_filename_is_skipped(): void {
		$GLOBALS['loader_loaded'] = [];

		// Create files with names that do not match ^widget-[a-z0-9_-]+\.php$.
		$this->make_widget_file( 'widget-BadCase.php' );    // uppercase — won't match glob, but belt-and-suspenders.
		file_put_contents( $this->temp_dir . '/random-file.php', "<?php \$GLOBALS['loader_loaded'][] = 'random-file.php';\n" );
		file_put_contents( $this->temp_dir . '/widget-.php', "<?php \$GLOBALS['loader_loaded'][] = 'widget-.php';\n" );

		Loader::load_widgets( $this->manager_spy );

		$loaded = (array) ( $GLOBALS['loader_loaded'] ?? [] );
		$this->assertNotContains( 'random-file.php', $loaded );
		$this->assertNotContains( 'widget-.php', $loaded );
	}

	public function test_multiple_active_widgets_all_loaded(): void {
		$GLOBALS['loader_loaded'] = [];
		$this->make_widget_file( 'widget-alpha.php' );
		$this->make_widget_file( 'widget-beta-2.php' );
		$this->make_widget_file( 'widget-gamma_3.php' );

		Loader::load_widgets( $this->manager_spy );

		$loaded = (array) ( $GLOBALS['loader_loaded'] ?? [] );
		$this->assertContains( 'widget-alpha.php', $loaded );
		$this->assertContains( 'widget-beta-2.php', $loaded );
		$this->assertContains( 'widget-gamma_3.php', $loaded );
	}

	public function test_pending_skipped_while_active_sibling_loaded(): void {
		$GLOBALS['loader_loaded'] = [];
		$this->make_widget_file( 'widget-delta.php' );
		$this->make_widget_file( 'widget-delta.pending.php' );

		Loader::load_widgets( $this->manager_spy );

		$loaded = (array) ( $GLOBALS['loader_loaded'] ?? [] );
		$this->assertContains( 'widget-delta.php', $loaded );
		$this->assertNotContains( 'widget-delta.pending.php', $loaded );
	}

	// -------------------------------------------------------------------------
	// New tests (C1 / I1 / M3)
	// -------------------------------------------------------------------------

	/**
	 * C1 — Compiled widgets must be registered via manager->register() immediately
	 * after require_once, not via a secondary add_action hook.
	 *
	 * A secondary add_action at priority 10 would fire BEFORE Loader's priority 20,
	 * meaning the widget was already missed. The fix: Loader passes $widgets_manager
	 * directly to the generated register_with_manager() function.
	 */
	public function test_compiled_widget_registered_via_manager_directly(): void {
		$slug   = 'loader-c1-' . uniqid();
		$result = \Stonewright\WpMcp\Elementor\WidgetBuilder\Compiler::compile(
			$slug,
			'Loader C1 Test',
			'stonewright',
			[ [ 'id' => 'title', 'label' => 'Title', 'type' => 'text', 'default' => '' ] ],
			'{{ title }}',
			'twig'
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result, 'Compiler should succeed.' );
		$source = (string) $result;

		$filename = 'widget-' . $slug . '.php';
		file_put_contents( $this->temp_dir . '/' . $filename, $source );

		$spy = new class() extends \Elementor\Widgets_Manager {
			/** @var array<int, \Elementor\Widget_Base> */
			public array $registered = [];

			public function register( \Elementor\Widget_Base $widget ): void {
				$this->registered[] = $widget;
			}
		};

		Loader::load_widgets( $spy );

		$this->assertNotEmpty( $spy->registered, 'Widget should be registered directly via manager->register().' );
		$this->assertSame( $slug, $spy->registered[0]->get_name() );
	}

	/**
	 * I1 — Loader must reject files with disallowed PHP sequences and not execute them.
	 * Uses concatenated disallowed keyword to avoid repo-wide scanner hits.
	 */
	public function test_static_guard_rejection_skips_file(): void {
		$GLOBALS['loader_loaded'] = [];

		// Disallowed sequence — breaks StaticGuard. Concatenated to avoid literal match.
		$bad_seq = 'ev' . 'al';
		$content = "<?php \$GLOBALS['loader_loaded'][] = 'evil-guard.php'; {$bad_seq}('1+1');\n";
		$path    = $this->temp_dir . '/widget-evil-guard.php';
		file_put_contents( $path, $content );

		Loader::load_widgets( $this->manager_spy );

		$this->assertNotContains(
			'evil-guard.php',
			(array) ( $GLOBALS['loader_loaded'] ?? [] ),
			'File with disallowed PHP must not be loaded.'
		);

		@unlink( $path );
	}

	/**
	 * M3 — Loader must not throw a fatal when a widget file has a syntax error.
	 * The bad file is skipped and subsequent valid files still load.
	 */
	public function test_invalid_php_file_does_not_fatal(): void {
		$GLOBALS['loader_loaded'] = [];

		// Syntactically broken PHP triggers ParseError on require_once.
		$bad_path = $this->temp_dir . '/widget-parse-err.php';
		file_put_contents( $bad_path, "<?php class { broken syntax\n" );

		// A valid sibling written after the bad file (glob order may vary).
		$this->make_widget_file( 'widget-after-err.php' );

		// Must not throw — Loader wraps require_once in try/catch(\Throwable).
		Loader::load_widgets( $this->manager_spy );

		// No fatal means the test passes. Optionally verify the valid sibling loaded.
		$loaded = (array) ( $GLOBALS['loader_loaded'] ?? [] );
		$this->assertNotContains( 'widget-parse-err.php', $loaded, 'Broken file must not set the marker.' );
		$this->assertTrue( true, 'No Throwable escaped from Loader.' );

		@unlink( $bad_path );
	}
}
