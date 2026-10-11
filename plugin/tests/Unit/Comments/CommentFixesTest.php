<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Comments;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Comments\CommentCreate;
use Stonewright\WpMcp\Abilities\Comments\CommentDelete;
use Stonewright\WpMcp\Abilities\Comments\CommentGet;
use Stonewright\WpMcp\Abilities\Comments\CommentList;
use Stonewright\WpMcp\Abilities\Comments\CommentUpdate;

/**
 * Comments go through core's comment pipeline, are checked against their post, and report the state
 * that was stored, not the state that was asked for.
 *
 * @covers \Stonewright\WpMcp\Abilities\Comments\CommentCreate
 * @covers \Stonewright\WpMcp\Abilities\Comments\CommentUpdate
 * @covers \Stonewright\WpMcp\Abilities\Comments\CommentDelete
 * @covers \Stonewright\WpMcp\Abilities\Comments\CommentList
 * @covers \Stonewright\WpMcp\Abilities\Comments\CommentGet
 * @covers \Stonewright\WpMcp\Abilities\Comments\CommentStatus
 */
final class CommentFixesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_comments']        = [];
		$GLOBALS['stonewright_test_next_comment_id'] = 5001;
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_user_caps']       = [ 'moderate_comments' => true, 'edit_posts' => true, 'edit_post' => true, 'edit_comment' => true ];
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
		$GLOBALS['stonewright_test_posts']           = [
			10 => (object) [ 'ID' => 10, 'post_type' => 'post', 'post_status' => 'publish', 'comment_status' => 'open' ],
			11 => (object) [ 'ID' => 11, 'post_type' => 'post', 'post_status' => 'publish', 'comment_status' => 'closed' ],
			12 => (object) [ 'ID' => 12, 'post_type' => 'post', 'post_status' => 'draft', 'comment_status' => 'open' ],
		];
		$GLOBALS['stonewright_test_wp_new_comment_calls'] = [];
		unset(
			$GLOBALS['stonewright_test_wp_new_comment_return'],
			$GLOBALS['stonewright_test_filters']['pre_comment_content'],
			$GLOBALS['stonewright_test_comment_update_fails'],
			$GLOBALS['stonewright_test_comment_status_fails']
		);
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_comments']        = [];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
		$GLOBALS['stonewright_test_posts']           = [];
		unset(
			$GLOBALS['stonewright_test_wp_new_comment_return'],
			$GLOBALS['stonewright_test_filters']['pre_comment_content'],
			$GLOBALS['stonewright_test_comment_update_fails'],
			$GLOBALS['stonewright_test_comment_status_fails']
		);
	}

	private function comment( int $id, string $approved, int $post = 10, string $content = 'hi' ): void {
		$GLOBALS['stonewright_test_comments'][ $id ] = [
			'comment_ID'       => $id,
			'comment_approved' => $approved,
			'comment_content'  => $content,
			'comment_post_ID'  => $post,
			'comment_author'   => 'A',
			'comment_date'     => '2026-01-01',
		];
	}

	// --- comment-create -----------------------------------------------------------------------------

	public function test_create_runs_the_content_through_the_core_filter_pipeline(): void {
		$GLOBALS['stonewright_test_filters']['pre_comment_content'] = static fn ( string $content ): string => preg_replace( '#<script\b.*?</script>#is', '', $content ) ?? '';

		$result = ( new CommentCreate() )->execute( [ 'post_id' => 10, 'content' => 'Hello <script>alert(1)</script><b>there</b>' ] );

		self::assertIsArray( $result );
		self::assertCount( 1, $GLOBALS['stonewright_test_wp_new_comment_calls'] );
		self::assertSame( 'Hello <b>there</b>', $GLOBALS['stonewright_test_comments'][ $result['id'] ]['comment_content'] );
		self::assertSame( 'approve', $result['status'] );
		self::assertSame( 10, $result['post_id'] );
	}

	public function test_create_hands_core_slashed_data_and_the_current_user(): void {
		$result = ( new CommentCreate() )->execute( [ 'post_id' => 10, 'content' => "it's" ] );

		self::assertIsArray( $result );
		$data = $GLOBALS['stonewright_test_wp_new_comment_calls'][0];
		self::assertSame( "it\\'s", $data['comment_content'] );
		self::assertSame( 10, $data['comment_post_ID'] );
		self::assertArrayHasKey( 'user_id', $data );
		self::assertSame( "it's", $GLOBALS['stonewright_test_comments'][ $result['id'] ]['comment_content'] );
	}

	public function test_create_reports_a_core_refusal(): void {
		$GLOBALS['stonewright_test_wp_new_comment_return'] = new \WP_Error( 'comment_duplicate', 'Duplicate comment detected.' );

		$result = ( new CommentCreate() )->execute( [ 'post_id' => 10, 'content' => 'again' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'comment_duplicate', $result->get_error_code() );
	}

	public function test_create_refuses_a_missing_post_a_draft_and_a_closed_post_for_a_user_who_cannot_moderate(): void {
		$missing = ( new CommentCreate() )->execute( [ 'post_id' => 999, 'content' => 'x' ] );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'stonewright_not_found', $missing->get_error_code() );

		$draft = ( new CommentCreate() )->execute( [ 'post_id' => 12, 'content' => 'x' ] );
		self::assertInstanceOf( \WP_Error::class, $draft );
		self::assertSame( 'stonewright_comment_post_unavailable', $draft->get_error_code() );

		$GLOBALS['stonewright_test_user_caps']['moderate_comments'] = false;
		$closed = ( new CommentCreate() )->execute( [ 'post_id' => 11, 'content' => 'x' ] );
		self::assertInstanceOf( \WP_Error::class, $closed );
		self::assertSame( 'stonewright_comments_closed', $closed->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_wp_new_comment_calls'] );
	}

	public function test_create_on_a_closed_post_is_allowed_for_a_moderator_like_the_admin_reply(): void {
		$result = ( new CommentCreate() )->execute( [ 'post_id' => 11, 'content' => 'staff note' ] );

		self::assertIsArray( $result );
		self::assertSame( 11, $result['post_id'] );
	}

	public function test_create_checks_that_the_parent_belongs_to_the_post(): void {
		$this->comment( 20, '1', 11 );

		$other_post = ( new CommentCreate() )->execute( [ 'post_id' => 10, 'content' => 'reply', 'parent' => 20 ] );
		self::assertInstanceOf( \WP_Error::class, $other_post );
		self::assertSame( 'stonewright_comment_parent_invalid', $other_post->get_error_code() );

		$missing = ( new CommentCreate() )->execute( [ 'post_id' => 10, 'content' => 'reply', 'parent' => 404 ] );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'stonewright_comment_parent_invalid', $missing->get_error_code() );

		$this->comment( 21, '1', 10 );
		$ok = ( new CommentCreate() )->execute( [ 'post_id' => 10, 'content' => 'reply', 'parent' => 21 ] );
		self::assertIsArray( $ok );
		self::assertSame( 21, $ok['parent'] );
	}

	public function test_create_refuses_empty_content(): void {
		$result = ( new CommentCreate() )->execute( [ 'post_id' => 10, 'content' => "  \n" ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_comment_content_required', $result->get_error_code() );
	}

	public function test_create_needs_edit_access_to_the_target_post_not_only_edit_posts(): void {
		$GLOBALS['stonewright_test_user_can_callback'] = static function ( string $cap, mixed ...$args ): bool {
			if ( 'edit_post' === $cap ) {
				return 10 === (int) ( $args[0] ?? 0 );
			}
			return in_array( $cap, [ 'edit_posts', 'moderate_comments' ], true );
		};

		$own = ( new CommentCreate() )->permission_callback( [ 'post_id' => 10, 'content' => 'x' ] );
		self::assertTrue( $own );

		$someone_elses = ( new CommentCreate() )->permission_callback( [ 'post_id' => 11, 'content' => 'x' ] );
		self::assertInstanceOf( \WP_Error::class, $someone_elses );
		self::assertSame( 'stonewright_forbidden', $someone_elses->get_error_code() );

		$missing = ( new CommentCreate() )->permission_callback( [ 'post_id' => 999, 'content' => 'x' ] );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'stonewright_not_found', $missing->get_error_code() );
	}

	// --- comment-update -----------------------------------------------------------------------------

	public function test_update_slashes_the_content_and_reports_the_stored_status(): void {
		$this->comment( 5, '0' );

		$result = ( new CommentUpdate() )->execute( [ 'id' => 5, 'content' => 'It\\\'s a "path\\to"', 'status' => 'approve' ] );

		self::assertIsArray( $result );
		self::assertSame( 'It\\\'s a "path\\to"', $GLOBALS['stonewright_test_comments'][5]['comment_content'] );
		self::assertSame( 'approve', $result['status'] );
	}

	public function test_update_does_not_echo_a_status_that_was_not_stored(): void {
		$this->comment( 5, '0' );
		$GLOBALS['stonewright_test_comment_status_fails'] = true;

		$result = ( new CommentUpdate() )->execute( [ 'id' => 5, 'status' => 'spam' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_comment_update_failed', $result->get_error_code() );
	}

	public function test_update_reports_a_content_write_that_failed(): void {
		$this->comment( 5, '1' );
		$GLOBALS['stonewright_test_comment_update_fails'] = true;

		$result = ( new CommentUpdate() )->execute( [ 'id' => 5, 'content' => 'new' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_comment_update_failed', $result->get_error_code() );
	}

	public function test_update_needs_something_to_change(): void {
		$this->comment( 5, '1' );

		$result = ( new CommentUpdate() )->execute( [ 'id' => 5 ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_comment_nothing_to_update', $result->get_error_code() );
	}

	// --- comment-delete -----------------------------------------------------------------------------

	public function test_delete_says_trashed_for_a_live_comment_and_deleted_for_a_permanent_delete(): void {
		$this->comment( 7, '1' );

		$trashed = ( new CommentDelete() )->execute( [ 'id' => 7 ] );
		self::assertIsArray( $trashed );
		self::assertTrue( $trashed['trashed'] );
		self::assertFalse( $trashed['deleted'] );
		self::assertSame( 'trash', $GLOBALS['stonewright_test_comments'][7]['comment_approved'] );

		$deleted = ( new CommentDelete() )->execute( [ 'id' => 7, 'force' => true ] );
		self::assertIsArray( $deleted );
		self::assertTrue( $deleted['deleted'] );
		self::assertFalse( $deleted['trashed'] );
		self::assertArrayNotHasKey( 7, $GLOBALS['stonewright_test_comments'] );
	}

	public function test_delete_does_not_delete_a_trashed_comment_for_good_without_force(): void {
		$this->comment( 7, 'trash' );
		$this->comment( 8, 'spam' );

		foreach ( [ 7, 8 ] as $id ) {
			$result = ( new CommentDelete() )->execute( [ 'id' => $id ] );
			self::assertInstanceOf( \WP_Error::class, $result, (string) $id );
			self::assertSame( 'stonewright_comment_force_required', $result->get_error_code(), (string) $id );
			self::assertArrayHasKey( $id, $GLOBALS['stonewright_test_comments'] );
		}
	}

	public function test_delete_reports_a_missing_comment(): void {
		$result = ( new CommentDelete() )->execute( [ 'id' => 404 ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_comment_not_found', $result->get_error_code() );
	}

	// --- comment-list and comment-get ---------------------------------------------------------------

	public function test_list_returns_the_real_total_and_normalized_status(): void {
		$this->comment( 1, '1' );
		$this->comment( 2, '0' );
		$this->comment( 3, 'spam' );
		$this->comment( 4, '1', 11 );

		$page = ( new CommentList() )->execute( [ 'per_page' => 2, 'page' => 1 ] );

		self::assertIsArray( $page );
		self::assertCount( 2, $page['items'] );
		self::assertSame( 4, $page['total'] );
		self::assertSame( [ 'approve', 'hold' ], array_column( $page['items'], 'status' ) );

		$filtered = ( new CommentList() )->execute( [ 'post_id' => 10, 'status' => 'approve', 'per_page' => 1 ] );
		self::assertSame( 1, $filtered['total'] );
	}

	public function test_list_documents_the_status_values(): void {
		$status = ( new CommentList() )->input_schema()['properties']['status'];

		self::assertSame( [ 'approve', 'hold', 'spam', 'trash', 'all' ], $status['enum'] );
	}

	public function test_get_reports_the_normalized_status(): void {
		$this->comment( 9, '0' );

		$result = ( new CommentGet() )->execute( [ 'id' => 9 ] );

		self::assertIsArray( $result );
		self::assertSame( 'hold', $result['status'] );
	}
}
