<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures;

use Stonewright\WpMcp\Authorization\Model\CodeGrantState;
use Stonewright\WpMcp\Authorization\Model\CodeExchangeOutcome;
use Stonewright\WpMcp\Authorization\Model\FamilyState;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Model\RefreshOutcome;
use Stonewright\WpMcp\Authorization\Ports\CodeLedger;

/**
 * Synthetic logical transaction model; it is not evidence for a SQL adapter. Families
 * are created and revoked through the shared family ledger, so code replay and refresh
 * duplicates act on the same state.
 */
final class MemoryCodeLedger implements CodeLedger {
	public ?\Closure $before_commit = null;
	public bool $fail_before_commit = false;
	public MemoryFamilyLedger $families;

	public function __construct( public ?CodeGrantState $state, ?MemoryFamilyLedger $families = null ) {
		$this->families = $families ?? new MemoryFamilyLedger();
	}

	/** Forget the code once its retention rule allows it. */
	public function purge( int $now ): void {
		if ( null !== $this->state && $now >= $this->state->retained_until() ) {
			$this->state = null;
		}
	}

	public function change( string $code_key, callable $decide ): CodeExchangeOutcome {
		if ( null === $this->state || $code_key !== $this->state->to_array()['code_key'] ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		$outcome = $decide( $this->state );
		$hook = $this->before_commit;
		$this->before_commit = null;
		if ( null !== $hook ) {
			$hook();
			$outcome = $decide( $this->state );
		}
		if ( $this->fail_before_commit ) {
			throw new \RuntimeException( 'Synthetic commit failure.' );
		}
		if ( null !== $outcome->family ) {
			$this->families->create( $outcome->family );
		}
		$this->state = $outcome->state;
		$key = $outcome->revoke_family_key;
		if ( null !== $key && $this->families->has( $key ) ) {
			$this->families->change( $key, static fn ( FamilyState $family ): RefreshOutcome => new RefreshOutcome( $family->revoke(), null, null ) );
		}
		return $outcome;
	}
}
