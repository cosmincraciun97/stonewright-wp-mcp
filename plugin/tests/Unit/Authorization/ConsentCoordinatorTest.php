<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Exchange\ConsentCoordinator;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\MemoryConsentLedger;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\ScriptedClock;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\ScriptedIdentifiers;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\SyntheticSubjectAuthority;

final class ConsentCoordinatorTest extends TestCase {

	private function coordinator( MemoryConsentLedger $ledger, bool $allowed = true ): ConsentCoordinator {
		return new ConsentCoordinator( $ledger, new ScriptedClock( [ 1700000000 ] ), new ScriptedIdentifiers(), new SyntheticSubjectAuthority( $allowed ) );
	}

	public function test_concurrent_approval_consumes_one_pending_transaction_and_creates_one_code(): void {
		$ledger = new MemoryConsentLedger( ConsentDecisionTest::pending() );
		$coordinator = $this->coordinator( $ledger );
		$winner = null;
		$ledger->before_commit = static function () use ( $coordinator, &$winner ): void {
			$winner = $coordinator->decide( 'pending-a', 'subject-a', true, true );
		};
		try {
			$coordinator->decide( 'pending-a', 'subject-a', true, true );
			self::fail( 'The repeated transaction must be rejected.' );
		} catch ( OAuthFault $fault ) {
			self::assertSame( 'invalid_request', $fault->error() );
		}
		self::assertTrue( $ledger->pending['used'] );
		self::assertCount( 1, $ledger->codes );
		self::assertSame( $winner['code'], array_values( $ledger->codes )[0] );
	}

	public function test_failed_persistence_does_not_deliver_a_code_or_consume_the_pending_transaction(): void {
		$ledger = new MemoryConsentLedger( ConsentDecisionTest::pending() );
		$ledger->fail_before_commit = true;
		try {
			$this->coordinator( $ledger )->decide( 'pending-a', 'subject-a', true, true );
			self::fail( 'The injected failure must propagate.' );
		} catch ( \RuntimeException $fault ) {
			self::assertSame( 'Synthetic commit failure.', $fault->getMessage() );
		}
		self::assertFalse( $ledger->pending['used'] );
		self::assertSame( [], $ledger->codes );
	}

	public function test_unknown_pending_transaction_is_a_protocol_fault_without_effects(): void {
		$ledger = new MemoryConsentLedger( ConsentDecisionTest::pending() );
		try {
			$this->coordinator( $ledger )->decide( 'pending-unknown', 'subject-a', true, true );
			self::fail( 'An unknown pending transaction must fail.' );
		} catch ( OAuthFault $fault ) {
			self::assertSame( 'invalid_request', $fault->error() );
			self::assertSame( 400, $fault->status() );
		}
		self::assertSame( ConsentDecisionTest::pending(), $ledger->pending );
		self::assertSame( [], $ledger->codes );
	}

	public function test_subject_denial_creates_no_code_and_produces_no_redirect(): void {
		$ledger = new MemoryConsentLedger( ConsentDecisionTest::pending() );
		$this->expectException( OAuthFault::class );
		try {
			$this->coordinator( $ledger, false )->decide( 'pending-a', 'subject-a', true, true );
		} finally {
			self::assertFalse( $ledger->pending['used'] );
			self::assertSame( [], $ledger->codes );
		}
	}
}
