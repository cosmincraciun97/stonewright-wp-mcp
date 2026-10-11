<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Comments;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Comments\CommentDelete;
use Stonewright\WpMcp\Abilities\Comments\CommentGet;
use Stonewright\WpMcp\Abilities\Comments\CommentList;
use Stonewright\WpMcp\Abilities\Comments\CommentUpdate;

/**
 * Comment reads and writes check the capability for the comment itself (edit_comment, which core maps to
 * the comment's post), not only the generic edit_posts or moderate_comments.
 *
 * @covers \Stonewright\WpMcp\Abilities\Comments\CommentGet
 * @covers \Stonewright\WpMcp\Abilities\Comments\CommentList
 * @covers \Stonewright\WpMcp\Abilities\Comments\CommentUpdate
 * @covers \Stonewright\WpMcp\Abilities\Comments\CommentDelete
 */
final class CommentCapabilityTest extends TestCase {

	/** @var list<int> Comment ids the user may edit. */
	private array $editable = [];

	protected function setUp(): void {
		$this->editable                                 = [ 1 ];
		$GLOBALS['stonewright_test_comments']           = [];
		$GLOBALS['stonewright_test_user_logged_in']     = true;
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
		$GLOBALS['stonewright_test_user_can_callback']  = function ( string $cap, mixed ...$args ): bool {
			if ( 'edit_comment' === $cap ) {
				return in_array( (int) ( $args[0] ?? 0 ), $this->editable, true );
			}
			return in_array( $cap, [ 'edit_posts', 'moderate_comments' ], true );
		};
		foreach ( [ 1, 2, 3 ] as $id ) {
			$GLOBALS['stonewright_test_comments'][ $id ] = [
				'comment_ID'       => $id,
				'comment_approved' => '1',
				'comment_content'  => 'text ' . $id,
				'comment_post_ID'  => 10 + $id,
				'comment_author'   => 'A',
				'comment_date'     => '2026-01-01',
			];
		}
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_comments']          = [];
		$GLOBALS['stonewright_test_user_can_callback'] = null;
	}

	public function test_get_needs_edit_comment_for_that_comment(): void {
		self::assertTrue( ( new CommentGet() )->permission_callback( [ 'id' => 1 ] ) );

		$denied = ( new CommentGet() )->permission_callback( [ 'id' => 2 ] );
		self::assertInstanceOf( \WP_Error::class, $denied );
		self::assertSame( 'stonewright_forbidden', $denied->get_error_code() );

		$missing = ( new CommentGet() )->permission_callback( [ 'id' => 404 ] );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'stonewright_comment_not_found', $missing->get_error_code() );
	}

	public function test_get_execute_does_not_return_a_comment_the_user_cannot_edit(): void {
		$result = ( new CommentGet() )->execute( [ 'id' => 2 ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_forbidden', $result->get_error_code() );
	}

	public function test_update_and_delete_need_edit_comment_as_well_as_moderate_comments(): void {
		foreach ( [ new CommentUpdate(), new CommentDelete() ] as $ability ) {
			self::assertTrue( $ability->permission_callback( [ 'id' => 1 ] ), get_class( $ability ) );

			$denied = $ability->permission_callback( [ 'id' => 2 ] );
			self::assertInstanceOf( \WP_Error::class, $denied, get_class( $ability ) );
			self::assertSame( 'stonewright_forbidden', $denied->get_error_code() );
		}

		$GLOBALS['stonewright_test_user_can_callback'] = static fn ( string $cap ): bool => 'edit_comment' === $cap;
		self::assertFalse( ( new CommentUpdate() )->permission_callback( [ 'id' => 1 ] ) );
		self::assertFalse( ( new CommentDelete() )->permission_callback( [ 'id' => 1 ] ) );
	}

	public function test_update_and_delete_execute_refuse_a_comment_the_user_cannot_edit(): void {
		$update = ( new CommentUpdate() )->execute( [ 'id' => 2, 'status' => 'spam' ] );
		self::assertInstanceOf( \WP_Error::class, $update );
		self::assertSame( 'stonewright_forbidden', $update->get_error_code() );
		self::assertSame( '1', $GLOBALS['stonewright_test_comments'][2]['comment_approved'] );

		$delete = ( new CommentDelete() )->execute( [ 'id' => 3 ] );
		self::assertInstanceOf( \WP_Error::class, $delete );
		self::assertSame( 'stonewright_forbidden', $delete->get_error_code() );
		self::assertSame( '1', $GLOBALS['stonewright_test_comments'][3]['comment_approved'] );
	}

	public function test_list_returns_only_comments_the_user_may_edit_and_counts_only_those(): void {
		$this->editable = [ 1, 3 ];

		$result = ( new CommentList() )->execute( [ 'per_page' => 1, 'page' => 2 ] );

		self::assertIsArray( $result );
		self::assertSame( 2, $result['total'] );
		self::assertSame( [ 3 ], array_column( $result['items'], 'id' ) );
	}

	public function test_list_uses_the_core_count_for_a_user_who_can_edit_every_post(): void {
		$GLOBALS['stonewright_test_user_can_callback'] = static fn ( string $cap ): bool => in_array(
			$cap,
			[ 'edit_posts', 'moderate_comments', 'edit_comment', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts' ],
			true
		);

		$result = ( new CommentList() )->execute( [ 'per_page' => 2 ] );

		self::assertSame( 3, $result['total'] );
		self::assertCount( 2, $result['items'] );
	}

	public function test_list_says_when_it_stopped_scanning_a_large_site(): void {
		$this->add_comments( 2050 );
		$this->editable = [ 1, 2000, 2001 ];

		$result = ( new CommentList() )->execute( [ 'per_page' => 50 ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['truncated'] );
		self::assertStringContainsString( '2000', (string) $result['hint'] );
		self::assertStringContainsString( 'post_id', (string) $result['hint'] );
		// Comment 2001 was never looked at, so it is not in the total.
		self::assertSame( 2, $result['total'] );
		self::assertSame( [ 1, 2000 ], array_column( $result['items'], 'id' ) );
	}

	public function test_list_is_not_truncated_when_the_scan_reaches_the_last_comment(): void {
		$this->add_comments( 2000 );
		$this->editable = [ 1, 2000 ];

		$result = ( new CommentList() )->execute( [ 'per_page' => 50 ] );

		self::assertFalse( $result['truncated'] );
		self::assertArrayNotHasKey( 'hint', $result );
		self::assertSame( 2, $result['total'] );
	}

	public function test_list_is_not_truncated_for_a_small_site_or_a_user_who_can_edit_every_post(): void {
		$small = ( new CommentList() )->execute( [] );
		self::assertFalse( $small['truncated'] );

		$GLOBALS['stonewright_test_user_can_callback'] = static fn ( string $cap ): bool => in_array(
			$cap,
			[ 'edit_posts', 'moderate_comments', 'edit_comment', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts' ],
			true
		);
		$this->add_comments( 2050 );
		$all = ( new CommentList() )->execute( [ 'per_page' => 50 ] );
		self::assertFalse( $all['truncated'] );
		self::assertSame( 2050, $all['total'] );
	}

	private function add_comments( int $count ): void {
		$GLOBALS['stonewright_test_comments'] = [];
		for ( $id = 1; $id <= $count; ++$id ) {
			$GLOBALS['stonewright_test_comments'][ $id ] = [
				'comment_ID'       => $id,
				'comment_approved' => '1',
				'comment_content'  => 'text ' . $id,
				'comment_post_ID'  => 10,
				'comment_author'   => 'A',
				'comment_date'     => '2026-01-01',
			];
		}
	}
}
