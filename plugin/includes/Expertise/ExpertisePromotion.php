<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Expertise;

/** Enforces evidence thresholds for candidate, verified, stable, stale, and retired states. */
final class ExpertisePromotion {

	/** @return array<string, mixed>|\WP_Error */
	public static function promote( string $pack_id, string $target, bool $maintainer_approved, string $approval_note ): array|\WP_Error {
		$pack = ExpertiseRegistry::get( $pack_id );
		if ( null === $pack ) {
			return new \WP_Error( 'stonewright_expertise_not_found', 'Expertise pack not found.' );
		}
		$current = (string) $pack['status'];
		$allowed = [ 'draft' => 'candidate', 'candidate' => 'verified', 'verified' => 'stable' ];
		if ( ! isset( $allowed[ $current ] ) || $allowed[ $current ] !== $target ) {
			return new \WP_Error( 'stonewright_expertise_transition_invalid', 'Promotion must follow draft → candidate → verified → stable.' );
		}
		$evaluation = ExpertiseEvaluator::evaluate( $pack_id, null, true );
		if ( $evaluation instanceof \WP_Error ) {
			return $evaluation;
		}
		$eligible = array_filter(
			ExpertiseStore::scorecards( $pack_id ),
			static fn( array $row ): bool => true === (bool) ( $row['compatible'] ?? false )
				&& true === (bool) ( $row['implementation_verified'] ?? false )
				&& (float) ( $row['curriculum_score'] ?? $row['score'] ?? 0 ) >= 90
				&& 0 === (int) ( $row['critical_failures'] ?? 0 )
				&& (string) ( $row['pack_hash'] ?? '' ) === (string) $pack['hash']
		);
		if ( 'verified' === $target && [] === $eligible ) {
			return new \WP_Error( 'stonewright_expertise_verification_gate', 'Verified requires a persisted compatible runtime scorecard with fixture, schema hash, editor, frontend, and readback evidence.', [ 'scorecard' => $evaluation ] );
		}
		$eligible_fingerprints = array_values( array_unique( array_filter( array_map( static fn( array $row ): string => (string) ( $row['runtime_fingerprint'] ?? '' ), $eligible ) ) ) );
		if ( 'verified' === $target ) {
			$pack['verified_runtime_fingerprints'] = $eligible_fingerprints;
		}
		if ( 'stable' === $target ) {
			$approval_note = sanitize_textarea_field( $approval_note );
			if ( count( $eligible_fingerprints ) < 2 && ( ! $maintainer_approved || '' === $approval_note ) ) {
				return new \WP_Error( 'stonewright_expertise_stability_gate', 'Stable requires two runtime fingerprints or explicit maintainer approval with a note.' );
			}
			$pack['verified_runtime_fingerprints'] = $eligible_fingerprints;
		}
		$pack['status']           = $target;
		$pack['last_verified_at'] = gmdate( DATE_ATOM );
		if ( ! ExpertiseStore::save_override( $pack ) ) {
			return new \WP_Error( 'stonewright_expertise_save_failed', 'Expertise pack override could not be saved.' );
		}
		return [ 'pack_id' => $pack_id, 'from' => $current, 'to' => $target, 'scorecard' => $evaluation, 'approval_note' => $approval_note ];
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function set_terminal_status( string $pack_id, string $target ): array|\WP_Error {
		if ( ! in_array( $target, [ 'stale', 'retired' ], true ) ) {
			return new \WP_Error( 'stonewright_expertise_status_invalid', 'Only stale or retired may be set directly.' );
		}
		$pack = ExpertiseRegistry::get( $pack_id );
		if ( null === $pack ) {
			return new \WP_Error( 'stonewright_expertise_not_found', 'Expertise pack not found.' );
		}
		$pack['status'] = $target;
		if ( ! ExpertiseStore::save_override( $pack ) ) {
			return new \WP_Error( 'stonewright_expertise_save_failed', 'Expertise pack override could not be saved.' );
		}
		return [ 'pack_id' => $pack_id, 'status' => $target ];
	}
}
