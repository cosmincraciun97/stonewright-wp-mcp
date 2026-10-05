<?php
/**
 * Atomic logical repository port. A physical adapter requires verified site evidence.
 *
 * @package Stonewright
 * @license AGPL-3.0-or-later
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary;

/** No SQL, legacy schema, or production memory fallback is defined by this port. */
interface Repository {
	/** @return array<int, array<string, mixed>> */
	public function all_records(): array;
	/** @return array<string, mixed>|null */
	public function find_slug( string $slug ): ?array;
	/** @return array<string, mixed>|null */
	public function find_id( int $id ): ?array;
	/** Unique insert must never replace an existing slug. @param array<string, mixed> $record */
	public function insert_unique( array $record ): int|\WP_Error;
	/** Atomically compare revision, archive exact previous record, and replace. @param array<string, mixed> $replacement */
	public function exchange_record( int $id, array $replacement, int $expected_revision ): bool|\WP_Error;
	/** Atomically compare revision and erase the record plus its snapshots. */
	public function remove_record( int $id, int $expected_revision ): bool|\WP_Error;
	/** Immutable snapshots, in increasing revision order. @return array<int, array<string, mixed>> */
	public function snapshots( string $slug ): array;
	/** @return array<string, mixed>|null */
	public function read_snapshot( string $slug, int $revision ): ?array;
}
