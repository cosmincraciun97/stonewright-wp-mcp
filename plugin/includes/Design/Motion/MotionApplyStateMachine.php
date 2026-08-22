<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Motion;

/**
 * Motion apply state machine with the single safe deterministic repair rule.
 *
 * Flow: planned -> approved -> snapshotted -> applied -> readback_verified
 * -> editor_verified -> frontend_verified -> quality_verified -> complete.
 *
 * On failure: classify -> at most ONE allowlisted deterministic repair under
 * policy=safe_once -> re-verify everything; otherwise roll back and report
 * blocked. Subjective verdicts, schema drift, missing providers, custom code,
 * and security failures never self-repair.
 */
final class MotionApplyStateMachine {

	public const POLICY_SAFE_ONCE = 'safe_once';
	public const POLICY_NEVER     = 'never';

	private const ORDER = [
		'planned',
		'approved',
		'snapshotted',
		'applied',
		'readback_verified',
		'editor_verified',
		'frontend_verified',
		'quality_verified',
		'complete',
	];

	private const REPAIR_ALLOWLIST = [
		'motion_class_missing_on_element',
		'readback_hash_mismatch_transient_cache',
	];

	private const NO_REPAIR_CODES = [
		'stonewright_spec_invalid',
		'schema_drift',
		'missing_provider',
		'custom_code_required',
		'security_failure',
		'editor_corruption',
	];

	/** @var string */
	private string $state = 'planned';

	/** @var int */
	private int $repair_count = 0;

	/**
	 * Applied transition log for receipts.
	 *
	 * @var list<string>
	 */
	private array $history = [];

	public function state(): string {
		return $this->state;
	}

	public function repair_count(): int {
		return $this->repair_count;
	}

	/**
	 * @return list<string>
	 */
	public function history(): array {
		return $this->history;
	}

	/**
	 * Advances one step when the transition is legal.
	 */
	public function advance( string $to ): bool {
		$from_index = array_search( $this->state, self::ORDER, true );
		$to_index   = array_search( $to, self::ORDER, true );
		if ( false === $from_index || false === $to_index || $to_index !== $from_index + 1 ) {
			return false;
		}
		$this->state    = $to;
		$this->history[] = $to;
		return true;
	}

	/**
	 * Decides the failure response. Never mutates state on refusal.
	 *
	 * @param string               $failure_code Structured failure code.
	 * @param string               $policy       safe_once|never.
	 * @return array<string, mixed> {action: repair|rollback, reason, repair_code?}
	 */
	public function on_failure( string $failure_code, string $policy ): array {
		if ( in_array( $failure_code, self::NO_REPAIR_CODES, true ) ) {
			return [ 'action' => 'rollback', 'reason' => 'not_repairable', 'code' => $failure_code ];
		}

		if ( self::POLICY_SAFE_ONCE !== $policy ) {
			return [ 'action' => 'rollback', 'reason' => 'policy_forbids_repair', 'code' => $failure_code ];
		}

		if ( $this->repair_count >= 1 ) {
			return [ 'action' => 'rollback', 'reason' => 'repair_budget_spent', 'code' => $failure_code ];
		}

		if ( ! in_array( $failure_code, self::REPAIR_ALLOWLIST, true ) ) {
			return [ 'action' => 'rollback', 'reason' => 'outside_allowlist', 'code' => $failure_code ];
		}

		++$this->repair_count;
		$this->history[] = 'repair:' . $failure_code;
		return [ 'action' => 'repair', 'reason' => 'allowlisted_safe_once', 'code' => $failure_code ];
	}

	/**
	 * After a repair, verification restarts from the applied step.
	 */
	public function reset_to_applied(): void {
		if ( 0 === $this->repair_count ) {
			return;
		}
		$this->state    = 'applied';
		$this->history[] = 'reset:applied';
	}
}
