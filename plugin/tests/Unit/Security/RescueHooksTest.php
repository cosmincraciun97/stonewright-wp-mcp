<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\RescuePage;
use Stonewright\WpMcp\Security\ChangeJournal;
use Stonewright\WpMcp\Security\ChangeJournalFile;
use Stonewright\WpMcp\Security\ProbeToken;
use Stonewright\WpMcp\Security\RescueHooks;
use Stonewright\WpMcp\Support\AgentNotices;

/**
 * What Rescue registers on every request.
 *
 * @covers \Stonewright\WpMcp\Security\RescueHooks
 */
final class RescueHooksTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		$this->uploads = sys_get_temp_dir() . '/sw-hooks-' . bin2hex( random_bytes( 5 ) );
		mkdir( $this->uploads, 0700, true );
		$GLOBALS['stonewright_test_upload_dir']   = [ 'basedir' => $this->uploads, 'baseurl' => 'https://example.test/uploads', 'error' => false ];
		$GLOBALS['stonewright_test_options']      = [];
		$GLOBALS['stonewright_test_actions']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		ChangeJournal::reset_for_tests();
		AgentNotices::reset_for_tests();
	}

	protected function tearDown(): void {
		AgentNotices::reset_for_tests();
		ChangeJournal::reset_for_tests();
		unset( $GLOBALS['stonewright_test_upload_dir'] );
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_actions'] = [];
		self::remove_tree( $this->uploads );
	}

	/** @return list<array{callback:mixed,priority:int,args:int}> */
	private static function callbacks( string $hook ): array {
		return $GLOBALS['stonewright_test_actions'][ $hook ] ?? [];
	}

	public function test_the_probe_token_is_checked_first_on_every_request(): void {
		RescueHooks::register();

		$registered = self::callbacks( 'plugins_loaded' );
		self::assertCount( 1, $registered );
		self::assertSame( [ ProbeToken::class, 'authenticate_request' ], $registered[0]['callback'] );
		self::assertSame( 1, $registered[0]['priority'], 'Before wp-admin asks who is logged in.' );
	}

	public function test_incidents_left_in_the_file_are_imported_on_the_first_admin_or_rest_load(): void {
		RescueHooks::register();

		foreach ( [ 'admin_init', 'rest_api_init' ] as $hook ) {
			$registered = self::callbacks( $hook );
			self::assertCount( 1, $registered, $hook );
			self::assertSame( [ RescueHooks::class, 'sync_journal' ], $registered[0]['callback'], $hook );
			self::assertSame( 1, $registered[0]['priority'], $hook );
		}
	}

	public function test_the_rescue_page_registers_its_menu_actions_and_script(): void {
		RescueHooks::register();

		self::assertSame( [ RescuePage::class, 'add_submenu' ], self::callbacks( 'admin_menu' )[0]['callback'] );
		foreach ( [ 'rollback', 'recheck', 'safe_mode' ] as $action ) {
			self::assertNotEmpty( self::callbacks( 'admin_post_stonewright_rescue_' . $action ), $action );
		}
		self::assertSame( [ RescuePage::class, 'enqueue' ], self::callbacks( 'admin_enqueue_scripts' )[0]['callback'] );
	}

	public function test_the_pending_incident_travels_on_responses_only_while_one_is_open(): void {
		RescueHooks::register();
		self::assertSame( [ 'ok' => true ], AgentNotices::attach( [ 'ok' => true ] ), 'Nothing to say, nothing added.' );

		$entry = ChangeJournal::arm( [ 'ability' => 'stonewright/x-y', 'resource_type' => 'option', 'resource_key' => 'blogname' ] );
		ChangeJournal::settle( (string) $entry['id'], 'rollback_failed' );
		$response = AgentNotices::attach( [ 'ok' => true ] );

		self::assertSame( $entry['id'], $response['pending_incident']['id'] );
		self::assertSame( 'stonewright-rescue-rollback', $response['pending_incident']['rollback'], 'It names the ability to call.' );

		ChangeJournal::settle( (string) $entry['id'], 'verified' );
		self::assertSame( [ 'ok' => true ], AgentNotices::attach( [ 'ok' => true ] ), 'Closed again.' );
	}

	public function test_syncing_brings_in_a_fatal_that_another_writer_recorded_in_the_file(): void {
		$entry = ChangeJournal::arm( [ 'ability' => 'stonewright/x-y', 'resource_type' => 'theme_file', 'resource_key' => 'functions.php' ] );
		$file  = new ChangeJournalFile( $this->uploads . '/stonewright-state/' . (string) get_option( ChangeJournal::FILE_OPTION, '' ) );
		$id    = (string) $entry['id'];
		$file->transaction(
			static function ( array $document ) use ( $id ): array {
				foreach ( $document['entries'] as $index => $candidate ) {
					if ( $candidate['id'] === $id ) {
						$document['entries'][ $index ]['state']    = 'incident';
						$document['entries'][ $index ]['incident'] = [
							'recorded_at'    => time(),
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
		self::assertSame( [], ChangeJournal::open_incidents(), 'Not known to the database yet.' );

		RescueHooks::sync_journal();

		self::assertCount( 1, ChangeJournal::open_incidents() );
		self::assertSame( $id, ChangeJournal::open_incidents()[0]['id'] );
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
