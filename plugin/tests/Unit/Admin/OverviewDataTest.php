<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\OverviewData;

/**
 * @covers \Stonewright\WpMcp\Admin\OverviewData
 */
final class OverviewDataTest extends TestCase {

	/** @param array<string, mixed> $override @return array<string, mixed> */
	private function facts( array $override = [] ): array {
		return array_merge(
			[
				'enabled'            => true,
				'sign_in_ready'      => true,
				'client_count'       => 1,
				'password_count'     => 0,
				'connection_seen'    => true,
				'audit_incidents'    => 0,
				'rescue_open'        => 0,
				'rescue_unconfirmed' => 0,
				'queue_queued'       => 0,
				'queue_failed'       => 0,
			],
			$override
		);
	}

	public function test_a_healthy_site_has_nothing_that_needs_attention(): void {
		self::assertSame( [], OverviewData::attention( $this->facts() ) );
	}

	public function test_items_come_most_urgent_first_and_each_has_one_action(): void {
		$items = OverviewData::attention(
			$this->facts(
				[
					'audit_incidents'    => 4,
					'rescue_open'        => 1,
					'rescue_unconfirmed' => 2,
					'queue_queued'       => 3,
					'queue_failed'       => 1,
					'connection_seen'    => false,
				]
			)
		);

		self::assertSame( [ 'rescue-open', 'rescue-unconfirmed', 'audit-incidents', 'queue-failed', 'queue-queued', 'connection' ], array_column( $items, 'id' ) );
		foreach ( $items as $item ) {
			self::assertNotSame( '', $item['title'], $item['id'] );
			self::assertNotSame( '', $item['detail'], $item['id'] );
			self::assertContains( $item['badge']['variant'], [ 'danger', 'warn', 'info' ], $item['id'] );
			self::assertNotSame( '', $item['badge']['label'], 'The state is a word, not a colour: ' . $item['id'] );
			self::assertNotSame( '', $item['action']['label'], $item['id'] );
			self::assertStringStartsWith( 'https://example.test/wp-admin/admin.php?page=stonewright', $item['action']['url'], $item['id'] );
		}
	}

	public function test_titles_count_what_is_waiting_in_plain_language(): void {
		$items = [];
		foreach ( OverviewData::attention( $this->facts( [ 'audit_incidents' => 1, 'rescue_open' => 1, 'queue_queued' => 1 ] ) ) as $item ) {
			$items[ $item['id'] ] = $item['title'];
		}
		self::assertSame( '1 open incident', $items['audit-incidents'] );
		self::assertSame( '1 change needs a rollback', $items['rescue-open'] );
		self::assertSame( '1 block change waiting', $items['queue-queued'] );

		$items = [];
		foreach ( OverviewData::attention( $this->facts( [ 'audit_incidents' => 4, 'rescue_open' => 2, 'rescue_unconfirmed' => 3, 'queue_queued' => 5, 'queue_failed' => 2 ] ) ) as $item ) {
			$items[ $item['id'] ] = $item['title'];
		}
		self::assertSame( '4 open incidents', $items['audit-incidents'] );
		self::assertSame( '2 changes need a rollback', $items['rescue-open'] );
		self::assertSame( '3 changes were not confirmed', $items['rescue-unconfirmed'] );
		self::assertSame( '5 block changes waiting', $items['queue-queued'] );
		self::assertSame( '2 block changes failed', $items['queue-failed'] );
	}

	public function test_rescue_items_lead_to_rescue_and_incident_items_to_the_incident_view(): void {
		$items = [];
		foreach ( OverviewData::attention( $this->facts( [ 'audit_incidents' => 1, 'rescue_open' => 1, 'queue_queued' => 1, 'connection_seen' => false ] ) ) as $item ) {
			$items[ $item['id'] ] = $item['action']['url'];
		}

		self::assertStringContainsString( 'page=stonewright-rescue', $items['rescue-open'] );
		self::assertStringContainsString( 'page=stonewright-audit-log', $items['audit-incidents'] );
		self::assertStringContainsString( 'view=incidents', $items['audit-incidents'] );
		self::assertStringContainsString( 'page=stonewright-block-finalizer', $items['queue-queued'] );
		self::assertSame( 'https://example.test/wp-admin/admin.php?page=stonewright', $items['connection'] );
	}

	public function test_an_unverified_connection_is_only_reported_when_abilities_are_on(): void {
		self::assertSame( [ 'connection' ], array_column( OverviewData::attention( $this->facts( [ 'connection_seen' => false ] ) ), 'id' ) );
		self::assertSame( [], OverviewData::attention( $this->facts( [ 'connection_seen' => false, 'enabled' => false ] ) ), 'Setup covers it while abilities are off.' );
	}

	public function test_every_action_names_what_it_acts_on_for_assistive_technology(): void {
		foreach ( OverviewData::attention( $this->facts( [ 'audit_incidents' => 2, 'rescue_open' => 1, 'queue_failed' => 1, 'connection_seen' => false ] ) ) as $item ) {
			self::assertNotSame( '', $item['action']['context'], $item['id'] );
		}
	}

	public function test_the_first_step_that_is_not_done_is_next(): void {
		$setup = OverviewData::setup_steps( $this->facts( [ 'enabled' => true, 'sign_in_ready' => true, 'client_count' => 1, 'connection_seen' => false ] ) );

		self::assertSame( 3, $setup['done'] );
		self::assertSame( 4, $setup['total'] );
		self::assertSame( [ 'done', 'done', 'done', 'next' ], array_column( $setup['steps'], 'state' ) );
		self::assertSame( 'verify', $setup['next']['id'] ?? '' );
		self::assertSame( 'Verify connection', $setup['next']['cta'] ?? '' );
	}

	public function test_a_site_that_has_not_started_begins_with_enabling(): void {
		$setup = OverviewData::setup_steps( $this->facts( [ 'enabled' => false, 'sign_in_ready' => false, 'client_count' => 0, 'password_count' => 0, 'connection_seen' => false ] ) );

		self::assertSame( 0, $setup['done'] );
		self::assertSame( [ 'next', 'todo', 'todo', 'todo' ], array_column( $setup['steps'], 'state' ) );
		self::assertSame( [ 'enable', 'sign-in', 'connect', 'verify' ], array_column( $setup['steps'], 'id' ) );
		self::assertSame( 'enable', $setup['next']['id'] ?? '' );
	}

	public function test_a_password_counts_as_a_connected_client(): void {
		$setup = OverviewData::setup_steps( $this->facts( [ 'client_count' => 0, 'password_count' => 1, 'connection_seen' => false ] ) );

		self::assertSame( [ 'done', 'done', 'done', 'next' ], array_column( $setup['steps'], 'state' ) );
	}

	public function test_a_complete_setup_has_no_next_step(): void {
		$setup = OverviewData::setup_steps( $this->facts() );

		self::assertSame( 4, $setup['done'] );
		self::assertNull( $setup['next'] );
		self::assertSame( [ 'done', 'done', 'done', 'done' ], array_column( $setup['steps'], 'state' ) );
	}

	public function test_every_step_has_a_label_and_a_place_to_do_it(): void {
		foreach ( OverviewData::setup_steps( $this->facts( [ 'enabled' => false ] ) )['steps'] as $step ) {
			self::assertNotSame( '', $step['label'] );
			self::assertNotSame( '', $step['cta'] );
			self::assertSame( 'https://example.test/wp-admin/admin.php?page=stonewright', $step['url'] );
		}
	}

	public function test_the_bridge_tile_shows_a_state_and_never_the_stored_url(): void {
		self::assertSame( [ 'state' => 'Not used', 'host' => '', 'detail' => 'No bridge URL set' ], OverviewData::companion( '' ) );
		self::assertSame( [ 'state' => 'Not used', 'host' => '', 'detail' => 'No bridge URL set' ], OverviewData::companion( '   ' ) );
		self::assertSame( [ 'state' => 'Configured', 'host' => '127.0.0.1:8765', 'detail' => '' ], OverviewData::companion( 'http://127.0.0.1:8765' ) );
		self::assertSame( [ 'state' => 'Configured', 'host' => 'bridge.example.test:9443', 'detail' => '' ], OverviewData::companion( 'https://user:secret@bridge.example.test:9443/private/path?token=x' ) );
		self::assertSame( 'Needs attention', OverviewData::companion( 'not a url' )['state'] );
	}

	public function test_relative_time_reads_in_words(): void {
		$now = gmdate( 'Y-m-d H:i:s' );

		self::assertSame( 'just now', OverviewData::relative_time( $now ) );
		self::assertSame( '5 mins ago', OverviewData::relative_time( gmdate( 'Y-m-d H:i:s', time() - 300 ) ) );
		self::assertSame( '1 hour ago', OverviewData::relative_time( gmdate( 'Y-m-d H:i:s', time() - 3700 ) ) );
		self::assertSame( '3 days ago', OverviewData::relative_time( gmdate( 'Y-m-d H:i:s', time() - 3 * 86400 - 60 ) ) );
		self::assertSame( 'not a time', OverviewData::relative_time( 'not a time' ) );
	}
}
