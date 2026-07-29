<?php
/**
 * Runtime enforcement side of the shared native rule registry.
 *
 * `GlobalRules` says which rules are runtime-enforced and which guard enforces
 * them. This class is how a guard says "rule X stopped this call" in a way the
 * audit log, the error-pattern tracker, and the client can all read.
 *
 * Two entry points, deliberately different:
 *
 * - `violation()` builds a fresh rule-violation error. Use it when the guard has
 *   no established error identity of its own.
 * - `attribute()` stamps rule provenance onto an existing guard error. Use it
 *   when the error code is already part of the client contract; renaming those
 *   codes would break callers that branch on them.
 *
 * Both refuse rule ids that are not runtime-enforced. A rule that PHP cannot
 * mechanically stop must never produce an error claiming it was stopped.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use InvalidArgumentException;

/**
 * Builds and stamps rule-violation errors.
 */
final class RuleEnforcer {

	/**
	 * Error code for a violation the guard raises on its own.
	 *
	 * `ErrorPatterns` treats it as an expected safety block: a guard doing its
	 * job is not an incident to learn from.
	 */
	public const ERROR_CODE = 'stonewright_rule_violation';

	/**
	 * Keys a caller may never set. Diagnostics are merged underneath these, so
	 * an ability cannot downgrade its own block to a retryable soft failure.
	 *
	 * @var list<string>
	 */
	public const CANONICAL_KEYS = [
		'status',
		'execution_status',
		'verification_status',
		'retryable',
		'error_code',
		'cause_key',
		'rule_id',
		'rule_severity',
		'rule_guard',
	];

	/**
	 * Fresh rule-violation error.
	 *
	 * @param string               $rule_id     Runtime-enforced rule id.
	 * @param string               $detail      What the caller did, in one sentence.
	 * @param array<string, mixed> $diagnostics Extra context; cannot override canonical keys.
	 * @throws InvalidArgumentException When the rule is unknown or not runtime-enforced.
	 */
	public static function violation( string $rule_id, string $detail, array $diagnostics = [] ): \WP_Error {
		$rule = self::runtime_rule( $rule_id );

		$message = trim( $rule['rule'] . ' ' . trim( $detail ) );

		return new \WP_Error(
			self::ERROR_CODE,
			$message,
			array_merge(
				$diagnostics,
				[
					'status'              => 409,
					'execution_status'    => 'blocked',
					'verification_status' => 'blocked',
					'retryable'           => false,
					'error_code'          => 'rule_violation',
					'cause_key'           => 'rule:' . $rule_id,
					'rule_id'             => $rule_id,
					'rule_severity'       => $rule['severity'],
					'rule_guard'          => $rule['enforcement']['guard'],
				]
			)
		);
	}

	/**
	 * Stamp rule provenance onto an existing guard error.
	 *
	 * Code, message, and HTTP status stay as the guard set them — clients branch
	 * on those. Effect status is forced to `blocked` because a guard rejection is
	 * a safety block, not a server error, and the audit severity follows from it.
	 *
	 * @param \WP_Error $error   Guard error to annotate.
	 * @param string    $rule_id Runtime-enforced rule id.
	 * @throws InvalidArgumentException When the rule is unknown or not runtime-enforced.
	 */
	public static function attribute( \WP_Error $error, string $rule_id ): \WP_Error {
		$rule = self::runtime_rule( $rule_id );

		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : [];

		$data['rule_id']             = $rule_id;
		$data['rule_severity']       = $rule['severity'];
		$data['rule_guard']          = $rule['enforcement']['guard'];
		$data['execution_status']    = 'blocked';
		$data['verification_status'] = 'blocked';
		$data['retryable']           = false;

		// An existing cause key already groups a known incident class; keep it.
		if ( ! isset( $data['cause_key'] ) || '' === (string) $data['cause_key'] ) {
			$data['cause_key'] = 'rule:' . $rule_id;
		}

		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	/**
	 * @return array{id: string, severity: string, scope: string, rule: string, why: string, enforcement: array{kind: string, guard: string}}
	 * @throws InvalidArgumentException When the rule is unknown or not runtime-enforced.
	 */
	private static function runtime_rule( string $rule_id ): array {
		$rule = GlobalRules::get( $rule_id );

		if ( null === $rule ) {
			throw new InvalidArgumentException(
				sprintf( 'Unknown rule id "%s"; it is not in the global rule registry.', $rule_id )
			);
		}

		if ( 'runtime' !== $rule['enforcement']['kind'] ) {
			throw new InvalidArgumentException(
				sprintf(
					'Rule "%s" is not runtime-enforced, so no guard may claim it blocked a call.',
					$rule_id
				)
			);
		}

		return $rule;
	}
}
