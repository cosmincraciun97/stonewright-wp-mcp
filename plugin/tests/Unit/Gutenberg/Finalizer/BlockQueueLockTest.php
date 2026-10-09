<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg\Finalizer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;

/**
 * Queue mutations run under one bounded mutex: a lease stored in an option with an
 * expiry. A live lease held by someone else makes the call fail with a retryable busy
 * error, an expired one is taken over with a compare-and-delete, and the holder only
 * ever releases its own lease.
 *
 * @covers \Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue
 */
final class BlockQueueLockTest extends TestCase {

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
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		unset( $GLOBALS['stonewright_test_before_option_delete'] );
	}

	/** @return array{token:string,owner_user_id:int,expires_at:int} */
	private static function hold( string $token, int $expires_at ): array {
		$lease = [ 'token' => $token, 'owner_user_id' => 99, 'expires_at' => $expires_at ];
		$GLOBALS['stonewright_test_options'][ BlockQueue::LOCK_OPTION ] = $lease;
		return $lease;
	}

	/** @return array<string, mixed> */
	private static function card_args(): array {
		return [
			'post_id'               => 42,
			'expected_content_hash' => hash( 'sha256', (string) $GLOBALS['stonewright_test_posts'][42]->post_content ),
			'block_spec'            => [ 'name' => 'vendor/card', 'attributes' => [ 'title' => 'Locked' ], 'innerBlocks' => [] ],
		];
	}

	private static function assert_busy( mixed $result, string $label ): void {
		self::assertInstanceOf( \WP_Error::class, $result, $label );
		self::assertSame( 'stonewright_finalizer_busy', $result->get_error_code(), $label );
		$data = $result->get_error_data();
		self::assertSame( 409, $data['status'], $label );
		self::assertTrue( $data['retryable'], $label );
		self::assertGreaterThanOrEqual( 1, $data['retry_after'], $label );
		self::assertLessThanOrEqual( 120, $data['retry_after'], $label );
		self::assertSame( $data['retry_after'], $data['retry_after_seconds'], $label );
	}

	public function test_a_live_lease_held_by_another_makes_every_mutation_busy_and_changes_nothing(): void {
		$queued = BlockQueue::enqueue( self::card_args() );
		self::assertIsArray( $queued );
		$id     = (string) $queued['id'];
		$lease  = self::hold( 'other-holder', time() + 9 );
		$before = get_option( BlockQueue::OPTION );
		$html   = '<!-- wp:vendor/card /-->';

		self::assert_busy( BlockQueue::enqueue( self::card_args() ), 'enqueue' );
		self::assert_busy( BlockQueue::cancel( [ $id ], false, 7 ), 'cancel' );
		self::assert_busy( BlockQueue::store_serialized( $id, $html, hash( 'sha256', $html ) ), 'store_serialized' );
		self::assert_busy( BlockQueue::mark_persisted( $id ), 'mark_persisted' );
		self::assert_busy( BlockQueue::mark_failed( $id, 'No.' ), 'mark_failed' );
		self::assert_busy( BlockQueue::reject_serialized( $id, 'x', 'No.' ), 'reject_serialized' );
		self::assert_busy( BlockQueue::lease_pending_for_scope( [ 'session_id' => (string) $queued['session_id'], 'owner_user_id' => 7, 'post_id' => 42 ], 'browser-a' ), 'lease_pending_for_scope' );

		self::assertSame( $before, get_option( BlockQueue::OPTION ), 'A busy call writes nothing.' );
		self::assertSame( $lease, get_option( BlockQueue::LOCK_OPTION ), 'A failed acquire leaves the holder\'s lease alone.' );
		self::assertSame( 'queued', BlockQueue::get( $id )['status'], 'Reads are not blocked.' );
		self::assertCount( 1, BlockQueue::list() );
	}

	public function test_the_busy_answer_tells_how_long_the_lease_has_left(): void {
		self::hold( 'other-holder', time() + 9 );

		$data = BlockQueue::enqueue( self::card_args() )->get_error_data();

		self::assertGreaterThanOrEqual( 8, $data['retry_after'] );
		self::assertLessThanOrEqual( 9, $data['retry_after'] );
	}

	public function test_an_expired_lease_is_taken_over_and_the_mutation_goes_through(): void {
		self::hold( 'abandoned-holder', time() - 1 );

		$result = BlockQueue::enqueue( self::card_args() );

		self::assertIsArray( $result );
		self::assertSame( 'queued', $result['status'] );
		self::assertArrayNotHasKey( BlockQueue::LOCK_OPTION, $GLOBALS['stonewright_test_options'], 'The takeover lease is released afterwards.' );
	}

	public function test_a_lease_that_is_replaced_while_it_is_taken_over_is_not_removed(): void {
		self::hold( 'abandoned-holder', time() - 1 );
		$newer = [ 'token' => 'newer-holder', 'owner_user_id' => 99, 'expires_at' => time() + 10 ];
		// Another process takes the expired lease first, between the read and the delete.
		$GLOBALS['stonewright_test_before_option_delete'] = static function () use ( $newer ): void {
			$GLOBALS['stonewright_test_options'][ BlockQueue::LOCK_OPTION ] = $newer;
		};

		$result = BlockQueue::enqueue( self::card_args() );

		self::assert_busy( $result, 'enqueue' );
		self::assertSame( $newer, get_option( BlockQueue::LOCK_OPTION ), 'The newer live lease survives.' );
		self::assertSame( [], BlockQueue::list(), 'Nothing was queued.' );
	}

	public function test_the_lease_is_bounded_and_released_after_the_work(): void {
		$seen = null;

		$value = BlockQueue::with_lock(
			static function () use ( &$seen ): string {
				$seen = get_option( BlockQueue::LOCK_OPTION );
				return 'done';
			}
		);

		self::assertSame( 'done', $value );
		self::assertIsArray( $seen );
		self::assertNotSame( '', $seen['token'] );
		self::assertSame( 7, $seen['owner_user_id'] );
		self::assertGreaterThanOrEqual( time() + 14, $seen['expires_at'] );
		self::assertLessThanOrEqual( time() + 15, $seen['expires_at'] );
		self::assertArrayNotHasKey( BlockQueue::LOCK_OPTION, $GLOBALS['stonewright_test_options'] );
	}

	public function test_the_lease_is_released_when_the_work_throws(): void {
		try {
			BlockQueue::with_lock(
				static function (): void {
					throw new \RuntimeException( 'work failed' );
				}
			);
			self::fail( 'The exception must reach the caller.' );
		} catch ( \RuntimeException $caught ) {
			self::assertSame( 'work failed', $caught->getMessage() );
		}

		self::assertArrayNotHasKey( BlockQueue::LOCK_OPTION, $GLOBALS['stonewright_test_options'] );
		self::assertIsArray( BlockQueue::enqueue( self::card_args() ), 'The next mutation is not blocked by the failed one.' );
	}

	public function test_a_nested_call_reuses_the_held_lease_instead_of_waiting_for_itself(): void {
		$inner = BlockQueue::with_lock(
			static fn (): mixed => BlockQueue::with_lock( static fn (): string => 'inner' )
		);

		self::assertSame( 'inner', $inner );
		self::assertArrayNotHasKey( BlockQueue::LOCK_OPTION, $GLOBALS['stonewright_test_options'] );
	}

	public function test_a_holder_whose_lease_was_taken_over_releases_only_its_own_lease(): void {
		$newer = [ 'token' => 'newer-holder', 'owner_user_id' => 99, 'expires_at' => time() + 10 ];

		BlockQueue::with_lock(
			static function () use ( $newer ): void {
				// The lease ran out and another process took the lock while this work was still running.
				$GLOBALS['stonewright_test_options'][ BlockQueue::LOCK_OPTION ] = $newer;
			}
		);

		self::assertSame( $newer, get_option( BlockQueue::LOCK_OPTION ) );
	}
}
