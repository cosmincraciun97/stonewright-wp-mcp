<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Skills;

use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Support\Json;

/**
 * Static CRUD helpers for the stonewright_skills table.
 *
 * @stonewright-status stable
 */
final class Skills {

	/**
	 * List all skills, optionally filtering to enabled only.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list( bool $enabled_only = false ): array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return [];
		}

		$table = SkillsTable::table_name();

		if ( $enabled_only ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE enabled = 1 ORDER BY source ASC, title ASC", ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY source ASC, title ASC", ARRAY_A );
		}

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_map( [ self::class, 'normalize_row' ], $rows );
	}

	/**
	 * List skills that should be included in automatic agentic matching.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_agentic(): array {
		return array_values(
			array_filter(
				self::list( true ),
				static fn( array $skill ): bool => 'active' === (string) ( $skill['status'] ?? 'active' )
					&& (bool) ( $skill['enable_agentic'] ?? true )
			)
		);
	}

	/**
	 * List skills that should be exposed as explicit prompt/command entries.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_prompt(): array {
		return array_values(
			array_filter(
				self::list( true ),
				static fn( array $skill ): bool => 'active' === (string) ( $skill['status'] ?? 'active' )
					&& (bool) ( $skill['enable_prompt'] ?? true )
			)
		);
	}

	/**
	 * Get a single skill by slug. Returns null if not found.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get( string $slug ): ?array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return null;
		}

		$table = SkillsTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s LIMIT 1", $slug ), ARRAY_A );

		return is_array( $row ) ? self::normalize_row( $row ) : null;
	}

	/**
	 * Get a single skill by ID. Returns null if not found.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_by_id( int $id ): ?array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return null;
		}

		$table = SkillsTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), ARRAY_A );

		return is_array( $row ) ? self::normalize_row( $row ) : null;
	}

	/**
	 * Insert or update a skill by slug (upsert).
	 *
	 * @param array<string, mixed> $data Skill fields and lifecycle metadata.
	 * @return int The id of the inserted or updated row. 0 on failure.
	 */
	public static function save( array $data ): int {
		global $wpdb;

		$slug = sanitize_title( (string) ( $data['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return 0;
		}

		$existing = self::table_exists() ? self::get( $slug ) : null;
		$now      = current_time( 'mysql', true );

		$default_enabled = null !== $existing ? (bool) ( $existing['enabled'] ?? true ) : true;
		$enabled         = isset( $data['enabled'] ) ? (int) (bool) $data['enabled'] : (int) $default_enabled;
		$status          = self::sanitize_status(
			(string) ( $data['status'] ?? ( null !== $existing ? ( $existing['status'] ?? 'active' ) : ( $enabled ? 'active' : 'draft' ) ) )
		);
		if ( 'active' !== $status ) {
			$enabled = 0;
		}
		$revision = null !== $existing ? (int) ( $existing['revision'] ?? 1 ) + 1 : 1;

		$row = [
			'slug'           => $slug,
			'title'          => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'description'    => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'content'        => (string) ( $data['content'] ?? '' ),
			'enabled'        => $enabled,
			'enable_agentic' => isset( $data['enable_agentic'] )
							? (int) (bool) $data['enable_agentic']
							: $enabled,
			'enable_prompt'  => isset( $data['enable_prompt'] )
							? (int) (bool) $data['enable_prompt']
							: $enabled,
			'source'         => in_array( $data['source'] ?? 'user', [ 'builtin', 'user', 'uploaded', 'candidate' ], true )
							? (string) ( $data['source'] ?? 'user' )
							: 'user',
			'status'         => $status,
			'topic'          => sanitize_text_field( (string) ( $data['topic'] ?? ( $existing['topic'] ?? '' ) ) ),
			'semantic_fingerprint' => self::sanitize_fingerprint( (string) ( $data['semantic_fingerprint'] ?? ( $existing['semantic_fingerprint'] ?? '' ) ) ),
			'version_constraints_json' => Json::encode( $data['version_constraints'] ?? self::decode_json_field( $existing['version_constraints_json'] ?? '[]' ) ),
			'verification_count' => max( 0, (int) ( $data['verification_count'] ?? ( $existing['verification_count'] ?? 0 ) ) ),
			'revision'       => $revision,
			'conflict_json'  => Json::encode( $data['conflicts'] ?? self::decode_json_field( $existing['conflict_json'] ?? '[]' ) ),
			'updated_at'     => $now,
		];

		$table = SkillsTable::table_name();

		if ( null !== $existing ) {
			self::record_version( $existing );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->update( $table, $row, [ 'slug' => $slug ] );
			return (int) $existing['id'];
		}

		$row['created_at'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( $table, $row );

		return (int) $wpdb->insert_id;
	}

	/** @return list<array<string, mixed>> */
	public static function find_active_by_topic( string $topic ): array {
		$topic = sanitize_text_field( $topic );
		return array_values(
			array_filter(
				self::list( true ),
				static fn( array $skill ): bool => 'active' === (string) ( $skill['status'] ?? 'active' )
					&& strtolower( (string) ( $skill['topic'] ?? '' ) ) === strtolower( $topic )
			)
		);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array{errors:list<string>,warnings:list<string>,word_count:int}
	 */
	public static function lint( array $data ): array {
		$errors      = [];
		$warnings    = [];
		$description = trim( (string) ( $data['description'] ?? '' ) );
		$content     = trim( (string) ( $data['content'] ?? '' ) );
		$topic       = strtolower( (string) ( $data['topic'] ?? '' ) );
		$constraints = $data['version_constraints'] ?? [];
		$word_count  = str_word_count( strip_tags( $content ) );
		$status      = (string) ( $data['status'] ?? 'draft' );
		$expires_at  = strtotime( (string) ( $data['expires_at'] ?? '' ) );

		if ( '' === $description || ! str_contains( strtolower( $description ), 'use when' ) ) {
			$errors[] = 'description_must_state_use_when_trigger';
		}
		if ( '' === $content ) {
			$errors[] = 'content_missing';
		}
		if ( str_contains( $topic, 'elementor' ) && ( ! is_array( $constraints ) || [] === $constraints ) ) {
			$errors[] = 'elementor_version_constraints_missing';
		}
		if ( 'stale' === $status || ( false !== $expires_at && $expires_at <= time() ) ) {
			$errors[] = 'stale_reference';
		}
		if ( [] !== (array) ( $data['conflicts'] ?? [] ) && '' === (string) ( $data['conflict_resolution'] ?? '' ) ) {
			$errors[] = 'unresolved_conflicts';
		}
		if ( preg_match_all( '/stonewright\/[a-z0-9-]+/', $content, $matches ) ) {
			$available = [];
			foreach ( AbilityRegistry::list() as $class ) {
				if ( class_exists( $class ) ) {
					$available[] = ( new $class() )->name();
				}
			}
			foreach ( array_unique( $matches[0] ) as $reference ) {
				if ( ! in_array( $reference, $available, true ) ) {
					$errors[] = 'missing_tool_reference:' . $reference;
				}
			}
		}
		if ( $word_count < 200 || $word_count > 800 ) {
			$warnings[] = 'body_target_is_200_to_800_words';
		}

		return [ 'errors' => array_values( array_unique( $errors ) ), 'warnings' => $warnings, 'word_count' => $word_count ];
	}

	/** @return list<array<string, mixed>> */
	public static function history( string $slug ): array {
		global $wpdb;
		$skill = self::get( $slug );
		if ( null === $skill ) {
			return [];
		}
		$table = SkillVersionsTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT revision, snapshot_json, created_at FROM {$table} WHERE skill_id = %d ORDER BY revision DESC", (int) $skill['id'] ), ARRAY_A );
		return array_values(
			array_map(
				static fn( array $row ): array => [
					'revision'   => (int) ( $row['revision'] ?? 0 ),
					'snapshot'   => self::decode_json_field( $row['snapshot_json'] ?? '[]' ),
					'created_at' => (string) ( $row['created_at'] ?? '' ),
				],
				is_array( $rows ) ? $rows : []
			)
		);
	}

	public static function rollback( string $slug, int $revision ): bool {
		$skill = self::get( $slug );
		if ( null === $skill || $revision < 1 ) {
			return false;
		}
		foreach ( self::history( $slug ) as $entry ) {
			if ( $revision !== (int) $entry['revision'] || ! is_array( $entry['snapshot'] ) ) {
				continue;
			}
			$snapshot         = $entry['snapshot'];
			$snapshot['slug'] = $slug;
			return self::save( $snapshot ) > 0;
		}
		return false;
	}

	/**
	 * Enable or disable a skill by ID.
	 */
	public static function toggle( int $id, bool $enabled ): bool {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return false;
		}

		$table = SkillsTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->update( $table, [ 'enabled' => (int) $enabled ], [ 'id' => $id ] );

		return false !== $result;
	}

	/**
	 * Delete a skill by ID.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return false;
		}
		$skill = self::get_by_id( $id );
		if ( ! $skill || 'builtin' === (string) ( $skill['source'] ?? '' ) ) {
			return false;
		}

		$table = SkillsTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->delete( $table, [ 'id' => $id ] );

		return false !== $result && $result > 0;
	}

	/**
	 * Build the Markdown block injected into MCP server instructions.
	 * Returns an empty string when no enabled skills exist.
	 */
	public static function instructions_block(): string {
		$skills = self::list_agentic();

		if ( empty( $skills ) ) {
			return '';
		}

		$lines = [
			'',
			'## Site Skills',
			'',
			'The following enabled site skills are available as short routing hints. To keep token usage low, only this index is injected.',
			'Before acting, compare the task with these descriptions. If a skill applies, call `stonewright/skills-get` with its slug and follow the returned playbook.',
			'',
		];

		foreach ( $skills as $skill ) {
			$slug        = (string) ( $skill['slug'] ?? '' );
			$title       = (string) ( $skill['title'] ?: $slug );
			$description = trim( (string) ( $skill['description'] ?? '' ) );
			$summary     = '' !== $description ? ' - ' . $description : '';

			$lines[] = sprintf( '- `%s` - %s%s', $slug, $title, $summary );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Check whether the skills table exists in the DB.
	 *
	 * In unit-test environments where wpdb is an anonymous-class stub (not a
	 * real \wpdb instance), we fall back to checking the stub's get_var result
	 * directly rather than running SHOW TABLES. This avoids cross-test static
	 * cache pollution because anonymous-class stubs return null from get_var,
	 * letting each test set its own wpdb mock independently.
	 */
	private static function table_exists(): bool {
		global $wpdb;

		// If wpdb is not a real wpdb instance (e.g. anonymous-class stub in
		// unit tests), ask its get_var() directly — it'll return null when the
		// stub says "no table" or a truthy value when the stub says "yes table".
		if ( ! ( $wpdb instanceof \wpdb ) ) {
			$table = SkillsTable::table_name();
			return null !== $wpdb->get_var( '' );
		}

		$table = SkillsTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Normalise DB rows created before skill mode flags existed.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function normalize_row( array $row ): array {
		$enabled = (bool) ( $row['enabled'] ?? true );
		$row['status'] = self::sanitize_status( (string) ( $row['status'] ?? ( $enabled ? 'active' : 'draft' ) ) );
		$row['revision'] = max( 1, (int) ( $row['revision'] ?? 1 ) );
		$row['verification_count'] = max( 0, (int) ( $row['verification_count'] ?? 0 ) );
		$row['version_constraints'] = self::decode_json_field( $row['version_constraints_json'] ?? '[]' );
		$row['conflicts']           = self::decode_json_field( $row['conflict_json'] ?? '[]' );

		if ( ! array_key_exists( 'enable_agentic', $row ) ) {
			$row['enable_agentic'] = $enabled ? '1' : '0';
		}
		if ( ! array_key_exists( 'enable_prompt', $row ) ) {
			$row['enable_prompt'] = $enabled ? '1' : '0';
		}

		return $row;
	}

	/** @param array<string, mixed> $skill */
	private static function record_version( array $skill ): void {
		global $wpdb;
		if ( empty( $skill['id'] ) ) {
			return;
		}
		$wpdb->insert(
			SkillVersionsTable::table_name(),
			[
				'skill_id'      => (int) $skill['id'],
				'revision'      => max( 1, (int) ( $skill['revision'] ?? 1 ) ),
				'snapshot_json' => Json::encode( $skill ),
				'created_by'    => get_current_user_id(),
			],
			[ '%d', '%d', '%s', '%d' ]
		);
	}

	private static function sanitize_status( string $status ): string {
		return in_array( $status, [ 'draft', 'active', 'stale', 'rejected' ], true ) ? $status : 'draft';
	}

	private static function sanitize_fingerprint( string $fingerprint ): string {
		$fingerprint = strtolower( preg_replace( '/[^a-f0-9]/', '', $fingerprint ) ?? '' );
		return 64 === strlen( $fingerprint ) ? $fingerprint : '';
	}

	private static function decode_json_field( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return $value;
		}
		try {
			return json_decode( (string) $value, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			return [];
		}
	}
}
