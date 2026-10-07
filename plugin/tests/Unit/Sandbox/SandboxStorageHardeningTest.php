<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Sandbox;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Sandbox\SandboxFiles;

/**
 * No sandbox draft or backup can run outside a WordPress request, on any web
 * server: drafts and backups are stored without a PHP extension, activation is
 * the only step that produces executable code, and that code stops at once when
 * it is loaded without WordPress.
 *
 * @covers \Stonewright\WpMcp\Sandbox\SandboxFiles
 */
final class SandboxStorageHardeningTest extends TestCase {

	private const EXECUTABLE_NAME = '/\.(?:php[0-9]?|phtml|pht|phar|phps)(?:\.|$)/i';

	private string $dir;
	private string $mu_dir;

	protected function setUp(): void {
		$this->dir    = WP_CONTENT_DIR . '/stonewright-sandbox';
		$this->mu_dir = WP_CONTENT_DIR . '/mu-plugins';
		foreach ( [ $this->dir, $this->mu_dir ] as $directory ) {
			if ( ! is_dir( $directory ) ) {
				mkdir( $directory, 0755, true );
			}
		}

		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true, 'edit_plugins' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$this->clean();
	}

	protected function tearDown(): void {
		$this->clean();
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	private function clean(): void {
		foreach ( scandir( $this->dir ) ?: [] as $entry ) {
			if ( 'hard-' === substr( $entry, 0, 5 ) || 'legacy-' === substr( $entry, 0, 7 ) || 'gone' === substr( $entry, 0, 4 )
				|| 'rogue' === strtolower( substr( $entry, 0, 5 ) ) || 'dup' === substr( $entry, 0, 3 ) || 'widget-hard-' === substr( $entry, 0, 12 ) ) {
				@unlink( $this->dir . '/' . $entry );
			}
		}
		foreach ( glob( $this->mu_dir . '/stonewright-sandbox-hard-*' ) ?: [] as $file ) {
			@unlink( $file );
		}
		foreach ( glob( $this->mu_dir . '/stonewright-sandbox-legacy-*' ) ?: [] as $file ) {
			@unlink( $file );
		}
	}

	/** @return list<string> */
	private function entries(): array {
		return array_values( array_diff( scandir( $this->dir ) ?: [], [ '.', '..' ] ) );
	}

	/** @return list<string> Entries of the sandbox folder that a web server could run as PHP. */
	private function runnable_entries(): array {
		return array_values(
			array_filter(
				$this->entries(),
				static fn( string $entry ): bool => 1 === preg_match( self::EXECUTABLE_NAME, $entry )
			)
		);
	}

	/**
	 * Runs a PHP file in a separate process, the way a web server without
	 * WordPress would, optionally with ABSPATH defined first.
	 *
	 * @return array{0: string, 1: int}
	 */
	private static function run_file( string $file, bool $with_wordpress ): array {
		$prepend = sys_get_temp_dir() . '/stonewright-define-abspath-' . getmypid() . '.php';
		file_put_contents( $prepend, "<?php define( 'ABSPATH', __DIR__ . '/' );\n" );
		$command = escapeshellarg( PHP_BINARY ) . ' -n -d display_errors=1 '
			. ( $with_wordpress ? '-d auto_prepend_file=' . escapeshellarg( $prepend ) . ' ' : '' )
			. escapeshellarg( $file ) . ' 2>&1';
		$output  = [];
		$status  = 0;
		exec( $command, $output, $status );
		@unlink( $prepend );
		return [ implode( "\n", $output ), $status ];
	}

	// -------------------------------------------------------------------------
	// Drafts and backups
	// -------------------------------------------------------------------------

	public function test_a_draft_is_stored_without_a_php_extension_and_is_still_listed_and_readable(): void {
		self::assertTrue( SandboxFiles::write( 'hard-note.php', "<?php\n// v1\n" ) );

		self::assertFileExists( $this->dir . '/hard-note.draft' );
		self::assertFileDoesNotExist( $this->dir . '/hard-note.php' );
		self::assertSame( $this->dir . '/hard-note.draft', SandboxFiles::stored_path( 'hard-note.php' ) );
		self::assertSame( "<?php\n// v1\n", SandboxFiles::read( 'hard-note.php' ) );

		$listed = array_column( SandboxFiles::list_files(), null, 'name' );
		self::assertArrayHasKey( 'hard-note.php', $listed );
		self::assertSame( 'draft', $listed['hard-note.php']['status'] );
		self::assertSame( $this->dir . '/hard-note.draft', $listed['hard-note.php']['path'] );
		self::assertSame( [], $this->runnable_entries() === [ 'index.php' ] ? [] : array_diff( $this->runnable_entries(), [ 'index.php' ] ) );
	}

	public function test_backups_have_no_php_extension_and_are_listed_newest_first(): void {
		SandboxFiles::write( 'hard-note.php', "<?php\n// v1\n" );
		SandboxFiles::write( 'hard-note.php', "<?php\n// v2\n" );

		$backups = glob( $this->dir . '/hard-note.*.bak' ) ?: [];
		self::assertCount( 1, $backups, 'The previous version is kept as <name>.<time>.bak.' );
		self::assertSame( [], glob( $this->dir . '/hard-note*.php' ) ?: [] );

		$versions = SandboxFiles::backup_versions( 'hard-note.php' );
		self::assertCount( 1, $versions );
		self::assertSame( $backups[0], $versions[0]['path'] );
		self::assertStringContainsString( '// v1', (string) file_get_contents( $versions[0]['path'] ) );
		self::assertMatchesRegularExpression( '/hard-note\.\d+\.bak$/', $versions[0]['path'] );
	}

	public function test_edit_keeps_its_backup_without_a_php_extension(): void {
		SandboxFiles::write( 'hard-note.php', "<?php\n// before\n" );

		self::assertTrue( SandboxFiles::edit( 'hard-note.php', 'before', 'after' ) );

		self::assertCount( 1, glob( $this->dir . '/hard-note.*.bak' ) ?: [] );
		self::assertSame( [], array_diff( $this->runnable_entries(), [ 'index.php' ] ) );
	}

	public function test_delete_removes_the_draft_its_backups_and_every_active_twin(): void {
		SandboxFiles::write( 'hard-note.php', "<?php\n// v1\n" );
		SandboxFiles::write( 'hard-note.php', "<?php\n// v2\n" );
		SandboxFiles::write( 'hard-note.php', "<?php\n// v3\n" );
		self::assertTrue( SandboxFiles::activate( 'hard-note.php' ) );
		self::assertTrue( SandboxFiles::disable( 'hard-note.php' ) );

		self::assertTrue( SandboxFiles::delete( 'hard-note.php' ) );

		self::assertSame( [], glob( $this->dir . '/hard-note*' ) ?: [], 'No draft or backup is left behind.' );
		self::assertSame( [], glob( $this->mu_dir . '/stonewright-sandbox-hard-note*' ) ?: [] );
		self::assertSame( [], SandboxFiles::backup_versions( 'hard-note.php' ) );
	}

	public function test_delete_also_clears_backups_that_outlived_their_draft(): void {
		file_put_contents( $this->dir . '/hard-note.1700000000.bak', "<?php\n// orphan\n" );

		self::assertTrue( SandboxFiles::delete( 'hard-note.php' ) );

		self::assertSame( [], glob( $this->dir . '/hard-note*' ) ?: [] );
		$missing = SandboxFiles::delete( 'hard-note.php' );
		self::assertInstanceOf( \WP_Error::class, $missing, 'Nothing left to delete is still reported.' );
		self::assertSame( 'stonewright_sandbox_not_found', $missing->get_error_code() );
	}

	public function test_nothing_but_the_guard_stub_in_the_folder_can_run_as_php(): void {
		SandboxFiles::write( 'hard-a.php', "<?php\n// a\n" );
		SandboxFiles::write( 'hard-a.php', "<?php\n// a2\n" );
		SandboxFiles::write( 'hard-b.php', "<?php\n// b\n" );
		SandboxFiles::edit( 'hard-b.php', '// b', '// b edited' );

		self::assertSame( [ 'index.php' ], $this->runnable_entries() );
	}

	public function test_the_guard_stub_exits_at_once_outside_wordpress(): void {
		SandboxFiles::draft_dir();

		[ $output, $status ] = self::run_file( $this->dir . '/index.php', false );

		self::assertSame( '', $output );
		self::assertSame( 0, $status );
		self::assertMatchesRegularExpression( '/^<\?php\s+defined\( \'ABSPATH\' \) \|\| exit;/', (string) file_get_contents( $this->dir . '/index.php' ) );
	}

	// -------------------------------------------------------------------------
	// Activation
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function activation_sources(): array {
		return [
			'plain'                  => [ "<?php\necho 'RAN';\n" ],
			'open tag and code'      => [ "<?php echo 'RAN';\n" ],
			'strict types'           => [ "<?php\ndeclare( strict_types=1 );\n\necho 'RAN';\n" ],
			'namespace'              => [ "<?php\nnamespace Hard\\Sample;\n\necho 'RAN';\n" ],
			'strict types namespace' => [ "<?php\n\ndeclare(strict_types=1);\nnamespace Hard\\Sample;\nuse Hard\\Other;\necho 'RAN';\n" ],
			'braced namespace'       => [ "<?php\nnamespace Hard\\Sample {\n\techo 'RAN';\n}\n" ],
			'global braced'          => [ "<?php\nnamespace {\n\techo 'RAN';\n}\n" ],
			'leading docblock'       => [ "<?php\n/**\n * Plugin Name: Sample\n */\n// note\ndeclare( strict_types=1 );\necho 'RAN';\n" ],
			'html before the tag'    => [ "#!/usr/bin/env php\n<?php\necho 'RAN';\n" ],
			'only declare'           => [ "<?php declare(strict_types=1);" ],
			'no php code'            => [ "plain text, nothing to run\n" ],
		];
	}

	/** @dataProvider activation_sources */
	public function test_an_activated_file_exits_at_once_outside_wordpress_and_runs_inside_it( string $source ): void {
		self::assertTrue( SandboxFiles::write( 'hard-act.php', $source ) );

		self::assertTrue( SandboxFiles::activate( 'hard-act.php' ) );

		$active = $this->mu_dir . '/stonewright-sandbox-hard-act.php';
		self::assertFileExists( $active );
		$guarded = (string) file_get_contents( $active );
		self::assertSame( substr_count( $source, "\n" ), substr_count( $guarded, "\n" ), 'Line numbers of the draft are kept.' );

		[ $lint ] = [ shell_exec( escapeshellarg( PHP_BINARY ) . ' -n -l ' . escapeshellarg( $active ) . ' 2>&1' ) ];
		self::assertStringContainsString( 'No syntax errors', (string) $lint );

		[ $outside ] = self::run_file( $active, false );
		self::assertStringNotContainsString( 'RAN', $outside );

		if ( str_contains( $source, "echo 'RAN'" ) ) {
			self::assertStringContainsString( "defined( 'ABSPATH' ) || exit;", $guarded );
			[ $inside ] = self::run_file( $active, true );
			self::assertSame( 'RAN', trim( $inside ) );
		}
	}

	public function test_activation_keeps_the_static_guard_and_leaves_no_file_when_it_blocks(): void {
		SandboxFiles::write( 'hard-act.php', "<?php\nexec( 'id' );\n" );

		$result = SandboxFiles::activate( 'hard-act.php' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_sandbox_static_guard', $result->get_error_code() );
		self::assertFileDoesNotExist( $this->mu_dir . '/stonewright-sandbox-hard-act.php' );
	}

	public function test_the_draft_itself_stays_unchanged_by_activation(): void {
		$source = "<?php\n// draft body\n";
		SandboxFiles::write( 'hard-act.php', $source );

		SandboxFiles::activate( 'hard-act.php' );

		self::assertSame( $source, SandboxFiles::read( 'hard-act.php' ) );
	}

	// -------------------------------------------------------------------------
	// The folder guard
	// -------------------------------------------------------------------------

	public function test_the_folder_guard_denies_every_request_on_current_and_older_servers(): void {
		SandboxFiles::draft_dir();

		$htaccess = (string) file_get_contents( $this->dir . '/.htaccess' );
		self::assertMatchesRegularExpression( '/<IfModule mod_authz_core\.c>\s*Require all denied\s*<\/IfModule>/', $htaccess );
		self::assertMatchesRegularExpression( '/<IfModule !mod_authz_core\.c>\s*Order deny,allow\s*Deny from all\s*<\/IfModule>/', $htaccess );

		$web_config = (string) file_get_contents( $this->dir . '/web.config' );
		self::assertStringContainsString( '<add accessType="Deny" users="*"', $web_config );
		self::assertFileExists( $this->dir . '/index.php' );
	}

	public function test_an_outdated_guard_is_replaced_on_the_next_use(): void {
		SandboxFiles::draft_dir();
		file_put_contents( $this->dir . '/.htaccess', "deny from all\n" );
		file_put_contents( $this->dir . '/index.php', "<?php\n// Silence is golden.\n" );
		unlink( $this->dir . '/web.config' );
		$GLOBALS['stonewright_test_options'] = [];

		SandboxFiles::draft_dir();

		self::assertStringContainsString( 'Require all denied', (string) file_get_contents( $this->dir . '/.htaccess' ) );
		self::assertStringContainsString( "defined( 'ABSPATH' ) || exit;", (string) file_get_contents( $this->dir . '/index.php' ) );
		self::assertFileExists( $this->dir . '/web.config' );
	}

	public function test_a_removed_guard_file_is_written_again_on_the_next_use(): void {
		SandboxFiles::draft_dir();
		unlink( $this->dir . '/.htaccess' );

		SandboxFiles::draft_dir();

		self::assertStringContainsString( 'Require all denied', (string) file_get_contents( $this->dir . '/.htaccess' ) );
	}

	// -------------------------------------------------------------------------
	// Migration of drafts and backups written by earlier versions
	// -------------------------------------------------------------------------

	private function seed_legacy_layout(): void {
		$files = [
			'legacy-one.php'                      => "<?php\n// one\n",
			'legacy-one.php.1700000000.bak.php'   => "<?php\n// one, older\n",
			'legacy-one.php.1700000005.bak.php'   => "<?php\n// one, newer\n",
			'legacy-two.php'                      => "<?php\n// two\n",
			'widget-hard-w.php'                   => "<?php\n// active widget\n",
			'widget-hard-p.pending.php'           => "<?php\n// pending widget\n",
			'gone.php.1700000001.bak.php'         => "<?php\n// backup without a draft\n",
			'Rogue.php'                           => "<?php\n// not written by the plugin\n",
			'Rogue.Phtml'                         => "<?php\n// odd extension\n",
			'dup.php'                             => "<?php\n// old duplicate\n",
			'dup.draft'                           => "<?php\n// current duplicate\n",
		];
		foreach ( $files as $name => $contents ) {
			file_put_contents( $this->dir . '/' . $name, $contents );
		}
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_earlier_drafts_and_backups_are_moved_to_the_new_storage_names(): void {
		$this->seed_legacy_layout();

		SandboxFiles::draft_dir();

		self::assertSame( [ 'index.php' ], $this->runnable_entries(), 'Nothing but the guard stub can run as PHP.' );
		self::assertSame( "<?php\n// one\n", file_get_contents( $this->dir . '/legacy-one.draft' ) );
		self::assertSame( "<?php\n// two\n", file_get_contents( $this->dir . '/legacy-two.draft' ) );
		self::assertSame( "<?php\n// active widget\n", file_get_contents( $this->dir . '/widget-hard-w.draft' ) );
		self::assertSame( "<?php\n// pending widget\n", file_get_contents( $this->dir . '/widget-hard-p.pending.draft' ) );
		self::assertSame( "<?php\n// one, older\n", file_get_contents( $this->dir . '/legacy-one.1700000000.bak' ) );
		self::assertSame( "<?php\n// one, newer\n", file_get_contents( $this->dir . '/legacy-one.1700000005.bak' ) );
		self::assertSame( "<?php\n// backup without a draft\n", file_get_contents( $this->dir . '/gone.1700000001.bak' ) );
	}

	public function test_files_the_plugin_did_not_write_are_neutralised_not_deleted(): void {
		$this->seed_legacy_layout();

		SandboxFiles::draft_dir();

		self::assertFileDoesNotExist( $this->dir . '/Rogue.php' );
		self::assertSame( "<?php\n// not written by the plugin\n", file_get_contents( $this->dir . '/Rogue_php.quarantined' ) );
		self::assertSame( "<?php\n// odd extension\n", file_get_contents( $this->dir . '/Rogue_Phtml.quarantined' ) );
	}

	public function test_a_name_clash_never_overwrites_the_current_draft(): void {
		$this->seed_legacy_layout();

		SandboxFiles::draft_dir();

		self::assertSame( "<?php\n// current duplicate\n", file_get_contents( $this->dir . '/dup.draft' ) );
		self::assertSame( "<?php\n// old duplicate\n", file_get_contents( $this->dir . '/dup_php.quarantined' ) );
		self::assertSame( [ 'index.php' ], $this->runnable_entries() );
	}

	public function test_migrated_drafts_and_backups_show_up_in_the_listings(): void {
		$this->seed_legacy_layout();

		$names = array_column( SandboxFiles::list_files(), 'name' );

		self::assertContains( 'legacy-one.php', $names );
		self::assertContains( 'legacy-two.php', $names );
		self::assertContains( 'widget-hard-w.php', $names );
		self::assertNotContains( 'index.php', $names );
		self::assertNotContains( 'Rogue.php', $names );
		self::assertSame( [ 1700000005, 1700000000 ], array_column( SandboxFiles::backup_versions( 'legacy-one.php' ), 'timestamp' ) );
	}

	public function test_the_migration_runs_once_and_later_calls_leave_the_folder_alone(): void {
		$this->seed_legacy_layout();
		SandboxFiles::draft_dir();
		self::assertSame( '2', (string) get_option( 'stonewright_sandbox_layout', '' ) );

		// A file dropped in later is not touched by the flag-guarded path.
		file_put_contents( $this->dir . '/hard-later.draft', 'x' );
		SandboxFiles::draft_dir();

		self::assertFileExists( $this->dir . '/hard-later.draft' );
		self::assertSame( [ 'index.php' ], $this->runnable_entries() );
	}

	public function test_an_active_twin_of_an_earlier_draft_keeps_its_status(): void {
		file_put_contents( $this->dir . '/legacy-one.php', "<?php\n// one\n" );
		file_put_contents( $this->mu_dir . '/stonewright-sandbox-legacy-one.php', "<?php\n// one\n" );
		$GLOBALS['stonewright_test_options'] = [];

		$listed = array_column( SandboxFiles::list_files(), null, 'name' );

		self::assertSame( 'active', $listed['legacy-one.php']['status'] );
		self::assertTrue( SandboxFiles::delete( 'legacy-one.php' ) );
		self::assertFileDoesNotExist( $this->mu_dir . '/stonewright-sandbox-legacy-one.php' );
	}
}
