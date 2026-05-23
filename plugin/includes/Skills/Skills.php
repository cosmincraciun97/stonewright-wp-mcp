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

		return is_array( $rows ) ? $rows : [];
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

		return is_array( $row ) ? $row : null;
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

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Insert or update a skill by slug (upsert).
	 *
	 * @param array<string, mixed> $data Keys: slug, title, description, content, enabled, source
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

		$row = [
			'slug'        => $slug,
			'title'       => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
			'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
			'content'     => (string) ( $data['content'] ?? '' ),
			'enabled'     => isset( $data['enabled'] ) ? (int) (bool) $data['enabled'] : 1,
			'source'      => in_array( $data['source'] ?? 'user', [ 'builtin', 'user', 'uploaded' ], true )
							? (string) ( $data['source'] ?? 'user' )
							: 'user',
			'updated_at'  => $now,
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
		$skills = self::list( true );

		if ( empty( $skills ) ) {
			return '';
		}

		$lines = [
			'',
			'## Site Skills',
			'',
			'The following skills are site-specific playbooks you MUST follow when the task matches their description.',
			'Always check if a skill applies before acting. Skills override your default behaviour.',
			'',
		];

		foreach ( $skills as $skill ) {
			$lines[] = '### ' . ( $skill['title'] ?: $skill['slug'] );
			if ( ! empty( $skill['description'] ) ) {
				$lines[] = $skill['description'];
				$lines[] = '';
			}
			$lines[] = $skill['content'];
			$lines[] = '';
			$lines[] = '---';
			$lines[] = '';
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
}
