<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg\Finalizer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\GetFinalizerRuntime;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;

/**
 * @covers \Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\GetFinalizerRuntime
 */
final class FinalizerLimitsTest extends TestCase {

	private const USER_ID = 7;
	private const POST_A  = 42;
	private const POST_B  = 43;
	private const MARKER  = 'SPEC_SECRET_MARKER_DO_NOT_ECHO';
	private const HTML_MARKER = 'HTML_SECRET_MARKER_DO_NOT_ECHO';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_current_user_id'] = self::USER_ID;
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_posts' => true, 'edit_post' => true ];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_posts']           = [
			self::POST_A => $this->post( self::POST_A, 'Target A' ),
			self::POST_B => $this->post( self::POST_B, 'Target B' ),
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
	}

	public function test_limit_constants_match_contract(): void {
		self::assertSame( 20, BlockQueue::MAX_BATCH_ITEMS );
		self::assertSame( 20, BlockQueue::MAX_OPEN_PER_USER );
		self::assertSame( 200, BlockQueue::MAX_TOTAL_RECORDS );
		self::assertSame( 131072, BlockQueue::MAX_SPEC_BYTES );
		self::assertSame( 32, BlockQueue::MAX_TREE_DEPTH );
		self::assertSame( 500, BlockQueue::MAX_TREE_NODES );
		self::assertSame( 1048576, BlockQueue::MAX_SERIALIZED_BYTES );
		self::assertSame( 2097152, BlockQueue::MAX_STATE_BYTES );
	}

	public function test_batch_twenty_ok_twenty_one_fails_without_write(): void {
		$before   = $this->queue_option();
		$too_many = BlockQueue::enqueue_many( $this->batch( self::POST_A, 21, self::MARKER ) );
		$this->assert_limit_error( $too_many, 'stonewright_finalizer_batch_limit', 429, self::MARKER );
		self::assertSame( $before, $this->queue_option() );

		$ok = BlockQueue::enqueue_many( $this->batch( self::POST_A, 20 ) );
		self::assertIsArray( $ok );
		self::assertCount( 20, $ok );
		self::assertSame( 0, (int) ( $ok[0]['pruned_count'] ?? -1 ) );
		self::assertCount( 20, (array) ( get_option( BlockQueue::OPTION )['changes'] ?? [] ) );
	}

	public function test_open_per_user_twenty_ok_twenty_first_fails(): void {
		for ( $i = 0; $i < BlockQueue::MAX_OPEN_PER_USER; $i++ ) {
			$post_id = 1000 + $i;
			$this->seed_post( $post_id );
			$queued = $this->enqueue_card( $post_id );
			self::assertIsArray( $queued );
		}
		self::assertSame( BlockQueue::MAX_OPEN_PER_USER, count( (array) ( get_option( BlockQueue::OPTION )['changes'] ?? [] ) ) );

		$before = $this->queue_option();
		$this->seed_post( 2000 );
		$denied = $this->enqueue_card( 2000, self::MARKER );
		$this->assert_limit_error( $denied, 'stonewright_finalizer_open_limit', 429, self::MARKER );
		self::assertSame( $before, $this->queue_option() );
		self::assertSame( BlockQueue::MAX_OPEN_PER_USER, count( (array) ( get_option( BlockQueue::OPTION )['changes'] ?? [] ) ) );
	}

	public function test_total_records_two_hundred_ok_two_hundred_one_fails(): void {
		for ( $i = 0; $i < BlockQueue::MAX_TOTAL_RECORDS - 1; $i++ ) {
			$this->seed_record( 'total-' . $i, self::POST_A, 'persisted', time() );
		}

		$ok = $this->enqueue_card( self::POST_A );
		self::assertIsArray( $ok );
		self::assertCount( BlockQueue::MAX_TOTAL_RECORDS, (array) ( get_option( BlockQueue::OPTION )['changes'] ?? [] ) );

		$before = $this->queue_option();
		$denied = $this->enqueue_card( self::POST_B, self::MARKER );
		$this->assert_limit_error( $denied, 'stonewright_finalizer_total_limit', 429, self::MARKER );
		self::assertSame( $before, $this->queue_option() );
		self::assertCount( BlockQueue::MAX_TOTAL_RECORDS, (array) ( get_option( BlockQueue::OPTION )['changes'] ?? [] ) );
	}

	public function test_spec_bytes_boundary_vs_plus_one(): void {
		$at_limit = $this->spec_with_json_bytes( BlockQueue::MAX_SPEC_BYTES, self::MARKER );
		self::assertSame( BlockQueue::MAX_SPEC_BYTES, strlen( (string) wp_json_encode( $at_limit ) ) );
		$ok = BlockQueue::enqueue( $this->args( self::POST_A, '', $at_limit ) );
		self::assertIsArray( $ok );

		$over = $this->spec_with_json_bytes( BlockQueue::MAX_SPEC_BYTES + 1, self::MARKER );
		self::assertSame( BlockQueue::MAX_SPEC_BYTES + 1, strlen( (string) wp_json_encode( $over ) ) );
		$before = $this->queue_option();
		$denied = BlockQueue::enqueue( $this->args( self::POST_B, '', $over ) );
		$this->assert_limit_error( $denied, 'stonewright_finalizer_spec_too_large', 413, self::MARKER );
		self::assertSame( $before, $this->queue_option() );
	}

	public function test_spec_byte_limit_is_enforced_before_raw_html_gate(): void {
		$over = $this->spec_with_json_bytes( BlockQueue::MAX_SPEC_BYTES + 1, self::MARKER );
		$over['name'] = 'core/html';
		$over['attributes']['content'] = '<p>raw</p>';
		$over = $this->spec_with_json_bytes( BlockQueue::MAX_SPEC_BYTES + 1, self::MARKER, $over );
		self::assertSame( BlockQueue::MAX_SPEC_BYTES + 1, strlen( (string) wp_json_encode( $over ) ) );

		$denied = BlockQueue::enqueue( $this->args( self::POST_A, '', $over ) );
		$this->assert_limit_error( $denied, 'stonewright_finalizer_spec_too_large', 413, self::MARKER );
		self::assertNotSame( 'stonewright_raw_html_refused', $denied instanceof \WP_Error ? $denied->get_error_code() : '' );
		self::assertArrayNotHasKey( BlockQueue::OPTION, $GLOBALS['stonewright_test_options'] );
	}

	public function test_tree_depth_thirty_two_ok_thirty_three_fails(): void {
		$ok = BlockQueue::enqueue( $this->args( self::POST_A, '', $this->nested_spec( BlockQueue::MAX_TREE_DEPTH ) ) );
		self::assertIsArray( $ok );

		$before = $this->queue_option();
		$denied = BlockQueue::enqueue( $this->args( self::POST_B, self::MARKER, $this->nested_spec( BlockQueue::MAX_TREE_DEPTH + 1, self::MARKER ) ) );
		$this->assert_limit_error( $denied, 'stonewright_finalizer_tree_too_deep', 413, self::MARKER );
		self::assertSame( $before, $this->queue_option() );
	}

	public function test_tree_nodes_five_hundred_ok_five_hundred_one_fails(): void {
		$ok = BlockQueue::enqueue( $this->args( self::POST_A, '', $this->wide_spec( BlockQueue::MAX_TREE_NODES ) ) );
		self::assertIsArray( $ok );

		$before = $this->queue_option();
		$denied = BlockQueue::enqueue( $this->args( self::POST_B, self::MARKER, $this->wide_spec( BlockQueue::MAX_TREE_NODES + 1, self::MARKER ) ) );
		$this->assert_limit_error( $denied, 'stonewright_finalizer_tree_too_large', 413, self::MARKER );
		self::assertSame( $before, $this->queue_option() );
	}

	public function test_serialized_html_boundary_vs_plus_one(): void {
		$queued = $this->enqueue_card( self::POST_A );
		self::assertIsArray( $queued );

		$html = str_repeat( 'a', BlockQueue::MAX_SERIALIZED_BYTES );
		$ok   = BlockQueue::store_serialized( (string) $queued['id'], $html, hash( 'sha256', $html ) );
		self::assertTrue( $ok );
		self::assertSame( 'serialized', BlockQueue::get( (string) $queued['id'] )['status'] ?? '' );

		$queued_over = $this->enqueue_card( self::POST_B );
		self::assertIsArray( $queued_over );
		$over   = self::HTML_MARKER . str_repeat( 'b', BlockQueue::MAX_SERIALIZED_BYTES + 1 - strlen( self::HTML_MARKER ) );
		$before = $this->queue_option();
		$denied = BlockQueue::store_serialized( (string) $queued_over['id'], $over, hash( 'sha256', $over ) );
		$this->assert_limit_error( $denied, 'stonewright_finalizer_html_too_large', 413, self::HTML_MARKER );
		self::assertSame( $before, $this->queue_option() );
		$fresh = BlockQueue::get( (string) $queued_over['id'] );
		self::assertIsArray( $fresh );
		self::assertSame( 'queued', $fresh['status'] );
		self::assertSame( '', (string) ( $fresh['serialized_html'] ?? '' ) );
	}

	public function test_mark_failed_drops_oversized_html_and_still_fails_the_record(): void {
		$queued = $this->enqueue_card( self::POST_A );
		self::assertIsArray( $queued );
		$over = self::HTML_MARKER . str_repeat( 'b', BlockQueue::MAX_SERIALIZED_BYTES + 1 - strlen( self::HTML_MARKER ) );
		self::assertSame( BlockQueue::MAX_SERIALIZED_BYTES + 1, strlen( $over ) );

		$result = BlockQueue::mark_failed(
			(string) $queued['id'],
			'serialize_roundtrip_failed',
			$over,
			'serialize_roundtrip_failed'
		);

		$fresh = BlockQueue::get( (string) $queued['id'] );
		self::assertIsArray( $fresh );
		self::assertSame( 'failed', $fresh['status'] ?? '' );
		$stored_html = (string) ( $fresh['serialized_html'] ?? '' );
		self::assertSame( '', $stored_html );
		self::assertStringNotContainsString( self::HTML_MARKER, $stored_html );

		$option = $this->queue_option();
		self::assertStringNotContainsString( self::HTML_MARKER, (string) wp_json_encode( $option ) );
		self::assertStringNotContainsString( self::HTML_MARKER, (string) maybe_serialize( $option ) );

		$payload = $result instanceof \WP_Error
			? [
				$result->get_error_code(),
				$result->get_error_message(),
				$result->get_error_data(),
			]
			: $result;
		$encoded = (string) wp_json_encode(
			[
				$payload,
				$fresh,
				BlockQueue::compact( $fresh ),
			]
		);
		self::assertStringNotContainsString( self::HTML_MARKER, $encoded );
		self::assertStringNotContainsString( self::HTML_MARKER, (string) ( $fresh['error'] ?? '' ) );
	}

	public function test_state_bytes_overflow_does_not_write(): void {
		$this->seed_record(
			'huge-state',
			self::POST_B,
			'serialized',
			time(),
			[ 'serialized_html' => str_repeat( 's', BlockQueue::MAX_STATE_BYTES ) ]
		);
		$before = $this->queue_option();
		self::assertGreaterThan( BlockQueue::MAX_STATE_BYTES, strlen( (string) maybe_serialize( $before ) ) );

		$denied = $this->enqueue_card( self::POST_A, self::MARKER );
		$this->assert_limit_error( $denied, 'stonewright_finalizer_state_too_large', 413, self::MARKER );
		self::assertSame( $before, $this->queue_option() );
		self::assertNull( BlockQueue::pending_for_target( self::POST_A ) );
	}

	public function test_enqueue_prunes_stale_terminal_records_and_reports_count(): void {
		$now = time();
		$this->seed_record( 'old-persisted', self::POST_B, 'persisted', $now - DAY_IN_SECONDS - 60 );
		$this->seed_record( 'old-cancelled', self::POST_B, 'cancelled', $now - DAY_IN_SECONDS - 60 );
		$this->seed_record( 'old-failed', self::POST_B, 'failed', $now - ( 7 * DAY_IN_SECONDS ) - 60 );
		$this->seed_record( 'fresh-persisted', self::POST_B, 'persisted', $now - 3600 );
		$this->seed_record( 'fresh-cancelled', self::POST_B, 'cancelled', $now - 3600 );
		$this->seed_record( 'fresh-failed', self::POST_B, 'failed', $now - ( 6 * DAY_IN_SECONDS ) );
		$this->seed_record( 'old-queued', self::POST_B, 'queued', $now - ( 30 * DAY_IN_SECONDS ) );
		$this->seed_record( 'old-serialized', self::POST_B, 'serialized', $now - ( 30 * DAY_IN_SECONDS ) );

		$queued = $this->enqueue_card( self::POST_A );
		self::assertIsArray( $queued );
		self::assertSame( 3, (int) ( $queued['pruned_count'] ?? -1 ) );

		$changes = (array) ( get_option( BlockQueue::OPTION )['changes'] ?? [] );
		self::assertArrayNotHasKey( 'old-persisted', $changes );
		self::assertArrayNotHasKey( 'old-cancelled', $changes );
		self::assertArrayNotHasKey( 'old-failed', $changes );
		self::assertArrayHasKey( 'fresh-persisted', $changes );
		self::assertArrayHasKey( 'fresh-cancelled', $changes );
		self::assertArrayHasKey( 'fresh-failed', $changes );
		self::assertArrayHasKey( 'old-queued', $changes );
		self::assertArrayHasKey( 'old-serialized', $changes );
		self::assertArrayHasKey( (string) $queued['id'], $changes );
		self::assertSame( 'queued', $changes['old-queued']['status'] ?? '' );
		self::assertSame( 'serialized', $changes['old-serialized']['status'] ?? '' );

		$audit = (string) wp_json_encode( $GLOBALS['stonewright_test_wpdb_inserts'] );
		self::assertStringContainsString( 'pruned_count', $audit );
		self::assertStringNotContainsString( self::MARKER, $audit );
	}

	public function test_overflow_errors_do_not_contain_full_spec_or_html(): void {
		$over_spec = $this->spec_with_json_bytes( BlockQueue::MAX_SPEC_BYTES + 1, self::MARKER );
		$denied    = BlockQueue::enqueue( $this->args( self::POST_A, '', $over_spec ) );
		$this->assert_limit_error( $denied, 'stonewright_finalizer_spec_too_large', 413, self::MARKER );
		self::assertStringNotContainsString( (string) wp_json_encode( $over_spec ), (string) wp_json_encode( $denied instanceof \WP_Error ? [ $denied->get_error_message(), $denied->get_error_data() ] : [] ) );

		$queued = $this->enqueue_card( self::POST_A );
		self::assertIsArray( $queued );
		$html   = self::HTML_MARKER . str_repeat( 'z', BlockQueue::MAX_SERIALIZED_BYTES );
		$denied = BlockQueue::store_serialized( (string) $queued['id'], $html, hash( 'sha256', $html ) );
		$this->assert_limit_error( $denied, 'stonewright_finalizer_html_too_large', 413, self::HTML_MARKER );
		self::assertStringNotContainsString( $html, (string) wp_json_encode( $denied instanceof \WP_Error ? [ $denied->get_error_message(), $denied->get_error_data() ] : [] ) );
	}

	public function test_runtime_sessions_omit_dead_persisted_sessions(): void {
		$first = $this->enqueue_card( self::POST_A );
		self::assertIsArray( $first );
		$html = '<!-- wp:vendor/card --><div>A</div><!-- /wp:vendor/card -->';
		self::assertTrue( BlockQueue::store_serialized( (string) $first['id'], $html, hash( 'sha256', $html ) ) );
		BlockQueue::mark_persisted( (string) $first['id'] );

		$second = $this->enqueue_card( self::POST_B );
		self::assertIsArray( $second );

		$runtime = ( new GetFinalizerRuntime() )->execute( [] );
		self::assertIsArray( $runtime );
		self::assertCount( 1, $runtime['sessions'] );
		self::assertSame( (string) $second['session_id'], (string) $runtime['sessions'][0]['session_id'] );
		self::assertGreaterThan( 0, (int) $runtime['sessions'][0]['queued_count'] );
		self::assertSame( (string) $second['session_id'], (string) $runtime['session_id'] );
	}

	/**
	 * @param array<string, mixed>|null $base
	 * @return array<string, mixed>
	 */
	private function spec_with_json_bytes( int $bytes, string $marker, ?array $base = null ): array {
		$spec = $base ?? [
			'name'        => 'vendor/card',
			'attributes'  => [],
			'innerBlocks' => [],
		];
		if ( ! isset( $spec['attributes'] ) || ! is_array( $spec['attributes'] ) ) {
			$spec['attributes'] = [];
		}
		$spec['attributes']['marker'] = $marker;
		$spec['attributes']['pad']    = '';
		$current = strlen( (string) wp_json_encode( $spec ) );
		$spec['attributes']['pad']    = str_repeat( 'a', max( 0, $bytes - $current ) );
		return $spec;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function nested_spec( int $depth, string $marker = '' ): array {
		$spec = [
			'name'        => 'core/group',
			'attributes'  => '' === $marker ? [] : [ 'marker' => $marker ],
			'innerBlocks' => [],
		];
		for ( $i = 1; $i < $depth; $i++ ) {
			$spec = [
				'name'        => 'core/group',
				'attributes'  => [],
				'innerBlocks' => [ $spec ],
			];
		}
		return $spec;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function wide_spec( int $nodes, string $marker = '' ): array {
		$children = [];
		for ( $i = 1; $i < $nodes; $i++ ) {
			$children[] = [
				'name'        => 'core/paragraph',
				'attributes'  => '' === $marker ? [ 'i' => $i ] : [ 'marker' => $marker, 'i' => $i ],
				'innerBlocks' => [],
			];
		}
		return [
			'name'        => 'core/group',
			'attributes'  => [],
			'innerBlocks' => $children,
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function batch( int $post_id, int $count, string $marker = '' ): array {
		$items = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$items[] = $this->args( $post_id, $marker . (string) $i );
		}
		return $items;
	}

	/**
	 * @param array<string, mixed>|null $spec
	 * @return array<string, mixed>
	 */
	private function args( int $post_id, string $title = '', ?array $spec = null ): array {
		return [
			'post_id'               => $post_id,
			'expected_content_hash' => hash( 'sha256', (string) $GLOBALS['stonewright_test_posts'][ $post_id ]->post_content ),
			'block_spec'            => $spec ?? [
				'name'        => 'vendor/card',
				'attributes'  => [ 'title' => '' === $title ? 'Card' : $title ],
				'innerBlocks' => [],
			],
		];
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private function enqueue_card( int $post_id, string $title = 'Card' ): array|\WP_Error {
		return BlockQueue::enqueue( $this->args( $post_id, $title ) );
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function seed_record( string $id, int $post_id, string $status, int $updated_at, array $extra = [] ): void {
		$state = get_option( BlockQueue::OPTION, [] );
		if ( ! is_array( $state ) || ! isset( $state['changes'] ) || ! is_array( $state['changes'] ) ) {
			$state = [
				'schema_version' => 2,
				'changes'        => [],
			];
		}
		$state['changes'][ $id ] = array_merge(
			[
				'id'                    => $id,
				'post_id'               => $post_id,
				'status'                => $status,
				'block_spec'            => [
					'name'        => 'vendor/card',
					'attributes'  => [],
					'innerBlocks' => [],
				],
				'action'                => 'insert',
				'path'                  => [],
				'position'              => null,
				'expected_content_hash' => '',
				'serialized_html'       => '',
				'serialized_html_hash'  => '',
				'session_id'            => 'seed-session-' . $id,
				'owner_user_id'         => self::USER_ID,
				'legacy'                => false,
				'created_at'            => $updated_at,
				'updated_at'            => $updated_at,
				'allow_raw_html'        => false,
			],
			$extra
		);
		update_option( BlockQueue::OPTION, $state, false );
	}

	private function seed_post( int $id ): void {
		$GLOBALS['stonewright_test_posts'][ $id ] = $this->post( $id, 'Target ' . $id );
	}

	private function post( int $id, string $title ): object {
		return (object) [
			'ID'           => $id,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => $title,
			'post_content' => '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->',
			'post_excerpt' => '',
			'meta'         => [],
		];
	}

	private function queue_option(): mixed {
		return $GLOBALS['stonewright_test_options'][ BlockQueue::OPTION ] ?? null;
	}

	private function assert_limit_error( mixed $result, string $code, int $status, string $marker = '' ): void {
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( $code, $result->get_error_code() );
		self::assertSame( $status, (int) ( $result->get_error_data()['status'] ?? 0 ) );
		$encoded = (string) wp_json_encode(
			[
				$result->get_error_code(),
				$result->get_error_message(),
				$result->get_error_data(),
			]
		);
		if ( '' !== $marker ) {
			self::assertStringNotContainsString( $marker, $encoded );
			self::assertStringNotContainsString( $marker, $result->get_error_message() );
		}
	}
}
