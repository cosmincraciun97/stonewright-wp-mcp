<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Site;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Site\ChangeRestore;
use Stonewright\WpMcp\Security\Backup;

/**
 * The snapshot a restore takes of the current state must not push out the snapshot it restores.
 *
 * @covers \Stonewright\WpMcp\Abilities\Site\ChangeRestore
 * @covers \Stonewright\WpMcp\Security\Backup
 */
final class ChangeRestoreEvictionTest extends TestCase {

	private const POST_ID = 4201;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_filters']         = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true, 'edit_posts' => true, 'edit_pages' => true, 'edit_post' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_posts']           = [
			self::POST_ID => (object) [
				'ID'           => self::POST_ID,
				'post_type'    => 'page',
				'post_content' => 'version 0',
				'post_status'  => 'publish',
				'post_title'   => 'Restore fixture',
				'post_excerpt' => '',
				'meta'         => [],
			],
		];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
	}

	/**
	 * A post with a full history: one snapshot of each of versions 0 to 9, and the page now at version 10.
	 *
	 * @return list<string> Snapshot ids, oldest first.
	 */
	private function full_history(): array {
		$ids = [];
		for ( $version = 0; $version < 10; ++$version ) {
			$GLOBALS['stonewright_test_posts'][ self::POST_ID ]->post_content = 'version ' . $version;
			$ids[]                                                            = Backup::snapshot_post( self::POST_ID );
			self::assertNotSame( '', $ids[ $version ] );
		}
		$GLOBALS['stonewright_test_posts'][ self::POST_ID ]->post_content = 'version 10';
		self::assertCount( 10, Backup::list_snapshots( self::POST_ID ), 'The history is full.' );
		return $ids;
	}

	public function test_restoring_the_oldest_snapshot_of_a_full_history_works(): void {
		$ids = $this->full_history();

		$result = ( new ChangeRestore() )->execute( [ 'post_id' => self::POST_ID, 'snapshot_id' => $ids[0] ] );

		self::assertIsArray( $result, 'The pre-restore snapshot must not evict the one being restored.' );
		self::assertTrue( $result['restored'] );
		self::assertSame( 'version 0', $GLOBALS['stonewright_test_posts'][ self::POST_ID ]->post_content );
	}

	public function test_the_restore_can_be_undone_with_the_snapshot_it_returns(): void {
		$ids = $this->full_history();

		$result = ( new ChangeRestore() )->execute( [ 'post_id' => self::POST_ID, 'snapshot_id' => $ids[0] ] );

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'pre_restore_snapshot_id', $result );
		$undo = (string) $result['pre_restore_snapshot_id'];
		self::assertNotSame( '', $undo );
		self::assertNotSame( $ids[0], $undo );
		$kept = Backup::get_snapshot( self::POST_ID, $undo );
		self::assertIsArray( $kept, 'The snapshot of the state before the restore is in the history.' );
		self::assertSame( 'version 10', $kept['post_content'] );
		self::assertTrue( Backup::restore( self::POST_ID, $undo ) );
		self::assertSame( 'version 10', $GLOBALS['stonewright_test_posts'][ self::POST_ID ]->post_content );
	}

	public function test_the_history_still_holds_ten_snapshots_after_a_restore(): void {
		$ids = $this->full_history();

		( new ChangeRestore() )->execute( [ 'post_id' => self::POST_ID, 'snapshot_id' => $ids[0] ] );

		self::assertLessThanOrEqual( 10, count( Backup::list_snapshots( self::POST_ID ) ), 'The history limit still holds.' );
	}

	public function test_a_restore_whose_safety_snapshot_fails_changes_nothing(): void {
		$ids = $this->full_history();
		$GLOBALS['stonewright_test_update_post_meta_returns'] = [ '_stonewright_backups' => true ];

		$result = ( new ChangeRestore() )->execute( [ 'post_id' => self::POST_ID, 'snapshot_id' => $ids[0] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_backup_failed', $result->get_error_code() );
		self::assertSame( 'version 10', $GLOBALS['stonewright_test_posts'][ self::POST_ID ]->post_content, 'Nothing was restored without a way back.' );
	}
}
