<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Expertise;

/** Enforces the compact ExpertisePack 1.0 contract. */
final class PackValidator {

	private const STATUSES = [ 'draft', 'candidate', 'verified', 'stable', 'stale', 'retired' ];

	/** @param array<string, mixed> $pack @return list<string> */
	public static function errors( array $pack ): array {
		$errors  = [];
		$required = [ 'id', 'domain', 'capability', 'version', 'status', 'trigger', 'supported_versions', 'required_capabilities', 'workflow', 'official_refs', 'recipes', 'failure_modes', 'semantic_rules', 'anti_hallucination_gates', 'write_gates', 'eval_cases', 'provenance' ];
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $pack ) || '' === $pack[ $field ] || [] === $pack[ $field ] ) {
				$errors[] = 'missing:' . $field;
			}
		}
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]+$/', (string) ( $pack['id'] ?? '' ) ) ) {
			$errors[] = 'invalid:id';
		}
		if ( ! in_array( (string) ( $pack['status'] ?? '' ), self::STATUSES, true ) ) {
			$errors[] = 'invalid:status';
		}
		if ( str_word_count( (string) ( $pack['trigger'] ?? '' ) ) > 60 ) {
			$errors[] = 'budget:trigger_over_60_words';
		}
		if ( count( (array) ( $pack['eval_cases'] ?? [] ) ) < 12 ) {
			$errors[] = 'coverage:minimum_12_evals';
		}
		foreach ( [ 'discover', 'inspect', 'plan', 'compile', 'write', 'verify', 'repair', 'learn' ] as $level ) {
			if ( '' === (string) ( $pack['workflow'][ $level ] ?? '' ) ) {
				$errors[] = 'workflow:' . $level;
			}
		}
		foreach ( [ 'permission', 'backup', 'audit', 'readback', 'rollback' ] as $gate ) {
			if ( ! in_array( $gate, (array) ( $pack['write_gates'] ?? [] ), true ) ) {
				$errors[] = 'write_gate:' . $gate;
			}
		}
		return array_values( array_unique( $errors ) );
	}

	/** @param array<string, mixed> $pack */
	public static function is_valid( array $pack ): bool {
		return [] === self::errors( $pack );
	}
}
