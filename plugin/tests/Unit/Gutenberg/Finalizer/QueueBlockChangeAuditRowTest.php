<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg\Finalizer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Gutenberg\QueueBlockChange;
use Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * One call of the queue-block-change ability writes one audit row, named after that
 * ability, even when the enqueue sweeps stale queue entries out on the way.
 *
 * The sweep is queue maintenance, not a second change to the block being queued, so
 * it keeps its own event name. It must stay visible with its count whoever enqueues.
 *
 * @covers \Stonewright\WpMcp\Abilities\Gutenberg\QueueBlockChange
 * @covers \Stonewright\WpMcp\Gutenberg\Finalizer\BlockQueue
 */
final class QueueBlockChangeAuditRowTest extends TestCase {

	private const ABILITY  = 'stonewright/blocks-queue-change';
	private const PRUNE    = 'gutenberg.queue_prune';
	private const USER_ID  = 7;
	private const POST_ID  = 42;
	private const OLD_POST = 43;
	private const MARKER   = 'SPEC_SECRET_MARKER_DO_NOT_ECHO';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']           = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_current_user_id']   = self::USER_ID;
		$GLOBALS['stonewright_test_user_logged_in']    = true;
		$GLOBALS['stonewright_test_user_caps']         = [ 'edit_posts' => true, 'edit_post' => true ];
		$GLOBALS['stonewright_test_wpdb_inserts']      = [];
		$GLOBALS['stonewright_test_posts']             = [
			self::POST_ID  => $this->post( self::POST_ID ),
			self::OLD_POST => $this->post( self::OLD_POST ),
		];
		$GLOBALS['stonewright_test_registered_blocks'] = [
			'vendor/card' => (object) [
				'attributes' => [ 'title' => [ 'type' => 'string' ] ],
			],
		];
		AuditLog::reset_request_state();
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		unset( $GLOBALS['stonewright_test_registered_blocks'] );
		AuditLog::reset_request_state();
		IncidentStore::reset_for_tests();
	}

	public function test_a_call_that_prunes_stale_entries_writes_exactly_one_row_named_after_the_ability(): void {
		$this->seed_stale_entries();

		$result = ( new QueueBlockChange() )->execute( $this->args() );

		self::assertIsArray( $result );
		self::assertTrue( $result['queued'] );
		$rows = $this->rows_named( self::ABILITY );
		self::assertCount( 1, $rows );
		self::assertSame( 'ok', $rows[0]['result_status'] );
		$recorded = $this->recorded_args( $rows[0] );
		self::assertSame( self::POST_ID, $recorded['post_id'] ?? null, 'The one row is the call row, which records the call input.' );
		self::assertArrayHasKey( '_meta', $recorded );
	}

	public function test_the_prune_stays_visible_as_a_maintenance_event_with_its_count(): void {
		$this->seed_stale_entries();

		( new QueueBlockChange() )->execute( $this->args() );

		$prunes = $this->rows_named( self::PRUNE );
		self::assertCount( 1, $prunes );
		self::assertSame( 'ok', $prunes[0]['result_status'] );
		$recorded = $this->recorded_args( $prunes[0] );
		self::assertSame( 3, $recorded['pruned_count'] ?? null );
		self::assertSame( self::POST_ID, $recorded['post_id'] ?? null );
		self::assertStringNotContainsString( self::MARKER, (string) $prunes[0]['sanitized_args'] );
	}

	public function test_a_call_that_prunes_nothing_writes_no_maintenance_event(): void {
		$this->seed_entry( 'fresh-persisted', 'persisted', time() - 3600 );

		$result = ( new QueueBlockChange() )->execute( $this->args() );

		self::assertIsArray( $result );
		self::assertCount( 1, $this->rows_named( self::ABILITY ) );
		self::assertSame( [], $this->rows_named( self::PRUNE ) );
	}

	public function test_a_plain_enqueue_reports_its_prune_under_the_maintenance_name_only(): void {
		$this->seed_stale_entries();

		$queued = BlockQueue::enqueue( $this->args() );

		self::assertIsArray( $queued );
		self::assertSame( 3, $queued['pruned_count'] );
		self::assertSame( [], $this->rows_named( self::ABILITY ), 'Only an ability call writes a row named after the ability.' );
		self::assertCount( 1, $this->rows_named( self::PRUNE ) );
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	/** Three stale terminal entries the next enqueue removes, plus entries it must keep. */
	private function seed_stale_entries(): void {
		$now = time();
		$this->seed_entry( 'old-persisted', 'persisted', $now - DAY_IN_SECONDS - 60 );
		$this->seed_entry( 'old-cancelled', 'cancelled', $now - DAY_IN_SECONDS - 60 );
		$this->seed_entry( 'old-failed', 'failed', $now - ( 7 * DAY_IN_SECONDS ) - 60 );
		$this->seed_entry( 'fresh-persisted', 'persisted', $now - 3600 );
		$this->seed_entry( 'old-queued', 'queued', $now - ( 30 * DAY_IN_SECONDS ) );
	}

	private function seed_entry( string $id, string $status, int $updated_at ): void {
		$state = get_option( BlockQueue::OPTION, [] );
		if ( ! is_array( $state ) || ! isset( $state['changes'] ) || ! is_array( $state['changes'] ) ) {
			$state = [
				'schema_version' => 2,
				'changes'        => [],
			];
		}
		$state['changes'][ $id ] = [
			'id'                    => $id,
			'post_id'               => self::OLD_POST,
			'status'                => $status,
			'block_spec'            => [
				'name'        => 'vendor/card',
				'attributes'  => [ 'title' => self::MARKER ],
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
		];
		update_option( BlockQueue::OPTION, $state, false );
	}

	/** @return array<string, mixed> */
	private function args(): array {
		return [
			'post_id'               => self::POST_ID,
			'expected_content_hash' => hash( 'sha256', (string) $GLOBALS['stonewright_test_posts'][ self::POST_ID ]->post_content ),
			'block_spec'            => [
				'name'        => 'vendor/card',
				'attributes'  => [ 'title' => 'Card' ],
				'innerBlocks' => [],
			],
		];
	}

	private function post( int $id ): object {
		return (object) [
			'ID'           => $id,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Target ' . $id,
			'post_content' => '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph -->',
			'post_excerpt' => '',
			'meta'         => [],
		];
	}

	/**
	 * Audit-log rows the wpdb double captured under one event name.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows_named( string $event ): array {
		$rows = [];
		foreach ( $GLOBALS['stonewright_test_wpdb_inserts'] as $insert ) {
			if ( str_contains( (string) $insert['table'], 'stonewright_audit_log' ) && $event === ( $insert['data']['ability_name'] ?? '' ) ) {
				$rows[] = $insert['data'];
			}
		}
		return $rows;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function recorded_args( array $row ): array {
		$decoded = json_decode( (string) ( $row['sanitized_args'] ?? '' ), true );
		return is_array( $decoded ) ? $decoded : [];
	}
}
