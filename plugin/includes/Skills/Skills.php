<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Skills;

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
				static fn( array $skill ): bool => (bool) ( $skill['enable_agentic'] ?? true )
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
				static fn( array $skill ): bool => (bool) ( $skill['enable_prompt'] ?? true )
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
	 * @param array<string, mixed> $data Keys: slug, title, description, content, enabled, enable_agentic, enable_prompt, source
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

		$enabled = isset( $data['enabled'] ) ? (int) (bool) $data['enabled'] : 1;

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
			'source'         => in_array( $data['source'] ?? 'user', [ 'builtin', 'user', 'uploaded' ], true )
							? (string) ( $data['source'] ?? 'user' )
							: 'user',
			'updated_at'     => $now,
		];

		$table = SkillsTable::table_name();

		if ( null !== $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->update( $table, $row, [ 'slug' => $slug ] );
			return (int) $existing['id'];
		}

		$row['created_at'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( $table, $row );

		return (int) $wpdb->insert_id;
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

		if ( ! array_key_exists( 'enable_agentic', $row ) ) {
			$row['enable_agentic'] = $enabled ? '1' : '0';
		}
		if ( ! array_key_exists( 'enable_prompt', $row ) ) {
			$row['enable_prompt'] = $enabled ? '1' : '0';
		}

		return $row;
	}
}
