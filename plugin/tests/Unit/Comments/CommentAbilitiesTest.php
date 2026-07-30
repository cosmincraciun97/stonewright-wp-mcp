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
 * @covers \Stonewright\WpMcp\Abilities\Comments\CommentList
 * @covers \Stonewright\WpMcp\Abilities\Comments\CommentUpdate
 * @covers \Stonewright\WpMcp\Abilities\Comments\CommentDelete
 */
final class CommentAbilitiesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_comments']         = [];
		$GLOBALS['stonewright_test_next_comment_id']  = 5001;
		$GLOBALS['stonewright_test_user_caps']        = [
			'moderate_comments' => true,
			'edit_posts'        => true,
		];
		$GLOBALS['stonewright_test_user_logged_in']   = true;
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
	}

	public function test_ability_names(): void {
		$this->assertSame( 'stonewright/comment-list', ( new CommentList() )->name() );
		$this->assertSame( 'stonewright/comment-get', ( new CommentGet() )->name() );
		$this->assertSame( 'stonewright/comment-create', ( new CommentCreate() )->name() );
		$this->assertSame( 'stonewright/comment-update', ( new CommentUpdate() )->name() );
		$this->assertSame( 'stonewright/comment-delete', ( new CommentDelete() )->name() );
	}

	public function test_writes_deny_without_moderate_comments(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		foreach ( [ new CommentUpdate(), new CommentDelete() ] as $a ) {
			$this->assertFalse( $a->permission_callback( [ 'id' => 1 ] ), get_class( $a ) );
		}
	}

	public function test_update_moderates_status(): void {
		$GLOBALS['stonewright_test_comments'][5] = [
			'comment_ID'       => 5,
			'comment_approved' => '0',
			'comment_content'  => 'hi',
			'comment_post_ID'  => 1,
			'comment_author'   => 'A',
			'comment_date'     => '2026-01-01',
		];
		$result = ( new CommentUpdate() )->execute( [ 'id' => 5, 'status' => 'approve' ] );
		$this->assertIsArray( $result );
		$this->assertSame( 'approve', $result['status'] );
	}

	public function test_delete_requires_token_in_production_safe(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$result = ( new CommentDelete() )->execute( [ 'id' => 5, 'force' => true ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
	}
}
