<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\AuditEvent;
use Stonewright\WpMcp\Security\IncidentStore;

/** @covers \Stonewright\WpMcp\Security\IncidentStore */
final class IncidentRepairLifecycleTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_update_option_failures'] = [];
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_update_option_failures'] = [];
	}

	public function test_verified_receipt_resolves_once_and_tracks_repair_metadata(): void {
		$first = IncidentStore::observe( $this->failure() );
		$open  = IncidentStore::observe( $this->failure( 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' ) );

		self::assertSame( 'observing', $first['state'] );
		self::assertSame( 'open', $open['state'] );
		self::assertSame( 'proposed', $open['repair_phase'] );
		self::assertSame( 'none', $open['learning_status'] );

		$resolved = IncidentStore::record_verified_repair( $this->receipt( $open['version_token'] ) );
		self::assertIsArray( $resolved );
		self::assertSame( 'resolved', $resolved['state'] );
		self::assertSame( 'verified', $resolved['repair_phase'] );
		self::assertSame( 'none', $resolved['learning_status'] );
		self::assertSame( hash( 'sha256', 'receipt' ), $resolved['repair_receipt_id'] );

		$again = IncidentStore::record_verified_repair( $this->receipt( $open['version_token'] ) );
		self::assertIsArray( $again );
		self::assertSame( $resolved['resolved_at'], $again['resolved_at'] );
		self::assertSame( 0, $again['reopened_count'] );
	}

	public function test_promoted_learning_becomes_stale_when_same_incident_recurs(): void {
		IncidentStore::observe( $this->failure() );
		IncidentStore::observe( $this->failure( 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' ) );
		$open = IncidentStore::get( $this->incident_id() );
		$resolved = IncidentStore::record_verified_repair( $this->receipt( $open['version_token'] ) );
		self::assertTrue( IncidentStore::mark_learning_promoted( $this->incident_id(), 'learning-repair-abc', hash( 'sha256', 'receipt' ), $resolved['version_token'] ) );

		$promoted = IncidentStore::get( $this->incident_id() );
		self::assertSame( 'promoted', $promoted['learning_status'] );
		self::assertSame( 'learning-repair-abc', $promoted['learning_memory_key'] );

		$reopened = IncidentStore::observe( $this->failure( 'cccccccc-cccc-4ccc-8ccc-cccccccccccc' ) );
		self::assertSame( 'open', $reopened['state'] );
		self::assertSame( 1, $reopened['reopened_count'] );
		self::assertSame( 'proposed', $reopened['repair_phase'] );
		self::assertSame( 'stale', $reopened['learning_status'] );
		self::assertSame( '', $reopened['resolution_event_id'] );
		self::assertSame( '', $reopened['repair_receipt_id'] );
	}

	public function test_legacy_uncorrelated_incident_remains_visible_and_open(): void {
		$failure = $this->failure();
		$failure['change_set_id'] = '';
		$failure['resource_key_hash'] = '';
		IncidentStore::observe( $failure );
		IncidentStore::observe( $failure );

		$current = IncidentStore::get( $this->incident_id() );
		$result = IncidentStore::record_verified_repair( $this->receipt( $current['version_token'] ) );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_repair_uncorrelated', $result->get_error_code() );
		self::assertSame( 'open', IncidentStore::get( $this->incident_id() )['state'] );
	}

	public function test_newer_failure_blocks_stale_resolution_and_learning_tokens(): void {
		IncidentStore::observe( $this->failure() );
		$validated = IncidentStore::observe( $this->failure( 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' ) );
		self::assertSame( [ 'generation', 'updated_at', 'occurrences' ], array_keys( $validated['version_token'] ) );

		IncidentStore::observe( $this->failure( 'cccccccc-cccc-4ccc-8ccc-cccccccccccc' ) );
		$stale_receipt = $this->receipt();
		$stale_receipt['version_token'] = $validated['version_token'];
		$stale = IncidentStore::record_verified_repair( $stale_receipt );

		self::assertInstanceOf( \WP_Error::class, $stale );
		self::assertSame( 'stonewright_incident_state_changed', $stale->get_error_code() );
		self::assertSame( 'open', IncidentStore::get( $this->incident_id() )['state'] );

		$current = IncidentStore::get( $this->incident_id() );
		$current_receipt = $this->receipt();
		$current_receipt['version_token'] = $current['version_token'];
		$resolved = IncidentStore::record_verified_repair( $current_receipt );
		self::assertIsArray( $resolved );

		IncidentStore::observe( $this->failure( 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee' ) );
		self::assertFalse( IncidentStore::mark_learning_promoted(
			$this->incident_id(),
			'learning-repair-abc',
			hash( 'sha256', 'receipt' ),
			$resolved['version_token']
		) );
		self::assertSame( 'open', IncidentStore::get( $this->incident_id() )['state'] );
	}

	public function test_failed_fallback_cas_does_not_report_or_cache_a_resolved_incident(): void {
		IncidentStore::observe( $this->failure() );
		$open = IncidentStore::observe( $this->failure( 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' ) );
		$GLOBALS['stonewright_test_update_option_failures'][ IncidentStore::OPTION_KEY ] = true;

		$result = IncidentStore::record_verified_repair( $this->receipt( $open['version_token'] ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_incident_state_changed', $result->get_error_code() );
		self::assertSame( 'open', IncidentStore::get( $this->incident_id() )['state'] );
		self::assertSame( $open['version_token'], IncidentStore::get( $this->incident_id() )['version_token'] );
	}

	/** @return array<string, mixed> */
	private function failure( string $event_id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' ): array {
		return [
			'incident_id'          => $this->incident_id(),
			'event_id'             => $event_id,
			'outcome'              => AuditEvent::OUTCOME_FAILED,
			'category'             => AuditEvent::CATEGORY_VALIDATION,
			'severity_level'       => 'high',
			'ability'              => 'stonewright/example-write',
			'ability_family'       => 'example',
			'root_error_code'      => 'stonewright_example_invalid',
			'resource_type'        => 'post',
			'resource_key_hash'    => hash( 'sha256', 'resource' ),
			'normalized_path'      => 'example/settings/title',
			'cause_fingerprint'    => hash( 'sha256', 'cause' ),
			'strategy_fingerprint' => hash( 'sha256', 'strategy' ),
			'expected_verifier'    => 'stonewright/example-verify',
			'change_set_id'        => 'change-set-a',
		];
	}

	/** @return array<string, mixed> */
	private function receipt( ?array $version_token = null ): array {
		return [
			'incident_id'         => $this->incident_id(),
			'repair_receipt_id'   => hash( 'sha256', 'receipt' ),
			'resolution_event_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
			'verification_status' => 'verified',
			'effect_verified'     => true,
			'change_set_id'       => 'change-set-a',
			'resource_key_hash'   => hash( 'sha256', 'resource' ),
			'normalized_path'     => 'example/settings/title',
			'repair_recipe'       => 'Read schema, replace rejected field, then verify readback.',
			'learning_eligible'   => true,
				'evidence'            => [
				'after_sha256' => hash( 'sha256', 'after' ),
				'verifier'     => 'stonewright/example-verify',
				],
				'version_token'       => $version_token ?? ( IncidentStore::get( $this->incident_id() )['version_token'] ?? [] ),
		];
	}

	private function incident_id(): string {
		return hash( 'sha256', 'incident' );
	}
}
