<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Exchange\CodeExchangeCoordinator;
use Stonewright\WpMcp\Authorization\Model\CodeGrantState;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\MemoryCodeLedger;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\MemoryFamilyLedger;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\ScriptedClock;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\ScriptedIdentifiers;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\SyntheticSubjectAuthority;

final class CodeExchangeCoordinatorTest extends TestCase {

	private MemoryFamilyLedger $families;

	private function ledger(): MemoryCodeLedger {
		$this->families = new MemoryFamilyLedger();
		return new MemoryCodeLedger( AuthorizationCodeDecisionTest::code(), $this->families );
	}

	private function coordinator( MemoryCodeLedger $ledger, array $times = [ 1700000000 ], ?SyntheticSubjectAuthority $authority = null ): CodeExchangeCoordinator {
		return new CodeExchangeCoordinator( $ledger, AuthorizationCodeDecisionTest::decision(), new ScriptedClock( $times ), new ScriptedIdentifiers(), $authority ?? new SyntheticSubjectAuthority() );
	}

	public function test_concurrent_redemptions_create_only_one_family_and_commit_replay_revocation(): void {
		$ledger = $this->ledger();
		$coordinator = $this->coordinator( $ledger );
		$winner = null;
		$ledger->before_commit = static function () use ( $coordinator, &$winner ): void {
			$winner = $coordinator->exchange( AuthorizationCodeDecisionTest::demand() );
		};
		$repeat = $coordinator->exchange( AuthorizationCodeDecisionTest::demand() );
		self::assertNotNull( $winner->issuance );
		self::assertSame( 'invalid_grant', $repeat->fault->error() );
		self::assertCount( 1, $this->families->keys() );
		self::assertSame( 'revoked', $this->families->read( $winner->family->to_array()['family_key'] )->to_array()['phase'] );
	}

	public function test_failed_commit_delivers_no_issuance_and_keeps_the_code_available(): void {
		$ledger = $this->ledger();
		$ledger->fail_before_commit = true;
		$coordinator = $this->coordinator( $ledger );
		try {
			$coordinator->exchange( AuthorizationCodeDecisionTest::demand() );
			self::fail( 'The injected commit failure must propagate.' );
		} catch ( \RuntimeException $fault ) {
			self::assertSame( 'Synthetic commit failure.', $fault->getMessage() );
		}
		self::assertFalse( $ledger->state->to_array()['used'] );
		self::assertSame( [], $this->families->keys() );
		$ledger->fail_before_commit = false;
		self::assertNotNull( $coordinator->exchange( AuthorizationCodeDecisionTest::demand() )->issuance );
	}

	public function test_expiry_is_checked_again_after_a_stale_decision(): void {
		$ledger = $this->ledger();
		$ledger->before_commit = static function (): void {};
		$result = $this->coordinator( $ledger, [ 1700000000, 1700000300 ] )->exchange( AuthorizationCodeDecisionTest::demand() );
		self::assertSame( 'invalid_grant', $result->fault->error() );
		self::assertSame( [], $this->families->keys() );
		self::assertFalse( $ledger->state->to_array()['used'] );
	}

	public function test_subject_permission_is_checked_again_at_exchange(): void {
		$ledger = $this->ledger();
		$authority = new SyntheticSubjectAuthority( false );
		$result = $this->coordinator( $ledger, [ 1700000000 ], $authority )->exchange( AuthorizationCodeDecisionTest::demand() );
		self::assertSame( 'invalid_grant', $result->fault->error() );
		self::assertSame( 400, $result->fault->status() );
		self::assertFalse( $ledger->state->to_array()['used'] );
		self::assertSame( [], $this->families->keys() );
	}

	public function test_unknown_code_is_an_invalid_grant_without_effects(): void {
		$ledger = $this->ledger();
		try {
			$this->coordinator( $ledger )->exchange( AuthorizationCodeDecisionTest::demand( [ 'code_key' => 'code-unknown' ] ) );
			self::fail( 'An unknown code must fail.' );
		} catch ( OAuthFault $fault ) {
			self::assertSame( 'invalid_grant', $fault->error() );
			self::assertSame( 400, $fault->status() );
		}
		self::assertFalse( $ledger->state->to_array()['used'] );
		self::assertSame( [], $this->families->keys() );
	}

	public function test_lost_permission_does_not_block_code_replay_revocation(): void {
		$ledger = $this->ledger();
		$family_key = $this->coordinator( $ledger )->exchange( AuthorizationCodeDecisionTest::demand() )->issuance->family_key;
		$replay = $this->coordinator( $ledger, [ 1700000001 ], new SyntheticSubjectAuthority( false ) )->exchange( AuthorizationCodeDecisionTest::demand() );
		self::assertSame( 'invalid_grant', $replay->fault->error() );
		self::assertSame( 'revoked', $this->families->read( $family_key )->to_array()['phase'] );
	}

	public function test_used_code_stays_recognizable_until_its_expiry_plus_the_retention_margin(): void {
		$ledger = $this->ledger();
		$family_key = $this->coordinator( $ledger )->exchange( AuthorizationCodeDecisionTest::demand() )->issuance->family_key;
		$retained_until = 1700000300 + CodeGrantState::USED_CODE_RETENTION;
		$ledger->purge( $retained_until - 1 );
		self::assertNotNull( $ledger->state );
		$late_replay = $this->coordinator( $ledger, [ $retained_until - 1 ] )->exchange( AuthorizationCodeDecisionTest::demand() );
		self::assertSame( 'invalid_grant', $late_replay->fault->error() );
		self::assertSame( 'revoked', $this->families->read( $family_key )->to_array()['phase'] );
	}

	public function test_code_forgotten_after_retention_is_an_unknown_grant(): void {
		$ledger = $this->ledger();
		$family_key = $this->coordinator( $ledger )->exchange( AuthorizationCodeDecisionTest::demand() )->issuance->family_key;
		$ledger->purge( 1700000300 + CodeGrantState::USED_CODE_RETENTION );
		self::assertNull( $ledger->state );
		try {
			$this->coordinator( $ledger, [ 1700000300 + CodeGrantState::USED_CODE_RETENTION ] )->exchange( AuthorizationCodeDecisionTest::demand() );
			self::fail( 'A forgotten code is unknown.' );
		} catch ( OAuthFault $fault ) {
			self::assertSame( 'invalid_grant', $fault->error() );
		}
		self::assertSame( 'active', $this->families->read( $family_key )->to_array()['phase'] );
	}
}
