<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Human approval for turning researched knowledge into guidance that agents follow.
 *
 * Promoting a knowledge candidate to an active skill, or an expertise pack to a verified or stable
 * pack, changes the instructions future agents receive. The evidence behind a promotion is recorded
 * by the plugin, but the decision belongs to a person. A promotion therefore proceeds only with a
 * grant that an administrator issued in wp-admin:
 *
 * 1. The promotion is requested. The plugin stages one pending request that names the exact subject,
 *    the content hash of that subject and the target state, and returns the approval link.
 * 2. An administrator reviews the request on the Skills screen and approves or rejects it. Approving
 *    needs `manage_options` and a nonce; no ability exposes it.
 * 3. The next promotion call for that same subject, hash and target spends the grant. A grant works
 *    once, expires after one hour and applies to nothing else.
 */
final class PromotionGrant {

	public const OPTION = 'stonewright_promotion_requests';

	public const KIND_KNOWLEDGE = 'knowledge_candidate';
	public const KIND_EXPERTISE = 'expertise_pack';

	/** How long a request waits for a decision. */
	public const REQUEST_TTL = 7 * DAY_IN_SECONDS;

	/** How long an approved request can still be spent. */
	public const GRANT_TTL = HOUR_IN_SECONDS;

	/** The newest requests that are kept. */
	private const MAX_ROWS = 50;

	private const KINDS = [ self::KIND_KNOWLEDGE, self::KIND_EXPERTISE ];

	/**
	 * Stage a pending request for one promotion. Repeating the same request returns the one that is still open.
	 *
	 * @param array{title?: string, details?: array<string, string>} $summary What the approver sees. Bounded and stripped of markup here.
	 * @param bool $requires_note True when the evidence alone is thin, so the approver must say why they approve.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function request( string $kind, string $subject, string $subject_hash, string $target, array $summary = [], bool $requires_note = false ): array|\WP_Error {
		$binding = self::binding( $kind, $subject, $subject_hash, $target );
		if ( $binding instanceof \WP_Error ) {
			return $binding;
		}

		$rows = self::rows();
		foreach ( $rows as $row ) {
			if ( $row['binding'] !== $binding ) {
				continue;
			}
			if ( 'rejected' === $row['status'] ) {
				return self::fail( 'stonewright_promotion_rejected', __( 'An administrator rejected this promotion.', 'stonewright' ) );
			}
			if ( 'pending' === $row['status'] || ( 'approved' === $row['status'] && (int) $row['grant_expires_at'] >= time() ) ) {
				return self::public_row( $row );
			}
		}

		$now        = time();
		$request_id = wp_generate_uuid4();
		$row        = [
			'request_id'       => $request_id,
			'binding'          => $binding,
			'kind'             => $kind,
			'subject'          => $subject,
			'subject_hash'     => strtolower( $subject_hash ),
			'target'           => sanitize_key( $target ),
			'summary'          => self::bounded_summary( $summary ),
			'requires_note'    => $requires_note,
			'status'           => 'pending',
			'requested_by'     => get_current_user_id(),
			'requested_at'     => $now,
			'expires_at'       => $now + self::REQUEST_TTL,
			'decided_by'       => 0,
			'decided_at'       => 0,
			'note'             => '',
			'grant_expires_at' => 0,
		];

		$rows[ $request_id ] = $row;
		self::save( $rows );

		return self::public_row( $row );
	}

	/**
	 * Approve a pending request. Administrators only; called from the nonce-checked admin action.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function approve( string $request_id, string $note = '' ): array|\WP_Error {
		return self::decide( $request_id, 'approved', $note );
	}

	/**
	 * Reject a pending request. Administrators only.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function reject( string $request_id ): array|\WP_Error {
		return self::decide( $request_id, 'rejected', '' );
	}

	/**
	 * Spend the grant for one promotion. Succeeds once, for an approved and unexpired request that names exactly this
	 * subject, hash and target.
	 *
	 * @return array<string, mixed>|\WP_Error The spent request, with the note the approver gave.
	 */
	public static function consume( string $kind, string $subject, string $subject_hash, string $target ): array|\WP_Error {
		$binding = self::binding( $kind, $subject, $subject_hash, $target );
		if ( $binding instanceof \WP_Error ) {
			return $binding;
		}

		$rows   = self::rows();
		$newest = null;
		foreach ( $rows as $id => $row ) {
			if ( $row['binding'] !== $binding ) {
				continue;
			}
			if ( null === $newest || (int) $row['requested_at'] >= (int) $newest['requested_at'] ) {
				$newest = $row;
			}
			if ( 'approved' === $row['status'] && (int) $row['grant_expires_at'] >= time() ) {
				$rows[ $id ]['status']      = 'consumed';
				$rows[ $id ]['consumed_at'] = time();
				self::save( $rows );
				return self::public_row( $rows[ $id ] );
			}
		}

		return match ( null === $newest ? '' : (string) $newest['status'] ) {
			'rejected' => self::fail( 'stonewright_promotion_rejected', __( 'An administrator rejected this promotion.', 'stonewright' ) ),
			'consumed' => self::fail( 'stonewright_promotion_grant_used', __( 'The approval for this promotion was already used. Request it again.', 'stonewright' ) ),
			'approved' => self::fail( 'stonewright_promotion_grant_expired', __( 'The approval for this promotion expired. Request it again.', 'stonewright' ) ),
			default    => self::fail( 'stonewright_promotion_approval_required', __( 'An administrator has not approved this promotion.', 'stonewright' ) ),
		};
	}

	/**
	 * Spend the grant for a promotion, or stage the request and tell the caller to stop.
	 *
	 * @param array{title?: string, details?: array<string, string>} $summary
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function require_grant( string $kind, string $subject, string $subject_hash, string $target, array $summary = [], bool $requires_note = false ): array|\WP_Error {
		$grant = self::consume( $kind, $subject, $subject_hash, $target );
		if ( is_array( $grant ) || 'stonewright_promotion_rejected' === $grant->get_error_code() || 'stonewright_promotion_invalid' === $grant->get_error_code() ) {
			return $grant;
		}

		$request = self::request( $kind, $subject, $subject_hash, $target, $summary, $requires_note );
		if ( $request instanceof \WP_Error ) {
			return $request;
		}

		return new \WP_Error(
			'stonewright_promotion_approval_required',
			__( 'Promoting this to guidance that agents follow needs an administrator. Show the user the approval link and stop. Do not approve it yourself.', 'stonewright' ),
			[
				'status'            => 403,
				'retryable'         => false,
				'execution_status'  => 'blocked',
				'approval_required' => true,
				'agent_must_stop'   => true,
				'request_id'        => $request['request_id'],
				'approval_url'      => $request['approval_url'],
			]
		);
	}

	/**
	 * Requests that wait for a decision, newest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function pending(): array {
		$out = [];
		foreach ( self::rows() as $row ) {
			if ( 'pending' === $row['status'] ) {
				$out[] = self::public_row( $row );
			}
		}
		usort( $out, static fn( array $a, array $b ): int => (int) $b['requested_at'] <=> (int) $a['requested_at'] );

		return $out;
	}

	public static function approval_url( string $request_id = '' ): string {
		$args = [
			'page' => 'stonewright-skills',
			'view' => 'catalog',
		];
		if ( '' !== $request_id ) {
			$args['promotion'] = $request_id;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function decide( string $request_id, string $decision, string $note ): array|\WP_Error {
		$operator = get_current_user_id();
		if ( $operator <= 0 || ! current_user_can( 'manage_options' ) ) {
			return self::fail( 'stonewright_promotion_forbidden', __( 'Only administrators can decide a promotion.', 'stonewright' ), 403 );
		}

		$rows = self::rows();
		$row  = $rows[ $request_id ] ?? null;
		if ( null === $row ) {
			return self::fail( 'stonewright_promotion_not_found', __( 'This promotion request was not found or expired.', 'stonewright' ), 404 );
		}
		if ( 'pending' !== $row['status'] ) {
			return self::fail( 'stonewright_promotion_not_pending', __( 'This promotion request was already decided.', 'stonewright' ) );
		}

		$note = mb_substr( trim( sanitize_textarea_field( $note ) ), 0, 500 );
		if ( 'approved' === $decision && $row['requires_note'] && '' === $note ) {
			return self::fail( 'stonewright_promotion_note_required', __( 'The evidence for this promotion is thin. Say why you approve it.', 'stonewright' ) );
		}

		$now = time();
		$rows[ $request_id ]['status']     = $decision;
		$rows[ $request_id ]['decided_by'] = $operator;
		$rows[ $request_id ]['decided_at'] = $now;
		$rows[ $request_id ]['note']       = $note;
		if ( 'approved' === $decision ) {
			$rows[ $request_id ]['grant_expires_at'] = $now + self::GRANT_TTL;
		}
		self::save( $rows );

		return self::public_row( $rows[ $request_id ] );
	}

	/** @return string|\WP_Error */
	private static function binding( string $kind, string $subject, string $subject_hash, string $target ): string|\WP_Error {
		$target = sanitize_key( $target );
		if (
			! in_array( $kind, self::KINDS, true )
			|| '' === $subject
			|| mb_strlen( $subject ) > 190
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', strtolower( $subject_hash ) )
			|| '' === $target
		) {
			return self::fail( 'stonewright_promotion_invalid', __( 'The promotion names no valid subject.', 'stonewright' ) );
		}

		return hash( 'sha256', implode( '|', [ $kind, $subject, strtolower( $subject_hash ), $target ] ) );
	}

	/**
	 * Stored requests without those that expired, newest 50 only.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function rows(): array {
		$stored = get_option( self::OPTION, [] );
		$now    = time();
		$rows   = [];
		foreach ( is_array( $stored ) ? $stored : [] as $id => $row ) {
			if ( is_array( $row ) && isset( $row['binding'], $row['status'] ) && (int) ( $row['expires_at'] ?? 0 ) > $now ) {
				$rows[ (string) $id ] = $row;
			}
		}
		uasort( $rows, static fn( array $a, array $b ): int => (int) $b['requested_at'] <=> (int) $a['requested_at'] );

		return array_slice( $rows, 0, self::MAX_ROWS, true );
	}

	/** @param array<string, array<string, mixed>> $rows */
	private static function save( array $rows ): void {
		update_option( self::OPTION, $rows, false );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function public_row( array $row ): array {
		unset( $row['binding'] );
		$row['approval_url'] = self::approval_url( (string) $row['request_id'] );

		return $row;
	}

	/**
	 * @param array<string, mixed> $summary
	 * @return array{title: string, details: array<string, string>}
	 */
	private static function bounded_summary( array $summary ): array {
		$details = [];
		foreach ( is_array( $summary['details'] ?? null ) ? array_slice( $summary['details'], 0, 8, true ) : [] as $label => $value ) {
			if ( is_scalar( $value ) ) {
				$details[ mb_substr( sanitize_text_field( (string) $label ), 0, 60 ) ] = mb_substr( sanitize_text_field( (string) $value ), 0, 300 );
			}
		}

		return [
			'title'   => mb_substr( sanitize_text_field( (string) ( $summary['title'] ?? '' ) ), 0, 200 ),
			'details' => $details,
		];
	}

	private static function fail( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			[
				'status'              => $status,
				'retryable'           => false,
				'execution_status'    => 'blocked',
				'verification_status' => 'blocked',
			]
		);
	}
}
