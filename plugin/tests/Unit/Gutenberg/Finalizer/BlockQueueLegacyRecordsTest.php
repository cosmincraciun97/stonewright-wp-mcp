<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg\Finalizer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;

/**
 * Records stored without an owner or a session are never trusted: an open one becomes a
 * failed record with the code legacy_session_unbound the first time the queue is read,
 * and no user can claim, serialize, persist, reject or cancel it as its owner.
 *
 * @covers \Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue
 */
final class BlockQueueLegacyRecordsTest extends TestCase {

	private const SPEC = [ 'name' => 'vendor/card', 'attributes' => [ 'title' => 'Stored' ], 'innerBlocks' => [] ];

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_posts' => true, 'edit_post' => true ];
		$GLOBALS['stonewright_test_posts']           = [
			42 => (object) [
				'ID'           => 42,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Finalizer target',
				'post_content' => '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->',
				'post_excerpt' => '',
				'meta'         => [],
			],
		];
		$GLOBALS['stonewright_test_options'][ BlockQueue::OPTION ] = [
			'schema_version' => 2,
			'changes'        => [
				// No owner and no session.
				'legacy-queued'     => self::record( 'legacy-queued', 'queued', [ 'serialized_html' => '' ] ),
				'legacy-serialized' => self::record( 'legacy-serialized', 'serialized', [ 'serialized_html' => '<!-- wp:vendor/card /-->', 'serialized_html_hash' => hash( 'sha256', '<!-- wp:vendor/card /-->' ) ] ),
				// An owner without a session is just as unbound.
				'legacy-no-session' => self::record( 'legacy-no-session', 'queued', [ 'owner_user_id' => 7 ] ),
				// A session without an owner too.
				'legacy-no-owner'   => self::record( 'legacy-no-owner', 'queued', [ 'session_id' => 'old-session' ] ),
				// Already terminal: kept as history, never owned.
				'legacy-persisted'  => self::record( 'legacy-persisted', 'persisted', [] ),
				// A bound record on another post is untouched.
				'bound-queued'      => self::record( 'bound-queued', 'queued', [ 'owner_user_id' => 7, 'session_id' => 'session-bound', 'post_id' => 99 ] ),
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		unset( $GLOBALS['stonewright_test_user_can_callback'] );
	}

	/**
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	private static function record( string $id, string $status, array $extra ): array {
		return array_merge(
			[
				'id'         => $id,
				'post_id'    => 42,
				'status'     => $status,
				'block_spec' => self::SPEC,
				'created_at' => 1700000000,
				'updated_at' => 1700000000,
			],
			$extra
		);
	}

	/** @return array<string, mixed> */
	private static function stored( string $id ): array {
		$record = BlockQueue::get( $id );
		self::assertIsArray( $record, $id );
		return $record;
	}

	public function test_open_unbound_records_become_failed_with_the_stable_code(): void {
		foreach ( [ 'legacy-queued', 'legacy-serialized', 'legacy-no-session', 'legacy-no-owner' ] as $id ) {
			$record = self::stored( $id );

			self::assertSame( 'failed', $record['status'], $id );
			self::assertSame( 'legacy_session_unbound', $record['error_code'], $id );
			self::assertTrue( $record['legacy'], $id );
			self::assertSame( 0, $record['owner_user_id'], $id . ': the owner is cleared, not trusted.' );
			self::assertSame( 'This queued change has no owner session and cannot be serialized.', $record['error'], $id );
			self::assertSame( 'legacy_session_unbound', BlockQueue::compact( $record )['error']['code'], $id );
		}
	}

	public function test_terminal_and_bound_records_keep_their_state(): void {
		$persisted = self::stored( 'legacy-persisted' );
		self::assertSame( 'persisted', $persisted['status'] );
		self::assertTrue( $persisted['legacy'], 'A terminal record without a session is still never owned.' );
		self::assertArrayNotHasKey( 'error_code', $persisted );

		$bound = self::stored( 'bound-queued' );
		self::assertSame( 'queued', $bound['status'] );
		self::assertFalse( $bound['legacy'] );
		self::assertSame( 7, $bound['owner_user_id'] );
		self::assertSame( 'session-bound', $bound['session_id'] );
	}

	public function test_the_migration_is_stored_once_at_the_current_schema(): void {
		BlockQueue::get( 'legacy-queued' );

		$stored = get_option( BlockQueue::OPTION );
		self::assertSame( 3, $stored['schema_version'] );
		self::assertSame( 'failed', $stored['changes']['legacy-queued']['status'] );
		self::assertSame( 'legacy_session_unbound', $stored['changes']['legacy-queued']['error_code'] );
	}

	public function test_a_failed_legacy_record_does_not_block_a_new_change_for_its_post(): void {
		self::assertNull( BlockQueue::pending_for_target( 42 ) );
		self::assertNotNull( BlockQueue::pending_for_target( 99 ), 'A bound open record still blocks its own post.' );

		$result = BlockQueue::enqueue(
			[
				'post_id'               => 42,
				'expected_content_hash' => hash( 'sha256', (string) $GLOBALS['stonewright_test_posts'][42]->post_content ),
				'block_spec'            => self::SPEC,
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'queued', $result['status'] );
	}

	public function test_no_one_can_write_to_a_legacy_record_as_its_owner(): void {
		BlockQueue::get( 'legacy-queued' );
		$before = get_option( BlockQueue::OPTION );
		$html   = '<!-- wp:vendor/card /-->';
		$scope  = [ 'session_id' => 'old-session', 'owner_user_id' => 7, 'post_id' => 42 ];

		$results = [
			'store_serialized'       => BlockQueue::store_serialized( 'legacy-queued', $html, hash( 'sha256', $html ) ),
			'store_serialized scope' => BlockQueue::store_serialized( 'legacy-no-owner', $html, hash( 'sha256', $html ), $scope ),
			'mark_persisted'         => BlockQueue::mark_persisted( 'legacy-serialized' ),
			'reject_serialized'      => BlockQueue::reject_serialized( 'legacy-serialized', 'serialized_markup_refused', 'No.' ),
			'mark_failed'            => BlockQueue::mark_failed( 'legacy-no-owner', 'No.', '', 'x', $scope ),
			'accept_serialized'      => BlockQueue::accept_serialized_result( 'legacy-no-owner', $html, hash( 'sha256', $html ), $scope, 'browser-a', 'result-1' ),
			'accept_failed'          => BlockQueue::accept_failed_result( 'legacy-no-owner', 'No.', '', 'x', $scope, 'browser-a', 'result-2' ),
		];

		foreach ( $results as $label => $result ) {
			self::assertInstanceOf( \WP_Error::class, $result, $label );
			self::assertSame( 'stonewright_finalizer_forbidden', $result->get_error_code(), $label );
		}
		self::assertSame( $before, get_option( BlockQueue::OPTION ), 'No refused call changed the stored queue.' );
	}

	public function test_a_legacy_record_is_never_claimed_for_a_browser_lease_or_a_token(): void {
		$scope = [ 'session_id' => 'old-session', 'owner_user_id' => 7, 'post_id' => 42 ];

		self::assertSame( [], BlockQueue::pending_for_scope( $scope ) );
		self::assertSame( [], BlockQueue::lease_pending_for_scope( $scope, 'browser-a', 45, 1000 ) );
		self::assertSame( 0, BlockQueue::renew_lease_for_scope( $scope, 'browser-a', 45, 1001 ) );

		$issued = BlockQueue::issue_token( 'old-session' );
		self::assertInstanceOf( \WP_Error::class, $issued );
		self::assertSame( 'stonewright_finalizer_forbidden', $issued->get_error_code() );
		self::assertSame( [ 'session-bound' ], array_column( BlockQueue::owned_sessions(), 'session_id' ), 'Only the bound record forms a session.' );
	}

	public function test_a_non_admin_cannot_see_or_cancel_a_legacy_record_but_an_admin_can_clear_it(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true, 'edit_post' => true ];
		$visible = array_column( BlockQueue::list_for_viewer( 42 ), 'id' );
		self::assertSame( [], $visible, 'Legacy records are not the viewer\'s own.' );

		$refused = BlockQueue::cancel( [ 'legacy-queued' ], false, 7 );
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'stonewright_finalizer_not_found', $refused->get_error_code() );
		self::assertNotNull( BlockQueue::get( 'legacy-queued' ) );

		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_posts' => true, 'edit_post' => true, 'manage_options' => true ];
		self::assertContains( 'legacy-queued', array_column( BlockQueue::list_for_viewer( 42 ), 'id' ) );
		$cancelled = BlockQueue::cancel( [ 'legacy-queued' ], false, 7 );
		self::assertIsArray( $cancelled );
		self::assertSame( 1, $cancelled['removed_count'] );
		self::assertNull( BlockQueue::get( 'legacy-queued' ) );
	}
}
