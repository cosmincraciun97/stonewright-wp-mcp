<?php
/**
 * Repository adapter on the site's skills and skill revision tables.
 *
 * @package Stonewright
 * @license GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary\Site;

use Stonewright\WpMcp\SkillLibrary\Repository;

/**
 * Writes are compare-and-exchange: the row is replaced only while it still holds
 * the revision and lifecycle state that was read. A change to text, provenance,
 * evidence, or exposure preferences records the previous row as a revision in
 * the same transaction; enabling, disabling, trashing, and restoring change the
 * lifecycle columns only. Times are stored in UTC.
 */
final class WordPressRepository implements Repository {

	/** @return array<int, array<string, mixed>> */
	public function all_records(): array {
		global $wpdb;
		$table = SkillTables::skills_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from the site prefix; the statement carries no input values.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return [];
		}
		return array_values( array_map( [ RowFormat::class, 'logical' ], array_filter( $rows, 'is_array' ) ) );
	}

	/** @return array<string, mixed>|null */
	public function find_slug( string $slug ): ?array {
		global $wpdb;
		if ( '' === $slug ) {
			return null;
		}
		$table = SkillTables::skills_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from the site prefix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s LIMIT 1", $slug ), ARRAY_A );
		return is_array( $row ) ? RowFormat::logical( $row ) : null;
	}

	/** @return array<string, mixed>|null */
	public function find_id( int $id ): ?array {
		global $wpdb;
		if ( $id < 1 ) {
			return null;
		}
		$table = SkillTables::skills_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from the site prefix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
		return is_array( $row ) ? RowFormat::logical( $row ) : null;
	}

	/** @param array<string, mixed> $record */
	public function insert_unique( array $record ): int|\WP_Error {
		global $wpdb;
		$problem = RowFormat::storage_problem( $record );
		if ( null !== $problem ) {
			return $problem;
		}
		$now     = self::now();
		$columns = RowFormat::stored( $record ) + [
			'trashed_at' => 'trashed' === ( $record['status'] ?? '' ) ? $now : null,
			'created_at' => $now,
			'updated_at' => $now,
		];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert( SkillTables::skills_table(), $columns, RowFormat::formats( $columns ) );
		if ( false === $inserted ) {
			return self::refused_insert( (string) $wpdb->last_error );
		}
		$id = (int) $wpdb->insert_id;
		return $id > 0 ? $id : self::failed();
	}

	/** @param array<string, mixed> $replacement */
	public function exchange_record( int $id, array $replacement, int $expected_revision ): bool|\WP_Error {
		global $wpdb;
		$slug = is_string( $replacement['slug'] ?? null ) ? $replacement['slug'] : '';
		if ( $id < 1 || $expected_revision < 1 || '' === $slug ) {
			return new \WP_Error( 'stonewright_skill_repository_contract', 'A replacement needs the stored id, identifier, and revision.' );
		}
		$problem = RowFormat::storage_problem( $replacement );
		if ( null !== $problem ) {
			return $problem;
		}

		$table = SkillTables::skills_table();
		$this->begin();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from the site prefix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND slug = %s LIMIT 1", $id, $slug ), ARRAY_A );
		if ( ! is_array( $row ) || (int) ( $row['id'] ?? 0 ) !== $id || (int) ( $row['revision'] ?? 0 ) !== $expected_revision ) {
			return $this->abandon( self::conflict() );
		}

		$current = RowFormat::logical( $row );
		$next    = RowFormat::logical( RowFormat::stored( $replacement ) + [ 'id' => $id ] );
		$change  = self::change( $current, $next );
		if ( 'none' === $change ) {
			return $this->finish();
		}

		$now        = self::now();
		$trashed_at = 'trashed' === $next['status'] ? ( $current['trashed_at'] ?? $now ) : null;
		$guard      = [
			'id'             => $id,
			'slug'           => $slug,
			'revision'       => $expected_revision,
			'status'         => (string) ( $row['status'] ?? '' ),
			'enabled'        => (int) ( $row['enabled'] ?? 0 ),
			'enable_agentic' => (int) ( $row['enable_agentic'] ?? 0 ),
			'enable_prompt'  => (int) ( $row['enable_prompt'] ?? 0 ),
		];

		if ( 'lifecycle' === $change ) {
			$data = [
				'enabled'        => $next['enabled'] ? 1 : 0,
				'enable_agentic' => $next['enable_agentic'] ? 1 : 0,
				'enable_prompt'  => $next['enable_prompt'] ? 1 : 0,
				'status'         => (string) $next['status'],
				'trashed_at'     => $trashed_at,
				'updated_at'     => $now,
			];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$updated = $wpdb->update( $table, $data, $guard, RowFormat::formats( $data ), RowFormat::formats( $guard ) );
			return 1 === $updated ? $this->finish() : $this->abandon( false === $updated ? self::failed() : self::conflict() );
		}

		$version = $this->archive( $id, $expected_revision, $row, $now );
		if ( is_wp_error( $version ) ) {
			return $this->abandon( $version );
		}
		$data = array_replace(
			RowFormat::stored( $next ),
			[
				'revision'   => $expected_revision + 1,
				'trashed_at' => $trashed_at,
				'updated_at' => $now,
			]
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->update( $table, $data, $guard, RowFormat::formats( $data ), RowFormat::formats( $guard ) );
		if ( 1 === $updated ) {
			return $this->finish();
		}
		$this->abandon( self::conflict() );
		// Storage engines without transactions keep the revision row; remove the one this write added.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( SkillTables::versions_table(), [ 'id' => $version ], [ '%d' ] );
		return false === $updated ? self::failed() : self::conflict();
	}

	public function remove_record( int $id, int $expected_revision ): bool|\WP_Error {
		global $wpdb;
		$this->begin();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$removed = $wpdb->delete(
			SkillTables::skills_table(),
			[
				'id'       => $id,
				'revision' => $expected_revision,
				'status'   => 'trashed',
			],
			[ '%d', '%d', '%s' ]
		);
		if ( 1 !== $removed ) {
			return $this->abandon( false === $removed ? self::failed() : self::conflict() );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$history = $wpdb->delete( SkillTables::versions_table(), [ 'skill_id' => $id ], [ '%d' ] );
		return false === $history ? $this->abandon( self::failed() ) : $this->finish();
	}

	/** @return array<int, array<string, mixed>> */
	public function snapshots( string $slug ): array {
		global $wpdb;
		$record = $this->find_slug( $slug );
		if ( null === $record ) {
			return [];
		}
		$table = SkillTables::versions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from the site prefix.
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE skill_id = %d ORDER BY revision ASC", $record['id'] ), ARRAY_A );
		$snapshots = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( ! is_array( $row ) || (int) ( $row['skill_id'] ?? 0 ) !== $record['id'] ) {
				continue;
			}
			$data = json_decode( (string) ( $row['snapshot_json'] ?? '' ), true );
			if ( ! is_array( $data ) || ! isset( $data['content'] ) ) {
				continue;
			}
			$snapshot             = RowFormat::logical( $data );
			$snapshot['id']       = $record['id'];
			$snapshot['revision'] = (int) ( $row['revision'] ?? 0 );
			$snapshots[]          = $snapshot;
		}
		usort( $snapshots, static fn( array $a, array $b ): int => $a['revision'] <=> $b['revision'] );
		return $snapshots;
	}

	/** @return array<string, mixed>|null */
	public function read_snapshot( string $slug, int $revision ): ?array {
		foreach ( $this->snapshots( $slug ) as $snapshot ) {
			if ( $revision === $snapshot['revision'] ) {
				return $snapshot;
			}
		}
		return null;
	}

	/**
	 * Classifies a replacement: no change, a lifecycle change (enable, disable,
	 * trash, restore, retire), or a change that records a revision.
	 *
	 * @param array<string, mixed> $current
	 * @param array<string, mixed> $next
	 */
	private static function change( array $current, array $next ): string {
		$text_changed = false;
		foreach ( array_diff( RowFormat::REVISED, [ 'enable_agentic', 'enable_prompt' ] ) as $field ) {
			if ( $current[ $field ] !== $next[ $field ] ) {
				$text_changed = true;
				break;
			}
		}
		$moves_trash = ( 'trashed' === $next['status'] ) !== ( 'trashed' === $current['status'] );
		if ( ! $text_changed && $moves_trash ) {
			return 'lifecycle';
		}
		$preferences_changed = $current['enable_agentic'] !== $next['enable_agentic'] || $current['enable_prompt'] !== $next['enable_prompt'];
		if ( $text_changed || $preferences_changed ) {
			return 'revision';
		}
		return $current['enabled'] !== $next['enabled'] || $current['status'] !== $next['status'] ? 'lifecycle' : 'none';
	}

	/**
	 * Records the row as it was before this write.
	 *
	 * @param array<string, mixed> $row
	 */
	private function archive( int $id, int $revision, array $row, string $now ): int|\WP_Error {
		global $wpdb;
		$columns = [
			'skill_id'      => $id,
			'revision'      => $revision,
			'snapshot_json' => RowFormat::snapshot( $row ),
			'created_by'    => max( 0, get_current_user_id() ),
			'created_at'    => $now,
		];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert( SkillTables::versions_table(), $columns, [ '%d', '%d', '%s', '%d', '%s' ] );
		if ( false === $inserted ) {
			return str_contains( strtolower( (string) $wpdb->last_error ), 'duplicate' ) ? self::conflict() : self::failed();
		}
		return (int) $wpdb->insert_id;
	}

	private function begin(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'START TRANSACTION' );
	}

	private function finish(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'COMMIT' );
		return true;
	}

	private function abandon( \WP_Error $error ): \WP_Error {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'ROLLBACK' );
		return $error;
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	private static function refused_insert( string $database_error ): \WP_Error {
		if ( str_contains( strtolower( $database_error ), 'duplicate' ) ) {
			return new \WP_Error( 'stonewright_skill_slug_taken', 'A skill with that identifier already exists.', [ 'status' => 409 ] );
		}
		return self::failed();
	}

	private static function conflict(): \WP_Error {
		return new \WP_Error( 'stonewright_skill_write_conflict', 'The skill changed while it was being saved. Reload it and try again.', [ 'status' => 409 ] );
	}

	private static function failed(): \WP_Error {
		return new \WP_Error( 'stonewright_skill_write_failed', 'The skill could not be stored.', [ 'status' => 500 ] );
	}
}
