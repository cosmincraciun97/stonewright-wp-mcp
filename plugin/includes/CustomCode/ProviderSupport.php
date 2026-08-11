<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\CustomCode;

use Stonewright\WpMcp\Security\CustomCodeGrant;
use Stonewright\WpMcp\Security\PhpSyntaxValidator;
use Stonewright\WpMcp\Security\ThemeWriteTransaction;

/**
 * Shared dry-run / hash / validation helpers for custom-code providers.
 */
final class ProviderSupport {

	public const DEFAULT_MAX_CHANGED_BYTES = 65536;

	/**
	 * @return true|\WP_Error
	 */
	public static function validate_code( string $code, string $language ) {
		$language = strtolower( $language );
		if ( 'php' === $language ) {
			return PhpSyntaxValidator::validate_complete_file( $code );
		}
		return ThemeWriteTransaction::validate_candidate( $code, $language );
	}

	public static function content_hash( string $code ): string {
		return hash( 'sha256', $code );
	}

	public static function changed_bytes( string $before, string $after ): int {
		return ThemeWriteTransaction::changed_bytes( $before, $after );
	}

	/**
	 * Build a dry-run handoff that stages a human approval proposal when content changed.
	 *
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function dry_run_handoff(
		string $provider_id,
		string $target_path,
		string $language,
		string $before,
		string $after,
		array $extra = []
	) {
		$before_hash   = self::content_hash( $before );
		$after_hash    = self::content_hash( $after );
		$changed       = ! hash_equals( $before_hash, $after_hash );
		$changed_bytes = self::changed_bytes( $before, $after );
		$max_bytes     = max( 1, (int) ( $extra['max_changed_bytes'] ?? self::DEFAULT_MAX_CHANGED_BYTES ) );

		$validation = self::validate_code( $after, $language );
		if ( $validation instanceof \WP_Error ) {
			return $validation;
		}

		if ( $changed && $changed_bytes > $max_bytes ) {
			return new \WP_Error(
				'stonewright_custom_code_size_exceeded',
				__( 'Candidate change exceeds the configured max_changed_bytes budget.', 'stonewright' ),
				[
					'status'          => 400,
					'changed_bytes'   => $changed_bytes,
					'max_changed_bytes' => $max_bytes,
					'provider'        => $provider_id,
				]
			);
		}

		$proposal = null;
		if ( $changed ) {
			$proposal = CustomCodeGrant::stage_proposal(
				[
					'path'              => $target_path,
					'language'          => $language,
					'before_sha256'     => $before_hash,
					'after_sha256'      => $after_hash,
					'changed_bytes'     => $changed_bytes,
					'max_changed_bytes' => $max_bytes,
					'risk_class'        => (string) ( $extra['risk_class'] ?? ( 'custom_code_' . $provider_id ) ),
					'native_gap'        => is_array( $extra['native_gap'] ?? null ) ? $extra['native_gap'] : [
						'reason'        => 'Provider-managed snippet/code surface; typed native controls unavailable for this target.',
						'methods_tried' => [ 'typed_api' ],
					],
					'diff_preview'      => [
						'changed_lines' => max( 1, substr_count( $after, "\n" ) - substr_count( $before, "\n" ) ),
						'preview'       => mb_substr( $before !== $after ? '(content changed; body redacted from audit)' : '', 0, 200 ),
					],
					'test_plan'         => is_array( $extra['test_plan'] ?? null ) ? $extra['test_plan'] : [
						'Validate syntax for the candidate language.',
						'Apply only with a human-issued single-use grant.',
						'Verify readback hash and roll back on mismatch.',
					],
					'rollback_plan'     => (string) ( $extra['rollback_plan'] ?? 'Restore the provider-record snapshot taken before write.' ),
				]
			);
			if ( $proposal instanceof \WP_Error ) {
				return $proposal;
			}
		}

		return array_merge(
			[
				'ok'                  => true,
				'dry_run'             => true,
				'provider'            => $provider_id,
				'target'              => $target_path,
				'path'                => $target_path,
				'language'            => $language,
				'changed'             => $changed,
				'before_bytes'        => strlen( $before ),
				'after_bytes'         => strlen( $after ),
				'changed_bytes'       => $changed_bytes,
				'before_sha256'       => $before_hash,
				'after_sha256'        => $after_hash,
				'change_summary'      => sprintf(
					'%s %s: %d → %d bytes (%+d)',
					$provider_id,
					$target_path,
					strlen( $before ),
					strlen( $after ),
					strlen( $after ) - strlen( $before )
				),
				'approval_required'   => $changed,
				'approval_url'        => is_array( $proposal ) ? (string) $proposal['approval_url'] : '',
				'proposal_id'         => is_array( $proposal ) ? (string) $proposal['proposal_id'] : '',
				'proposal_expires_at' => is_array( $proposal ) ? (string) $proposal['expires_at'] : '',
				'agent_must_stop'     => $changed,
				'operator_action'     => $changed
					? 'Human reviews the proposal in wp-admin, issues the one-time grant, and sends the token back. Do not open the approval page unless the user explicitly asks.'
					: 'No approval needed because the candidate is unchanged.',
				'rollback_scope'      => (string) ( $extra['rollback_scope'] ?? 'provider_record' ),
				'execution_status'    => 'ok',
				'verification_status' => 'dry_run',
				'rollback_status'     => 'not_needed',
				'effect_verified'     => true,
				'operation_class'     => 'custom_code',
				'resource_type'       => 'custom_code_' . sanitize_key( $provider_id ),
				'resource_ref'        => $target_path,
			],
			$extra['response_extra'] ?? []
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function consume_grant( string $token, string $path, string $after_hash, string $language, int $changed_bytes ) {
		if ( '' === trim( $token ) ) {
			return new \WP_Error(
				'stonewright_custom_code_grant_required',
				__( 'Custom code apply requires a human-issued one-time grant after dry_run. Show approval_url, path, hashes, and summary, then stop.', 'stonewright' ),
				array_merge(
					[ 'status' => 400, 'retryable' => false ],
					CustomCodeGrant::missing_grant_proposal(
						[
							'path'          => $path,
							'after_sha256'  => $after_hash,
							'language'      => $language,
							'changed_bytes' => $changed_bytes,
						]
					)
				)
			);
		}
		return CustomCodeGrant::verify_and_consume( $token, $path, $after_hash, $language, $changed_bytes );
	}

	/**
	 * Snapshot payload for provider records (hashes only in audit; full body kept in transient/option).
	 *
	 * @return array{snapshot_id:string,path:string,before_sha256:string,body:string,provider:string,target_id:string}
	 */
	public static function snapshot_record( string $provider, string $target_id, string $path, string $body ): array {
		$snapshot_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : substr( hash( 'sha256', uniqid( 'sw-snap-', true ) ), 0, 36 );
		$payload     = [
			'snapshot_id'   => $snapshot_id,
			'provider'      => $provider,
			'target_id'     => $target_id,
			'path'          => $path,
			'before_sha256' => self::content_hash( $body ),
			'body'          => $body,
			'created_at'    => time(),
		];
		set_transient( 'sw_cc_snap_' . $snapshot_id, $payload, DAY_IN_SECONDS );
		return [
			'snapshot_id'   => $snapshot_id,
			'path'          => $path,
			'before_sha256' => $payload['before_sha256'],
			'body'          => $body,
			'provider'      => $provider,
			'target_id'     => $target_id,
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function load_snapshot( string $snapshot_id ): ?array {
		$stored = get_transient( 'sw_cc_snap_' . $snapshot_id );
		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * Optimistic concurrency: live hash must match expected before_sha256 when provided.
	 *
	 * @return true|\WP_Error
	 */
	public static function assert_expected_hash( string $live, string $expected_before_hash, string $path ) {
		$expected_before_hash = strtolower( trim( $expected_before_hash ) );
		if ( '' === $expected_before_hash ) {
			return true;
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_before_hash ) ) {
			return new \WP_Error(
				'stonewright_custom_code_concurrency_invalid',
				__( 'expected_before_sha256 must be a 64-char hex sha256.', 'stonewright' ),
				[ 'status' => 400, 'path' => $path ]
			);
		}
		$live_hash = self::content_hash( $live );
		if ( ! hash_equals( $expected_before_hash, $live_hash ) ) {
			return new \WP_Error(
				'stonewright_custom_code_concurrency_conflict',
				__( 'Live provider record changed since dry_run. Re-read, dry_run again, then re-apply.', 'stonewright' ),
				[
					'status'                 => 409,
					'path'                   => $path,
					'expected_before_sha256' => $expected_before_hash,
					'live_sha256'            => $live_hash,
					'retryable'              => false,
				]
			);
		}
		return true;
	}
}
