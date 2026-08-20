<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Skills;

use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\SensitiveContent;
use Stonewright\WpMcp\Support\Json;
use Stonewright\WpMcp\Support\Logger;

/**
 * Static CRUD helpers for the stonewright_skills table.
 *
 * @stonewright-status stable
 */
final class Skills {

	/** Status a trashed skill carries. Trashed skills never reach an agent. */
	public const STATUS_TRASHED = 'trashed';

	/** Ability name a hard delete is confirmed against in production-safe mode. */
	public const DESTROY_ABILITY = 'stonewright/skills-delete';

	/** Sources Stonewright ships. The site may disable these, never remove them. */
	private const PROTECTED_SOURCES = [ 'builtin', 'playbook' ];

	/**
	 * List all skills, optionally filtering to enabled only.
	 *
	 * Trashed skills are excluded here. Use list_trashed() to see them.
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
			$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE enabled = 1 AND status <> 'trashed' ORDER BY source ASC, title ASC", ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE status <> 'trashed' ORDER BY source ASC, title ASC", ARRAY_A );
		}

		if ( ! is_array( $rows ) ) {
			return [];
		}

		// The WHERE clause is the fast path; this is the one that decides.
		return array_values(
			array_filter(
				array_map( [ self::class, 'normalize_row' ], $rows ),
				static fn( array $row ): bool => self::STATUS_TRASHED !== (string) ( $row['status'] ?? '' )
			)
		);
	}

	/**
	 * List trashed skills, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_trashed(): array {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return [];
		}

		$table = SkillsTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'trashed' ORDER BY trashed_at DESC, title ASC", ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_values(
			array_filter(
				array_map( [ self::class, 'normalize_row' ], $rows ),
				static fn( array $row ): bool => self::STATUS_TRASHED === (string) ( $row['status'] ?? '' )
			)
		);
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
					&& self::runtime_visible( $skill )
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
					&& self::runtime_visible( $skill )
			)
		);
	}

	/**
	 * Hide agent-facing skills whose version constraints are not satisfied.
	 *
	 * @param array<string, mixed> $skill
	 */
	public static function runtime_visible( array $skill ): bool {
		$constraints = $skill['version_constraints'] ?? [];
		if ( ! is_array( $constraints ) || [] === $constraints ) {
			return true;
		}

		return \Stonewright\WpMcp\Elementor\Schema\RuntimeFingerprint::matches_constraints( $constraints );
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
		return self::save_internal( $data, false );
	}

	/**
	 * Insert a packaged built-in skill or playbook.
	 *
	 * Packaged files pass the public-hygiene release gate. Keeping this path
	 * separate prevents generic security prose from being mistaken for a live
	 * credential while user-supplied writes remain guarded.
	 *
	 * @param array<string, mixed> $data Packaged skill fields.
	 * @return int The id of the inserted or updated row. 0 on failure.
	 */
	public static function save_packaged( array $data ): int {
		$source = (string) ( $data['source'] ?? '' );
		if ( ! in_array( $source, [ 'builtin', 'playbook' ], true ) ) {
			return 0;
		}
		return self::save_internal( $data, true );
	}

	/**
	 * @param array<string, mixed> $data Skill fields and lifecycle metadata.
	 */
	private static function save_internal( array $data, bool $packaged ): int {
		global $wpdb;

		if ( ! $packaged && SensitiveContent::contains( Json::encode( $data ) ) ) {
			Logger::error(
				'skill_sensitive_content_blocked',
				[
					'slug' => sanitize_title( (string) ( $data['slug'] ?? '' ) ),
				]
			);
			return 0;
		}

		$slug = sanitize_title( (string) ( $data['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return 0;
		}

		$existing = self::table_exists() ? self::get( $slug ) : null;
		$now      = current_time( 'mysql', true );

		$default_enabled = null !== $existing ? (bool) ( $existing['enabled'] ?? true ) : true;
		$enabled         = array_key_exists( 'enabled', $data ) ? (int) (bool) $data['enabled'] : (int) $default_enabled;
		$prior_status    = self::sanitize_status( (string) ( $existing['status'] ?? ( $default_enabled ? 'active' : 'draft' ) ) );
		$status          = array_key_exists( 'status', $data )
			? self::sanitize_status( (string) $data['status'] )
			: self::status_for_enabled_change( $prior_status, (bool) $enabled, array_key_exists( 'enabled', $data ) );
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
			'source'         => in_array( $data['source'] ?? 'user', [ 'builtin', 'user', 'uploaded', 'candidate', 'playbook' ], true )
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

	/**
	 * Every skill this site can see, merged across sources.
	 *
	 * `list()` answers "what is in our table". This answers "what is on offer",
	 * which on a default site is the same thing and stops being the same thing
	 * the moment somebody registers an external source. The conflicts list says
	 * what was dropped and why, so a source that tried to shadow a built-in
	 * shows up in the admin instead of quietly winning.
	 *
	 * @return array{skills: list<array<string, mixed>>, conflicts: list<array<string, string>>}
	 */
	public static function catalog(): array {
		return SkillSourceRegistry::resolve();
	}

	/**
	 * The sources behind the catalog, in resolution order.
	 *
	 * @return list<array{id: string, label: string, kind: string, count: int}>
	 */
	public static function sources(): array {
		return array_map(
			static fn( SkillSource $source ): array => $source->to_array(),
			SkillSourceRegistry::sources()
		);
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

		$skill = self::get_by_id( $id );
		if ( null === $skill ) {
			return false;
		}

		$status = self::status_for_enabled_change(
			self::sanitize_status( (string) ( $skill['status'] ?? ( ! empty( $skill['enabled'] ) ? 'active' : 'draft' ) ) ),
			$enabled,
			true
		);
		if ( $enabled && 'active' !== $status ) {
			return false;
		}

		$table = SkillsTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->update(
			$table,
			[ 'enabled' => (int) $enabled, 'status' => $status ],
			[ 'id' => $id ]
		);

		return false !== $result;
	}

	/**
	 * Move a skill to the trash.
	 *
	 * Trash is the reversible half of deletion: the row stays, its history
	 * stays, and every switch that could make an agent read it goes off.
	 *
	 * @return bool|\WP_Error True when trashed, error explaining the refusal.
	 */
	public static function trash( int $id ): bool|\WP_Error {
		global $wpdb;

		$skill = self::writable_skill( $id );
		if ( $skill instanceof \WP_Error ) {
			return $skill;
		}

		if ( self::STATUS_TRASHED === (string) ( $skill['status'] ?? '' ) ) {
			return new \WP_Error(
				'stonewright_skill_already_trashed',
				__( 'That skill is already in the trash.', 'stonewright' )
			);
		}

		$table = SkillsTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->update(
			$table,
			[
				'status'         => self::STATUS_TRASHED,
				'enabled'        => 0,
				'enable_agentic' => 0,
				'enable_prompt'  => 0,
				'trashed_at'     => current_time( 'mysql', true ),
				'updated_at'     => current_time( 'mysql', true ),
			],
			[ 'id' => $id ]
		);

		if ( false === $result ) {
			return new \WP_Error(
				'stonewright_skill_trash_failed',
				__( 'The skill could not be moved to the trash.', 'stonewright' )
			);
		}

		return true;
	}

	/**
	 * Take a skill back out of the trash as a disabled draft.
	 *
	 * Restore never re-enables anything. Somebody trashed this skill on
	 * purpose, so it comes back where a human has to look at it again.
	 *
	 * @return bool|\WP_Error True when restored, error explaining the refusal.
	 */
	public static function restore( int $id ): bool|\WP_Error {
		global $wpdb;

		$skill = self::writable_skill( $id );
		if ( $skill instanceof \WP_Error ) {
			return $skill;
		}

		if ( self::STATUS_TRASHED !== (string) ( $skill['status'] ?? '' ) ) {
			return new \WP_Error(
				'stonewright_skill_not_trashed',
				__( 'That skill is not in the trash.', 'stonewright' )
			);
		}

		$table = SkillsTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->update(
			$table,
			[
				'status'         => 'draft',
				'enabled'        => 0,
				'enable_agentic' => 0,
				'enable_prompt'  => 0,
				'trashed_at'     => null,
				'updated_at'     => current_time( 'mysql', true ),
			],
			[ 'id' => $id ]
		);

		if ( false === $result ) {
			return new \WP_Error(
				'stonewright_skill_restore_failed',
				__( 'The skill could not be restored.', 'stonewright' )
			);
		}

		return true;
	}

	/**
	 * Delete a skill and its row for good.
	 *
	 * In production-safe mode this needs a confirmation token issued for this
	 * exact skill id, because there is nothing to undo afterwards.
	 *
	 * @param string $token Confirmation token, required in production-safe mode.
	 * @return bool|\WP_Error True when deleted, error explaining the refusal.
	 */
	public static function destroy( int $id, string $token = '' ): bool|\WP_Error {
		global $wpdb;

		$skill = self::writable_skill( $id );
		if ( $skill instanceof \WP_Error ) {
			return $skill;
		}

		if ( 'production-safe' === (string) get_option( 'stonewright_mode', 'development' ) ) {
			$confirmed = ConfirmationToken::verify_or_error( $token, self::DESTROY_ABILITY, [ 'id' => $id ] );
			if ( is_wp_error( $confirmed ) ) {
				return $confirmed;
			}
		}

		$table = SkillsTable::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->delete( $table, [ 'id' => $id ] );

		if ( false === $result || $result < 1 ) {
			return new \WP_Error(
				'stonewright_skill_delete_failed',
				__( 'The skill could not be deleted.', 'stonewright' )
			);
		}

		return true;
	}

	/**
	 * Delete a skill by ID.
	 *
	 * Thin bool wrapper over destroy() for callers that only need yes or no.
	 */
	public static function delete( int $id ): bool {
		return true === self::destroy( $id );
	}

	/**
	 * Load a skill the site is allowed to change, or explain why it cannot.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function writable_skill( int $id ): array|\WP_Error {
		if ( ! self::table_exists() ) {
			return new \WP_Error(
				'stonewright_skills_unavailable',
				__( 'The skills table is not installed on this site.', 'stonewright' )
			);
		}

		$skill = self::get_by_id( $id );
		if ( null === $skill ) {
			return new \WP_Error(
				'stonewright_skill_not_found',
				__( 'That skill does not exist.', 'stonewright' )
			);
		}

		if ( in_array( (string) ( $skill['source'] ?? '' ), self::PROTECTED_SOURCES, true ) ) {
			return new \WP_Error(
				'stonewright_skill_protected',
				__( 'Skills that ship with Stonewright can be disabled but not removed.', 'stonewright' )
			);
		}

		return $skill;
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
		return in_array( $status, [ 'draft', 'active', 'stale', 'rejected', self::STATUS_TRASHED ], true ) ? $status : 'draft';
	}

	private static function status_for_enabled_change( string $status, bool $enabled, bool $enabled_was_supplied ): string {
		if ( ! $enabled_was_supplied || ! in_array( $status, [ 'active', 'draft' ], true ) ) {
			return $status;
		}

		return $enabled ? 'active' : 'draft';
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
