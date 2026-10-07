<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeJournalFile;

/**
 * @covers \Stonewright\WpMcp\Security\ChangeJournal
 */
final class ChangeJournalTest extends TestCase {

	private string $uploads;

	/** @var mixed */
	private $original_upload_dir;

	protected function setUp(): void {
		$this->uploads             = sys_get_temp_dir() . '/sw-rescue-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->uploads, 0700, true );
		$this->original_upload_dir = $GLOBALS['stonewright_test_upload_dir'] ?? null;
		$GLOBALS['stonewright_test_upload_dir'] = [
			'basedir' => $this->uploads,
			'baseurl' => 'https://example.test/wp-content/uploads',
			'error'   => false,
		];
		$GLOBALS['stonewright_test_options']      = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$_SERVER['HTTP_USER_AGENT']                  = 'claude-code/1.4.2 (cli)';
		ChangeJournal::reset_for_tests();
	}

	protected function tearDown(): void {
		if ( null === $this->original_upload_dir ) {
			unset( $GLOBALS['stonewright_test_upload_dir'] );
		} else {
			$GLOBALS['stonewright_test_upload_dir'] = $this->original_upload_dir;
		}
		$GLOBALS['stonewright_test_options'] = [];
		unset( $_SERVER['HTTP_USER_AGENT'] );
		ChangeJournal::reset_for_tests();
		self::remove_tree( $this->uploads );
	}

	/** @return array<string, mixed> */
	private static function spec( array $override = [] ): array {
		return array_merge(
			[
				'ability'       => 'stonewright/theme-file-patch',
				'resource_type' => 'theme_file',
				'resource_key'  => 'functions.php',
				'recipe'        => [ 'type' => 'theme_backup', 'ref' => 'sw-theme-backup-1111' ],
				'paths'         => [ 'wp-content/themes/site-a/functions.php' ],
				'scope'         => 'site',
			],
			$override
		);
	}

	private function journal_path(): string {
		$name = (string) get_option( ChangeJournal::FILE_OPTION, '' );
		return $this->uploads . '/stonewright-state/' . $name;
	}

	/** @return array<string, mixed> */
	private function file_document(): array {
		return json_decode( (string) file_get_contents( $this->journal_path() ), true );
	}

	public function test_arming_creates_the_protected_state_directory_and_a_randomly_named_file(): void {
		$entry = ChangeJournal::arm( self::spec() );

		$name = (string) get_option( ChangeJournal::FILE_OPTION, '' );
		self::assertTrue( ChangeJournalFile::is_valid_file_name( $name ) );
		self::assertFileExists( $this->journal_path() );
		self::assertFileExists( $this->uploads . '/stonewright-state/.htaccess' );
		self::assertFileExists( $this->uploads . '/stonewright-state/index.php' );
		self::assertMatchesRegularExpression( '/^cs-[a-f0-9]{24}$/', $entry['id'] );

		// The name stays the same for every later write.
		ChangeJournal::arm( self::spec( [ 'resource_key' => 'style.css' ] ) );
		self::assertSame( $name, (string) get_option( ChangeJournal::FILE_OPTION, '' ) );
	}

	public function test_an_armed_entry_has_the_contract_shape_in_the_file(): void {
		$before = time();
		$entry  = ChangeJournal::arm( self::spec() );

		$file = $this->file_document();
		self::assertSame( 1, $file['version'] );
		self::assertCount( 1, $file['entries'] );
		$stored = $file['entries'][0];
		self::assertSame( $entry['id'], $stored['id'] );
		self::assertSame( 'stonewright/theme-file-patch', $stored['ability'] );
		self::assertSame( 'theme_file', $stored['resource_type'] );
		self::assertSame( 'functions.php', $stored['resource_key'] );
		self::assertSame( [ 'type' => 'theme_backup', 'ref' => 'sw-theme-backup-1111' ], $stored['recipe'] );
		self::assertSame( [ 'wp-content/themes/site-a/functions.php' ], $stored['paths'] );
		self::assertGreaterThanOrEqual( $before, $stored['armed_at'] );
		self::assertGreaterThan( $stored['armed_at'], $stored['probe_deadline'] );
		self::assertSame( 'armed', $stored['state'] );
		self::assertNull( $stored['incident'] );
	}

	public function test_the_database_copy_adds_actor_client_and_recipe_detail_that_the_file_never_carries(): void {
		$entry = ChangeJournal::arm( self::spec( [ 'recipe_detail' => [ 'backup_ref' => 'sw-theme-backup-1111', 'absent_before' => false ] ] ) );

		$rich = ChangeJournal::get( $entry['id'] );
		self::assertSame( 7, $rich['actor'] );
		self::assertSame( 'claude-code/1.4.2', $rich['client'] );
		self::assertSame( 'sw-theme-backup-1111', $rich['recipe_detail']['backup_ref'] );
		self::assertSame( 'site', $rich['scope'] );

		$raw = (string) file_get_contents( $this->journal_path() );
		self::assertStringNotContainsString( 'claude-code', $raw );
		self::assertStringNotContainsString( 'recipe_detail', $raw );
		self::assertStringNotContainsString( 'actor', $raw );
	}

	public function test_secrets_never_reach_the_file_or_the_database_copy(): void {
		$entry = ChangeJournal::arm( self::spec( [ 'resource_key' => 'Bearer abcdefghijklmnopqrstuvwxyz0123456789', 'resource_type' => 'option' ] ) );

		self::assertSame( '[redacted]', ChangeJournal::get( $entry['id'] )['resource_key'] );
		self::assertStringNotContainsString( 'abcdefghijklmnopqrstuvwxyz', (string) file_get_contents( $this->journal_path() ) );
		self::assertStringNotContainsString( 'abcdefghijklmnopqrstuvwxyz', (string) wp_json_encode( get_option( ChangeJournal::DB_OPTION ) ) );
	}

	public function test_arming_again_with_the_same_change_set_id_replaces_the_entry(): void {
		ChangeJournal::arm( self::spec( [ 'change_set_id' => 'cs-fixed' ] ) );
		ChangeJournal::arm( self::spec( [ 'change_set_id' => 'cs-fixed', 'resource_key' => 'style.css' ] ) );

		$ids = array_column( $this->file_document()['entries'], 'id' );
		self::assertSame( [ 'cs-fixed' ], $ids );
		self::assertSame( 'style.css', ChangeJournal::get( 'cs-fixed' )['resource_key'] );
	}

	public function test_a_recipe_can_be_attached_after_arming(): void {
		$entry = ChangeJournal::arm( self::spec( [ 'resource_type' => 'custom_code', 'recipe' => [ 'type' => 'none', 'ref' => '' ] ] ) );

		ChangeJournal::attach_recipe( $entry['id'], [ 'type' => 'none', 'ref' => 'snap-9' ], [ 'provider' => 'wpcode', 'snapshot_id' => 'snap-9', 'target_id' => '12' ] );

		self::assertSame( 'snap-9', $this->file_document()['entries'][0]['recipe']['ref'] );
		self::assertSame( 'wpcode', ChangeJournal::get( $entry['id'] )['recipe_detail']['provider'] );
	}

	public function test_a_verified_entry_is_settled_and_stays_in_the_file(): void {
		$entry = ChangeJournal::arm( self::spec() );

		$settled = ChangeJournal::settle( $entry['id'], 'verified', [ 'probe' => [ 'status' => 'passed' ] ] );

		self::assertSame( 'verified', $settled['state'] );
		self::assertSame( 'verified', $this->file_document()['entries'][0]['state'] );
		self::assertSame( 'passed', ChangeJournal::get( $entry['id'] )['probe']['status'] );
		self::assertGreaterThan( 0, ChangeJournal::get( $entry['id'] )['settled_at'] );
		self::assertNull( ChangeJournal::banner() );
	}

	public function test_an_unavailable_probe_keeps_the_entry_armed_with_the_reason(): void {
		$entry = ChangeJournal::arm( self::spec() );

		ChangeJournal::record_probe( $entry['id'], [ 'status' => 'unavailable', 'legs' => [ [ 'leg' => 'home', 'status' => 'unavailable', 'reason' => 'http_request_failed' ] ] ] );

		self::assertSame( 'armed', $this->file_document()['entries'][0]['state'] );
		self::assertSame( 'unavailable', ChangeJournal::get( $entry['id'] )['probe']['status'] );
		self::assertNull( ChangeJournal::banner(), 'An unverified change is not an incident.' );
	}

	public function test_a_failed_rollback_opens_an_incident_that_the_banner_reports(): void {
		$entry = ChangeJournal::arm( self::spec() );

		ChangeJournal::settle( $entry['id'], 'rollback_failed', [ 'rollback' => [ 'status' => 'failed' ] ] );

		$banner = ChangeJournal::banner();
		self::assertSame( [ 'id', 'ability', 'since', 'rollback' ], array_keys( $banner ) );
		self::assertSame( $entry['id'], $banner['id'] );
		self::assertSame( 'stonewright/theme-file-patch', $banner['ability'] );
		self::assertSame( 'stonewright-rescue-rollback', $banner['rollback'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $banner['since'] );
		self::assertCount( 1, ChangeJournal::open_incidents() );

		ChangeJournal::settle( $entry['id'], 'rolled_back', [ 'rollback' => [ 'status' => 'succeeded' ] ] );
		self::assertNull( ChangeJournal::banner() );
		self::assertSame( [], ChangeJournal::open_incidents() );
	}

	public function test_opening_and_closing_an_incident_bumps_the_tool_surface_revision_once_each(): void {
		$GLOBALS['stonewright_test_options']['stonewright_surface_revision'] = 4;
		$entry = ChangeJournal::arm( self::spec() );

		ChangeJournal::settle( $entry['id'], 'rollback_failed' );
		self::assertSame( 5, get_option( 'stonewright_surface_revision' ) );
		ChangeJournal::record_probe( $entry['id'], [ 'status' => 'failed' ] );
		self::assertSame( 5, get_option( 'stonewright_surface_revision' ), 'Still the same open incident.' );

		ChangeJournal::settle( $entry['id'], 'rolled_back' );
		self::assertSame( 6, get_option( 'stonewright_surface_revision' ) );
	}

	public function test_an_incident_written_by_another_writer_is_imported_once(): void {
		$entry = ChangeJournal::arm( self::spec() );
		$this->external_incident( $entry['id'], 4242 );

		self::assertSame( 1, ChangeJournal::sync_from_file() );

		$rich = ChangeJournal::get( $entry['id'] );
		self::assertSame( 'incident', $rich['state'] );
		self::assertSame( 4242, $rich['incident']['recorded_at'] );
		self::assertSame( $entry['id'], ChangeJournal::banner()['id'] );
		self::assertCount( 1, ChangeJournal::open_incidents() );
		$rows = $this->incident_audit_rows();
		self::assertCount( 1, $rows );

		// Importing twice must not duplicate anything.
		self::assertSame( 0, ChangeJournal::sync_from_file() );
		self::assertSame( 0, ChangeJournal::sync_from_file() );
		self::assertCount( 1, ChangeJournal::open_incidents() );
		self::assertCount( 1, $this->incident_audit_rows() );
	}

	public function test_a_second_fatal_on_the_same_entry_is_a_new_incident(): void {
		$entry = ChangeJournal::arm( self::spec() );
		$this->external_incident( $entry['id'], 4242 );
		ChangeJournal::sync_from_file();

		$this->external_incident( $entry['id'], 4300 );

		self::assertSame( 1, ChangeJournal::sync_from_file() );
		self::assertSame( 4300, ChangeJournal::get( $entry['id'] )['incident']['recorded_at'] );
		self::assertCount( 2, $this->incident_audit_rows() );
	}

	public function test_an_incident_on_an_entry_that_was_already_rolled_back_is_ignored(): void {
		$entry = ChangeJournal::arm( self::spec() );
		ChangeJournal::settle( $entry['id'], 'rolled_back' );
		// The file entry is still "rolled_back"; a late writer flips it.
		$this->external_incident( $entry['id'], 5000 );

		self::assertSame( 0, ChangeJournal::sync_from_file() );
		self::assertSame( 'rolled_back', ChangeJournal::get( $entry['id'] )['state'] );
		self::assertNull( ChangeJournal::banner() );
	}

	public function test_syncing_an_unchanged_file_does_not_parse_it_again(): void {
		$entry = ChangeJournal::arm( self::spec() );
		$this->external_incident( $entry['id'], 4242 );
		ChangeJournal::sync_from_file();

		$seen = get_option( ChangeJournal::SEEN_OPTION );
		self::assertIsArray( $seen );
		self::assertSame( 0, ChangeJournal::sync_from_file() );
		self::assertSame( $seen, get_option( ChangeJournal::SEEN_OPTION ) );
	}

	// -- The file is input, not a source of entries --------------------------------------------

	/** @return array<string, array{0:string,1:string,2:string,3:string,4:string}> */
	public static function forged_entries(): array {
		return [
			'a post'         => [ 'post', '31', 'post_snapshot', 'snap-1', 'stonewright/elementor-v3-batch-mutate' ],
			'an option'      => [ 'option', 'siteurl', 'option_restore', 'rp-1', 'stonewright/theme-chrome-update' ],
			'a plugin'       => [ 'plugin', 'security-plugin/security.php', 'plugin_state', 'security-plugin/security.php', 'stonewright/plugin-deactivate' ],
			'a sandbox file' => [ 'sandbox', 'snippet.php', 'sandbox_file', 'snippet.php', 'stonewright/sandbox-activate' ],
			'a theme file'   => [ 'theme_file', 'functions.php', 'theme_backup', 'sw-theme-backup-9', 'stonewright/theme-file-patch' ],
		];
	}

	/** Writes a compact entry straight into the journal file, the way anything with write access to uploads could. */
	private function plant( array $override ): string {
		$entry = array_merge(
			[
				'id'             => 'cs-planted',
				'ability'        => 'stonewright/theme-file-patch',
				'resource_type'  => 'theme_file',
				'resource_key'   => 'functions.php',
				'recipe'         => [ 'type' => 'theme_backup', 'ref' => 'sw-theme-backup-9' ],
				'paths'          => [],
				'armed_at'       => time(),
				'probe_deadline' => time() + 120,
				'state'          => 'armed',
				'incident'       => null,
			],
			$override
		);
		$file = new ChangeJournalFile( $this->journal_path() );
		$file->transaction(
			static function ( array $document ) use ( $entry ): array {
				$document['entries'][] = $entry;
				return $document;
			}
		);
		clearstatcache();
		return (string) $entry['id'];
	}

	/** @return array<string, mixed> */
	private static function fatal( int $recorded_at = 9000 ): array {
		return [
			'recorded_at'    => $recorded_at,
			'file'           => 'wp-content/themes/site-a/functions.php',
			'line'           => 9,
			'type'           => 1,
			'message_sha256' => str_repeat( 'c', 64 ),
			'source'         => 'shutdown',
		];
	}

	/**
	 * @dataProvider forged_entries
	 */
	public function test_an_entry_the_database_never_armed_is_not_imported_from_the_file( string $type, string $key, string $recipe, string $ref, string $ability ): void {
		$known = ChangeJournal::arm( self::spec() );
		$id    = $this->plant(
			[
				'id'            => 'cs-forged-' . $type,
				'ability'       => $ability,
				'resource_type' => $type,
				'resource_key'  => $key,
				'recipe'        => [ 'type' => $recipe, 'ref' => $ref ],
				'state'         => 'incident',
				'incident'      => self::fatal(),
			]
		);

		self::assertSame( 0, ChangeJournal::sync_from_file() );

		self::assertNull( ChangeJournal::get( $id ), 'The file never creates an entry or a recipe.' );
		self::assertSame( [], ChangeJournal::open_incidents() );
		self::assertFalse( ChangeJournal::has_open_incident() );
		self::assertNull( ChangeJournal::banner() );
		$ids = array_column( $this->file_document()['entries'], 'id' );
		self::assertNotContains( $id, $ids, 'The file is rewritten without the forged entry.' );
		self::assertContains( $known['id'], $ids );
		self::assertSame( [], $this->incident_audit_rows() );
	}

	public function test_a_forged_entry_in_the_file_is_dropped_by_the_next_write_even_when_nothing_else_is_wrong(): void {
		ChangeJournal::arm( self::spec() );
		$id = $this->plant( [ 'id' => 'cs-forged-quiet', 'state' => 'armed' ] );

		ChangeJournal::arm( self::spec( [ 'resource_key' => 'style.css' ] ) );

		self::assertNull( ChangeJournal::get( $id ) );
		self::assertNotContains( $id, array_column( $this->file_document()['entries'], 'id' ) );
	}

	public function test_a_file_entry_with_the_id_of_a_known_change_but_another_ability_or_resource_is_ignored(): void {
		$entry = ChangeJournal::arm( self::spec() );
		$file  = new ChangeJournalFile( $this->journal_path() );
		$id    = $entry['id'];
		$file->transaction(
			static function ( array $document ) use ( $id ): array {
				foreach ( $document['entries'] as $index => $candidate ) {
					if ( $candidate['id'] === $id ) {
						$document['entries'][ $index ]['ability']       = 'stonewright/plugin-deactivate';
						$document['entries'][ $index ]['resource_type'] = 'plugin';
						$document['entries'][ $index ]['resource_key']  = 'security-plugin/security.php';
						$document['entries'][ $index ]['recipe']        = [ 'type' => 'plugin_state', 'ref' => 'security-plugin/security.php' ];
						$document['entries'][ $index ]['state']         = 'incident';
						$document['entries'][ $index ]['incident']      = self::fatal();
					}
				}
				return $document;
			}
		);
		clearstatcache();

		self::assertSame( 0, ChangeJournal::sync_from_file() );

		$known = ChangeJournal::get( $id );
		self::assertSame( 'armed', $known['state'] );
		self::assertSame( 'stonewright/theme-file-patch', $known['ability'] );
		self::assertSame( 'theme_backup', $known['recipe']['type'] );
		self::assertSame( [], ChangeJournal::open_incidents() );
		$on_disk = $this->file_document()['entries'][0];
		self::assertSame( 'armed', $on_disk['state'], 'The file is put back in step with the database.' );
		self::assertSame( 'theme_file', $on_disk['resource_type'] );
		self::assertSame( 'theme_backup', $on_disk['recipe']['type'] );
	}

	public function test_the_file_can_add_a_fatal_to_a_known_entry_but_never_change_its_recipe_or_paths(): void {
		$entry = ChangeJournal::arm( self::spec() );
		$file  = new ChangeJournalFile( $this->journal_path() );
		$id    = $entry['id'];
		$file->transaction(
			static function ( array $document ) use ( $id ): array {
				foreach ( $document['entries'] as $index => $candidate ) {
					if ( $candidate['id'] === $id ) {
						$document['entries'][ $index ]['recipe']   = [ 'type' => 'plugin_state', 'ref' => 'security-plugin/security.php' ];
						$document['entries'][ $index ]['paths']    = [ 'wp-content/plugins/security-plugin/security.php' ];
						$document['entries'][ $index ]['state']    = 'incident';
						$document['entries'][ $index ]['incident'] = self::fatal();
					}
				}
				return $document;
			}
		);
		clearstatcache();

		self::assertSame( 1, ChangeJournal::sync_from_file() );

		$known = ChangeJournal::get( $id );
		self::assertSame( 'incident', $known['state'] );
		self::assertSame( [ 'type' => 'theme_backup', 'ref' => 'sw-theme-backup-1111' ], $known['recipe'] );
		self::assertSame( [ 'wp-content/themes/site-a/functions.php' ], $known['paths'] );
		$on_disk = $this->file_document()['entries'][0];
		self::assertSame( 'theme_backup', $on_disk['recipe']['type'] );
		self::assertSame( [ 'wp-content/themes/site-a/functions.php' ], $on_disk['paths'] );
	}

	public function test_a_stale_incident_in_the_file_is_rewritten_so_nothing_keeps_reading_it_as_open(): void {
		$entry = ChangeJournal::arm( self::spec() );
		ChangeJournal::settle( $entry['id'], 'rolled_back' );
		$this->external_incident( $entry['id'], 5000 );
		self::assertStringContainsString( '"state":"incident"', (string) file_get_contents( $this->journal_path() ) );

		self::assertSame( 0, ChangeJournal::sync_from_file() );

		self::assertSame( 'rolled_back', $this->file_document()['entries'][0]['state'], 'The helper reads the file: it must not see an open incident.' );
		self::assertNull( ChangeJournal::banner() );
		self::assertFalse( ChangeJournal::has_open_incident() );
		self::assertSame( 0, ChangeJournal::sync_from_file(), 'And it stays that way.' );
	}

	public function test_an_oversize_journal_file_is_replaced_even_when_the_journal_holds_nothing(): void {
		ChangeJournal::arm( self::spec() );
		update_option( ChangeJournal::DB_OPTION, [], false );
		file_put_contents( $this->journal_path(), str_repeat( ' ', ChangeJournalFile::MAX_BYTES + 10 ) . '{}' );
		clearstatcache();

		ChangeJournal::sync_from_file();

		self::assertLessThan( ChangeJournalFile::MAX_BYTES, filesize( $this->journal_path() ) );
		self::assertSame( [], $this->file_document()['entries'] );
		self::assertSame( 0, ChangeJournal::sync_from_file(), 'And the next load has nothing left to do.' );
	}
	public function test_a_journal_file_over_the_helpers_limit_is_replaced_by_a_clean_one(): void {
		$entry = ChangeJournal::arm( self::spec() );
		file_put_contents( $this->journal_path(), str_repeat( ' ', ChangeJournalFile::MAX_BYTES + 10 ) . '{"version":1,"entries":[]}' );
		clearstatcache();

		self::assertSame( 0, ChangeJournal::sync_from_file() );

		self::assertLessThan( ChangeJournalFile::MAX_BYTES, filesize( $this->journal_path() ) );
		self::assertSame( [ $entry['id'] ], array_column( $this->file_document()['entries'], 'id' ), 'Rebuilt from the database copy.' );
	}
	public function test_a_deleted_file_is_rebuilt_from_the_database_copy(): void {
		$entry = ChangeJournal::arm( self::spec() );
		unlink( $this->journal_path() );

		ChangeJournal::arm( self::spec( [ 'resource_key' => 'style.css' ] ) );

		$ids = array_column( $this->file_document()['entries'], 'id' );
		self::assertContains( $entry['id'], $ids );
		self::assertCount( 2, $ids );
	}

	public function test_the_journal_still_works_from_the_database_when_the_state_directory_cannot_be_created(): void {
		// A file where the directory should be.
		file_put_contents( $this->uploads . '/stonewright-state', 'in the way' );

		$entry = ChangeJournal::arm( self::spec() );

		self::assertNotNull( ChangeJournal::get( $entry['id'] ) );
		self::assertSame( 'unavailable', ChangeJournal::storage_status()['mirror'] );
		ChangeJournal::settle( $entry['id'], 'rollback_failed' );
		self::assertSame( $entry['id'], ChangeJournal::banner()['id'] );
	}

	public function test_it_keeps_at_most_fifty_entries_in_both_copies(): void {
		for ( $i = 0; $i < 53; $i++ ) {
			$entry = ChangeJournal::arm( self::spec( [ 'resource_key' => 'f' . $i . '.php' ] ) );
			ChangeJournal::settle( $entry['id'], 'verified' );
		}

		self::assertCount( 50, $this->file_document()['entries'] );
		self::assertCount( 50, ChangeJournal::recent( 100 ) );
	}

	public function test_recent_lists_the_newest_entries_first(): void {
		$first  = ChangeJournal::arm( self::spec( [ 'resource_key' => 'a.php' ] ) );
		$second = ChangeJournal::arm( self::spec( [ 'resource_key' => 'b.php' ] ) );

		$ids = array_column( ChangeJournal::recent( 5 ), 'id' );
		self::assertSame( [ $second['id'], $first['id'] ], $ids );
	}

	public function test_armed_entries_past_their_deadline_are_reported_as_unconfirmed(): void {
		$entry = ChangeJournal::arm( self::spec() );
		self::assertSame( [], ChangeJournal::unconfirmed() );

		$late = time() + 4000;
		$ids  = array_column( ChangeJournal::unconfirmed( $late ), 'id' );
		self::assertSame( [ $entry['id'] ], $ids );

		ChangeJournal::settle( $entry['id'], 'verified' );
		self::assertSame( [], ChangeJournal::unconfirmed( $late ) );
	}

	// -- Claiming an entry for a rollback ------------------------------------------------------

	public function test_an_entry_can_be_claimed_for_a_rollback_by_one_caller_only(): void {
		$entry = ChangeJournal::arm( self::spec() );

		$first = ChangeJournal::claim( $entry['id'], 'page' );

		self::assertNotNull( $first );
		self::assertGreaterThan( 0, $first['claim_at'] );
		self::assertSame( 'page', $first['claim_by'] );
		self::assertNull( ChangeJournal::claim( $entry['id'], 'ability' ), 'The second caller loses; the first one owns the rollback.' );
		self::assertTrue( ChangeJournal::is_claimed( (array) ChangeJournal::get( $entry['id'] ) ) );
		self::assertSame( 'page', ChangeJournal::get( $entry['id'] )['claim_by'] );
	}

	public function test_a_claim_is_not_part_of_the_shared_file_contract(): void {
		$entry = ChangeJournal::arm( self::spec() );

		ChangeJournal::claim( $entry['id'], 'page' );

		$on_disk = $this->file_document()['entries'][0];
		self::assertArrayNotHasKey( 'claim_at', $on_disk );
		self::assertSame( 'armed', $on_disk['state'], 'The helper keeps matching a fatal during the rollback to this entry.' );
	}

	public function test_a_claim_goes_stale_so_a_rollback_that_died_never_locks_the_entry(): void {
		$entry = ChangeJournal::arm( self::spec() );
		ChangeJournal::claim( $entry['id'], 'page' );

		$GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] = static fn (): int => time() + ChangeJournal::CLAIM_TTL + 5;

		self::assertFalse( ChangeJournal::is_claimed( (array) ChangeJournal::get( $entry['id'] ) ) );
		self::assertNotNull( ChangeJournal::claim( $entry['id'], 'ability' ) );
		unset( $GLOBALS['stonewright_test_filters']['stonewright_rescue_now'] );
	}

	public function test_settling_an_entry_releases_its_claim(): void {
		$entry = ChangeJournal::arm( self::spec() );
		ChangeJournal::claim( $entry['id'], 'page' );

		ChangeJournal::settle( $entry['id'], 'rolled_back' );

		$settled = ChangeJournal::get( $entry['id'] );
		self::assertSame( 0, $settled['claim_at'] );
		self::assertSame( '', $settled['claim_by'] );
		self::assertNull( ChangeJournal::claim( $entry['id'], 'page' ), 'A settled change has nothing left to roll back.' );
	}

	public function test_a_claim_can_be_released_without_settling(): void {
		$entry = ChangeJournal::arm( self::spec() );
		ChangeJournal::claim( $entry['id'], 'page' );

		ChangeJournal::release_claim( $entry['id'] );

		self::assertFalse( ChangeJournal::is_claimed( (array) ChangeJournal::get( $entry['id'] ) ) );
		self::assertNotNull( ChangeJournal::claim( $entry['id'], 'ability' ) );
	}

	public function test_only_a_known_entry_that_can_still_be_rolled_back_can_be_claimed(): void {
		self::assertNull( ChangeJournal::claim( 'cs-unknown', 'page' ) );
		$verified = ChangeJournal::arm( self::spec() );
		ChangeJournal::settle( $verified['id'], 'verified' );

		self::assertNull( ChangeJournal::claim( $verified['id'], 'page' ) );
	}

	// -- Newer changes on the same resource ------------------------------------------------------

	public function test_a_newer_change_to_the_same_resource_is_reported_but_other_resources_and_undone_changes_are_not(): void {
		$older = ChangeJournal::arm( self::spec() );
		$newer = ChangeJournal::arm( self::spec() );
		$other = ChangeJournal::arm( self::spec( [ 'resource_key' => 'style.css' ] ) );
		$undone = ChangeJournal::arm( self::spec() );
		ChangeJournal::settle( $undone['id'], 'rolled_back' );

		$found = ChangeJournal::newer_changes( (array) ChangeJournal::get( $older['id'] ) );

		self::assertSame( [ $newer['id'] ], array_column( $found, 'id' ) );
		self::assertSame( [], ChangeJournal::newer_changes( (array) ChangeJournal::get( $other['id'] ) ), 'Nothing newer on style.css.' );
		self::assertSame( [], ChangeJournal::newer_changes( (array) ChangeJournal::get( $undone['id'] ) ) );
	}

	public function test_an_older_change_is_never_reported_as_newer(): void {
		$older = ChangeJournal::arm( self::spec() );
		$newer = ChangeJournal::arm( self::spec() );

		self::assertSame( [], ChangeJournal::newer_changes( (array) ChangeJournal::get( $newer['id'] ) ) );
		self::assertNotSame( [], ChangeJournal::newer_changes( (array) ChangeJournal::get( $older['id'] ) ) );
	}

	public function test_option_writes_overlap_when_they_share_an_option(): void {
		$older = ChangeJournal::arm( self::spec( [ 'resource_type' => 'option', 'resource_key' => 'blogname,blogdescription' ] ) );
		$newer = ChangeJournal::arm( self::spec( [ 'resource_type' => 'option', 'resource_key' => 'blogdescription,siteurl' ] ) );
		ChangeJournal::arm( self::spec( [ 'resource_type' => 'option', 'resource_key' => 'timezone_string' ] ) );

		self::assertSame( [ $newer['id'] ], array_column( ChangeJournal::newer_changes( (array) ChangeJournal::get( $older['id'] ) ), 'id' ) );
	}
	public function test_erasing_the_state_files_removes_the_journal_its_lock_and_the_folder(): void {
		ChangeJournal::arm( self::spec() );
		$dir = $this->uploads . '/stonewright-state';
		file_put_contents( $this->journal_path() . '.lock', '' );
		file_put_contents( $this->journal_path() . '.tmp-0a1b2c3d', '{}' );
		self::assertNotEmpty( glob( $dir . '/journal-*.json' ) );

		self::assertTrue( ChangeJournal::erase_state_files() );

		self::assertDirectoryDoesNotExist( $dir, 'Nothing of the journal is left, guard files included.' );
	}

	public function test_erasing_leaves_what_the_journal_did_not_write(): void {
		ChangeJournal::arm( self::spec() );
		$dir = $this->uploads . '/stonewright-state';
		file_put_contents( $dir . '/notes.txt', 'someone else put this here' );

		self::assertTrue( ChangeJournal::erase_state_files() );

		self::assertFileExists( $dir . '/notes.txt' );
		self::assertSame( [], glob( $dir . '/journal-*' ) ?: [], 'The journal files are gone.' );
		self::assertFileExists( $dir . '/.htaccess', 'The folder is still closed to the web while other files are in it.' );
	}

	public function test_erasing_when_nothing_was_ever_written_is_not_an_error(): void {
		self::assertTrue( ChangeJournal::erase_state_files() );
		self::assertDirectoryDoesNotExist( $this->uploads . '/stonewright-state' );
	}

	public function test_the_journal_works_again_after_its_files_were_erased(): void {
		$first = ChangeJournal::arm( self::spec() );
		ChangeJournal::erase_state_files();

		$second = ChangeJournal::arm( self::spec( [ 'resource_key' => 'header.php' ] ) );

		self::assertNotNull( $second );
		self::assertFileExists( $this->journal_path() );
		self::assertNotSame( $first['id'], $second['id'] );
	}

	public function test_an_invalid_spec_is_refused_without_writing_anything(): void {
		self::assertNull( ChangeJournal::arm( [ 'ability' => 'not valid', 'resource_type' => 'theme_file', 'resource_key' => 'x' ] ) );
		self::assertFalse( get_option( ChangeJournal::FILE_OPTION, false ) );
	}

	/** Simulates the MU-plugin: take the shared lock and flip an entry to "incident". */
	private function external_incident( string $id, int $recorded_at ): void {
		$file = new ChangeJournalFile( $this->journal_path() );
		$file->transaction(
			static function ( array $document ) use ( $id, $recorded_at ): array {
				foreach ( $document['entries'] as $index => $entry ) {
					if ( $entry['id'] === $id ) {
						$document['entries'][ $index ]['state']    = 'incident';
						$document['entries'][ $index ]['incident'] = [
							'recorded_at'    => $recorded_at,
							'file'           => 'wp-content/themes/site-a/functions.php',
							'line'           => 9,
							'type'           => 1,
							'message_sha256' => str_repeat( 'c', 64 ),
							'source'         => 'shutdown',
						];
					}
				}
				return $document;
			}
		);
		clearstatcache();
	}

	/** @return list<array<string, mixed>> */
	private function incident_audit_rows(): array {
		return array_values(
			array_filter(
				$GLOBALS['stonewright_test_wpdb_inserts'],
				static fn ( array $row ): bool => str_contains( (string) ( $row['data']['ability_name'] ?? '' ), 'rescue-incident' )
			)
		);
	}

	private static function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: [] as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? self::remove_tree( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}
}
