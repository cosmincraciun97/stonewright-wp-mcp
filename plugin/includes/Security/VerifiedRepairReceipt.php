<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Builds a bounded repair receipt from persisted incident and audit evidence.
 */
final class VerifiedRepairReceipt {

	private const MAX_RECIPE_LENGTH = 500;
	private const MIN_RECIPE_LENGTH = 24;

	/**
	 * @param array<string, mixed> $incident
	 * @param array<string, mixed> $failure
	 * @param array<string, mixed> $success
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function from_events( array $incident, array $failure, array $success, string $recipe ): array|\WP_Error {
		$category = strtoupper( self::text( $incident['category'] ?? '', 32 ) );
		if ( in_array( $category, [ AuditEvent::CATEGORY_AUTH, AuditEvent::CATEGORY_PERMISSION, AuditEvent::CATEGORY_SAFETY ], true ) ) {
			return new \WP_Error(
				'stonewright_repair_not_learnable',
				__( 'Authentication, permission, and expected safety events cannot become repair learning.', 'stonewright' )
			);
		}

		if ( AuditEvent::OUTCOME_SUCCESS !== (string) ( $success['outcome'] ?? '' )
			|| 'verified' !== strtolower( (string) ( $success['verification_status'] ?? '' ) )
			|| true !== ( $success['effect_verified'] ?? false ) ) {
			return self::uncorrelated();
		}

		$change_set = self::matching_text( $failure, $success, 'change_set_id', 96 );
		$resource   = self::matching_hash( $failure, $success, 'resource_key_hash' );
		$path       = self::matching_text( $failure, $success, 'normalized_path', 255 );
		if ( '' === $change_set || '' === $resource || '' === $path ) {
			return self::uncorrelated();
		}

		$expected_verifier = self::text( $incident['expected_verifier'] ?? '', 190 );
		$verifier          = self::text( $success['ability'] ?? '', 190 );
		if ( '' !== $expected_verifier && $expected_verifier !== $verifier ) {
			return self::uncorrelated();
		}

		$failure_time = max(
			self::timestamp( $failure['recorded_at'] ?? '' ),
			self::timestamp( $incident['last_seen'] ?? '' )
		);
		$success_time = self::timestamp( $success['recorded_at'] ?? '' );
		if ( 0 === $failure_time || $success_time <= $failure_time ) {
			return self::uncorrelated();
		}

		$incident_id = self::hash( $incident['incident_id'] ?? '' );
		$event_id    = self::uuid( $success['event_id'] ?? '' );
		if ( '' === $incident_id || '' === $event_id ) {
			return self::uncorrelated();
		}

		$clean_recipe = self::scrub_recipe( $recipe );
		if ( $clean_recipe instanceof \WP_Error ) {
			return $clean_recipe;
		}

		$receipt_id = hash( 'sha256', implode( '|', [ $incident_id, $event_id, $change_set, $resource, $path ] ) );

		return [
			'incident_id'          => $incident_id,
			'repair_receipt_id'    => $receipt_id,
			'resolution_event_id'  => $event_id,
			'verification_status'  => 'verified',
			'effect_verified'      => true,
			'change_set_id'        => $change_set,
			'resource_key_hash'    => $resource,
			'normalized_path'      => $path,
			'repair_recipe'        => $clean_recipe,
			'repair_scope'         => self::text( $incident['ability_family'] ?? $incident['root_error_code'] ?? '', 190 ),
			'learning_eligible'    => '' !== $clean_recipe,
			'evidence'             => [
				'after_sha256' => self::hash( $success['after_sha256'] ?? '' ),
				'verifier'     => $verifier,
			],
		];
	}

	public static function scrub_recipe( string $recipe ): string|\WP_Error {
		$recipe = trim( sanitize_textarea_field( $recipe ) );
		if ( '' === $recipe ) {
			return '';
		}

		$unsafe = mb_strlen( $recipe ) > self::MAX_RECIPE_LENGTH
			|| mb_strlen( $recipe ) < self::MIN_RECIPE_LENGTH
			|| count( preg_split( '/\s+/u', $recipe ) ?: [] ) < 5
			|| 1 === preg_match( '~https?://|www\.~iu', $recipe )
			|| 1 === preg_match( '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', $recipe )
			|| 1 === preg_match( '#(?:^|\s)(?:/var/|/home/|/Users/|[A-Z]:\\\\|~/|wp-content/)#iu', $recipe )
			|| 1 === preg_match( '/\b(?:authorization|bearer|password|passwd|secret|api[_ -]?key|access[_ -]?token|refresh[_ -]?token|cookie)\b/iu', $recipe )
			|| 1 === preg_match( '/\b(?:post|page|user|site|widget|element|resource)\s*(?:id)?\s*[:#]?\s*\d{2,}\b/iu', $recipe );

		if ( $unsafe ) {
			return new \WP_Error(
				'stonewright_repair_recipe_unsafe',
				__( 'Repair recipe must be concise, reusable, and free of private or resource-specific data.', 'stonewright' )
			);
		}

		return $recipe;
	}

	private static function uncorrelated(): \WP_Error {
		return new \WP_Error(
			'stonewright_repair_uncorrelated',
			__( 'Stored failure and verifier events do not prove the same repaired change.', 'stonewright' )
		);
	}

	/** @param array<string, mixed> $left @param array<string, mixed> $right */
	private static function matching_text( array $left, array $right, string $key, int $length ): string {
		$a = self::text( $left[ $key ] ?? '', $length );
		$b = self::text( $right[ $key ] ?? '', $length );
		return '' !== $a && hash_equals( $a, $b ) ? $a : '';
	}

	/** @param array<string, mixed> $left @param array<string, mixed> $right */
	private static function matching_hash( array $left, array $right, string $key ): string {
		$a = self::hash( $left[ $key ] ?? '' );
		$b = self::hash( $right[ $key ] ?? '' );
		return '' !== $a && hash_equals( $a, $b ) ? $a : '';
	}

	private static function text( mixed $value, int $length ): string {
		return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $length ) : '';
	}

	private static function hash( mixed $value ): string {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	private static function uuid( mixed $value ): string {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value ) ? $value : '';
	}

	private static function timestamp( mixed $value ): int {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return 0;
		}
		$timestamp = strtotime( (string) $value . ' UTC' );
		return false === $timestamp ? 0 : $timestamp;
	}
}
