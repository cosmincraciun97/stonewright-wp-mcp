<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorWidgets;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\WidgetBuilder\Loader;
use Stonewright\WpMcp\Elementor\WidgetBuilder\RegisteredWidgets;
use Stonewright\WpMcp\Elementor\WidgetBuilder\ScanCache;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Support\AgentNotices;

/**
 * The widget loader scans a generated widget file once per file version: a verdict is cached for the
 * exact bytes (sha256) of one slug, a rejected file stays rejected on every request and is logged once.
 *
 * @covers \Stonewright\WpMcp\Elementor\WidgetBuilder\Loader
 * @covers \Stonewright\WpMcp\Elementor\WidgetBuilder\ScanCache
 * @covers \Stonewright\WpMcp\Elementor\WidgetBuilder\RegisteredWidgets
 */
final class WidgetLoaderScanCacheTest extends TestCase {

	private string $log_file;

	private string $previous_error_log;

	/** @var \Elementor\Widgets_Manager */
	private \Elementor\Widgets_Manager $manager_spy;

	protected function setUp(): void {
		$dir = WP_CONTENT_DIR . '/stonewright-sandbox';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0700, true );
		}
		foreach ( glob( $dir . '/widget-scn-*.draft' ) ?: [] as $file ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$GLOBALS['stonewright_test_options']['stonewright_registered_widgets'] = [];
		unset( $GLOBALS['stonewright_test_options'][ ScanCache::OPTION ], $GLOBALS['stonewright_test_options'][ AgentNotices::OPTION ] );
		$GLOBALS['loader_loaded'] = [];

		$this->log_file           = (string) tempnam( sys_get_temp_dir(), 'sw-scan-log-' );
		$this->previous_error_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		$this->manager_spy = new class() extends \Elementor\Widgets_Manager {
			public function register( \Elementor\Widget_Base $widget ): void {}
		};
	}

	protected function tearDown(): void {
		Loader::set_scanner( null );
		ScanCache::set_guard_id( null );
		ini_set( 'error_log', $this->previous_error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		@unlink( $this->log_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		foreach ( glob( WP_CONTENT_DIR . '/stonewright-sandbox/widget-scn-*.draft' ) ?: [] as $file ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		unset(
			$GLOBALS['stonewright_test_options']['stonewright_registered_widgets'],
			$GLOBALS['stonewright_test_options'][ ScanCache::OPTION ],
			$GLOBALS['stonewright_test_options'][ AgentNotices::OPTION ],
			$GLOBALS['stonewright_test_filters'][ 'pre_update_option_' . ScanCache::OPTION ],
			$GLOBALS['loader_loaded']
		);
	}

	/** A unique slug per test: require_once never runs the same path twice in one process. */
	private function slug( string $tag ): string {
		return 'scn-' . $tag . '-' . substr( md5( uniqid( $tag, true ) ), 0, 8 );
	}

	private function write_widget( string $slug, string $marker ): string {
		$path = SandboxFiles::stored_path( 'widget-' . $slug . '.php' );
		file_put_contents( $path, "<?php \$GLOBALS['loader_loaded'][] = " . var_export( $marker, true ) . ";\n" );
		return $path;
	}

	/** Source the guard rejects; the keyword is concatenated to keep repo-wide scanners quiet. */
	private function write_rejected_widget( string $slug, string $marker ): string {
		$path = SandboxFiles::stored_path( 'widget-' . $slug . '.php' );
		$bad  = 'ev' . 'al';
		file_put_contents( $path, "<?php \$GLOBALS['loader_loaded'][] = " . var_export( $marker, true ) . "; {$bad}('1+1');\n" );
		return $path;
	}

	private function register( string $slug ): void {
		$GLOBALS['stonewright_test_options']['stonewright_registered_widgets'][] = $slug;
	}

	/** Installs a counting scanner over the real guard and returns the call counter. */
	private function counting_scanner(): \ArrayObject {
		$calls = new \ArrayObject( [ 'n' => 0 ] );
		Loader::set_scanner(
			static function ( string $code ) use ( $calls ): array {
				$calls['n'] = $calls['n'] + 1;
				return \Stonewright\WpMcp\Sandbox\StaticGuard::scan( $code );
			}
		);
		return $calls;
	}

	/** Counts writes of the cache option. */
	private function count_cache_writes(): \ArrayObject {
		$writes = new \ArrayObject( [ 'n' => 0 ] );
		add_filter(
			'pre_update_option_' . ScanCache::OPTION,
			static function ( $value ) use ( $writes ) {
				$writes['n'] = $writes['n'] + 1;
				return $value;
			},
			10,
			3
		);
		return $writes;
	}

	/** @return list<string> The structured widget_rejected lines written to the error log. */
	private function rejection_log_lines(): array {
		$lines = [];
		foreach ( file( $this->log_file, FILE_IGNORE_NEW_LINES ) ?: [] as $line ) {
			if ( str_contains( $line, 'stonewright.widget_rejected' ) ) {
				$lines[] = $line;
			}
		}
		return $lines;
	}

	public function test_second_load_of_an_unchanged_file_does_not_scan(): void {
		$slug = $this->slug( 'same' );
		$this->write_widget( $slug, $slug );
		$this->register( $slug );
		$scans = $this->counting_scanner();

		Loader::load_widgets( $this->manager_spy );
		$writes = $this->count_cache_writes();
		Loader::load_widgets( $this->manager_spy );
		Loader::load_widgets( $this->manager_spy );

		self::assertSame( 1, $scans['n'], 'the file is scanned on the first load only' );
		self::assertSame( 0, $writes['n'], 'a cache hit writes nothing' );
		self::assertSame( [ $slug ], $GLOBALS['loader_loaded'], 'the clean file loads' );
	}

	public function test_changed_file_is_rescanned(): void {
		$slug = $this->slug( 'chg' );
		$path = $this->write_widget( $slug, 'first' );
		$this->register( $slug );
		$scans = $this->counting_scanner();

		Loader::load_widgets( $this->manager_spy );
		self::assertSame( [ 'first' ], $GLOBALS['loader_loaded'] );

		// The verdict of the first bytes must not carry over to a file that now fails the guard.
		$this->write_rejected_widget( $slug, 'second' );
		self::assertFileExists( $path );
		Loader::load_widgets( $this->manager_spy );

		self::assertSame( 2, $scans['n'], 'the changed file is scanned again' );
		self::assertNotContains( 'second', $GLOBALS['loader_loaded'], 'the changed, rejected file is not loaded' );
	}

	public function test_a_rejected_widget_stays_rejected_on_every_load_without_scanning_again(): void {
		$slug = $this->slug( 'rej' );
		$this->write_rejected_widget( $slug, 'evil' );
		$this->register( $slug );
		$scans = $this->counting_scanner();

		Loader::load_widgets( $this->manager_spy );
		Loader::load_widgets( $this->manager_spy );
		Loader::load_widgets( $this->manager_spy );

		self::assertSame( 1, $scans['n'], 'the rejection is cached' );
		self::assertNotContains( 'evil', $GLOBALS['loader_loaded'], 'a cached rejection never loads the file' );
		$verdict = ScanCache::verdict( $slug, hash( 'sha256', (string) file_get_contents( SandboxFiles::stored_path( 'widget-' . $slug . '.php' ) ) ) );
		self::assertNotNull( $verdict );
		self::assertNotSame( [], $verdict['findings'] );
	}

	public function test_a_cached_pass_for_other_bytes_does_not_let_a_failing_file_through(): void {
		$slug = $this->slug( 'swap' );
		$path = $this->write_widget( $slug, 'clean' );
		$this->register( $slug );

		// A pass is cached for the clean bytes; the file is then replaced.
		ScanCache::remember( $slug, hash( 'sha256', (string) file_get_contents( $path ) ), [] );
		$this->write_rejected_widget( $slug, 'swapped' );

		Loader::load_widgets( $this->manager_spy );

		self::assertSame( [], $GLOBALS['loader_loaded'], 'the verdict belongs to the old bytes only' );
	}

	public function test_a_cached_pass_for_another_slug_does_not_count(): void {
		$slug  = $this->slug( 'own' );
		$other = $this->slug( 'oth' );
		$path  = $this->write_rejected_widget( $slug, 'evil' );
		$this->register( $slug );

		ScanCache::remember( $other, hash( 'sha256', (string) file_get_contents( $path ) ), [] );

		Loader::load_widgets( $this->manager_spy );

		self::assertSame( [], $GLOBALS['loader_loaded'] );
	}

	public function test_rejection_is_logged_once_and_pushed_as_an_agent_notice(): void {
		$slug = $this->slug( 'log' );
		$this->write_rejected_widget( $slug, 'evil' );
		$this->register( $slug );

		Loader::load_widgets( $this->manager_spy );
		Loader::load_widgets( $this->manager_spy );
		Loader::load_widgets( $this->manager_spy );

		$lines = $this->rejection_log_lines();
		self::assertCount( 1, $lines, 'three requests log the same rejection once' );
		self::assertStringContainsString( $slug, $lines[0] );

		$notices = AgentNotices::fields();
		self::assertArrayHasKey( 'notices', $notices );
		self::assertCount( 1, $notices['notices'] );
		self::assertStringContainsString( $slug, $notices['notices'][0] );
		$key    = 'widget_rejected_' . substr( md5( $slug ), 0, 8 );
		$stored = $GLOBALS['stonewright_test_options'][ AgentNotices::OPTION ][ $key ] ?? null;
		self::assertIsArray( $stored );
		self::assertSame( DAY_IN_SECONDS, $stored['expires'] - $stored['at'] );
	}

	public function test_a_rejection_after_a_further_edit_is_logged_again(): void {
		$slug = $this->slug( 'again' );
		$this->write_rejected_widget( $slug, 'evil-one' );
		$this->register( $slug );

		Loader::load_widgets( $this->manager_spy );
		$this->write_rejected_widget( $slug, 'evil-two' );
		Loader::load_widgets( $this->manager_spy );
		Loader::load_widgets( $this->manager_spy );

		self::assertCount( 2, $this->rejection_log_lines(), 'one line per file version' );
	}

	public function test_the_cache_is_written_once_per_verdict_and_never_on_a_hit(): void {
		$slug = $this->slug( 'wr' );
		$this->write_rejected_widget( $slug, 'evil' );
		$this->register( $slug );
		$writes = $this->count_cache_writes();

		Loader::load_widgets( $this->manager_spy );
		$after_first = $writes['n'];
		Loader::load_widgets( $this->manager_spy );
		Loader::load_widgets( $this->manager_spy );

		self::assertSame( 1, $after_first, 'a rejected miss is stored once, already marked as logged' );
		self::assertSame( $after_first, $writes['n'], 'a hit writes nothing' );
	}

	public function test_two_rejected_widgets_each_keep_their_own_notice(): void {
		$first  = $this->slug( 'nta' );
		$second = $this->slug( 'ntb' );
		$this->write_rejected_widget( $first, 'evil-a' );
		$this->write_rejected_widget( $second, 'evil-b' );
		$this->register( $first );
		$this->register( $second );

		Loader::load_widgets( $this->manager_spy );

		$notices = AgentNotices::fields()['notices'] ?? [];
		self::assertCount( 2, $notices );
		self::assertStringContainsString( $first, implode( ' ', $notices ) );
		self::assertStringContainsString( $second, implode( ' ', $notices ) );
	}

	public function test_a_pass_cached_under_another_guard_id_is_rescanned_and_rejected(): void {
		$slug = $this->slug( 'gpass' );
		$path = $this->write_rejected_widget( $slug, 'evil' );
		$this->register( $slug );
		$hash = hash( 'sha256', (string) file_get_contents( $path ) );

		// An older guard let these exact bytes through.
		ScanCache::set_guard_id( 'older-guard:0' );
		ScanCache::remember( $slug, $hash, [] );
		ScanCache::set_guard_id( null );
		$scans = $this->counting_scanner();

		Loader::load_widgets( $this->manager_spy );

		self::assertSame( 1, $scans['n'], 'the stale pass is a miss, so the current guard scans' );
		self::assertSame( [], $GLOBALS['loader_loaded'], 'the file the current guard rejects does not load' );
	}

	public function test_a_rejection_cached_under_another_guard_id_is_rescanned(): void {
		$slug = $this->slug( 'grej' );
		$path = $this->write_widget( $slug, 'clean-now' );
		$this->register( $slug );
		$hash = hash( 'sha256', (string) file_get_contents( $path ) );

		// A stricter guard rejected these bytes; the current one passes them.
		ScanCache::set_guard_id( 'older-guard:0' );
		ScanCache::remember( $slug, $hash, [ 'Disallowed token: old rule (line 1)' ], true );
		ScanCache::set_guard_id( null );
		$scans = $this->counting_scanner();

		Loader::load_widgets( $this->manager_spy );

		self::assertSame( 1, $scans['n'] );
		self::assertSame( [ 'clean-now' ], $GLOBALS['loader_loaded'] );
	}

	public function test_an_entry_without_a_guard_id_is_a_miss(): void {
		$hash                                                    = hash( 'sha256', 'a' );
		$GLOBALS['stonewright_test_options'][ ScanCache::OPTION ] = [
			'scn-a' => [ 'sha256' => $hash, 'findings' => [], 'logged' => false ],
			'scn-b' => [ 'sha256' => $hash, 'findings' => [], 'logged' => false, 'guard' => 7 ],
		];

		self::assertNull( ScanCache::verdict( 'scn-a', $hash ) );
		self::assertNull( ScanCache::verdict( 'scn-b', $hash ) );
	}

	public function test_the_guard_id_names_the_plugin_version_and_the_rules_version(): void {
		self::assertSame( STONEWRIGHT_VERSION . ':' . \Stonewright\WpMcp\Sandbox\StaticGuard::RULES_VERSION, ScanCache::guard_id() );
	}

	public function test_forgetting_a_widget_drops_its_verdict(): void {
		$slug = $this->slug( 'fgt' );
		$this->write_widget( $slug, $slug );
		$this->register( $slug );
		Loader::load_widgets( $this->manager_spy );
		$hash = hash( 'sha256', (string) file_get_contents( SandboxFiles::stored_path( 'widget-' . $slug . '.php' ) ) );
		self::assertNotNull( ScanCache::verdict( $slug, $hash ) );

		RegisteredWidgets::forget( $slug );

		self::assertNull( ScanCache::verdict( $slug, $hash ) );
		self::assertNotContains( $slug, RegisteredWidgets::all() );
	}

	public function test_forgetting_an_unknown_slug_writes_nothing(): void {
		$writes = $this->count_cache_writes();

		ScanCache::forget( 'scn-nothing-here' );

		self::assertSame( 0, $writes['n'] );
	}

	// -------------------------------------------------------------------------
	// ScanCache itself
	// -------------------------------------------------------------------------

	public function test_a_verdict_is_returned_only_for_the_same_slug_and_hash(): void {
		$hash = hash( 'sha256', 'a' );
		ScanCache::remember( 'scn-a', $hash, [ 'Disallowed token: eval (line 2)' ] );

		self::assertSame(
			[
				'findings' => [ 'Disallowed token: eval (line 2)' ],
				'logged'   => false,
			],
			ScanCache::verdict( 'scn-a', $hash )
		);
		self::assertNull( ScanCache::verdict( 'scn-a', hash( 'sha256', 'b' ) ) );
		self::assertNull( ScanCache::verdict( 'scn-b', $hash ) );
		self::assertNull( ScanCache::verdict( 'scn-a', 'not-a-hash' ) );
	}

	public function test_remembering_the_same_verdict_again_writes_nothing(): void {
		$hash = hash( 'sha256', 'a' );
		ScanCache::remember( 'scn-a', $hash, [ 'x' ] );
		$writes = $this->count_cache_writes();

		ScanCache::remember( 'scn-a', $hash, [ 'x' ] );
		self::assertSame( 0, $writes['n'] );

		ScanCache::remember( 'scn-a', $hash, [ 'x' ], true );
		self::assertSame( 1, $writes['n'], 'marking the rejection as logged is a change' );
		self::assertTrue( ScanCache::verdict( 'scn-a', $hash )['logged'] ?? false );
	}

	public function test_the_option_is_autoloaded_and_holds_at_most_one_hundred_entries_oldest_evicted(): void {
		for ( $i = 1; $i <= 105; $i++ ) {
			ScanCache::remember( 'scn-w' . $i, hash( 'sha256', (string) $i ), [] );
		}

		self::assertTrue( $GLOBALS['stonewright_test_option_autoload'][ ScanCache::OPTION ] );
		self::assertCount( ScanCache::MAX_ENTRIES, $GLOBALS['stonewright_test_options'][ ScanCache::OPTION ] );
		self::assertSame( 100, ScanCache::MAX_ENTRIES );
		self::assertNull( ScanCache::verdict( 'scn-w1', hash( 'sha256', '1' ) ), 'the oldest entries went first' );
		self::assertNull( ScanCache::verdict( 'scn-w5', hash( 'sha256', '5' ) ) );
		self::assertNotNull( ScanCache::verdict( 'scn-w6', hash( 'sha256', '6' ) ) );
		self::assertNotNull( ScanCache::verdict( 'scn-w105', hash( 'sha256', '105' ) ) );
	}

	public function test_findings_are_stored_as_a_few_short_strings(): void {
		$hash     = hash( 'sha256', 'a' );
		$findings = [];
		for ( $i = 0; $i < 10; $i++ ) {
			$findings[] = str_repeat( 'x', 500 ) . $i;
		}

		ScanCache::remember( 'scn-a', $hash, $findings );

		$verdict = ScanCache::verdict( 'scn-a', $hash );
		self::assertNotNull( $verdict );
		self::assertCount( ScanCache::MAX_FINDINGS, $verdict['findings'] );
		foreach ( $verdict['findings'] as $finding ) {
			self::assertLessThanOrEqual( ScanCache::MAX_FINDING_LENGTH, strlen( $finding ) );
		}
	}

	public function test_a_failing_scan_always_stores_a_non_empty_verdict(): void {
		$hash = hash( 'sha256', 'a' );

		ScanCache::remember( 'scn-a', $hash, [ '', '   ' ] );

		$verdict = ScanCache::verdict( 'scn-a', $hash );
		self::assertNotNull( $verdict );
		self::assertNotSame( [], $verdict['findings'], 'blank findings still mean a rejection' );
	}

	public function test_a_malformed_stored_option_is_ignored(): void {
		$hash                                                    = hash( 'sha256', 'a' );
		$GLOBALS['stonewright_test_options'][ ScanCache::OPTION ] = [
			'scn-a' => 'nope',
			'scn-b' => [ 'sha256' => $hash, 'findings' => 'text', 'logged' => true ],
			'scn-c' => [ 'sha256' => $hash, 'findings' => [ [ 'nested' ] ], 'logged' => true ],
		];

		self::assertNull( ScanCache::verdict( 'scn-a', $hash ) );
		self::assertNull( ScanCache::verdict( 'scn-b', $hash ) );
		self::assertNull( ScanCache::verdict( 'scn-c', $hash ) );

		$GLOBALS['stonewright_test_options'][ ScanCache::OPTION ] = 'garbage';
		self::assertNull( ScanCache::verdict( 'scn-a', $hash ) );
	}
}
