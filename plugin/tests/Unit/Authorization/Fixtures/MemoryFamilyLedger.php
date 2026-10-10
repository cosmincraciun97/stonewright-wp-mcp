<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures;

use Stonewright\WpMcp\Authorization\Ports\FamilyLedger;
use Stonewright\WpMcp\Authorization\Model\FamilyState;
use Stonewright\WpMcp\Authorization\Model\RefreshOutcome;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;

/**
 * Deterministic decision scheduler with a revision compare-and-swap at commit; this is
 * not proof of SQL atomicity. A before_commit hook runs one interleaving after the first
 * decision; the effect boundary then moves, so the ledger decides again on fresh state.
 */
final class MemoryFamilyLedger implements FamilyLedger {
	private array $families = [];
	public ?\Closure $before_commit = null;
	public bool $fail_before_commit = false;
	public int $conflicts = 0;

	public function __construct( array $families = [] ) {
		foreach ( $families as $family ) {
			$this->create( $family );
		}
	}

	/** Shared creation path, also used by the code ledger; a family key exists once. */
	public function create( FamilyState $family ): void {
		$key = $family->to_array()['family_key'];
		if ( isset( $this->families[ $key ] ) ) {
			throw new \RuntimeException( 'Synthetic family collision.' );
		}
		$this->families[ $key ] = $family;
	}

	public function has( string $key ): bool {
		return isset( $this->families[ $key ] );
	}

	/** @return list<string> */
	public function keys(): array {
		return array_map( 'strval', array_keys( $this->families ) );
	}

	public function read( string $key ): FamilyState {
		if ( ! isset( $this->families[ $key ] ) ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		return $this->families[ $key ];
	}

	public function change( string $family_key, callable $decide ): RefreshOutcome {
		for ( $attempt = 0; $attempt < 4; ++$attempt ) {
			$observed = $this->read( $family_key );
			$outcome = $decide( $observed );
			if ( null !== $this->before_commit ) {
				$interleave = $this->before_commit;
				$this->before_commit = null;
				$interleave();
				continue;
			}
			if ( $this->fail_before_commit ) {
				$this->fail_before_commit = false;
				throw new \RuntimeException( 'Synthetic storage failure.' );
			}
			if ( $this->commit( $family_key, $observed, $outcome ) ) {
				return $outcome;
			}
		}
		throw new \RuntimeException( 'Synthetic persistent conflict.' );
	}

	/**
	 * Commit only if the stored revision is still the observed one. A stale writer is
	 * refused (and counted) instead of overwriting a newer lineage.
	 */
	public function commit( string $family_key, FamilyState $observed, RefreshOutcome $outcome ): bool {
		$stored = $this->read( $family_key )->to_array();
		$seen = $observed->to_array();
		if ( $stored['revision'] !== $seen['revision'] ) {
			++$this->conflicts;
			return false;
		}
		$next = $outcome->state->to_array();
		$valid = $next['revision'] === $seen['revision'] ? $next === $seen : $next['revision'] === $seen['revision'] + 1;
		if ( ! $valid || $next['family_key'] !== $family_key ) {
			throw new \LogicException( 'Synthetic invalid revision step.' );
		}
		$this->families[ $family_key ] = $outcome->state;
		return true;
	}
}
