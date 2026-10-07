<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\MuRuntime;

/**
 * The fatal error record of the rescue MU-plugin: which journal entry a fatal error belongs
 * to, what is written, and the cases in which the journal is left alone.
 *
 * @coversNothing The MU-plugin lives outside includes/.
 */
final class RescueFatalRecordTest extends TestCase {

	private const NOW = 2_000_000_000;

	protected function setUp(): void {
		MuRuntime::begin();
	}

	protected function tearDown(): void {
		MuRuntime::end();
	}

	/** @param array<string, mixed> $error */
	private function record( array $error, ?int $now = self::NOW ): ?string {
		MuRuntime::boot();
		return \Stonewright_Rescue::record_fatal( $error, $now );
	}

	private function journal_hash(): string {
		return (string) md5_file( MuRuntime::journal_path() );
	}

	public function test_a_fatal_in_a_path_of_an_armed_entry_records_the_incident_and_sets_the_state(): void {
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-1', [ 'armed_at' => self::NOW - 30 ] ) ] );

		$id = $this->record( MuRuntime::fatal( 'wp-content/themes/site-a/functions.php', E_ERROR, 12, 'Uncaught Error: Call to undefined function site_a_boot()' ) );

		self::assertSame( 'cs-1', $id );
		$entry = MuRuntime::journal_entry( 'cs-1' );
		self::assertSame( 'incident', $entry['state'] );
		self::assertSame(
			[
				'recorded_at'    => self::NOW,
				'file'           => 'wp-content/themes/site-a/functions.php',
				'line'           => 12,
				'type'           => E_ERROR,
				'message_sha256' => hash( 'sha256', 'Uncaught Error: Call to undefined function site_a_boot()' ),
				'source'         => 'shutdown',
			],
			$entry['incident']
		);
		self::assertSame( self::NOW, MuRuntime::read_journal()['updated_at'] );
	}

	public function test_the_incident_keeps_the_hash_of_the_message_and_never_the_message(): void {
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-1', [ 'armed_at' => self::NOW - 30 ] ) ] );

		$this->record( MuRuntime::fatal( 'wp-content/themes/site-a/functions.php', E_ERROR, 3, 'Uncaught Exception: could not connect with password hunter2 in /srv/www/site-a/wp-config.php' ) );

		$raw = (string) file_get_contents( MuRuntime::journal_path() );
		self::assertStringNotContainsString( 'hunter2', $raw );
		self::assertStringNotContainsString( 'Uncaught Exception', $raw );
		self::assertStringContainsString( hash( 'sha256', 'Uncaught Exception: could not connect with password hunter2 in /srv/www/site-a/wp-config.php' ), $raw );
	}

	/** @return array<string, array{0: int}> */
	public static function fatal_types(): array {
		return [
			'E_ERROR'             => [ E_ERROR ],
			'E_PARSE'             => [ E_PARSE ],
			'E_CORE_ERROR'        => [ E_CORE_ERROR ],
			'E_COMPILE_ERROR'     => [ E_COMPILE_ERROR ],
			'E_USER_ERROR'        => [ E_USER_ERROR ],
			'E_RECOVERABLE_ERROR' => [ E_RECOVERABLE_ERROR ],
		];
	}

	/** @dataProvider fatal_types */
	public function test_every_fatal_error_type_is_recorded( int $type ): void {
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-1', [ 'armed_at' => self::NOW - 30 ] ) ] );

		self::assertSame( 'cs-1', $this->record( MuRuntime::fatal( 'wp-content/themes/site-a/functions.php', $type ) ) );
		self::assertSame( $type, MuRuntime::journal_entry( 'cs-1' )['incident']['type'] );
	}

	/** @return array<string, array{0: int}> */
	public static function other_types(): array {
		return [
			'E_WARNING'         => [ E_WARNING ],
			'E_NOTICE'          => [ E_NOTICE ],
			'E_DEPRECATED'      => [ E_DEPRECATED ],
			'E_USER_WARNING'    => [ E_USER_WARNING ],
			'E_USER_NOTICE'     => [ E_USER_NOTICE ],
			'E_USER_DEPRECATED' => [ E_USER_DEPRECATED ],
			'E_CORE_WARNING'    => [ E_CORE_WARNING ],
			'E_COMPILE_WARNING' => [ E_COMPILE_WARNING ],
		];
	}

	/** @dataProvider other_types */
	public function test_an_error_that_is_not_fatal_leaves_the_journal_alone( int $type ): void {
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-1', [ 'armed_at' => self::NOW - 30 ] ) ] );
		$before = $this->journal_hash();

		self::assertNull( $this->record( MuRuntime::fatal( 'wp-content/themes/site-a/functions.php', $type ) ) );
		self::assertSame( $before, $this->journal_hash() );
	}

	public function test_a_fatal_in_a_file_no_entry_touched_leaves_the_journal_alone(): void {
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-1', [ 'armed_at' => self::NOW - 30 ] ) ] );
		$before = $this->journal_hash();

		self::assertNull( $this->record( MuRuntime::fatal( 'wp-content/plugins/other/other.php' ) ) );
		self::assertSame( $before, $this->journal_hash() );
		self::assertSame( 'armed', MuRuntime::journal_entry( 'cs-1' )['state'] );
	}

	public function test_a_fatal_outside_abspath_is_ignored(): void {
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-1', [ 'armed_at' => self::NOW - 30 ] ) ] );

		self::assertNull(
			$this->record( [ 'type' => E_ERROR, 'message' => 'x', 'file' => '/elsewhere/wp-content/themes/site-a/functions.php', 'line' => 1 ] )
		);
	}

	public function test_a_path_that_names_a_directory_matches_the_files_inside_it_and_nothing_next_to_it(): void {
		MuRuntime::write_journal(
			[
				MuRuntime::entry( 'cs-dir', [ 'paths' => [ 'wp-content/plugins/shop' ], 'armed_at' => self::NOW - 30 ] ),
			]
		);

		self::assertNull( $this->record( MuRuntime::fatal( 'wp-content/plugins/shop-extra/x.php' ) ) );
		self::assertNull( $this->record( MuRuntime::fatal( 'wp-content/plugins/shop.php' ) ) );
		self::assertSame( 'cs-dir', $this->record( MuRuntime::fatal( 'wp-content/plugins/shop/includes/cart.php' ) ) );
	}

	public function test_paths_match_after_separators_and_dot_prefixes_are_normalised(): void {
		MuRuntime::write_journal(
			[
				MuRuntime::entry( 'cs-1', [ 'paths' => [ './wp-content\\themes\\site-a\\functions.php' ], 'armed_at' => self::NOW - 30 ] ),
			]
		);

		self::assertSame( 'cs-1', $this->record( MuRuntime::fatal( 'wp-content/themes/site-a/functions.php' ) ) );
	}

	public function test_a_fatal_reported_with_backslashes_matches(): void {
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-1', [ 'armed_at' => self::NOW - 30 ] ) ] );
		$error         = MuRuntime::fatal( 'wp-content/themes/site-a/functions.php' );
		$error['file'] = str_replace( '/', '\\', $error['file'] );

		self::assertSame( 'cs-1', $this->record( $error ) );
	}

	/** @return array<string, array{0: string}> */
	public static function settled_states(): array {
		return [
			'verified'    => [ 'verified' ],
			'rolled back' => [ 'rolled_back' ],
		];
	}

	/** @dataProvider settled_states */
	public function test_a_settled_entry_matches_for_fifteen_minutes_after_it_settled( string $state ): void {
		MuRuntime::write_journal(
			[
				MuRuntime::entry( 'cs-recent', [ 'state' => $state, 'armed_at' => self::NOW - 400, 'probe_deadline' => self::NOW - 300 ] ),
			]
		);

		self::assertSame( 'cs-recent', $this->record( MuRuntime::fatal() ) );
	}

	/** @dataProvider settled_states */
	public function test_a_settled_entry_stops_matching_after_fifteen_minutes( string $state ): void {
		MuRuntime::write_journal(
			[
				MuRuntime::entry( 'cs-old', [ 'state' => $state, 'armed_at' => self::NOW - 2000, 'probe_deadline' => self::NOW - 901 ] ),
			]
		);
		$before = $this->journal_hash();

		self::assertNull( $this->record( MuRuntime::fatal() ) );
		self::assertSame( $before, $this->journal_hash() );
	}

	public function test_a_settled_entry_that_carries_its_settle_time_is_judged_by_it(): void {
		MuRuntime::write_journal(
			[
				MuRuntime::entry( 'cs-1', [ 'state' => 'verified', 'armed_at' => self::NOW - 5000, 'probe_deadline' => self::NOW - 4900, 'settled_at' => self::NOW - 20 ] ),
			]
		);

		self::assertSame( 'cs-1', $this->record( MuRuntime::fatal() ) );
	}

	/** @return array<string, array{0: string}> */
	public static function open_states(): array {
		return [
			'armed'             => [ 'armed' ],
			'probe unavailable' => [ 'probe_unavailable' ],
			'rollback failed'   => [ 'rollback_failed' ],
		];
	}

	/** @dataProvider open_states */
	public function test_an_entry_that_still_needs_attention_matches_at_any_age( string $state ): void {
		MuRuntime::write_journal(
			[
				MuRuntime::entry( 'cs-1', [ 'state' => $state, 'armed_at' => self::NOW - 3 * 86400, 'probe_deadline' => self::NOW - 3 * 86400 + 120 ] ),
			]
		);

		self::assertSame( 'cs-1', $this->record( MuRuntime::fatal() ) );
		self::assertSame( 'incident', MuRuntime::journal_entry( 'cs-1' )['state'] );
	}

	public function test_a_fatal_before_the_change_was_armed_does_not_match(): void {
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-1', [ 'armed_at' => self::NOW + 100 ] ) ] );

		self::assertNull( $this->record( MuRuntime::fatal() ) );
	}

	public function test_the_latest_matching_entry_gets_the_incident(): void {
		MuRuntime::write_journal(
			[
				MuRuntime::entry( 'cs-older', [ 'armed_at' => self::NOW - 200 ] ),
				MuRuntime::entry( 'cs-latest', [ 'armed_at' => self::NOW - 20 ] ),
				MuRuntime::entry( 'cs-other-file', [ 'armed_at' => self::NOW - 5, 'paths' => [ 'wp-content/themes/site-b/style.css' ] ] ),
			]
		);

		self::assertSame( 'cs-latest', $this->record( MuRuntime::fatal() ) );
		self::assertSame( 'armed', MuRuntime::journal_entry( 'cs-older' )['state'] );
		self::assertNull( MuRuntime::journal_entry( 'cs-older' )['incident'] );
	}

	public function test_the_same_fatal_again_is_not_written_again(): void {
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-1', [ 'armed_at' => self::NOW - 30 ] ) ] );
		$error = MuRuntime::fatal();

		self::assertSame( 'cs-1', $this->record( $error, self::NOW ) );
		$after_first = $this->journal_hash();
		self::assertSame( 'cs-1', $this->record( $error, self::NOW + 7 ) );

		self::assertSame( $after_first, $this->journal_hash(), 'a broken file that fails on every request writes the journal once' );
	}

	public function test_a_second_fatal_in_the_same_entry_keeps_the_first_incident(): void {
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-1', [ 'armed_at' => self::NOW - 30 ] ) ] );

		$this->record( MuRuntime::fatal( 'wp-content/themes/site-a/functions.php', E_ERROR, 12 ), self::NOW );
		$this->record( MuRuntime::fatal( 'wp-content/themes/site-a/functions.php', E_ERROR, 99 ), self::NOW + 5 );

		self::assertSame( 12, MuRuntime::journal_entry( 'cs-1' )['incident']['line'] );
	}

	/** @return array<string, array{0: string}> */
	public static function unreadable_journals(): array {
		return [
			'not json'            => [ 'this is not json' ],
			'truncated'           => [ '{"version":1,"updated_at":1,"entries":[{"id":"cs-1"' ],
			'empty'               => [ '' ],
			'a list'              => [ '[]' ],
			'another version'     => [ '{"version":2,"updated_at":1,"entries":[]}' ],
			'no version'          => [ '{"updated_at":1,"entries":[]}' ],
			'entries is a string' => [ '{"version":1,"updated_at":1,"entries":"x"}' ],
			'too large'           => [ '{"version":1,"updated_at":1,"entries":[],"pad":"' . str_repeat( 'a', 1_048_600 ) . '"}' ],
		];
	}

	/** @dataProvider unreadable_journals */
	public function test_a_journal_that_cannot_be_read_is_left_alone( string $contents ): void {
		MuRuntime::write_journal( [], $contents );
		$before = $this->journal_hash();

		self::assertNull( $this->record( MuRuntime::fatal() ) );
		self::assertSame( $before, $this->journal_hash() );
		self::assertSame( [ MuRuntime::JOURNAL ], array_map( 'basename', glob( MuRuntime::state_dir() . '/*' ) ?: [] ), 'no lock file or temporary file appeared' );
	}

	public function test_a_journal_of_another_version_is_left_alone_even_when_an_entry_matches(): void {
		$entry = MuRuntime::entry( 'cs-1', [ 'armed_at' => self::NOW - 30 ] );
		MuRuntime::write_journal( [], json_encode( [ 'version' => 2, 'updated_at' => 1000, 'entries' => [ $entry ] ], JSON_UNESCAPED_SLASHES ) ?: '' );
		$before = $this->journal_hash();

		self::assertNull( $this->record( MuRuntime::fatal() ) );
		self::assertSame( $before, $this->journal_hash() );
	}

	public function test_a_missing_journal_does_nothing(): void {
		self::assertNull( $this->record( MuRuntime::fatal() ) );
		self::assertDirectoryDoesNotExist( MuRuntime::state_dir() );
	}

	public function test_the_journal_is_found_by_its_name_pattern_when_the_option_is_not_set(): void {
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-1', [ 'armed_at' => self::NOW - 30 ] ) ] );
		unset( $GLOBALS['stonewright_test_options']['stonewright_rescue_journal_file'] );

		self::assertSame( 'cs-1', $this->record( MuRuntime::fatal() ) );
	}

	public function test_the_directory_the_plugin_recorded_is_used_for_a_journal_kept_elsewhere(): void {
		$custom = WP_CONTENT_DIR . '/relocated-uploads/stonewright-state';
		mkdir( $custom, 0777, true );
		try {
			file_put_contents(
				$custom . '/' . MuRuntime::JOURNAL,
				json_encode( [ 'version' => 1, 'updated_at' => 1000, 'entries' => [ MuRuntime::entry( 'cs-1', [ 'armed_at' => self::NOW - 30 ] ) ] ], JSON_UNESCAPED_SLASHES )
			);
			MuRuntime::set_option( 'stonewright_rescue_journal_file', MuRuntime::JOURNAL );
			MuRuntime::set_option( 'stonewright_rescue_state_dir', str_replace( '/', '\\', $custom ) );

			self::assertSame( 'cs-1', $this->record( MuRuntime::fatal() ) );
			$stored = json_decode( (string) file_get_contents( $custom . '/' . MuRuntime::JOURNAL ), true );
			self::assertSame( 'incident', $stored['entries'][0]['state'] );
		} finally {
			MuRuntime::remove_tree( WP_CONTENT_DIR . '/relocated-uploads' );
		}
	}

	public function test_a_state_directory_option_that_is_not_a_string_is_ignored(): void {
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-1', [ 'armed_at' => self::NOW - 30 ] ) ] );
		MuRuntime::set_option( 'stonewright_rescue_state_dir', [ '/etc' ] );

		self::assertSame( 'cs-1', $this->record( MuRuntime::fatal() ) );
	}

	public function test_a_journal_name_that_is_not_a_journal_name_is_not_followed(): void {
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-1', [ 'armed_at' => self::NOW - 30 ] ) ] );
		file_put_contents( MuRuntime::state_dir() . '/other.json', '{"version":1,"updated_at":1,"entries":[]}' );
		MuRuntime::set_option( 'stonewright_rescue_journal_file', '../other.json' );

		// The invalid name is ignored; the file that matches the pattern is used instead.
		self::assertSame( 'cs-1', $this->record( MuRuntime::fatal() ) );
		self::assertSame( '{"version":1,"updated_at":1,"entries":[]}', file_get_contents( MuRuntime::state_dir() . '/other.json' ) );
	}

	public function test_unknown_fields_and_empty_objects_survive_the_rewrite(): void {
		$raw = '{"version":1,"updated_at":1,"note":{"keep":true},"entries":[{"id":"cs-1","ability":"stonewright\/theme-file-patch","resource_type":"theme_file","resource_key":"functions.php","recipe":{"type":"theme_backup","ref":"r"},"paths":["wp-content\/themes\/site-a\/functions.php"],"armed_at":' . ( self::NOW - 30 ) . ',"probe_deadline":' . ( self::NOW + 60 ) . ',"state":"armed","incident":null,"meta":{},"extra":{"a":[]}}]}';
		MuRuntime::write_journal( [], $raw );

		$this->record( MuRuntime::fatal() );

		$written = (string) file_get_contents( MuRuntime::journal_path() );
		self::assertStringContainsString( '"meta":{}', $written );
		self::assertStringContainsString( '"extra":{"a":[]}', $written );
		self::assertStringContainsString( '"note":{"keep":true}', $written );
		self::assertStringContainsString( '"ability":"stonewright/theme-file-patch"', $written );
		self::assertSame( [ 'version', 'updated_at', 'note', 'entries' ], array_keys( (array) json_decode( $written, true ) ) );
	}

	public function test_a_write_follows_the_journal_protocol(): void {
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-1', [ 'armed_at' => self::NOW - 30 ] ) ] );

		$this->record( MuRuntime::fatal() );

		$names = array_map( 'basename', glob( MuRuntime::state_dir() . '/*' ) ?: [] );
		sort( $names );
		self::assertSame( [ MuRuntime::JOURNAL, MuRuntime::JOURNAL . '.lock' ], $names, 'the lock file is "<journal>.lock" and no temporary file is left behind' );
	}

	public function test_the_file_mode_of_the_journal_is_kept(): void {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			self::markTestSkipped( 'POSIX file modes only.' );
		}
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-1', [ 'armed_at' => self::NOW - 30 ] ) ] );
		chmod( MuRuntime::journal_path(), 0600 );

		$this->record( MuRuntime::fatal() );

		clearstatcache();
		self::assertSame( 0600, fileperms( MuRuntime::journal_path() ) & 0777 );
	}

	public function test_more_than_fifty_entries_are_trimmed_and_the_oldest_settled_ones_go_first(): void {
		$entries = [];
		for ( $i = 0; $i < 30; $i++ ) {
			$entries[] = MuRuntime::entry( 'cs-settled-' . $i, [ 'state' => 'verified', 'armed_at' => self::NOW - 90_000 + $i, 'probe_deadline' => self::NOW - 80_000, 'paths' => [ 'wp-content/elsewhere/' . $i . '.php' ] ] );
		}
		for ( $i = 0; $i < 22; $i++ ) {
			$entries[] = MuRuntime::entry( 'cs-armed-' . $i, [ 'armed_at' => self::NOW - 70_000 + $i, 'paths' => [ 'wp-content/other/' . $i . '.php' ] ] );
		}
		$entries[] = MuRuntime::entry( 'cs-target', [ 'armed_at' => self::NOW - 20 ] );
		MuRuntime::write_journal( $entries );

		self::assertSame( 'cs-target', $this->record( MuRuntime::fatal() ) );

		$ids = array_column( MuRuntime::read_journal()['entries'], 'id' );
		self::assertCount( 50, $ids );
		self::assertContains( 'cs-target', $ids );
		for ( $i = 0; $i < 22; $i++ ) {
			self::assertContains( 'cs-armed-' . $i, $ids, 'armed entries are never dropped' );
		}
		self::assertNotContains( 'cs-settled-0', $ids );
		self::assertNotContains( 'cs-settled-2', $ids );
		self::assertContains( 'cs-settled-3', $ids );
	}

	public function test_the_runtime_does_nothing_while_stonewright_is_not_active(): void {
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-1', [ 'armed_at' => self::NOW - 30 ] ) ] );
		$GLOBALS['stonewright_test_options']['active_plugins'] = [ 'akismet/akismet.php' ];

		MuRuntime::boot();

		self::assertSame( '', \Stonewright_Rescue::plugin_basename() );
		self::assertFalse( \Stonewright_Rescue::is_safe_boot() );
		self::assertSame( [], $GLOBALS['stonewright_test_filters'] );
	}
}
