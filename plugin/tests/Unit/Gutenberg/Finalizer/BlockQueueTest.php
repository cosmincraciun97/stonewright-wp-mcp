<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg\Finalizer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;

/**
 * @covers \Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue
 */
final class BlockQueueTest extends TestCase {

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
		unset( $GLOBALS['stonewright_test_filters'], $GLOBALS['stonewright_test_queue_writes'], $GLOBALS['stonewright_test_user_can_callback'] );
	}

	public function test_queues_third_party_spec_as_name_attributes_inner_blocks_not_html(): void {
		$result = BlockQueue::enqueue(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'block_spec'            => [
					'name'        => 'vendor/card',
					'attributes'  => [ 'title' => 'Stone' ],
					'innerBlocks' => [],
					'innerHTML'   => '<div class="card">should not be stored</div>',
				],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'queued', $result['status'] );
		self::assertNotSame( '', $result['id'] );

		$stored = BlockQueue::get( (string) $result['id'] );
		self::assertIsArray( $stored );
		self::assertSame(
			[
				'name'        => 'vendor/card',
				'attributes'  => [ 'title' => 'Stone' ],
				'innerBlocks' => [],
			],
			$stored['block_spec']
		);
		self::assertArrayNotHasKey( 'innerHTML', $stored['block_spec'] );
		self::assertStringNotContainsString( '<!-- wp:', wp_json_encode( $stored['block_spec'] ) );
		self::assertStringNotContainsString( '<div class="card">', wp_json_encode( $stored['block_spec'] ) );
	}

	public function test_refuses_a_second_non_terminal_change_for_the_same_target(): void {
		$first = BlockQueue::enqueue(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'block_spec'            => [
					'name'        => 'vendor/card',
					'attributes'  => [],
					'innerBlocks' => [],
				],
			]
		);
		self::assertIsArray( $first );

		$second = BlockQueue::enqueue(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'block_spec'            => [
					'name'        => 'vendor/hero',
					'attributes'  => [],
					'innerBlocks' => [],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $second );
		self::assertSame( 'stonewright_finalizer_pending_change', $second->get_error_code() );
		self::assertSame( (string) $first['id'], (string) ( $second->get_error_data()['change_id'] ?? '' ) );
		self::assertStringContainsString( 'Finalize or cancel it first', $second->get_error_message() );
	}

	public function test_content_hash_conflict_when_the_post_changed_underneath(): void {
		$result = BlockQueue::enqueue(
			[
				'post_id'               => 42,
				'expected_content_hash' => hash( 'sha256', 'stale' ),
				'block_spec'            => [
					'name'        => 'vendor/card',
					'attributes'  => [],
					'innerBlocks' => [],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_content_conflict', $result->get_error_code() );
		self::assertSame( 409, (int) ( $result->get_error_data()['status'] ?? 0 ) );
	}

	public function test_list_omits_full_block_spec(): void {
		$queued = BlockQueue::enqueue(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'block_spec'            => [
					'name'        => 'vendor/card',
					'attributes'  => [ 'secret_copy' => 'do-not-list' ],
					'innerBlocks' => [],
				],
			]
		);
		self::assertIsArray( $queued );

		$list = BlockQueue::list();
		self::assertNotEmpty( $list );
		$encoded = (string) wp_json_encode( $list );
		self::assertStringNotContainsString( 'do-not-list', $encoded );
		self::assertArrayNotHasKey( 'block_spec', $list[0] );
		self::assertSame( 'vendor/card', $list[0]['block_name'] );
		self::assertSame( 'queued', $list[0]['status'] );
		self::assertSame( 42, $list[0]['post_id'] );
	}

	public function test_omitted_inner_blocks_are_stored_as_absent_not_empty_array(): void {
		$result = BlockQueue::enqueue(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'action'                => 'update',
				'path'                  => [ 0, 1 ],
				'block_spec'            => [
					'name'       => 'vendor/card',
					'attributes' => [ 'title' => 'No children supplied' ],
				],
			]
		);

		self::assertIsArray( $result );
		$stored = BlockQueue::get( (string) $result['id'] );
		self::assertIsArray( $stored );
		self::assertArrayNotHasKey( 'innerBlocks', $stored['block_spec'] );
		self::assertSame( 'update', $stored['action'] );
		self::assertSame( [ 0, 1 ], $stored['path'] );
	}

	public function test_enqueue_receipt_includes_shared_session_owner_and_post(): void {
		$batch = BlockQueue::enqueue_many(
			[
				[
					'post_id'               => 42,
					'expected_content_hash' => $this->current_hash(),
					'block_spec'            => [
						'name'        => 'vendor/card',
						'attributes'  => [ 'title' => 'One' ],
						'innerBlocks' => [],
					],
				],
				[
					'post_id'               => 42,
					'expected_content_hash' => $this->current_hash(),
					'block_spec'            => [
						'name'        => 'vendor/hero',
						'attributes'  => [ 'title' => 'Two' ],
						'innerBlocks' => [],
					],
				],
			]
		);

		self::assertIsArray( $batch );
		self::assertCount( 2, $batch );
		self::assertNotSame( '', (string) ( $batch[0]['session_id'] ?? '' ) );
		self::assertSame( $batch[0]['session_id'], $batch[1]['session_id'] );
		self::assertArrayNotHasKey( 'block_spec', $batch[0] );

		$first  = BlockQueue::get( (string) $batch[0]['id'] );
		$second = BlockQueue::get( (string) $batch[1]['id'] );
		self::assertIsArray( $first );
		self::assertIsArray( $second );
		self::assertSame( $first['session_id'], $second['session_id'] );
		self::assertSame( 7, (int) $first['owner_user_id'] );
		self::assertSame( 7, (int) $second['owner_user_id'] );
		self::assertSame( 42, (int) $first['post_id'] );
		self::assertSame( 42, (int) $second['post_id'] );
		self::assertSame( 2, (int) ( get_option( BlockQueue::OPTION )['schema_version'] ?? 0 ) );
	}

	public function test_enqueue_refuses_logged_out_owner_zero(): void {
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_user_logged_in']  = false;

		$result = BlockQueue::enqueue(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'block_spec'            => [
					'name'        => 'vendor/card',
					'attributes'  => [],
					'innerBlocks' => [],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		self::assertNull( BlockQueue::pending_for_target( 42 ) );
	}

	public function test_refuses_all_raw_html_without_allow_raw_html(): void {
		$result = BlockQueue::enqueue(
			[
				'post_id'               => 42,
				'expected_content_hash' => $this->current_hash(),
				'block_spec'            => '<!-- wp:paragraph --><p>raw</p><!-- /wp:paragraph -->',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_raw_html_refused', $result->get_error_code() );
	}

	public function test_cancel_dry_run_returns_statuses_without_changing_state(): void {
		$queued = $this->enqueue_card( 'Keep me' );
		self::assertIsArray( $queued );
		$before = get_option( BlockQueue::OPTION );

		$result = BlockQueue::cancel( [ (string) $queued['id'] ], true, 7 );
		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( 0, (int) $result['removed_count'] );
		self::assertSame( [ (string) $queued['id'] ], $result['change_ids'] );
		self::assertSame( [ 'queued' ], $result['previous_statuses'] );
		self::assertSame( [ 42 ], $result['post_ids'] );
		self::assertSame( 'planned', $result['verification_status'] );
		self::assertFalse( $result['effect_verified'] );
		self::assertArrayNotHasKey( 'block_spec', $result );
		self::assertSame( $before, get_option( BlockQueue::OPTION ) );
		self::assertSame( 'queued', BlockQueue::get( (string) $queued['id'] )['status'] ?? '' );
	}

	public function test_cancel_deletes_queued_serialized_and_failed_in_one_save(): void {
		$batch = BlockQueue::enqueue_many(
			[
				$this->card_args( 'Queued' ),
				$this->card_args( 'Serialized' ),
				$this->card_args( 'Failed' ),
			]
		);
		self::assertIsArray( $batch );
		self::assertCount( 3, $batch );

		$html = '<!-- wp:vendor/card /-->';
		self::assertTrue( BlockQueue::store_serialized( (string) $batch[1]['id'], $html, hash( 'sha256', $html ) ) );
		self::assertTrue( BlockQueue::mark_failed( (string) $batch[2]['id'], 'boom' ) );

		$ids = [
			(string) $batch[0]['id'],
			(string) $batch[1]['id'],
			(string) $batch[2]['id'],
		];
		sort( $ids, SORT_STRING );
		$this->start_counting_queue_writes();
		$result = BlockQueue::cancel( array_reverse( $ids ), false, 7 );

		self::assertIsArray( $result );
		self::assertFalse( $result['dry_run'] );
		self::assertSame( 3, (int) $result['removed_count'] );
		self::assertSame( $ids, $result['change_ids'] );
		self::assertSame( 1, (int) ( $GLOBALS['stonewright_test_queue_writes'] ?? 0 ) );
		self::assertTrue( $result['effect_verified'] );
		self::assertSame( 'verified', $result['verification_status'] );
		foreach ( $ids as $id ) {
			self::assertNull( BlockQueue::get( $id ) );
		}
		self::assertNull( BlockQueue::pending_for_target( 42 ) );

		$again = $this->enqueue_card( 'After cancel' );
		self::assertIsArray( $again );
		self::assertNotSame( '', (string) $again['id'] );
		self::assertSame( 'queued', (string) ( $again['status'] ?? '' ) );
	}

	public function test_cancel_refuses_persisted_without_deleting(): void {
		$queued = $this->enqueue_card( 'Applied' );
		self::assertIsArray( $queued );
		$html = '<!-- wp:vendor/card /-->';
		self::assertTrue( BlockQueue::store_serialized( (string) $queued['id'], $html, hash( 'sha256', $html ) ) );
		self::assertTrue( BlockQueue::mark_persisted( (string) $queued['id'] ) );

		$result = BlockQueue::cancel( [ (string) $queued['id'] ], false, 7 );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_finalizer_already_persisted', $result->get_error_code() );
		self::assertSame( 409, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		self::assertNotNull( BlockQueue::get( (string) $queued['id'] ) );
		self::assertSame( 'persisted', BlockQueue::get( (string) $queued['id'] )['status'] ?? '' );
		self::assertStringNotContainsString( 'Applied', (string) wp_json_encode( $result ) );
		self::assertArrayNotHasKey( 'block_spec', (array) $result->get_error_data() );
	}

	public function test_cancel_unknown_id_in_batch_is_generic_404_without_partial_delete(): void {
		$queued = $this->enqueue_card( 'Stay' );
		self::assertIsArray( $queued );
		$before = get_option( BlockQueue::OPTION );

		$result = BlockQueue::cancel( [ (string) $queued['id'], 'missing-change-id' ], false, 7 );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_finalizer_not_found', $result->get_error_code() );
		self::assertSame( 404, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		self::assertArrayNotHasKey( 'change_id', (array) $result->get_error_data() );
		self::assertStringNotContainsString( (string) $queued['id'], (string) wp_json_encode( $result ) );
		self::assertStringNotContainsString( 'missing-change-id', (string) wp_json_encode( $result ) );
		self::assertSame( $before, get_option( BlockQueue::OPTION ) );
		self::assertNotNull( BlockQueue::get( (string) $queued['id'] ) );
	}

	public function test_cancel_foreign_id_for_non_admin_is_generic_404_without_partial_delete(): void {
		$owned = $this->enqueue_card( 'Owner secret' );
		self::assertIsArray( $owned );
		$GLOBALS['stonewright_test_posts'][43] = (object) [
			'ID'           => 43,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Other',
			'post_content' => '<!-- wp:paragraph --><p>Other</p><!-- /wp:paragraph -->',
			'post_excerpt' => '',
			'meta'         => [],
		];
		$GLOBALS['stonewright_test_current_user_id'] = 8;
		$mine = BlockQueue::enqueue(
			[
				'post_id'               => 43,
				'expected_content_hash' => hash( 'sha256', (string) $GLOBALS['stonewright_test_posts'][43]->post_content ),
				'block_spec'            => [
					'name'        => 'vendor/card',
					'attributes'  => [ 'title' => 'Mine' ],
					'innerBlocks' => [],
				],
			]
		);
		self::assertIsArray( $mine );

		$result = BlockQueue::cancel( [ (string) $mine['id'], (string) $owned['id'] ], false, 8 );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_finalizer_not_found', $result->get_error_code() );
		self::assertSame( 404, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		self::assertArrayNotHasKey( 'change_id', (array) $result->get_error_data() );
		$encoded = (string) wp_json_encode( $result );
		self::assertStringNotContainsString( (string) $owned['id'], $encoded );
		self::assertStringNotContainsString( 'Owner secret', $encoded );
		self::assertArrayNotHasKey( 'owner_user_id', (array) $result->get_error_data() );
		self::assertNotNull( BlockQueue::get( (string) $owned['id'] ) );
		self::assertNotNull( BlockQueue::get( (string) $mine['id'] ) );
	}

	public function test_cancel_admin_can_remove_foreign_queued_and_failed(): void {
		$queued = $this->enqueue_card( 'Foreign queued' );
		self::assertIsArray( $queued );
		$GLOBALS['stonewright_test_posts'][43] = (object) [
			'ID'           => 43,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Other',
			'post_content' => '<!-- wp:paragraph --><p>Other</p><!-- /wp:paragraph -->',
			'post_excerpt' => '',
			'meta'         => [],
		];
		$failed = BlockQueue::enqueue(
			[
				'post_id'               => 43,
				'expected_content_hash' => hash( 'sha256', (string) $GLOBALS['stonewright_test_posts'][43]->post_content ),
				'block_spec'            => [
					'name'        => 'vendor/card',
					'attributes'  => [ 'title' => 'Foreign failed' ],
					'innerBlocks' => [],
				],
			]
		);
		self::assertIsArray( $failed );
		self::assertTrue( BlockQueue::mark_failed( (string) $failed['id'], 'nope' ) );

		$GLOBALS['stonewright_test_current_user_id'] = 9;
		$GLOBALS['stonewright_test_user_caps']       = [
			'edit_posts'     => true,
			'edit_post'      => true,
			'manage_options' => true,
		];

		$result = BlockQueue::cancel( [ (string) $queued['id'], (string) $failed['id'] ], false, 9 );
		self::assertIsArray( $result );
		self::assertSame( 2, (int) $result['removed_count'] );
		self::assertNull( BlockQueue::get( (string) $queued['id'] ) );
		self::assertNull( BlockQueue::get( (string) $failed['id'] ) );
		self::assertStringNotContainsString( 'Foreign queued', (string) wp_json_encode( $result ) );
	}

	private function enqueue_card( string $title ): array|\WP_Error {
		return BlockQueue::enqueue( $this->card_args( $title ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function card_args( string $title ): array {
		return [
			'post_id'               => 42,
			'expected_content_hash' => $this->current_hash(),
			'block_spec'            => [
				'name'        => 'vendor/card',
				'attributes'  => [ 'title' => $title ],
				'innerBlocks' => [],
			],
		];
	}

	private function start_counting_queue_writes(): void {
		$GLOBALS['stonewright_test_queue_writes'] = 0;
		add_filter(
			'pre_update_option',
			static function ( mixed $value, mixed $option ): mixed {
				if ( BlockQueue::OPTION === $option ) {
					++$GLOBALS['stonewright_test_queue_writes'];
				}
				return $value;
			}
		);
	}

	private function current_hash(): string {
		return hash( 'sha256', (string) $GLOBALS['stonewright_test_posts'][42]->post_content );
	}
}
