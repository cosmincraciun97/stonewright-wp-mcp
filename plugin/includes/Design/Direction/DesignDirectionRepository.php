<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Direction;

use WP_Error;

/**
 * Storage for design directions and their immutable version history.
 *
 * Every SQL statement for the two direction tables lives here. The repository
 * holds no lifecycle rules: it does not validate contracts, compute hashes,
 * decide revisions, or touch the active-direction option. Those belong to
 * DesignDirectionService, which drives this class.
 *
 * The class is intentionally not final. Storage is the one seam the lifecycle
 * tests need to replace, and an overridable repository keeps those tests
 * asserting real state transitions instead of mock expectations.
 */
class DesignDirectionRepository {

	/**
	 * Lifecycle statuses a stored direction may carry.
	 *
	 * "Active" is deliberately absent: it is a pointer held in an option, not a
	 * status, so a ready direction can be active without a second source of
	 * truth for the same fact.
	 *
	 * @var list<string>
	 */
	public const STATUSES = [ 'draft', 'ready', 'stale', 'archived' ];

	/** @var string Structured error code for a rejected record shape. */
	public const INVALID_CODE = 'stonewright_direction_invalid';

	/** @var string Structured error code for a database write that did not apply. */
	public const WRITE_FAILED_CODE = 'stonewright_direction_write_failed';

	/**
	 * Lists stored directions, newest first.
	 *
	 * @param array<string,mixed> $filters Optional `status` filter; unknown
	 *                                     statuses are ignored rather than
	 *                                     interpolated.
	 * @return list<array<string,mixed>>
	 */
	public function list( array $filters = [] ): array {
		global $wpdb;

		$table = DesignDirectionsTable::table_name();
		$where = '';

		if ( isset( $filters['status'] ) && in_array( $filters['status'], self::STATUSES, true ) ) {
			$where = $wpdb->prepare( ' WHERE status = %s', $filters['status'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table}{$where} ORDER BY updated_at DESC, id DESC", ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_values( array_map( [ $this, 'normalize_record' ], $rows ) );
	}

	/**
	 * Reads one direction by id.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$table = DesignDirectionsTable::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), ARRAY_A );

		return is_array( $row ) ? $this->normalize_record( $row ) : null;
	}

	/**
	 * Reads one direction by slug.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_by_slug( string $slug ): ?array {
		global $wpdb;

		$table = DesignDirectionsTable::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s LIMIT 1", $slug ), ARRAY_A );

		return is_array( $row ) ? $this->normalize_record( $row ) : null;
	}

	/**
	 * Inserts or updates a direction record.
	 *
	 * A record carrying an `id` is updated in place; anything else is inserted.
	 *
	 * @param array<string,mixed> $record Record as the service composed it.
	 * @return int|WP_Error Stored record id.
	 */
	public function save( array $record ) {
		global $wpdb;

		$slug = isset( $record['slug'] ) ? (string) $record['slug'] : '';
		if ( '' === $slug ) {
			return new WP_Error( self::INVALID_CODE, 'A design direction requires a slug.' );
		}

		$table = DesignDirectionsTable::table_name();
		$now   = current_time( 'mysql', true );

		$data = [
			'slug'             => $slug,
			'status'           => (string) ( $record['status'] ?? 'draft' ),
			'contract_json'    => $this->encode( $record['contract'] ?? [] ),
			'contract_hash'    => (string) ( $record['contract_hash'] ?? '' ),
			'source_type'      => (string) ( $record['source_type'] ?? '' ),
			'source_refs_json' => $this->encode( $record['source_refs'] ?? [] ),
			'revision'         => (int) ( $record['revision'] ?? 1 ),
			'updated_at'       => $now,
		];

		$format = [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ];

		$id = isset( $record['id'] ) ? (int) $record['id'] : 0;

		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$updated = $wpdb->update( $table, $data, [ 'id' => $id ], $format, [ '%d' ] );

			if ( false === $updated ) {
				return new WP_Error( self::WRITE_FAILED_CODE, 'Could not update the design direction.' );
			}

			return $id;
		}

		$data['created_at'] = $now;
		$format[]           = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert( $table, $data, $format );

		if ( false === $inserted ) {
			return new WP_Error( self::WRITE_FAILED_CODE, 'Could not insert the design direction.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Appends an immutable version snapshot.
	 *
	 * @param array<string,mixed> $snapshot Snapshot as the service composed it.
	 * @return int|WP_Error Stored version row id.
	 */
	public function add_version( array $snapshot ) {
		global $wpdb;

		$direction_id = (int) ( $snapshot['direction_id'] ?? 0 );
		$revision     = (int) ( $snapshot['revision'] ?? 0 );

		if ( $direction_id < 1 || $revision < 1 ) {
			return new WP_Error( self::INVALID_CODE, 'A version snapshot requires a direction id and a revision.' );
		}

		$table = DesignDirectionVersionsTable::table_name();

		$data = [
			'direction_id'     => $direction_id,
			'revision'         => $revision,
			'status'           => (string) ( $snapshot['status'] ?? 'draft' ),
			'contract_json'    => $this->encode( $snapshot['contract'] ?? [] ),
			'contract_hash'    => (string) ( $snapshot['contract_hash'] ?? '' ),
			'source_type'      => (string) ( $snapshot['source_type'] ?? '' ),
			'source_refs_json' => $this->encode( $snapshot['source_refs'] ?? [] ),
			'created_at'       => current_time( 'mysql', true ),
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert( $table, $data, [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ] );

		if ( false === $inserted ) {
			return new WP_Error( self::WRITE_FAILED_CODE, 'Could not record the design direction version.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Lists a direction's version history, newest revision first.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function versions( int $id ): array {
		global $wpdb;

		$table = DesignDirectionVersionsTable::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE direction_id = %d ORDER BY revision DESC", $id ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		return array_values( array_map( [ $this, 'normalize_version' ], $rows ) );
	}

	/**
	 * Reads one stored revision.
	 *
	 * @return array<string,mixed>|null
	 */
	public function version( int $id, int $revision ): ?array {
		global $wpdb;

		$table = DesignDirectionVersionsTable::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE direction_id = %d AND revision = %d LIMIT 1", $id, $revision ), ARRAY_A );

		return is_array( $row ) ? $this->normalize_version( $row ) : null;
	}

	/**
	 * Marks a direction archived. Version history is left untouched.
	 *
	 * @return true|WP_Error
	 */
	public function archive( int $id ) {
		global $wpdb;

		$table = DesignDirectionsTable::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->update(
			$table,
			[
				'status'     => 'archived',
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		if ( false === $updated ) {
			return new WP_Error( self::WRITE_FAILED_CODE, 'Could not archive the design direction.' );
		}

		return true;
	}

	/**
	 * Opens a transaction when the storage layer supports one.
	 *
	 * Transactions are a best-effort guard: they apply on a real wpdb against
	 * transactional storage, and are a no-op elsewhere, so callers must still
	 * repair state themselves on failure rather than rely on a rollback.
	 */
	public function begin_transaction(): void {
		$this->transactional_query( 'START TRANSACTION' );
	}

	public function commit_transaction(): void {
		$this->transactional_query( 'COMMIT' );
	}

	public function rollback_transaction(): void {
		$this->transactional_query( 'ROLLBACK' );
	}

	private function transactional_query( string $statement ): void {
		global $wpdb;

		if ( ! ( $wpdb instanceof \wpdb ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $statement );
	}

	/**
	 * Maps a stored direction row onto the service-facing shape.
	 *
	 * @param array<string,mixed> $row Raw database row.
	 * @return array<string,mixed>
	 */
	private function normalize_record( array $row ): array {
		return [
			'id'            => (int) ( $row['id'] ?? 0 ),
			'slug'          => (string) ( $row['slug'] ?? '' ),
			'status'        => (string) ( $row['status'] ?? 'draft' ),
			'contract'      => $this->decode( $row['contract_json'] ?? '' ),
			'contract_hash' => (string) ( $row['contract_hash'] ?? '' ),
			'source_type'   => (string) ( $row['source_type'] ?? '' ),
			'source_refs'   => $this->decode( $row['source_refs_json'] ?? '' ),
			'revision'      => (int) ( $row['revision'] ?? 1 ),
			'created_at'    => (string) ( $row['created_at'] ?? '' ),
			'updated_at'    => (string) ( $row['updated_at'] ?? '' ),
		];
	}

	/**
	 * Maps a stored version row onto the service-facing shape.
	 *
	 * @param array<string,mixed> $row Raw database row.
	 * @return array<string,mixed>
	 */
	private function normalize_version( array $row ): array {
		return [
			'id'            => (int) ( $row['id'] ?? 0 ),
			'direction_id'  => (int) ( $row['direction_id'] ?? 0 ),
			'revision'      => (int) ( $row['revision'] ?? 0 ),
			'status'        => (string) ( $row['status'] ?? 'draft' ),
			'contract'      => $this->decode( $row['contract_json'] ?? '' ),
			'contract_hash' => (string) ( $row['contract_hash'] ?? '' ),
			'source_type'   => (string) ( $row['source_type'] ?? '' ),
			'source_refs'   => $this->decode( $row['source_refs_json'] ?? '' ),
			'created_at'    => (string) ( $row['created_at'] ?? '' ),
		];
	}

	/**
	 * @param mixed $value Structured value destined for a JSON column.
	 */
	private function encode( mixed $value ): string {
		$encoded = wp_json_encode( is_array( $value ) ? $value : [] );

		return is_string( $encoded ) ? $encoded : '[]';
	}

	/**
	 * @param mixed $value Raw JSON column value.
	 * @return array<string,mixed>
	 */
	private function decode( mixed $value ): array {
		if ( ! is_string( $value ) || '' === $value ) {
			return [];
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : [];
	}
}
