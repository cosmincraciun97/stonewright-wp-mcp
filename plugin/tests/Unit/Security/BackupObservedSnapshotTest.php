<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\Backup;

/**
 * The snapshot the observer takes of a call Stonewright does not run lives in a pool of its own, so a call that
 * fails (or changes nothing) never pushes a snapshot of a real Stonewright write out of the history.
 *
 * @covers \Stonewright\WpMcp\Security\Backup
 */
final class BackupObservedSnapshotTest extends TestCase {

	private const PAGE = 61;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_posts']           = [
			self::PAGE => (object) [
				'ID'           => self::PAGE,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Page',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'page',
				'meta'         => [ '_elementor_data' => '[{"id":"a"}]' ],
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
	}

	public function test_a_dozen_observed_snapshots_do_not_evict_a_single_real_one(): void {
		$real = [];
		for ( $index = 0; $index < 10; $index++ ) {
			$real[] = Backup::snapshot_post( self::PAGE, [], false );
		}
		self::assertCount( 10, Backup::list_snapshots( self::PAGE ) );

		for ( $index = 0; $index < 12; $index++ ) {
			self::assertNotSame( '', Backup::snapshot_observed( self::PAGE ) );
		}

		self::assertSame( $real, array_keys( Backup::list_snapshots( self::PAGE ) ), 'all ten real snapshots survive' );
	}

	public function test_an_observed_snapshot_can_be_read_and_restored_by_its_id(): void {
		$id = Backup::snapshot_observed( self::PAGE );
		self::assertNotSame( '', $id );
		self::assertNotNull( Backup::get_snapshot( self::PAGE, $id ) );

		$GLOBALS['stonewright_test_posts'][ self::PAGE ]->meta['_elementor_data'] = '[{"id":"changed"}]';
		self::assertTrue( Backup::restore( self::PAGE, $id ) );
		self::assertSame( '[{"id":"a"}]', get_post_meta( self::PAGE, '_elementor_data', true ) );
	}

	public function test_observed_snapshots_keep_only_the_newest_few_and_stay_out_of_the_timeline(): void {
		$ids = [];
		for ( $index = 0; $index < 5; $index++ ) {
			$ids[] = Backup::snapshot_observed( self::PAGE );
		}

		self::assertNull( Backup::get_snapshot( self::PAGE, $ids[0] ), 'the oldest observed snapshot was trimmed' );
		self::assertNotNull( Backup::get_snapshot( self::PAGE, $ids[4] ) );
		self::assertSame( [], Backup::list_snapshots( self::PAGE ), 'the real history lists none of them' );
		self::assertSame( [], Backup::list_timeline( 50, self::PAGE ) );
	}

	public function test_a_discarded_observed_snapshot_is_gone_and_a_real_one_is_never_discarded(): void {
		$observed = Backup::snapshot_observed( self::PAGE );
		$real     = Backup::snapshot_post( self::PAGE, [], false );

		Backup::discard_observed( self::PAGE, $observed );
		Backup::discard_observed( self::PAGE, $real );

		self::assertNull( Backup::get_snapshot( self::PAGE, $observed ) );
		self::assertNotNull( Backup::get_snapshot( self::PAGE, $real ), 'only the observed pool is touched' );
	}

	public function test_an_unknown_post_gets_no_observed_snapshot(): void {
		self::assertSame( '', Backup::snapshot_observed( 999999 ) );
	}
}
