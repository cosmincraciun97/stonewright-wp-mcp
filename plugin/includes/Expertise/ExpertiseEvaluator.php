<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Expertise;

/** Deterministic curriculum audit plus explicit runtime-verification evidence. */
final class ExpertiseEvaluator {

	/** @param array<string, mixed>|null $runtime @return array<string, mixed>|\WP_Error */
	public static function evaluate( string $pack_id, ?array $runtime = null, bool $persist = false ): array|\WP_Error {
		$pack = ExpertiseRegistry::get( $pack_id );
		if ( null === $pack ) {
			return new \WP_Error( 'stonewright_expertise_not_found', 'Expertise pack not found.' );
		}
		$runtime ??= RuntimeContext::capture();
		$validation_errors = PackValidator::errors( $pack );
		$compatibility     = ExpertiseResolver::compatibility( $pack, $runtime );
		$results           = [];
		$critical_failures = 0;
		foreach ( (array) $pack['eval_cases'] as $case ) {
			$level  = (string) ( $case['level'] ?? '' );
			$passed = '' !== (string) ( $pack['workflow'][ $level ] ?? '' );
			if ( 'plan' === $level ) {
				$passed = $passed && in_array( 'semantic_action_resolution', (array) $pack['semantic_rules'], true ) && in_array( 'native_editability', (array) $pack['semantic_rules'], true );
			}
			if ( 'compile' === $level ) {
				$passed = $passed && in_array( 'live_schema_required', (array) $pack['anti_hallucination_gates'], true ) && in_array( 'unknown_setting_rejection', (array) $pack['anti_hallucination_gates'], true );
			}
			if ( 'write' === $level ) {
				$passed = $passed && [] === array_diff( [ 'permission', 'backup', 'audit', 'readback', 'rollback' ], (array) $pack['write_gates'] );
			}
			if ( 'verify' === $level ) {
				$passed = $passed && in_array( 'readback', (array) $pack['write_gates'], true );
			}
			if ( 'repair' === $level ) {
				$passed = $passed && in_array( 'minimal_repair', (array) $pack['semantic_rules'], true );
			}
			if ( 'negative' === (string) ( $case['type'] ?? '' ) ) {
				$passed = $passed && in_array( 'no_silent_fallback', (array) $pack['anti_hallucination_gates'], true );
			}
			if ( 'learn' === $level ) {
				$passed = $passed && str_contains( strtolower( (string) $pack['workflow']['learn'] ), 'verified' ) && str_contains( (string) $pack['workflow']['learn'], 'KnowledgeCandidate' );
			}
			if ( ! $passed && (bool) ( $case['critical'] ?? false ) ) {
				++$critical_failures;
			}
			$results[] = [ 'id' => (string) ( $case['id'] ?? '' ), 'type' => (string) ( $case['type'] ?? '' ), 'passed' => $passed, 'critical' => (bool) ( $case['critical'] ?? false ) ];
		}
		$passed_count = count( array_filter( $results, static fn( array $result ): bool => $result['passed'] ) );
		$total        = count( $results );
		$score        = $total > 0 ? round( 100 * $passed_count / $total, 2 ) : 0.0;
		if ( [] !== $validation_errors ) {
			$score = 0.0;
			$critical_failures += count( $validation_errors );
		}
		$evidence        = is_array( $runtime['verification_evidence'] ?? null ) ? $runtime['verification_evidence'] : [];
		$evidence_errors = self::evidence_errors( $evidence );
		$report = [
			'pack_id'             => (string) $pack['id'],
			'pack_version'        => (string) $pack['version'],
			'pack_hash'           => (string) $pack['hash'],
			'pack_status'         => (string) $pack['status'],
			'runtime_fingerprint' => (string) ( $runtime['fingerprint'] ?? '' ),
			'compatible'          => $compatibility['compatible'],
			'incompatibilities'   => $compatibility['reasons'],
			'evaluation_kind'     => 'curriculum_contract',
			'score'               => $score,
			'curriculum_score'    => $score,
			'implementation_verified' => [] === $evidence_errors,
			'verification_evidence'   => [] === $evidence_errors ? $evidence : null,
			'evidence_errors'         => $evidence_errors,
			'critical_failures'   => $critical_failures,
			'validation_errors'   => $validation_errors,
			'cases_total'         => $total,
			'cases_passed'        => $passed_count,
			'case_results'        => $results,
			'metrics'             => [
				'invalid_retries'       => 0,
				'estimated_tokens'      => (int) ceil( strlen( wp_json_encode( $pack ) ?: '' ) / 4 ),
				'tool_calls'            => count( (array) $pack['required_capabilities'] ),
				'editability'           => in_array( 'native_editability', (array) $pack['semantic_rules'], true ) ? 1.0 : 0.0,
				'semantic_completeness' => in_array( 'semantic_action_resolution', (array) $pack['semantic_rules'], true ) ? 1.0 : 0.0,
				'rollback'              => in_array( 'rollback', (array) $pack['write_gates'], true ) ? 1.0 : 0.0,
			],
		];
		if ( $persist ) {
			$report['scorecard_id'] = ExpertiseStore::record_scorecard( $report );
		}
		return $report;
	}

	/** @param array<string, mixed> $evidence @return list<string> */
	private static function evidence_errors( array $evidence ): array {
		$errors = [];
		foreach ( [ 'task_id', 'fixture_id' ] as $field ) {
			if ( '' === trim( (string) ( $evidence[ $field ] ?? '' ) ) ) {
				$errors[] = 'missing:' . $field;
			}
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $evidence['schema_hash'] ?? '' ) ) ) {
			$errors[] = 'invalid:schema_hash';
		}
		foreach ( [ 'editor_verified', 'frontend_verified', 'readback_verified' ] as $field ) {
			if ( true !== ( $evidence[ $field ] ?? false ) ) {
				$errors[] = 'required_true:' . $field;
			}
		}
		return $errors;
	}

	/** @return array<string, mixed> */
	public static function evaluate_all( bool $persist = false ): array {
		$runtime = RuntimeContext::capture();
		$reports = [];
		foreach ( ExpertiseRegistry::all() as $pack ) {
			$result = self::evaluate( (string) $pack['id'], $runtime, $persist );
			if ( is_array( $result ) ) {
				$reports[] = $result;
			}
		}
		return [
			'packs'             => $reports,
			'count'             => count( $reports ),
			'below_90'          => array_values( array_map( static fn( array $row ): string => (string) $row['pack_id'], array_filter( $reports, static fn( array $row ): bool => (float) $row['score'] < 90 ) ) ),
			'critical_failures' => array_sum( array_map( static fn( array $row ): int => (int) $row['critical_failures'], $reports ) ),
			'implementation_unverified' => array_values( array_map( static fn( array $row ): string => (string) $row['pack_id'], array_filter( $reports, static fn( array $row ): bool => ! (bool) $row['implementation_verified'] ) ) ),
		];
	}
}
