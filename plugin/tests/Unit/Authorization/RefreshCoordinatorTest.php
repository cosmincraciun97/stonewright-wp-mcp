<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Model\RotationDemand;
use Stonewright\WpMcp\Authorization\Refresh\RefreshCoordinator;
use Stonewright\WpMcp\Authorization\Refresh\RefreshPolicy;
use Stonewright\WpMcp\Authorization\Refresh\RotationDecision;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\MemoryFamilyLedger;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\ScriptedClock;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\ScriptedIdentifiers;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\SyntheticSubjectAuthority;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\SyntheticFamilies;

final class RefreshCoordinatorTest extends TestCase {

	private function demand( string $refresh = 'refresh-0' ): RotationDemand {
		return new RotationDemand( 'family-a', $refresh, 'client-a', [ 'https://example.test/mcp' ], null );
	}

	/** These schedules pin strict single-use rotation, so the duplicate window is zero. */
	private function coordinator( MemoryFamilyLedger $ledger, ?ScriptedClock $clock = null, ?SyntheticSubjectAuthority $authority = null ): RefreshCoordinator {
		return new RefreshCoordinator( $ledger, self::strict(), $clock ?? new ScriptedClock( [ 1700000000 ] ), new ScriptedIdentifiers(), $authority ?? new SyntheticSubjectAuthority() );
	}

	private static function strict(): RotationDecision {
		return new RotationDecision( new RefreshPolicy( 0 ) );
	}

	public function test_same_parent_fanout_commits_revocation_before_delivering_the_fault(): void {
		$ledger = new MemoryFamilyLedger( [ SyntheticFamilies::initial() ] );
		$coordinator = $this->coordinator( $ledger );
		$winner = $coordinator->rotate( $this->demand() );
		$loser = $coordinator->rotate( $this->demand() );
		self::assertNotNull( $winner->issuance );
		self::assertNull( $loser->issuance );
		self::assertSame( 'invalid_grant', $loser->fault->error() );
		self::assertSame( 'revoked', $ledger->read( 'family-a' )->to_array()['phase'] );
		self::assertNull( $coordinator->rotate( $this->demand() )->issuance );
		self::assertCount( 2, $ledger->read( 'family-a' )->to_array()['credential_history'] );
	}

	public function test_stale_child_and_successor_commit_are_rechecked(): void {
		$ledger = new MemoryFamilyLedger( [ SyntheticFamilies::initial() ] );
		$coordinator = $this->coordinator( $ledger );
		$first = $coordinator->rotate( $this->demand() );
		$ledger->before_commit = function () use ( $ledger, $first ): void {
			$ledger->change( 'family-a', fn ( $state ) => self::strict()->decide( $state, $this->demand( $first->issuance->refresh_key ), 1700000001, 'other-access', 'grandchild' ) );
		};
		$duplicate = $coordinator->rotate( $this->demand() );
		self::assertNull( $duplicate->issuance );
		self::assertSame( 'revoked', $ledger->read( 'family-a' )->to_array()['phase'] );
		self::assertSame( 'grandchild', $ledger->read( 'family-a' )->to_array()['current_refresh_key'] );
		self::assertCount( 3, $ledger->read( 'family-a' )->to_array()['credential_history'] );
	}

	public function test_revocation_that_commits_first_prevents_issuance(): void {
		$ledger = new MemoryFamilyLedger( [ SyntheticFamilies::initial() ] );
		$ledger->before_commit = static function () use ( $ledger ): void {
			$ledger->change( 'family-a', static fn ( $state ) => new \Stonewright\WpMcp\Authorization\Model\RefreshOutcome( $state->advance( [ 'phase' => 'revoked', 'revision' => 1 ] ), null, new OAuthFault( 'invalid_grant' ) ) );
		};
		self::assertNull( $this->coordinator( $ledger )->rotate( $this->demand() )->issuance );
		self::assertCount( 1, $ledger->read( 'family-a' )->to_array()['credential_history'] );
	}

	public function test_expiry_is_rechecked_when_the_effect_is_delayed(): void {
		$ledger = new MemoryFamilyLedger( [ SyntheticFamilies::initial() ] );
		$ledger->before_commit = static function (): void {};
		$result = $this->coordinator( $ledger, new ScriptedClock( [ 1701209599, 1701209600 ] ) )->rotate( $this->demand() );
		self::assertNull( $result->issuance );
		self::assertSame( 'expired', $ledger->read( 'family-a' )->to_array()['phase'] );
	}

	public function test_failed_commit_does_not_consume_and_lost_delivery_does_not_rollback(): void {
		$ledger = new MemoryFamilyLedger( [ SyntheticFamilies::initial() ] );
		$coordinator = $this->coordinator( $ledger );
		$ledger->fail_before_commit = true;
		try {
			$coordinator->rotate( $this->demand() );
			self::fail( 'Storage failure must propagate without pretending to commit.' );
		} catch ( \RuntimeException $exception ) {
			self::assertFalse( $ledger->read( 'family-a' )->to_array()['credential_history']['refresh-0']['consumed'] );
		}
		$coordinator->rotate( $this->demand() ); // The response is deliberately discarded.
		$retry = $coordinator->rotate( $this->demand() );
		self::assertNull( $retry->issuance );
		self::assertSame( 'revoked', $ledger->read( 'family-a' )->to_array()['phase'] );
	}

	public function test_subject_authority_is_rechecked_without_mutating_other_families(): void {
		$other = SyntheticFamilies::initial( [ 'family_key' => 'family-b' ] );
		$ledger = new MemoryFamilyLedger( [ SyntheticFamilies::initial(), $other ] );
		$result = $this->coordinator( $ledger, null, new SyntheticSubjectAuthority( false ) )->rotate( $this->demand() );
		self::assertNull( $result->issuance );
		self::assertSame( 'invalid_grant', $result->fault->error() );
		self::assertSame( 400, $result->fault->status() );
		self::assertSame( $other->to_array(), $ledger->read( 'family-b' )->to_array() );
		self::assertFalse( $ledger->read( 'family-a' )->to_array()['credential_history']['refresh-0']['consumed'] );
	}
}
