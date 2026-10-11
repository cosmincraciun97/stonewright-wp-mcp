<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Site;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Site\ChangeLog;
use Stonewright\WpMcp\Abilities\Site\ChangeRestore;
use Stonewright\WpMcp\Security\Backup;

/**
 * change-log shows snapshot rows only for posts the user may edit, and change-restore needs the publish
 * capability to bring back a published, scheduled or private status.
 *
 * @covers \Stonewright\WpMcp\Abilities\Site\ChangeLog
 * @covers \Stonewright\WpMcp\Abilities\Site\ChangeRestore
 * @covers \Stonewright\WpMcp\Security\Backup
 */
final class ChangeObjectPermissionsTest extends TestCase {

	private const OWN_POST   = 4201;
	private const OTHER_POST = 4202;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_post_types']      = [
			'post' => (object) [ 'cap' => (object) [ 'publish_posts' => 'publish_posts' ] ],
		];
		foreach ( [ self::OWN_POST => 'Own post', self::OTHER_POST => 'Somebody else\'s post' ] as $id => $title ) {
			$GLOBALS['stonewright_test_posts'][ $id ] = (object) [
				'ID'           => $id,
				'post_type'    => 'post',
				'post_content' => 'original content',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_excerpt' => '',
				'meta'         => [ '_elementor_data' => '[{"id":"a"}]' ],
			];
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_user_can_callback'] );
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_types']      = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
	}

	/**
	 * @param list<string> $caps
	 * @param list<string> $denied "cap:post_id" pairs refused although the capability is held.
	 */
	private function user_can( array $caps, array $denied = [] ): void {
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ) use ( $caps, $denied ): bool {
			if ( isset( $args[0] ) && in_array( $cap . ':' . (int) $args[0], $denied, true ) ) {
				return false;
			}
			return in_array( $cap, $caps, true );
		};
	}

	public function test_the_log_without_a_post_id_lists_only_posts_the_user_can_edit(): void {
		Backup::snapshot_post( self::OWN_POST );
		Backup::snapshot_post( self::OTHER_POST );
		$this->user_can( [ 'edit_posts', 'edit_post' ], [ 'edit_post:' . self::OTHER_POST ] );

		$result = ( new ChangeLog() )->execute( [ 'limit' => 50 ] );

		self::assertSame( [ self::OWN_POST ], array_column( $result['entries'], 'post_id' ) );
		self::assertSame( 1, $result['count'] );
	}

	public function test_the_log_without_a_post_id_lists_every_post_for_a_user_who_can_edit_them_all(): void {
		Backup::snapshot_post( self::OWN_POST );
		Backup::snapshot_post( self::OTHER_POST );
		$this->user_can( [ 'edit_posts', 'edit_post' ] );

		$result = ( new ChangeLog() )->execute( [ 'limit' => 50 ] );

		self::assertEqualsCanonicalizing( [ self::OWN_POST, self::OTHER_POST ], array_column( $result['entries'], 'post_id' ) );
	}

	/** @return array<string, array{0: string}> */
	public static function publishing_statuses(): array {
		return [
			'publish' => [ 'publish' ],
			'future'  => [ 'future' ],
			'private' => [ 'private' ],
		];
	}

	/**
	 * @dataProvider publishing_statuses
	 */
	public function test_restoring_a_publishing_status_needs_the_publish_capability( string $status ): void {
		$GLOBALS['stonewright_test_posts'][ self::OWN_POST ]->post_status = $status;
		$snapshot_id = Backup::snapshot_post( self::OWN_POST );
		$GLOBALS['stonewright_test_posts'][ self::OWN_POST ]->post_status  = 'draft';
		$GLOBALS['stonewright_test_posts'][ self::OWN_POST ]->post_content = 'a draft edit';
		$this->user_can( [ 'edit_posts', 'edit_post' ] );
		$args = [ 'post_id' => self::OWN_POST, 'snapshot_id' => $snapshot_id ];

		$permission = ( new ChangeRestore() )->permission_callback( $args );
		self::assertInstanceOf( \WP_Error::class, $permission );
		self::assertSame( 'stonewright_cannot_publish', $permission->get_error_code() );

		$result = ( new ChangeRestore() )->execute( $args );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_cannot_publish', $result->get_error_code() );
		self::assertSame( 'draft', $GLOBALS['stonewright_test_posts'][ self::OWN_POST ]->post_status, 'The status was not restored.' );
		self::assertSame( 'a draft edit', $GLOBALS['stonewright_test_posts'][ self::OWN_POST ]->post_content );
	}

	/**
	 * @dataProvider publishing_statuses
	 */
	public function test_restoring_a_publishing_status_works_with_the_publish_capability( string $status ): void {
		$GLOBALS['stonewright_test_posts'][ self::OWN_POST ]->post_status = $status;
		$snapshot_id = Backup::snapshot_post( self::OWN_POST );
		$GLOBALS['stonewright_test_posts'][ self::OWN_POST ]->post_status = 'draft';
		$this->user_can( [ 'edit_posts', 'edit_post', 'publish_posts' ] );
		$args = [ 'post_id' => self::OWN_POST, 'snapshot_id' => $snapshot_id ];

		self::assertTrue( ( new ChangeRestore() )->permission_callback( $args ) );
		$result = ( new ChangeRestore() )->execute( $args );

		self::assertIsArray( $result );
		self::assertTrue( $result['restored'] );
		self::assertSame( $status, $GLOBALS['stonewright_test_posts'][ self::OWN_POST ]->post_status );
	}

	public function test_restoring_a_draft_status_needs_no_publish_capability(): void {
		$GLOBALS['stonewright_test_posts'][ self::OWN_POST ]->post_status = 'draft';
		$snapshot_id = Backup::snapshot_post( self::OWN_POST );
		$GLOBALS['stonewright_test_posts'][ self::OWN_POST ]->post_content = 'edited';
		$this->user_can( [ 'edit_posts', 'edit_post' ] );

		$result = ( new ChangeRestore() )->execute( [ 'post_id' => self::OWN_POST, 'snapshot_id' => $snapshot_id ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['restored'] );
		self::assertSame( 'original content', $GLOBALS['stonewright_test_posts'][ self::OWN_POST ]->post_content );
	}
}
