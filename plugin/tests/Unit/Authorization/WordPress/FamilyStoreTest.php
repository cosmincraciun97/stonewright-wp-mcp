<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\FamilyState;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Model\RefreshOutcome;
use Stonewright\WpMcp\Authorization\Model\RotationDemand;
use Stonewright\WpMcp\Authorization\Refresh\RefreshPolicy;
use Stonewright\WpMcp\Authorization\WordPress\CredentialKeys;
use Stonewright\WpMcp\Authorization\WordPress\RowKeys;
use Stonewright\WpMcp\Authorization\WordPress\StorageFailure;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\ScriptedClock;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\LegacyRows;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\FamilyStore
 * @covers \Stonewright\WpMcp\Authorization\WordPress\AccessTokenStore
 */
final class FamilyStoreTest extends TestCase {

	private const DAY = 86400;

	private StorageRig $rig;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$this->rig = new StorageRig();
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
	}

	private function refresh_row( string $key ): array {
		return (array) $this->rig->row( 'refresh_tokens', 'identifier_hash', RowKeys::refresh( $key ) );
	}

	private function access_row( string $access_key ): array {
		return (array) $this->rig->row( 'access_tokens', 'identifier_hash', hash( 'sha256', $access_key ) );
	}

	private function family_row( string $family_key ): array {
		return (array) $this->rig->row( 'families', 'family_hash', RowKeys::grant_family( $family_key ) );
	}

	/** @return list<array<string, ?string>> */
	private function live_refresh_rows( string $family_hash ): array {
		return array_values( array_filter( $this->rig->rows( 'refresh_tokens' ), static fn ( array $row ): bool => $row['grant_family_hash'] === $family_hash && null === $row['consumed_at'] && 'rotated' !== $row['revoked_reason'] ) );
	}

	private function moment( int $time ): StorageRig {
		return $this->rig->at( $time );
	}

	private function expect_fault( string $error, callable $work ): void {
		try {
			$work();
			self::fail( 'Expected ' . $error );
		} catch ( OAuthFault $fault ) {
			self::assertSame( $error, $fault->error() );
		}
	}

	/** Upgrade fixture: version 4 tables, earlier rows, then the schema upgrade. */
	private function earlier_site(): LegacyRows {
		StorageRig::reset_globals();
		$space = new \Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\FakeTables();
		LegacyRows::install_version_four( $space );
		update_option( 'stonewright_oauth_schema_version', '4' );
		$this->rig = new StorageRig( $space );
		$legacy = new LegacyRows( $space, StorageRig::T );
		$legacy->client();
		return $legacy;
	}

	private function earlier_refresh( array $family, int $index ): string {
		return LegacyRows::refresh_payload( $family, $index, (string) get_option( CredentialKeys::ENCRYPTION_KEY_OPTION ) );
	}

	public function test_rotation_persists_one_successor_and_revokes_the_previous_access_token(): void {
		[ $client, $first, $created ] = $this->rig->connect();
		$family_key = $created->issuance->family_key;

		$outcome = $this->moment( StorageRig::T + 100 )->refresh( $first->refresh_token, $client );
		$this->rig->codec->encode( $outcome->issuance );

		$old = $this->refresh_row( $created->issuance->refresh_key );
		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T + 100 ), $old['consumed_at'] );
		self::assertSame( 'rotated', $old['revoked_reason'] );
		self::assertSame( '1', $old['revoked'] );
		$new = $this->refresh_row( $outcome->issuance->refresh_key );
		self::assertSame( $old['identifier_hash'], $new['parent_identifier_hash'] );
		self::assertSame( $old['grant_family_hash'], $new['grant_family_hash'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T + RefreshPolicy::FAMILY_LIFETIME ), $new['family_expires_at'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T + 100 + RefreshPolicy::REFRESH_IDLE_LIFETIME ), $new['expires_at'] );
		self::assertSame( $client, $new['client_id'] );
		self::assertSame( '7', $new['user_id'] );
		self::assertSame( $outcome->issuance->refresh_key, $new['credential_key'] );
		self::assertSame( hash( 'sha256', $outcome->issuance->access_key ), $new['access_token_hash'] );
		self::assertSame( '1', $this->access_row( $created->issuance->access_key )['revoked'] );
		self::assertSame( '0', $this->access_row( $outcome->issuance->access_key )['revoked'] );
		self::assertSame( '1', $this->family_row( $family_key )['revision'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T + 100 ), $this->rig->row( 'clients', 'client_id', $client )['last_used_at'] );
	}

	public function test_a_duplicate_inside_the_window_redelivers_the_current_credential(): void {
		[ $client, $first, $created ] = $this->rig->connect();
		$rotated = $this->moment( StorageRig::T + 100 )->refresh( $first->refresh_token, $client );
		$second = $this->rig->codec->encode( $rotated->issuance );
		$rows_before = count( $this->rig->rows( 'refresh_tokens' ) );

		$duplicate = $this->moment( StorageRig::T + 130 )->refresh( $first->refresh_token, $client );

		self::assertNull( $duplicate->fault );
		self::assertTrue( $duplicate->issuance->redelivery );
		self::assertSame( $rotated->issuance->refresh_key, $duplicate->issuance->refresh_key );
		$again = $this->rig->codec->encode( $duplicate->issuance );
		self::assertNotSame( $second->refresh_token, $again->refresh_token );
		self::assertCount( $rows_before, $this->rig->rows( 'refresh_tokens' ) );
		self::assertSame( '1', $this->family_row( $created->issuance->family_key )['revision'] );
		self::assertSame( '1', $this->family_row( $created->issuance->family_key )['delivery_count'] );
		$extra = $this->access_row( $duplicate->issuance->access_key );
		self::assertSame( '0', $extra['revoked'] );
		self::assertSame( RowKeys::refresh( $rotated->issuance->refresh_key ), $extra['refresh_identifier_hash'] );
		self::assertSame( '0', $this->access_row( $rotated->issuance->access_key )['revoked'], 'a duplicate revokes nothing' );

		$next = $this->moment( StorageRig::T + 140 )->refresh( $again->refresh_token, $client );
		self::assertFalse( $next->issuance->redelivery, 'either encoding consumes the same credential' );
		self::assertSame( '1', $this->access_row( $duplicate->issuance->access_key )['revoked'] );
		self::assertSame( '1', $this->access_row( $rotated->issuance->access_key )['revoked'] );

		$late_copy = $this->moment( StorageRig::T + 150 )->refresh( $second->refresh_token, $client );
		self::assertTrue( $late_copy->issuance->redelivery );
		self::assertSame( $next->issuance->refresh_key, $late_copy->issuance->refresh_key );
		self::assertCount( 1, $this->live_refresh_rows( RowKeys::grant_family( $created->issuance->family_key ) ) );
	}

	public function test_a_replay_at_the_window_boundary_revokes_the_family_before_answering(): void {
		[ $client, $first, $created ] = $this->rig->connect();
		$rotated = $this->moment( StorageRig::T + 100 )->refresh( $first->refresh_token, $client );
		$second = $this->rig->codec->encode( $rotated->issuance );

		$replay = $this->moment( StorageRig::T + 160 )->refresh( $first->refresh_token, $client );

		self::assertSame( 'invalid_grant', $replay->fault->error() );
		$family = $this->family_row( $created->issuance->family_key );
		self::assertSame( 'revoked', $family['phase'] );
		self::assertSame( '2', $family['revision'] );
		$head = $this->refresh_row( $rotated->issuance->refresh_key );
		self::assertSame( '1', $head['revoked'] );
		self::assertSame( 'replayed', $head['revoked_reason'] );
		foreach ( $this->rig->rows( 'access_tokens' ) as $row ) {
			self::assertSame( '1', $row['revoked'] );
		}

		$after = $this->moment( StorageRig::T + 170 )->refresh( $second->refresh_token, $client );
		self::assertSame( 'invalid_grant', $after->fault->error() );
		self::assertSame( '2', $this->family_row( $created->issuance->family_key )['revision'] );
	}

	public function test_a_revision_conflict_reruns_the_decision_on_fresh_state(): void {
		[ $client, $first, $created ] = $this->rig->connect();
		// The competing process runs one second later, so its committed row differs from
		// anything this process would write: only the revision guard can stop a second successor.
		$other = $this->rig->connection();
		$other->clock = new ScriptedClock( [ StorageRig::T + 101 ] );
		$other->wire();
		$competitor = null;
		$this->rig->wpdb->before = static function ( string $sql ) use ( $other, $first, $client, &$competitor ): void {
			if ( null === $competitor && str_starts_with( $sql, 'UPDATE wptests_stonewright_oauth_families' ) ) {
				$competitor = $other->refresh( $first->refresh_token, $client );
			}
		};

		$outcome = $this->moment( StorageRig::T + 100 )->refresh( $first->refresh_token, $client );

		self::assertFalse( $competitor->issuance->redelivery );
		self::assertTrue( $outcome->issuance->redelivery, 'the loser re-decides and becomes a duplicate' );
		self::assertSame( $competitor->issuance->refresh_key, $outcome->issuance->refresh_key );
		self::assertSame( 1, $this->rig->families->conflicts() );
		self::assertSame( 1, $this->rig->wpdb->rollbacks );
		self::assertSame( '1', $this->family_row( $created->issuance->family_key )['revision'] );
		self::assertCount( 1, $this->live_refresh_rows( RowKeys::grant_family( $created->issuance->family_key ) ) );
	}

	public function test_a_stale_duplicate_is_decided_again_and_becomes_a_replay(): void {
		[ $client, $first, $created ] = $this->rig->connect();
		$rotated = $this->moment( StorageRig::T + 100 )->refresh( $first->refresh_token, $client );
		$second = $this->rig->codec->encode( $rotated->issuance );
		$other = $this->rig->connection();
		$moved = false;
		$this->rig->wpdb->before = static function ( string $sql ) use ( $other, $second, $client, &$moved ): void {
			// Another process rotates the current credential just before the duplicate claims the family.
			if ( ! $moved && str_starts_with( $sql, 'UPDATE wptests_stonewright_oauth_families SET delivery_count' ) ) {
				$moved = true;
				$other->refresh( $second->refresh_token, $client );
			}
		};

		$stale = $this->moment( StorageRig::T + 110 )->refresh( $first->refresh_token, $client );

		self::assertTrue( $moved );
		self::assertSame( 'invalid_grant', $stale->fault->error() );
		self::assertSame( 'revoked', $this->family_row( $created->issuance->family_key )['phase'] );
	}

	public function test_a_revocation_committed_before_the_duplicate_claim_wins(): void {
		[ $client, $first, $created ] = $this->rig->connect();
		$this->moment( StorageRig::T + 100 )->refresh( $first->refresh_token, $client );
		$other = $this->rig->connection();
		$revoked = false;
		$this->rig->wpdb->before = static function ( string $sql ) use ( $other, $created, &$revoked ): void {
			if ( ! $revoked && str_starts_with( $sql, 'UPDATE wptests_stonewright_oauth_families SET delivery_count' ) ) {
				$revoked = true;
				$other->families->revoke( $created->issuance->family_key );
			}
		};
		$access_rows = count( $this->rig->rows( 'access_tokens' ) );

		$duplicate = $this->moment( StorageRig::T + 110 )->refresh( $first->refresh_token, $client );

		self::assertTrue( $revoked );
		self::assertSame( 'invalid_grant', $duplicate->fault->error() );
		self::assertCount( $access_rows, $this->rig->rows( 'access_tokens' ), 'no access credential outlives the revocation' );
	}

	public function test_a_revocation_after_a_duplicate_reaches_its_access_credential(): void {
		[ $client, $first, $created ] = $this->rig->connect();
		$this->moment( StorageRig::T + 100 )->refresh( $first->refresh_token, $client );
		$duplicate = $this->moment( StorageRig::T + 110 )->refresh( $first->refresh_token, $client );

		$this->rig->families->revoke( $created->issuance->family_key );

		self::assertSame( '1', $this->access_row( $duplicate->issuance->access_key )['revoked'] );
	}

	public function test_a_refused_claim_is_retried_like_a_lost_one(): void {
		[ $client, $first, $created ] = $this->rig->connect();
		$refusals = 1;
		$this->rig->wpdb->fail_when = static function ( string $sql ) use ( &$refusals ): bool {
			// A busy or locked database refuses the compare-and-swap once.
			return str_starts_with( $sql, 'UPDATE wptests_stonewright_oauth_families SET revision' ) && $refusals-- > 0;
		};

		$outcome = $this->moment( StorageRig::T + 100 )->refresh( $first->refresh_token, $client );

		self::assertNull( $outcome->fault );
		self::assertFalse( $outcome->issuance->redelivery );
		self::assertSame( 1, $this->rig->families->conflicts() );
		self::assertSame( '1', $this->family_row( $created->issuance->family_key )['revision'] );
	}

	public function test_a_storage_failure_rolls_back_every_effect(): void {
		[ $client, $first, $created ] = $this->rig->connect();
		$before = $this->rig->space->tables;
		$this->rig->wpdb->fail_when = static fn ( string $sql ): bool => str_starts_with( $sql, 'INSERT INTO wptests_stonewright_oauth_refresh_tokens' );

		try {
			$this->moment( StorageRig::T + 100 )->refresh( $first->refresh_token, $client );
			self::fail( 'A failed write must surface.' );
		} catch ( StorageFailure $failure ) {
			self::assertStringNotContainsString( $first->refresh_token, $failure->getMessage() );
		}

		self::assertSame( $before, $this->rig->space->tables );
		$this->rig->wpdb->fail_when = null;
		$retry = $this->moment( StorageRig::T + 101 )->refresh( $first->refresh_token, $client );
		self::assertFalse( $retry->issuance->redelivery );
		self::assertSame( '1', $this->family_row( $created->issuance->family_key )['revision'] );
	}

	public function test_losing_permission_changes_nothing(): void {
		[ $client, $first ] = $this->rig->connect();
		$before = $this->rig->space->tables;
		$facts = $this->rig->codec->inspect( $first->refresh_token );

		$outcome = $this->moment( StorageRig::T + 100 )->coordinator( false )->rotate( new RotationDemand( (string) $facts->family_key, $facts->credential_key, $client, [ StorageRig::RESOURCE ], null ) );

		self::assertSame( 'invalid_grant', $outcome->fault->error() );
		self::assertSame( $before, $this->rig->space->tables );
	}

	public function test_the_family_deadline_expires_the_family(): void {
		[ $client, $first, $created ] = $this->rig->connect();
		$deadline = StorageRig::T + RefreshPolicy::FAMILY_LIFETIME;
		$this->rig->db->execute( 'UPDATE ' . $this->rig->db->table( 'refresh_tokens' ) . ' SET expires_at = %s', [ gmdate( 'Y-m-d H:i:s', $deadline ) ] );

		$outcome = $this->moment( $deadline )->refresh( $first->refresh_token, $client );

		self::assertSame( 'invalid_grant', $outcome->fault->error() );
		self::assertSame( 'expired', $this->family_row( $created->issuance->family_key )['phase'] );
	}

	public function test_unknown_families_are_invalid_grant(): void {
		$this->expect_fault( 'invalid_grant', fn () => $this->rig->families->change( str_repeat( 'e', 80 ), static fn ( FamilyState $state ): RefreshOutcome => new RefreshOutcome( $state, null, null ) ) );
		$this->expect_fault( 'invalid_grant', fn () => $this->rig->families->change( str_repeat( 'e', 64 ), static fn ( FamilyState $state ): RefreshOutcome => new RefreshOutcome( $state, null, null ) ) );
	}

	public function test_explicit_revocation_closes_the_family_and_its_credentials(): void {
		[ , , $created ] = $this->rig->connect();

		$this->rig->families->revoke( $created->issuance->family_key );

		self::assertSame( 'revoked', $this->family_row( $created->issuance->family_key )['phase'] );
		$head = $this->refresh_row( $created->issuance->refresh_key );
		self::assertSame( '1', $head['revoked'] );
		self::assertNull( $head['revoked_reason'] );
		self::assertSame( '1', $this->access_row( $created->issuance->access_key )['revoked'] );
		self::assertSame( 'revoked', $this->rig->families->load( $created->issuance->family_key )->to_array()['phase'] );
	}

	public function test_compaction_keeps_the_head_and_its_predecessor(): void {
		[ $client, $token, $created ] = $this->rig->connect();
		$family_key = $created->issuance->family_key;
		$first = $token;
		for ( $step = 1; $step <= 4; $step++ ) {
			$outcome = $this->moment( StorageRig::T + $step * 1000 )->refresh( $token->refresh_token, $client );
			$token = $this->rig->codec->encode( $outcome->issuance );
		}

		self::assertTrue( $this->rig->at( StorageRig::T + 9000 )->families->compact( RowKeys::grant_family( $family_key ), 60 ) );

		$state = $this->rig->families->load( $family_key )->to_array();
		self::assertCount( 2, $state['credential_history'] );
		self::assertSame( 3, $state['compacted_entries'] );
		self::assertSame( '3', $this->family_row( $family_key )['compacted_entries'] );
		self::assertCount( 2, $this->rig->rows( 'refresh_tokens' ) );
		self::assertFalse( $this->rig->families->compact( RowKeys::grant_family( $family_key ), 60 ), 'nothing left to drop' );

		$replay = $this->moment( StorageRig::T + 9100 )->refresh( $first->refresh_token, $client );
		self::assertSame( 'invalid_grant', $replay->fault->error() );
		self::assertSame( 'revoked', $this->family_row( $family_key )['phase'] );
	}

	public function test_an_earlier_family_rotates_without_extending_its_deadline(): void {
		$legacy = $this->earlier_site();
		$family = $legacy->family( 'f', 2, null, StorageRig::T - 3600 );

		$outcome = $this->rig->refresh( $this->earlier_refresh( $family, 2 ), LegacyRows::CLIENT );

		self::assertNull( $outcome->fault );
		$new = $this->refresh_row( $outcome->issuance->refresh_key );
		self::assertSame( gmdate( 'Y-m-d H:i:s', $family['deadline'] ), $new['family_expires_at'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', $family['deadline'] ), $new['expires_at'] );
		self::assertSame( $family['deadline'], $outcome->issuance->refresh_deadline );
		self::assertSame( $family['rows'][2], $new['parent_identifier_hash'] );
		self::assertSame( $family['family_hash'], $new['grant_family_hash'] );
		self::assertSame( LegacyRows::CLIENT, $new['client_id'] );
		$old = (array) $this->rig->row( 'refresh_tokens', 'identifier_hash', $family['rows'][2] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T ), $old['consumed_at'] );
		self::assertSame( 'rotated', $old['revoked_reason'] );
		self::assertSame( '1', $this->access_row( $family['jtis'][2] )['revoked'] );
		self::assertSame( '1', $this->family_row( $family['family_hash'] )['revision'] );
		$pair = $this->rig->codec->encode( $outcome->issuance );
		$next = $this->moment( StorageRig::T + 100 )->refresh( $pair->refresh_token, LegacyRows::CLIENT );
		self::assertFalse( $next->issuance->redelivery );
	}

	public function test_an_earlier_rotated_credential_presented_again_is_a_replay(): void {
		$legacy = $this->earlier_site();
		$family = $legacy->family( 'f', 2, null, StorageRig::T - 3600 );

		$outcome = $this->rig->refresh( $this->earlier_refresh( $family, 0 ), LegacyRows::CLIENT );

		self::assertSame( 'invalid_grant', $outcome->fault->error() );
		self::assertSame( 'revoked', $this->family_row( $family['family_hash'] )['phase'] );
		$head = (array) $this->rig->row( 'refresh_tokens', 'identifier_hash', $family['rows'][2] );
		self::assertSame( '1', $head['revoked'] );
		self::assertSame( 'replayed', $head['revoked_reason'] );
		self::assertSame( '1', $this->access_row( $family['jtis'][2] )['revoked'] );
	}

	public function test_an_earlier_replayed_family_stays_revoked(): void {
		$legacy = $this->earlier_site();
		$family = $legacy->family( 'f', 2, 'replayed', StorageRig::T - 3600 );
		$credential = $this->earlier_refresh( $family, 2 );
		$this->rig->codec->inspect( $credential );
		$before = $this->rig->space->tables;

		$outcome = $this->rig->refresh( $credential, LegacyRows::CLIENT );

		self::assertSame( 'invalid_grant', $outcome->fault->error() );
		self::assertSame( $before, $this->rig->space->tables );
		self::assertSame( 'revoked', $this->rig->families->load( $family['family_hash'] )->to_array()['phase'] );
	}

	public function test_an_earlier_head_cannot_be_redelivered_across_the_upgrade(): void {
		$legacy = $this->earlier_site();
		$family = $legacy->family( 'f', 1, null, StorageRig::T - 5 );
		$credential = $this->earlier_refresh( $family, 0 );
		$this->rig->codec->inspect( $credential );
		$before = $this->rig->space->tables;

		$this->expect_fault( 'server_error', fn () => $this->rig->refresh( $credential, LegacyRows::CLIENT ) );

		self::assertSame( $before, $this->rig->space->tables, 'no state change and no extra access credential' );
		self::assertSame( '0', $this->family_row( $family['family_hash'] )['revision'] );
	}

	public function test_an_earlier_family_is_adopted_from_its_paired_access_row(): void {
		$legacy = $this->earlier_site();
		$family = $legacy->family( 'f', 1 );

		$this->rig->families->revoke( $family['family_hash'] );

		$adopted = $this->family_row( $family['family_hash'] );
		self::assertSame( LegacyRows::CLIENT, $adopted['client_id'] );
		self::assertSame( 'revoked', $adopted['phase'] );
		self::assertSame( '1', (string) $this->rig->row( 'refresh_tokens', 'identifier_hash', $family['rows'][1] )['revoked'] );
	}

	public function test_an_earlier_family_without_any_owner_record_is_revoked_row_by_row(): void {
		$legacy = $this->earlier_site();
		$family = $legacy->family( 'f', 1 );
		$this->rig->db->execute( 'DELETE FROM ' . $this->rig->db->table( 'access_tokens' ) );

		$this->rig->families->revoke( $family['family_hash'] );

		self::assertSame( [], $this->rig->rows( 'families' ) );
		self::assertSame( '1', (string) $this->rig->row( 'refresh_tokens', 'identifier_hash', $family['rows'][1] )['revoked'] );
		$this->expect_fault( 'invalid_grant', fn () => $this->rig->families->change( $family['family_hash'], static fn ( FamilyState $state ): RefreshOutcome => new RefreshOutcome( $state, null, null ) ) );
	}

	public function test_earlier_rows_map_to_logical_state(): void {
		$legacy = $this->earlier_site();
		$family = $legacy->family( 'f', 2, null, StorageRig::T - 3600 );
		$this->rig->codec->inspect( $this->earlier_refresh( $family, 2 ) );

		$state = $this->rig->families->load( $family['family_hash'] )->to_array();

		self::assertSame( $family['family_hash'], $state['family_key'] );
		self::assertSame( LegacyRows::CLIENT, $state['client_key'] );
		self::assertSame( '7', $state['subject_key'] );
		self::assertSame( $family['deadline'], $state['family_deadline'] );
		self::assertSame( $family['rows'][2], $state['current_refresh_key'] );
		self::assertSame( 'active', $state['phase'] );
		self::assertSame( [ 'mcp' ], $state['consented_scopes'] );
		self::assertContains( StorageRig::RESOURCE, $state['consented_resources'] );
		self::assertSame( $family['rows'][1], $state['credential_history'][ $family['rows'][0] ]['successor_key'] );
		self::assertTrue( $state['credential_history'][ $family['rows'][0] ]['consumed'] );
		self::assertSame( $family['issued'] + 1, $state['credential_history'][ $family['rows'][0] ]['consumed_at'] );
		self::assertFalse( $state['credential_history'][ $family['rows'][2] ]['consumed'] );
	}
}
