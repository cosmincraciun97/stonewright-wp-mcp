<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Quality;

/**
 * Bounded per-post ledger of rendered quality reports.
 *
 * A quality report is only useful later if it says what it described: which
 * page, which direction revision, and which render. That is the whole reason
 * this store exists rather than the report being returned once and forgotten.
 *
 * Storage is a single post meta row per page. That choice sets the rest of the
 * design: the ledger is capped at {@see self::MAX_REPORTS} entries newest first,
 * findings are capped per entry, and nothing large or sensitive is accepted at
 * all — no screenshots, no markup, no credentials. A quality report is numbers
 * and rule ids.
 *
 * The store refuses malformed input instead of repairing it. A report whose
 * hashes are missing cannot be tied to anything, and an entry nobody can trust
 * is worse than no entry.
 */
final class QualityReportStore {

	/**
	 * Post meta key holding the whole ledger for one post.
	 */
	public const META_KEY = '_stonewright_quality_reports';

	/**
	 * Error code for every refusal in this class.
	 */
	public const ERROR_CODE = 'stonewright_quality_report_invalid';

	/**
	 * Maximum reports retained per post. Older entries are dropped on save.
	 */
	public const MAX_REPORTS = 20;

	/**
	 * Maximum findings retained per stored report.
	 */
	public const MAX_FINDINGS = 200;

	/**
	 * Default number of entries returned by {@see self::latest()}.
	 */
	public const DEFAULT_LIMIT = 10;

	/**
	 * Report statuses the evaluator can produce.
	 */
	private const STATUSES = [ 'pass', 'warn', 'fail', 'not_checked' ];

	/**
	 * Accepted keys on an incoming report envelope.
	 */
	private const REPORT_KEYS = [
		'schema_version',
		'status',
		'coverage',
		'findings',
		'truncated_findings',
		'direction_revision',
		'direction_hash',
		'render_hash',
	];

	/**
	 * Envelope keys that must be present.
	 */
	private const REQUIRED_KEYS = [
		'status',
		'coverage',
		'findings',
		'direction_revision',
		'direction_hash',
		'render_hash',
	];

	/**
	 * Accepted keys on a single finding. Anything else — markup, screenshots,
	 * raw CSS — is refused rather than trimmed away.
	 */
	private const FINDING_KEYS = [
		'rule_id',
		'severity',
		'viewport',
		'element_ref',
		'evidence',
		'repair_hint',
		'waived',
		'waiver_reason',
	];

	/**
	 * Accepted keys on the coverage block.
	 */
	private const COVERAGE_KEYS = [ 'checked', 'not_checked', 'not_checked_rules' ];

	/**
	 * Stores one report against a post and returns its report id.
	 *
	 * @param array<string, mixed> $report Report envelope: the evaluator report
	 *                                     plus direction_revision, direction_hash
	 *                                     and render_hash.
	 * @return string|\WP_Error Report id, or an error describing the refusal.
	 */
	public static function save( int $post_id, int $direction_id, array $report ) {
		if ( $post_id <= 0 || null === get_post( $post_id ) ) {
			return self::error( sprintf( 'Post %d does not exist, so a quality report cannot be attached to it.', $post_id ) );
		}

		if ( $direction_id <= 0 ) {
			return self::error( 'A quality report must name the design direction it was measured against.' );
		}

		$checked = self::check_envelope( $report );
		if ( $checked instanceof \WP_Error ) {
			return $checked;
		}

		$ledger   = self::ledger( $post_id );
		$sequence = 0;
		foreach ( $ledger as $entry ) {
			$sequence = max( $sequence, (int) ( $entry['sequence'] ?? 0 ) );
		}
		++$sequence;

		$findings  = array_values( $checked['findings'] );
		$truncated = (int) ( $checked['truncated_findings'] ?? 0 );
		if ( count( $findings ) > self::MAX_FINDINGS ) {
			$truncated += count( $findings ) - self::MAX_FINDINGS;
			$findings   = array_slice( $findings, 0, self::MAX_FINDINGS );
		}

		$created_at = current_time( 'mysql', true );
		$report_id  = self::report_id( $post_id, $direction_id, $sequence, $created_at, $checked );

		$entry = [
			'report_id'          => $report_id,
			'sequence'           => $sequence,
			'post_id'            => $post_id,
			'direction_id'       => $direction_id,
			'direction_revision' => (int) $checked['direction_revision'],
			'direction_hash'     => (string) $checked['direction_hash'],
			'render_hash'        => (string) $checked['render_hash'],
			'created_at'         => $created_at,
			'schema_version'     => (string) ( $checked['schema_version'] ?? QualityEvaluator::SCHEMA_VERSION ),
			'status'             => (string) $checked['status'],
			'coverage'           => $checked['coverage'],
			'findings'           => $findings,
			'truncated_findings' => $truncated,
		];

		array_unshift( $ledger, $entry );
		$ledger = array_slice( $ledger, 0, self::MAX_REPORTS );

		update_post_meta( $post_id, self::META_KEY, $ledger );

		return $report_id;
	}

	/**
	 * Returns the newest reports for a post, newest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function latest( int $post_id, int $limit = self::DEFAULT_LIMIT ): array {
		if ( $limit <= 0 ) {
			return [];
		}

		return array_slice( self::ledger( $post_id ), 0, min( $limit, self::MAX_REPORTS ) );
	}

	/**
	 * Returns one stored report, or null when this post has no such report.
	 *
	 * The post id is part of the lookup on purpose: a report id from one page
	 * must not resolve through another page.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function find( int $post_id, string $report_id ): ?array {
		foreach ( self::ledger( $post_id ) as $entry ) {
			if ( (string) ( $entry['report_id'] ?? '' ) === $report_id ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Reads the stored ledger, discarding anything that is not a list of entries.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function ledger( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return [];
		}

		$stored = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$entries = [];
		foreach ( $stored as $entry ) {
			if ( is_array( $entry ) && isset( $entry['report_id'] ) ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Validates an incoming envelope and returns it unchanged on success.
	 *
	 * @param array<string, mixed> $report Report envelope.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function check_envelope( array $report ) {
		$unknown = array_diff( array_keys( $report ), self::REPORT_KEYS );
		if ( [] !== $unknown ) {
			return self::error(
				sprintf(
					'A quality report cannot carry %s. Reports store numbers and rule ids, never markup, screenshots, or secrets.',
					implode( ', ', $unknown )
				)
			);
		}

		foreach ( self::REQUIRED_KEYS as $key ) {
			if ( ! array_key_exists( $key, $report ) ) {
				return self::error( sprintf( 'A quality report requires %s.', $key ) );
			}
		}

		if ( ! in_array( $report['status'], self::STATUSES, true ) ) {
			return self::error(
				sprintf(
					'Report status must be one of %s.',
					implode( ', ', self::STATUSES )
				)
			);
		}

		foreach ( [ 'direction_hash', 'render_hash' ] as $key ) {
			if ( ! is_string( $report[ $key ] ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $report[ $key ] ) ) {
				return self::error( sprintf( '%s must be a sha256 hex digest so the report can be matched to what it describes.', $key ) );
			}
		}

		if ( ! is_int( $report['direction_revision'] ) || $report['direction_revision'] < 1 ) {
			return self::error( 'direction_revision must be a positive integer.' );
		}

		if ( isset( $report['truncated_findings'] ) && ( ! is_int( $report['truncated_findings'] ) || $report['truncated_findings'] < 0 ) ) {
			return self::error( 'truncated_findings must be a non-negative integer.' );
		}

		$coverage = self::check_coverage( $report['coverage'] );
		if ( $coverage instanceof \WP_Error ) {
			return $coverage;
		}

		if ( ! is_array( $report['findings'] ) ) {
			return self::error( 'findings must be a list.' );
		}

		foreach ( $report['findings'] as $index => $finding ) {
			if ( ! is_array( $finding ) ) {
				return self::error( sprintf( 'Finding %s must be an object.', (string) $index ) );
			}

			$unknown = array_diff( array_keys( $finding ), self::FINDING_KEYS );
			if ( [] !== $unknown ) {
				return self::error(
					sprintf(
						'Finding %1$s cannot carry %2$s. A stored finding is a rule id, a viewport, an element reference, and the numbers behind it.',
						(string) $index,
						implode( ', ', $unknown )
					)
				);
			}

			if ( ! isset( $finding['rule_id'] ) || ! is_string( $finding['rule_id'] ) || '' === $finding['rule_id'] ) {
				return self::error( sprintf( 'Finding %s requires a rule_id.', (string) $index ) );
			}
		}

		return $report;
	}

	/**
	 * @param mixed $coverage Coverage block from the evaluator.
	 * @return true|\WP_Error
	 */
	private static function check_coverage( $coverage ) {
		if ( ! is_array( $coverage ) ) {
			return self::error( 'coverage must be an object.' );
		}

		$unknown = array_diff( array_keys( $coverage ), self::COVERAGE_KEYS );
		if ( [] !== $unknown ) {
			return self::error( sprintf( 'coverage cannot carry %s.', implode( ', ', $unknown ) ) );
		}

		foreach ( [ 'checked', 'not_checked' ] as $key ) {
			if ( ! isset( $coverage[ $key ] ) || ! is_int( $coverage[ $key ] ) || $coverage[ $key ] < 0 ) {
				return self::error( sprintf( 'coverage.%s must be a non-negative integer.', $key ) );
			}
		}

		return true;
	}

	/**
	 * Derives a stable, collision-free report id.
	 *
	 * The per-post sequence is part of the input so two identical reports saved
	 * in the same second still get distinct ids.
	 *
	 * @param array<string, mixed> $report Validated envelope.
	 */
	private static function report_id( int $post_id, int $direction_id, int $sequence, string $created_at, array $report ): string {
		$material = wp_json_encode(
			[
				'post_id'            => $post_id,
				'direction_id'       => $direction_id,
				'direction_revision' => (int) $report['direction_revision'],
				'direction_hash'     => (string) $report['direction_hash'],
				'render_hash'        => (string) $report['render_hash'],
				'created_at'         => $created_at,
				'sequence'           => $sequence,
			]
		);

		return substr( hash( 'sha256', (string) $material ), 0, 32 );
	}

	private static function error( string $message ): \WP_Error {
		return new \WP_Error( self::ERROR_CODE, $message, [ 'status' => 400 ] );
	}
}
