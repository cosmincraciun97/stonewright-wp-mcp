<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Knowledge\Lifecycle;

use Stonewright\WpMcp\Elementor\Schema\RuntimeFingerprint;
use Stonewright\WpMcp\Skills\Skills;
use Stonewright\WpMcp\Support\Json;

/** Site-local lifecycle for researched knowledge and candidate skills. */
final class CandidateRepository {

	private const STATUSES = [ 'candidate', 'verified', 'approved', 'stale', 'rejected' ];

	private const EVIDENCE_TYPES = [ 'official_docs', 'live_schema', 'fixture', 'user', 'design' ];

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create( array $input ): array|\WP_Error {
		global $wpdb;
		$normalized = self::normalize_input( $input );
		if ( $normalized instanceof \WP_Error ) {
			return $normalized;
		}

		$existing = self::get_by_fingerprint( (string) $normalized['semantic_fingerprint'] );
		if ( null !== $existing ) {
			$existing['deduplicated'] = true;
			return $existing;
		}

		$row               = $normalized;
		$row['skill_slug'] = '';
		$row['created_by'] = get_current_user_id();
		$result            = $wpdb->insert(
			CandidateTable::table_name(),
			$row,
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d' ]
		);
		if ( false === $result ) {
			return new \WP_Error( 'stonewright_knowledge_candidate_save_failed', 'The knowledge candidate could not be stored.' );
		}

		$id = (int) $wpdb->insert_id;
		if ( true === ( $input['create_draft_skill'] ?? true ) ) {
			$skill_slug = 'draft-' . self::slugify( (string) $normalized['topic'] ) . '-' . substr( (string) $normalized['semantic_fingerprint'], 0, 8 );
			$skill_id   = Skills::save( self::skill_payload( $normalized, $skill_slug, 'draft', 0 ) );
			if ( $skill_id > 0 ) {
				self::update( $id, [ 'skill_slug' => $skill_slug ] );
				$row['skill_slug'] = $skill_slug;
			}
		}

		$created = self::get( $id );
		self::prune();
		return null !== $created ? $created : array_merge( [ 'id' => $id ], self::decode_row( $row ) );
	}

	/**
	 * Delete only non-approved, expired or excess candidates.
	 */
	public static function prune( int $max_total = 500, int $max_per_topic = 25 ): int {
		global $wpdb;
		$table         = CandidateTable::table_name();
		$max_total     = max( 1, $max_total );
		$max_per_topic = max( 1, $max_per_topic );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- CandidateTable returns the prefix-owned table name; this query has no user values.
			"SELECT id, topic, status, expires_at, updated_at FROM {$table} WHERE status IN ('candidate','verified','stale','rejected') ORDER BY updated_at DESC",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$keep         = 0;
		$topic_counts = [];
		$delete_ids   = [];
		foreach ( $rows as $row ) {
			$id      = (int) ( $row['id'] ?? 0 );
			$topic   = (string) ( $row['topic'] ?? '' );
			$expired = strtotime( (string) ( $row['expires_at'] ?? '' ) . ' UTC' ) <= time();
			$topic_counts[ $topic ] = (int) ( $topic_counts[ $topic ] ?? 0 );
			if ( $id < 1 || $expired || $keep >= $max_total || $topic_counts[ $topic ] >= $max_per_topic ) {
				if ( $id > 0 ) {
					$delete_ids[] = $id;
				}
				continue;
			}
			++$keep;
			++$topic_counts[ $topic ];
		}
		if ( [] === $delete_ids ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $delete_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders}) AND status <> 'approved'", ...$delete_ids ) );
	}

	/** @return array<string, mixed>|null */
	public static function get( int $id ): ?array {
		global $wpdb;
		self::mark_expired();
		$table = CandidateTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
		return is_array( $row ) ? self::decode_row( $row ) : null;
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return list<array<string, mixed>>
	 */
	public static function list( array $filters = [] ): array {
		global $wpdb;
		self::mark_expired();
		$table  = CandidateTable::table_name();
		$status = (string) ( $filters['status'] ?? '' );
		$topic  = sanitize_text_field( (string) ( $filters['topic'] ?? '' ) );
		$limit  = max( 1, min( 100, (int) ( $filters['limit'] ?? 20 ) ) );
		$where  = [];
		$args   = [];
		if ( in_array( $status, self::STATUSES, true ) ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		if ( '' !== $topic ) {
			$where[] = 'topic = %s';
			$args[]  = $topic;
		}
		$sql = "SELECT * FROM {$table}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( [] !== $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql    .= ' ORDER BY updated_at DESC LIMIT %d';
		$args[]  = $limit;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		return array_values( array_map( [ self::class, 'decode_row' ], is_array( $rows ) ? $rows : [] ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function verify( int $id, string $task_id, string $runtime_fingerprint, bool $success ): array|\WP_Error {
		$candidate = self::get( $id );
		if ( null === $candidate ) {
			return new \WP_Error( 'stonewright_knowledge_candidate_not_found', 'Knowledge candidate not found.' );
		}
		if ( in_array( (string) $candidate['status'], [ 'approved', 'rejected' ], true ) ) {
			return new \WP_Error( 'stonewright_knowledge_candidate_locked', 'Approved or rejected candidates cannot be re-verified.' );
		}
		$task_id = sanitize_text_field( $task_id );
		if ( '' === $task_id || ( $success && ! preg_match( '/^[a-f0-9]{64}$/', $runtime_fingerprint ) ) ) {
			return new \WP_Error( 'stonewright_knowledge_verification_invalid', 'Provide a task id and an exact 64-character runtime fingerprint for successful verification.' );
		}

		$tasks        = array_values( array_unique( (array) $candidate['verification_task_ids'] ) );
		$fingerprints = (array) $candidate['verified_fingerprints'];
		$verified     = (int) $candidate['verification_count'];
		$failures     = (int) $candidate['failure_count'];
		$status       = (string) $candidate['status'];
		if ( $success ) {
			if ( ! in_array( $task_id, $tasks, true ) ) {
				$tasks[] = $task_id;
			}
			if ( ! in_array( $runtime_fingerprint, $fingerprints, true ) ) {
				$fingerprints[] = $runtime_fingerprint;
			}
			$verified = count( $tasks );
			$status   = 'verified';
		} else {
			++$failures;
			$status = 'candidate';
		}

		self::update(
			$id,
			[
				'verification_task_ids_json' => Json::encode( $tasks ),
				'verified_fingerprints_json' => Json::encode( array_values( array_unique( $fingerprints ) ) ),
				'verification_count'         => $verified,
				'failure_count'              => $failures,
				'status'                     => $status,
			]
		);
		return self::get( $id ) ?? $candidate;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function promote( int $id, bool $user_approved, string $approval_note, string $conflict_resolution = 'reject' ): array|\WP_Error {
		$candidate = self::get( $id );
		if ( null === $candidate ) {
			return new \WP_Error( 'stonewright_knowledge_candidate_not_found', 'Knowledge candidate not found.' );
		}
		if ( in_array( (string) $candidate['status'], [ 'stale', 'rejected' ], true ) ) {
			return new \WP_Error( 'stonewright_knowledge_candidate_not_promotable', 'Stale or rejected candidates must be researched again.' );
		}
		$approval_note = sanitize_textarea_field( $approval_note );
		if ( (int) $candidate['verification_count'] < 2 && ( ! $user_approved || '' === $approval_note ) ) {
			return new \WP_Error( 'stonewright_knowledge_promotion_gate', 'Promotion needs two verified successes or explicit user approval with a note.' );
		}

		$conflicts = array_values(
			array_filter(
				Skills::find_active_by_topic( (string) $candidate['topic'] ),
				static fn( array $skill ): bool => (string) ( $skill['semantic_fingerprint'] ?? '' ) !== (string) $candidate['semantic_fingerprint']
			)
		);
		if ( [] !== $conflicts && 'replace' !== $conflict_resolution ) {
			return new \WP_Error(
				'stonewright_knowledge_conflict',
				'An active skill covers this topic with different semantics. Choose replace explicitly or keep the current skill.',
				[ 'conflicts' => array_column( $conflicts, 'slug' ) ]
			);
		}
		foreach ( $conflicts as $conflict ) {
			Skills::save(
				array_merge(
					$conflict,
					[ 'enabled' => false, 'status' => 'stale', 'conflicts' => [ (string) $candidate['semantic_fingerprint'] ] ]
				)
			);
		}

		$slug    = '' !== (string) $candidate['skill_slug'] ? (string) $candidate['skill_slug'] : 'learned-' . self::slugify( (string) $candidate['topic'] );
		$payload = self::skill_payload( $candidate, $slug, 'active', (int) $candidate['verification_count'] );
		$lint    = Skills::lint( $payload );
		if ( [] !== $lint['errors'] ) {
			return new \WP_Error( 'stonewright_skill_lint_failed', 'The candidate skill failed promotion lint.', [ 'lint' => $lint ] );
		}
		$skill_id = Skills::save( $payload );
		if ( $skill_id < 1 ) {
			return new \WP_Error( 'stonewright_skill_promotion_failed', 'The candidate skill could not be activated.' );
		}

		self::update( $id, [ 'status' => 'approved', 'skill_slug' => $slug ] );
		return [
			'candidate'      => self::get( $id ),
			'skill_id'       => $skill_id,
			'skill_slug'     => $slug,
			'promotion_gate' => (int) $candidate['verification_count'] >= 2 ? 'verified_successes' : 'user_approval',
			'approval_note'  => $approval_note,
			'lint'           => $lint,
			'replaced'       => array_values( array_map( static fn( array $row ): string => (string) $row['slug'], $conflicts ) ),
		];
	}

	/** @return array<string, mixed>|\WP_Error */
	public static function set_status( int $id, string $status ): array|\WP_Error {
		if ( ! in_array( $status, [ 'stale', 'rejected' ], true ) ) {
			return new \WP_Error( 'stonewright_knowledge_status_invalid', 'Only stale or rejected can be set directly.' );
		}
		$candidate = self::get( $id );
		if ( null === $candidate ) {
			return new \WP_Error( 'stonewright_knowledge_candidate_not_found', 'Knowledge candidate not found.' );
		}
		self::update( $id, [ 'status' => $status ] );
		self::stale_linked_skill( $candidate, $status );
		return self::get( $id ) ?? [];
	}

	public static function invalidate_fingerprint( string $runtime_fingerprint ): int {
		global $wpdb;
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $runtime_fingerprint ) ) {
			return 0;
		}
		$table = CandidateTable::table_name();
		$like  = '%' . $wpdb->esc_like( $runtime_fingerprint ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN ('verified','approved') AND verified_fingerprints_json NOT LIKE %s", $like ), ARRAY_A );
		// Only exact-version verified knowledge is invalidated; generic candidates remain untouched.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'stale' WHERE status IN ('verified','approved') AND verified_fingerprints_json NOT LIKE %s", $like ) );
		foreach ( is_array( $affected ) ? $affected : [] as $candidate ) {
			self::stale_linked_skill( self::decode_row( $candidate ), 'stale' );
		}
		return $count;
	}

	public static function invalidate_on_elementor_change( mixed ...$event ): void {
		$summary = strtolower( wp_json_encode( $event ) ?: '' );
		if ( ! str_contains( $summary, 'elementor' ) ) {
			return;
		}
		$fingerprint = RuntimeFingerprint::describe();
		self::invalidate_fingerprint( (string) ( $fingerprint['hash'] ?? '' ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function normalize_input( array $input ): array|\WP_Error {
		$topic         = sanitize_text_field( (string) ( $input['topic'] ?? '' ) );
		$widget        = sanitize_key( (string) ( $input['widget'] ?? '' ) );
		$control       = sanitize_key( (string) ( $input['control'] ?? '' ) );
		$fact          = sanitize_textarea_field( (string) ( $input['fact'] ?? '' ) );
		$recipe        = sanitize_textarea_field( (string) ( $input['recipe'] ?? '' ) );
		$source_url    = esc_url_raw( (string) ( $input['source_url'] ?? '' ) );
		$source_hash   = strtolower( (string) ( $input['source_hash'] ?? '' ) );
		$evidence_type = (string) ( $input['evidence_type'] ?? '' );
		$constraints   = isset( $input['version_constraints'] ) && is_array( $input['version_constraints'] ) ? $input['version_constraints'] : [];
		if ( '' === $topic || ( '' === $fact && '' === $recipe ) || ! in_array( $evidence_type, self::EVIDENCE_TYPES, true ) ) {
			return new \WP_Error( 'stonewright_knowledge_candidate_invalid', 'topic, fact or recipe, and a valid evidence_type are required.' );
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $source_hash ) ) {
			return new \WP_Error( 'stonewright_knowledge_source_hash_invalid', 'source_hash must be a lowercase SHA-256 digest.' );
		}
		if ( 'official_docs' === $evidence_type && ! self::is_official_elementor_url( $source_url ) ) {
			return new \WP_Error( 'stonewright_knowledge_source_untrusted', 'Elementor documentation evidence must use an official elementor.com URL.' );
		}
		if ( ( '' !== $widget || str_contains( strtolower( $topic ), 'elementor' ) ) && [] === $constraints ) {
			return new \WP_Error( 'stonewright_knowledge_version_constraints_missing', 'Elementor knowledge requires explicit Core/Pro/add-on version constraints.' );
		}

		$fetched_at = self::normalize_date( (string) ( $input['fetched_at'] ?? '' ) );
		$expires_at = self::normalize_date( (string) ( $input['expires_at'] ?? gmdate( DATE_ATOM, time() + 30 * DAY_IN_SECONDS ) ) );
		if ( '' === $fetched_at || '' === $expires_at || strtotime( $expires_at . ' UTC' ) <= time() ) {
			return new \WP_Error( 'stonewright_knowledge_dates_invalid', 'Provide fetched_at and a future expires_at.' );
		}

		$fingerprint = hash(
			'sha256',
			Json::encode( [ strtolower( $topic ), $widget, $control, $fact, $recipe, $source_hash, $constraints ] )
		);
		return [
			'topic'                      => $topic,
			'widget'                     => $widget,
			'control_key'                => $control,
			'fact'                       => $fact,
			'recipe'                     => $recipe,
			'source_url'                 => $source_url,
			'source_hash'                => $source_hash,
			'fetched_at'                 => $fetched_at,
			'version_constraints_json'   => Json::encode( $constraints ),
			'evidence_type'              => $evidence_type,
			'confidence'                 => max( 0, min( 1, (float) ( $input['confidence'] ?? 0.5 ) ) ),
			'verification_task_ids_json' => '[]',
			'verified_fingerprints_json' => '[]',
			'verification_count'         => 0,
			'failure_count'              => 0,
			'expires_at'                 => $expires_at,
			'status'                     => 'candidate',
			'semantic_fingerprint'       => $fingerprint,
		];
	}

	/** @param array<string, mixed> $candidate @return array<string, mixed> */
	private static function skill_payload( array $candidate, string $slug, string $status, int $verification_count ): array {
		$topic       = (string) $candidate['topic'];
		$constraints = $candidate['version_constraints'] ?? self::decode_json( $candidate['version_constraints_json'] ?? '[]' );
		$content     = "# {$topic}\n\n## When to use\nUse this only when the live runtime matches the version constraints below.\n\n## Verified knowledge\n";
		if ( '' !== trim( (string) ( $candidate['fact'] ?? '' ) ) ) {
			$content .= trim( (string) $candidate['fact'] ) . "\n\n";
		}
		if ( '' !== trim( (string) ( $candidate['recipe'] ?? '' ) ) ) {
			$content .= "## Recipe\n" . trim( (string) $candidate['recipe'] ) . "\n\n";
		}
		$content .= "## Version and evidence\n- Constraints: `" . Json::encode( $constraints ) . "`\n- Source: " . (string) ( $candidate['source_url'] ?? '' ) . "\n- Revalidate against the live schema before every write.\n";

		return [
			'slug'                 => $slug,
			'title'                => $topic,
			'description'          => 'Use when implementing ' . $topic . ' on a matching verified runtime.',
			'content'              => $content,
			'enabled'              => 'active' === $status,
			'enable_agentic'       => 'active' === $status,
			'enable_prompt'        => 'active' === $status,
			'source'               => 'candidate',
			'status'               => $status,
			'topic'                => $topic,
			'semantic_fingerprint' => (string) $candidate['semantic_fingerprint'],
			'version_constraints'  => $constraints,
			'verification_count'   => $verification_count,
		];
	}

	/** @param array<string, mixed> $changes */
	private static function update( int $id, array $changes ): bool {
		global $wpdb;
		$result = $wpdb->update( CandidateTable::table_name(), $changes, [ 'id' => $id ] );
		return false !== $result;
	}

	/** @return array<string, mixed>|null */
	private static function get_by_fingerprint( string $fingerprint ): ?array {
		global $wpdb;
		$table = CandidateTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE semantic_fingerprint = %s LIMIT 1", $fingerprint ), ARRAY_A );
		return is_array( $row ) ? self::decode_row( $row ) : null;
	}

	private static function mark_expired(): void {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return;
		}
		/** @var \wpdb $wpdb */
		$table = CandidateTable::table_name();
		$now   = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE expires_at <= %s AND status IN ('candidate','verified','approved')", $now ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$candidate = self::decode_row( $row );
			if ( strtotime( (string) ( $candidate['expires_at'] ?? '' ) . ' UTC' ) > time() ) {
				continue;
			}
			self::update( (int) $candidate['id'], [ 'status' => 'stale' ] );
			self::stale_linked_skill( $candidate, 'stale' );
		}
	}

	/** @param array<string, mixed> $candidate */
	private static function stale_linked_skill( array $candidate, string $reason ): void {
		$slug = (string) ( $candidate['skill_slug'] ?? '' );
		if ( '' === $slug ) {
			return;
		}
		$skill = Skills::get( $slug );
		if ( null === $skill || 'active' !== (string) ( $skill['status'] ?? '' ) ) {
			return;
		}
		Skills::save( array_merge( $skill, [ 'enabled' => false, 'status' => 'stale', 'conflicts' => [ $reason ] ] ) );
	}

	/** @param array<string, mixed> $row @return array<string, mixed> */
	private static function decode_row( array $row ): array {
		$row['id']                    = (int) ( $row['id'] ?? 0 );
		$row['confidence']            = (float) ( $row['confidence'] ?? 0 );
		$row['verification_count']    = (int) ( $row['verification_count'] ?? 0 );
		$row['failure_count']         = (int) ( $row['failure_count'] ?? 0 );
		$row['version_constraints']   = self::decode_json( $row['version_constraints_json'] ?? '[]' );
		$row['verification_task_ids'] = self::decode_json( $row['verification_task_ids_json'] ?? '[]' );
		$row['verified_fingerprints'] = self::decode_json( $row['verified_fingerprints_json'] ?? '[]' );
		return $row;
	}

	private static function decode_json( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return $value;
		}
		try {
			return json_decode( (string) $value, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			return [];
		}
	}

	private static function normalize_date( string $value ): string {
		$timestamp = strtotime( $value );
		return false === $timestamp ? '' : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	private static function is_official_elementor_url( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return 'elementor.com' === $host || str_ends_with( $host, '.elementor.com' );
	}

	private static function slugify( string $value ): string {
		$normalized = preg_replace( '/[^A-Za-z0-9]+/', '-', $value ) ?? '';
		return trim( sanitize_title( $normalized ), '-' );
	}
}
