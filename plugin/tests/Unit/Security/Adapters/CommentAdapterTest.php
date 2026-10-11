<?php
/**
 * The comment family in the change ledger: fields and status, with a delete as a full image.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Abilities\Comments\CommentCreate;
use Stonewright\WpMcp\Abilities\Comments\CommentDelete;
use Stonewright\WpMcp\Abilities\Comments\CommentUpdate;
use Stonewright\WpMcp\Security\Adapters\CommentAdapter;
use Stonewright\WpMcp\Security\Adapters\OtherFamilies;
use Stonewright\WpMcp\Security\ChangeLedger;

/**
 * @covers \Stonewright\WpMcp\Security\Adapters\CommentAdapter
 */
final class CommentAdapterTest extends OtherFamilyLedgerTestCase {

	private function make_comment( int $id = 5, array $overrides = [] ): void {
		$GLOBALS['stonewright_test_comments'][ $id ] = array_merge(
			[
				'comment_ID'           => $id,
				'comment_post_ID'      => 31,
				'comment_author'       => 'Reader One',
				'comment_author_email' => 'reader@example.test',
				'comment_author_url'   => '',
				'comment_author_IP'    => '203.0.113.9',
				'comment_date'         => '2026-01-02 03:04:05',
				'comment_date_gmt'     => '2026-01-02 03:04:05',
				'comment_content'      => 'First!',
				'comment_karma'        => 0,
				'comment_approved'     => '0',
				'comment_agent'        => 'Synthetic agent',
				'comment_type'         => 'comment',
				'comment_parent'       => 0,
				'user_id'              => 0,
			],
			$overrides
		);
	}

	public function test_a_status_change_is_recorded_and_restored(): void {
		$this->make_comment();

		$result = ( new CommentUpdate() )->execute( [ 'id' => 5, 'status' => 'approve' ] );

		self::assertSame( [ 'id' => 5, 'status' => 'approve' ], $result );
		$row = $this->row_of( 'comment' );
		self::assertSame( [ 'comment', '5', 'stonewright/comment-update', 'verified', true ], [ $row['resource_type'], $row['resource_id'], $row['ability'], $row['status'], $row['restorable'] ] );
		self::assertStringStartsWith( 'Updated comment', $row['summary'] );
		self::assertSame( '0', ChangeLedger::read_image( $row['change_id'], 'before' )['fields']['comment_approved'] );
		self::assertSame( 'approve', ChangeLedger::read_image( $row['change_id'], 'after' )['fields']['comment_approved'] );

		$restore = OtherFamilies::restore( $row['change_id'] );

		self::assertSame( 'succeeded', $restore['status'], (string) $restore['detail'] );
		self::assertSame( '0', $GLOBALS['stonewright_test_comments'][5]['comment_approved'] );
		self::assertSame( 'First!', $GLOBALS['stonewright_test_comments'][5]['comment_content'] );
		self::assertCount( 1, $this->rows_of_kind( 'rollback' ) );
	}

	public function test_a_content_change_is_recorded_and_restored(): void {
		$this->make_comment();

		( new CommentUpdate() )->execute( [ 'id' => 5, 'content' => 'Edited by an agent' ] );

		$row = $this->row_of( 'comment' );
		self::assertSame( 'First!', ChangeLedger::read_image( $row['change_id'], 'before' )['fields']['comment_content'] );
		OtherFamilies::restore( $row['change_id'] );
		self::assertSame( 'First!', $GLOBALS['stonewright_test_comments'][5]['comment_content'] );
	}

	public function test_a_delete_keeps_the_full_image_and_a_restore_brings_the_comment_back(): void {
		$this->make_comment( 5, [ 'comment_approved' => '1', 'comment_parent' => 4 ] );

		$result = ( new CommentDelete() )->execute( [ 'id' => 5, 'force' => true ] );

		self::assertSame( [ 'deleted' => true, 'id' => 5 ], $result );
		self::assertArrayNotHasKey( 5, $GLOBALS['stonewright_test_comments'] );
		$row = $this->row_of( 'comment' );
		self::assertStringStartsWith( 'Deleted comment', $row['summary'] );
		self::assertSame( '', $row['after_ref'] );
		$before = ChangeLedger::read_image( $row['change_id'], 'before' )['fields'];
		self::assertSame( [ 'Reader One', 'reader@example.test', 'First!', 31, 4 ], [ $before['comment_author'], $before['comment_author_email'], $before['comment_content'], $before['comment_post_ID'], $before['comment_parent'] ] );

		$restore = OtherFamilies::restore( $row['change_id'] );

		self::assertSame( 'succeeded', $restore['status'], (string) $restore['detail'] );
		self::assertArrayHasKey( 5001, $GLOBALS['stonewright_test_comments'], 'A recreated comment has a new id.' );
		$new = $GLOBALS['stonewright_test_comments'][5001];
		self::assertSame( [ 'Reader One', 'First!', '1', 31 ], [ $new['comment_author'], $new['comment_content'], $new['comment_approved'], $new['comment_post_ID'] ] );
		$rollback = $this->rows_of_kind( 'rollback' )[0];
		self::assertSame( '5001', $rollback['resource_id'] );
		self::assertStringContainsString( 'new id', implode( ' ', $restore['limits'] ) );
		self::assertStringContainsString( 'new id', $row['summary'], 'The row says what a restore cannot bring back.' );
	}

	public function test_a_comment_moved_to_the_trash_is_a_status_change_and_restores(): void {
		$this->make_comment( 5, [ 'comment_approved' => '1' ] );

		$this->call(
			'stonewright/comment-delete',
			[ 'id' => 5 ],
			static function (): void {
				$GLOBALS['stonewright_test_comments'][5]['comment_approved'] = 'trash';
			},
			[ 'deleted' => true, 'id' => 5 ]
		);

		$row = $this->row_of( 'comment' );
		self::assertNotSame( '', $row['after_ref'] );
		OtherFamilies::restore( $row['change_id'] );
		self::assertSame( '1', $GLOBALS['stonewright_test_comments'][5]['comment_approved'] );
	}

	public function test_a_created_comment_is_recorded_and_its_undo_moves_it_to_the_trash(): void {
		$result = ( new CommentCreate() )->execute( [ 'post_id' => 31, 'content' => 'Written by an agent' ] );

		self::assertSame( [ 'id' => 5001 ], $result );
		$row = $this->row_of( 'comment' );
		self::assertSame( '5001', $row['resource_id'] );
		self::assertStringStartsWith( 'Created comment', $row['summary'] );
		self::assertTrue( $row['restorable'] );
		self::assertSame( '', $row['before_ref'] );

		$undo = OtherFamilies::restore( $row['change_id'] );

		self::assertSame( 'succeeded', $undo['status'], (string) $undo['detail'] );
		self::assertSame( 'trash', $GLOBALS['stonewright_test_comments'][5001]['comment_approved'], 'The comment is trashed, not deleted.' );
		self::assertSame( 'noop', OtherFamilies::restore( $row['change_id'] )['status'] );
	}

	public function test_restore_needs_the_moderation_capability(): void {
		$this->make_comment();
		( new CommentUpdate() )->execute( [ 'id' => 5, 'status' => 'approve' ] );
		$row                                           = $this->row_of( 'comment' );
		$GLOBALS['stonewright_test_user_can_callback'] = static fn ( string $cap ): bool => 'moderate_comments' !== $cap;

		$restore = OtherFamilies::restore( $row['change_id'] );

		self::assertSame( [ 'failed', 'permission_denied' ], [ $restore['status'], $restore['detail'] ] );
		self::assertSame( 'approve', $GLOBALS['stonewright_test_comments'][5]['comment_approved'] );
	}

	public function test_a_comment_with_a_credential_in_it_is_masked_and_not_restorable(): void {
		$this->make_comment( 5, [ 'comment_content' => "see below\napi_key: sk_live_abcd1234efgh5678" ] );

		( new CommentUpdate() )->execute( [ 'id' => 5, 'status' => 'approve' ] );

		$row = $this->row_of( 'comment' );
		self::assertFalse( $row['restorable'] );
		self::assertSame( 'masked_secret', $row['restorable_reason'] );
		self::assertStringNotContainsString( 'sk_live_abcd1234efgh5678', $this->stored_text() );
	}

	public function test_nothing_is_recorded_for_an_unknown_comment_or_a_no_op(): void {
		$this->make_comment();

		( new CommentUpdate() )->execute( [ 'id' => 99, 'status' => 'approve' ] );
		( new CommentUpdate() )->execute( [ 'id' => 5, 'content' => 'First!' ] );

		self::assertSame( [], $this->ledger_rows() );
	}

	public function test_the_image_holds_only_the_comment_fields(): void {
		$this->make_comment();

		$image = CommentAdapter::image( 'comment', '5' );

		self::assertSame( [ 'fields', 'v' ], array_keys( $image ) );
		self::assertSame( 'First!', $image['fields']['comment_content'] );
		self::assertNull( CommentAdapter::image( 'comment', '404' ) );
	}
}
