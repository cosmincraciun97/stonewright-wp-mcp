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

	private function current_hash(): string {
		return hash( 'sha256', (string) $GLOBALS['stonewright_test_posts'][42]->post_content );
	}
}
