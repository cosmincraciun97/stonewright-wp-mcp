<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Exchange\CodeExchangeCoordinator;
use Stonewright\WpMcp\Authorization\Model\FamilyState;
use Stonewright\WpMcp\Authorization\Model\RefreshOutcome;
use Stonewright\WpMcp\Authorization\Model\RotationDemand;
use Stonewright\WpMcp\Authorization\Refresh\RefreshCoordinator;
use Stonewright\WpMcp\Authorization\Refresh\RefreshPolicy;
use Stonewright\WpMcp\Authorization\Refresh\RotationDecision;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\MemoryCodeLedger;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\MemoryFamilyLedger;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\ScriptedClock;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\ScriptedIdentifiers;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\SyntheticFamilies;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\SyntheticSubjectAuthority;

/** Duplicate-delivery interleavings against the in-memory ledger with the default 60-second window. */
final class RefreshContinuityTest extends TestCase {

	private const T = 1700000000;

	private MemoryFamilyLedger $ledger;
	private ScriptedClock $clock;
	private ScriptedIdentifiers $identifiers;
	private RefreshCoordinator $refresh;

	protected function setUp(): void {
		$this->clock = new ScriptedClock( [ self::T ] );
		$this->identifiers = new ScriptedIdentifiers();
		$this->use_family( SyntheticFamilies::initial() );
	}

	private function use_family( FamilyState $family ): void {
		$this->ledger = new MemoryFamilyLedger( [ $family ] );
		$this->refresh = $this->coordinator();
	}

	private function coordinator( int $window = 60, bool $allowed = true ): RefreshCoordinator {
		return new RefreshCoordinator( $this->ledger, new RotationDecision( new RefreshPolicy( $window ) ), $this->clock, $this->identifiers, new SyntheticSubjectAuthority( $allowed ) );
	}

	private static function demand( string $key, ?array $scopes = null, array $resources = [ 'https://example.test/mcp' ] ): RotationDemand {
		return new RotationDemand( 'family-a', $key, 'client-a', $resources, $scopes );
	}

	private function present( string $key, int $at, ?array $scopes = null, array $resources = [ 'https://example.test/mcp' ], ?RefreshCoordinator $coordinator = null ): RefreshOutcome {
		$this->clock->set( $at );
		return ( $coordinator ?? $this->refresh )->rotate( self::demand( $key, $scopes, $resources ) );
	}

	private function family(): array {
		return $this->ledger->read( 'family-a' )->to_array();
	}

	/** @return list<string> */
	private function unconsumed(): array {
		return array_keys( array_filter( $this->family()['credential_history'], static fn ( array $entry ): bool => ! $entry['consumed'] ) );
	}

	public function test_serialized_demands_for_the_same_head_rotate_once_then_redeliver(): void {
		$first = $this->present( 'refresh-0', self::T );
		$second = $this->present( 'refresh-0', self::T );
		self::assertFalse( $first->issuance->redelivery );
		self::assertTrue( $second->issuance->redelivery );
		self::assertSame( $first->issuance->refresh_key, $second->issuance->refresh_key );
		self::assertNotSame( $first->issuance->access_key, $second->issuance->access_key );
		self::assertSame( [ $first->issuance->refresh_key ], $this->unconsumed() );
		self::assertCount( 2, $this->family()['credential_history'] );
		self::assertSame( 1, $this->family()['revision'] );
	}

	public function test_lost_response_retries_inside_the_window_redeliver_the_same_head(): void {
		$rotated = $this->present( 'refresh-0', self::T );
		$after_rotation = $this->family();
		$retry = $this->present( 'refresh-0', self::T + 10 );
		$again = $this->present( 'refresh-0', self::T + 20 );
		foreach ( [ $retry, $again ] as $duplicate ) {
			self::assertNull( $duplicate->fault );
			self::assertTrue( $duplicate->issuance->redelivery );
			self::assertSame( $rotated->issuance->refresh_key, $duplicate->issuance->refresh_key );
			self::assertSame( $rotated->issuance->refresh_deadline, $duplicate->issuance->refresh_deadline );
		}
		self::assertSame( self::T + 20 + 3600, $again->issuance->access_deadline );
		self::assertSame( $after_rotation, $this->family() );
	}

	public function test_older_ancestor_inside_its_window_revokes_and_the_current_head_then_fails(): void {
		$h = $this->present( 'refresh-0', self::T )->issuance->refresh_key;
		$j = $this->present( $h, self::T + 5 )->issuance->refresh_key;
		$replay = $this->present( 'refresh-0', self::T + 10 );
		self::assertSame( 'invalid_grant', $replay->fault->error() );
		self::assertSame( 'revoked', $this->family()['phase'] );
		$after = $this->present( $j, self::T + 11 );
		self::assertNull( $after->issuance );
		self::assertSame( 'invalid_grant', $after->fault->error() );
		self::assertFalse( $this->family()['credential_history'][ $j ]['consumed'] );
	}

	public function test_presentation_exactly_at_the_window_end_is_a_replay(): void {
		$this->present( 'refresh-0', self::T );
		$late = $this->present( 'refresh-0', self::T + 60 );
		self::assertNull( $late->issuance );
		self::assertSame( 'invalid_grant', $late->fault->error() );
		self::assertSame( 'revoked', $this->family()['phase'] );
	}

	public function test_revocation_between_rotation_and_duplicate_resurrects_nothing(): void {
		$h = $this->present( 'refresh-0', self::T )->issuance->refresh_key;
		$this->refresh->revoke( 'family-a' );
		$revoked = $this->family();
		$duplicate = $this->present( 'refresh-0', self::T + 1 );
		self::assertNull( $duplicate->issuance );
		self::assertSame( 'invalid_grant', $duplicate->fault->error() );
		self::assertSame( 'revoked', $revoked['phase'] );
		self::assertSame( $revoked, $this->family() );
		self::assertFalse( $revoked['credential_history'][ $h ]['consumed'] );
	}

	public function test_family_deadline_between_rotation_and_duplicate_expires_the_family(): void {
		$this->use_family( SyntheticFamilies::initial( [ 'family_deadline' => self::T + 30, 'credential_history' => [ 'refresh-0' => [ 'expires_at' => self::T + 30, 'consumed' => false, 'successor_key' => null ] ] ] ) );
		$this->present( 'refresh-0', self::T );
		$duplicate = $this->present( 'refresh-0', self::T + 30 );
		self::assertNull( $duplicate->issuance );
		self::assertSame( 'invalid_grant', $duplicate->fault->error() );
		self::assertSame( 'expired', $this->family()['phase'] );
	}

	public function test_idle_expired_head_refuses_a_duplicate_without_revocation(): void {
		$this->use_family(
			SyntheticFamilies::initial(
				[
					'current_refresh_key' => 'refresh-1',
					'revision' => 1,
					'credential_history' => [
						'refresh-0' => [ 'expires_at' => self::T + 600, 'consumed' => true, 'consumed_at' => self::T, 'successor_key' => 'refresh-1' ],
						'refresh-1' => [ 'expires_at' => self::T + 10, 'consumed' => false, 'consumed_at' => null, 'successor_key' => null ],
					],
				]
			)
		);
		$before = $this->family();
		$duplicate = $this->present( 'refresh-0', self::T + 20 );
		self::assertNull( $duplicate->issuance );
		self::assertSame( 'invalid_grant', $duplicate->fault->error() );
		self::assertSame( $before, $this->family() );
	}

	public function test_duplicate_narrowing_follows_rotation_rules_and_failures_change_nothing(): void {
		$this->present( 'refresh-0', self::T );
		$before = $this->family();
		self::assertSame( [ 'read' ], $this->present( 'refresh-0', self::T + 1, [ 'read' ] )->issuance->access_scopes );
		self::assertSame( 'invalid_scope', $this->present( 'refresh-0', self::T + 2, [ 'read', 'write' ] )->fault->error() );
		self::assertSame( 'invalid_target', $this->present( 'refresh-0', self::T + 3, null, [ 'https://example.test/other' ] )->fault->error() );
		self::assertSame( $before, $this->family() );
	}

	public function test_duplicate_is_decided_again_when_the_head_is_consumed_before_commit(): void {
		$h = $this->present( 'refresh-0', self::T )->issuance->refresh_key;
		$other = $this->coordinator();
		$this->ledger->before_commit = static function () use ( $other, $h ): void {
			$other->rotate( self::demand( $h ) );
		};
		$stale = $this->present( 'refresh-0', self::T + 5 );
		self::assertNull( $stale->issuance );
		self::assertSame( 'invalid_grant', $stale->fault->error() );
		self::assertSame( 'revoked', $this->family()['phase'] );
		self::assertCount( 3, $this->family()['credential_history'] );
	}

	/** @dataProvider retry_outcomes */
	public function test_two_writers_on_one_revision_cannot_both_commit( int $window, bool $redelivered ): void {
		$decision = new RotationDecision( new RefreshPolicy( $window ) );
		$demand = self::demand( 'refresh-0' );
		$seen_by_a = $this->ledger->read( 'family-a' );
		$seen_by_b = $this->ledger->read( 'family-a' );
		$a = $decision->decide( $seen_by_a, $demand, self::T, 'access-a', 'refresh-a' );
		$b = $decision->decide( $seen_by_b, $demand, self::T, 'access-b', 'refresh-b' );
		self::assertTrue( $this->ledger->commit( 'family-a', $seen_by_a, $a ) );
		self::assertFalse( $this->ledger->commit( 'family-a', $seen_by_b, $b ), 'The stale writer must not overwrite the committed lineage.' );
		self::assertSame( 1, $this->ledger->conflicts );
		self::assertSame( [ 'refresh-a' ], $this->unconsumed() );
		$retry = $this->ledger->change( 'family-a', static fn ( FamilyState $current ): RefreshOutcome => $decision->decide( $current, $demand, self::T, 'access-b2', 'refresh-b2' ) );
		self::assertSame( $redelivered, null !== $retry->issuance );
		self::assertSame( $redelivered ? 'active' : 'revoked', $this->family()['phase'] );
		if ( $redelivered ) {
			self::assertSame( 'refresh-a', $retry->issuance->refresh_key );
			self::assertTrue( $retry->issuance->redelivery );
		}
		self::assertSame( [ 'refresh-a' ], $this->unconsumed() );
		self::assertCount( 2, $this->family()['credential_history'] );
	}

	public function retry_outcomes(): array {
		return [ 'default window' => [ 60, true ], 'strict rotation' => [ 0, false ] ];
	}

	public function test_lost_permission_blocks_live_outcomes_but_not_replay_revocation(): void {
		$this->present( 'refresh-0', self::T );
		$before = $this->family();
		$denied = $this->coordinator( 60, false );
		$duplicate = $this->present( 'refresh-0', self::T + 1, null, [ 'https://example.test/mcp' ], $denied );
		self::assertSame( 'invalid_grant', $duplicate->fault->error() );
		self::assertSame( 400, $duplicate->fault->status() );
		self::assertSame( $before, $this->family() );
		$replay = $this->present( 'refresh-0', self::T + 61, null, [ 'https://example.test/mcp' ], $denied );
		self::assertSame( 'invalid_grant', $replay->fault->error() );
		self::assertSame( 'revoked', $this->family()['phase'] );
	}

	public function test_revocation_committed_before_a_refresh_prevents_issuance(): void {
		$this->ledger->before_commit = function (): void {
			$this->refresh->revoke( 'family-a' );
		};
		$outcome = $this->present( 'refresh-0', self::T );
		self::assertNull( $outcome->issuance );
		self::assertSame( 'revoked', $this->family()['phase'] );
		self::assertCount( 1, $this->family()['credential_history'] );
	}

	public function test_revocation_after_a_committed_refresh_closes_the_new_head(): void {
		$h = $this->present( 'refresh-0', self::T )->issuance->refresh_key;
		$revoked = $this->refresh->revoke( 'family-a' )->to_array();
		self::assertSame( 'revoked', $revoked['phase'] );
		self::assertSame( 2, $revoked['revision'] );
		self::assertSame( 'invalid_grant', $this->present( $h, self::T + 1 )->fault->error() );
		self::assertSame( $revoked, $this->family() );
	}

	public function test_expired_and_revoked_phases_keep_their_precedence(): void {
		$this->present( 'refresh-0', 1701209600 );
		self::assertSame( 'expired', $this->family()['phase'] );
		self::assertSame( 'expired', $this->refresh->revoke( 'family-a' )->to_array()['phase'] );
		$this->use_family( SyntheticFamilies::initial() );
		$this->refresh->revoke( 'family-a' );
		$this->present( 'refresh-0', 1701209600 );
		self::assertSame( 'revoked', $this->family()['phase'] );
	}

	public function test_compaction_keeps_the_lineage_usable_and_turns_dropped_credentials_into_replays(): void {
		$h = $this->present( 'refresh-0', self::T )->issuance->refresh_key;
		$j = $this->present( $h, self::T + 100 )->issuance->refresh_key;
		$k = $this->present( $j, self::T + 200 )->issuance->refresh_key;
		$this->clock->set( self::T + 1000 );
		$compacted = $this->refresh->compact( 'family-a' )->to_array();
		self::assertSame( [ $j, $k ], array_keys( $compacted['credential_history'] ) );
		self::assertSame( 2, $compacted['compacted_entries'] );
		self::assertSame( 4, $compacted['revision'] );
		$replay = $this->present( $h, self::T + 1001 );
		self::assertSame( 'invalid_grant', $replay->fault->error() );
		self::assertSame( 'revoked', $this->family()['phase'] );
	}

	public function test_code_replay_revokes_the_family_whose_refresh_duplicates_share_the_ledger(): void {
		$families = new MemoryFamilyLedger();
		$codes = new MemoryCodeLedger( AuthorizationCodeDecisionTest::code(), $families );
		$policy = new RefreshPolicy();
		$authority = new SyntheticSubjectAuthority();
		$exchange = new CodeExchangeCoordinator( $codes, AuthorizationCodeDecisionTest::decision(), $this->clock, $this->identifiers, $authority );
		$refresh = new RefreshCoordinator( $families, new RotationDecision( $policy ), $this->clock, $this->identifiers, $authority );
		$issued = $exchange->exchange( AuthorizationCodeDecisionTest::demand() )->issuance;
		$parent = new RotationDemand( $issued->family_key, $issued->refresh_key, 'client-a', [ 'https://example.test/mcp' ], null );
		$rotated = $refresh->rotate( $parent );
		$this->clock->set( self::T + 5 );
		$duplicate = $refresh->rotate( $parent );
		self::assertTrue( $duplicate->issuance->redelivery );
		self::assertSame( $rotated->issuance->refresh_key, $duplicate->issuance->refresh_key );
		$replay = $exchange->exchange( AuthorizationCodeDecisionTest::demand() );
		self::assertSame( 'invalid_grant', $replay->fault->error() );
		self::assertSame( 'revoked', $families->read( $issued->family_key )->to_array()['phase'] );
		$head = new RotationDemand( $issued->family_key, $rotated->issuance->refresh_key, 'client-a', [ 'https://example.test/mcp' ], null );
		self::assertSame( 'invalid_grant', $refresh->rotate( $head )->fault->error() );
	}
}
