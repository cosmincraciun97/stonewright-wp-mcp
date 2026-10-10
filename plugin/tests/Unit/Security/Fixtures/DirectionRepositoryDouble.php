<?php
/**
 * An in-memory design direction repository for the ledger tests.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Fixtures;

use Stonewright\WpMcp\Design\Direction\DesignDirectionRepository;

/**
 * Records and versions live in arrays, so the service rules are observable without a database.
 */
final class DirectionRepositoryDouble extends DesignDirectionRepository {

	/** @var array<int,array<string,mixed>> */
	public array $records = [];

	/** @var list<array<string,mixed>> */
	public array $version_rows = [];

	private int $next_id = 1;

	private int $next_version_id = 1;

	public function __construct() {
	}

	public function list( array $filters = [] ): array {
		return array_values( $this->records );
	}

	public function get( int $id ): ?array {
		return $this->records[ $id ] ?? null;
	}

	public function find_by_slug( string $slug ): ?array {
		foreach ( $this->records as $record ) {
			if ( $record['slug'] === $slug ) {
				return $record;
			}
		}
		return null;
	}

	public function save( array $record ) {
		$id                   = isset( $record['id'] ) ? (int) $record['id'] : $this->next_id++;
		$record['id']         = $id;
		$record['created_at'] ??= '2026-07-24 09:00:00';
		$record['updated_at'] = '2026-07-24 09:00:00';
		$this->records[ $id ] = $record;
		return $id;
	}

	public function add_version( array $snapshot ) {
		$snapshot['id']         = $this->next_version_id++;
		$snapshot['created_at'] = '2026-07-24 09:00:00';
		$this->version_rows[]   = $snapshot;
		return (int) $snapshot['id'];
	}

	public function versions( int $id ): array {
		$rows = array_values( array_filter( $this->version_rows, static fn ( array $row ): bool => (int) $row['direction_id'] === $id ) );
		usort( $rows, static fn ( array $a, array $b ): int => (int) $b['revision'] <=> (int) $a['revision'] );
		return $rows;
	}

	public function version( int $id, int $revision ): ?array {
		foreach ( $this->version_rows as $row ) {
			if ( (int) $row['direction_id'] === $id && (int) $row['revision'] === $revision ) {
				return $row;
			}
		}
		return null;
	}

	public function archive( int $id ) {
		if ( ! isset( $this->records[ $id ] ) ) {
			return new \WP_Error( 'stonewright_direction_not_found', 'Missing record.' );
		}
		$this->records[ $id ]['status'] = 'archived';
		return true;
	}

	public function begin_transaction(): void {
	}

	public function commit_transaction(): void {
	}

	public function rollback_transaction(): void {
	}
}
