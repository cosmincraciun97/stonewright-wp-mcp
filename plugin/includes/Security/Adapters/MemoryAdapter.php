<?php
/**
 * Site memory in the change ledger: the full row of an entry before it is deleted for good.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Adapters;

use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\RescueGuard;
use Stonewright\WpMcp\Support\Logger;

/**
 * Deleting a memory entry removes its row. Memory::delete_by_id() and Memory::delete() call before_delete()
 * to read the row and after_delete() to record it once the row is gone, so the delete from the ability, from
 * the admin screen and from the REST route are all recorded. The image is the entry as Memory::get_by_id()
 * returns it, without its id, which is the resource id of the row.
 *
 * A restore inserts the row again under the id it had, with its dates, and refuses when that id or the
 * scope and key pair is taken by another entry. A masked image (a credential was found in the value) is not
 * restorable, as for every family.
 */
final class MemoryAdapter extends FamilyAdapter {

	public const FAMILY = 'memory';

	public const RECIPE = 'memory';

	/** The name a row has when the delete did not come from an ability call. */
	public const ABILITY = 'stonewright/memory-delete';

	public static function types(): array {
		return [ 'memory' ];
	}

	public static function ledger_family( string $type ): string {
		return self::FAMILY;
	}

	public static function image( string $type, string $id ): ?array {
		if ( 'memory' !== $type || 1 !== preg_match( '/^[1-9][0-9]{0,18}$/D', $id ) ) {
			return null;
		}
		$entry = Memory::get_by_id( (int) $id );
		if ( null === $entry ) {
			return null;
		}
		unset( $entry['id'], $entry['last_retrieved_at'] );
		ksort( $entry, SORT_STRING );
		return [ 'entry' => $entry, 'v' => self::IMAGE_VERSION ];
	}

	/**
	 * The image of an entry that is about to be deleted, or null when it is not to be recorded.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function before_delete( int $id ): ?array {
		try {
			if ( FamilyLedger::restoring() || ! FamilyLedger::ready() ) {
				return null;
			}
			return self::image( 'memory', (string) $id );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_capture_failed', [ 'error' => $failure::class, 'family' => self::FAMILY ] );
			return null;
		}
	}

	/**
	 * Record a delete.
	 *
	 * @param array<string, mixed>|null $before  What before_delete() returned.
	 * @param bool                      $deleted Whether a row was deleted.
	 */
	public static function after_delete( int $id, ?array $before, bool $deleted ): void {
		if ( null === $before || ! $deleted ) {
			return;
		}
		try {
			$ability = RescueGuard::current_ability( self::ABILITY );
			$change  = FamilyLedger::record(
				[
					'ability'       => $ability,
					'family'        => self::FAMILY,
					'resource_type' => 'memory',
					'resource_id'   => (string) $id,
					'before'        => $before,
					'summary'       => self::summarize( $ability, 'memory', (string) $id, $before, null ),
				]
			);
			FamilyLedger::settle( $change, 'verified' );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_record_failed', [ 'error' => $failure::class, 'family' => self::FAMILY ] );
		}
	}

	protected static function subject( string $type, ?array $image, string $id ): string {
		$key = is_array( $image['entry'] ?? null ) ? (string) ( $image['entry']['memory_key'] ?? '' ) : '';
		return 'memory entry ' . ( '' === $key ? '#' . $id : FamilyLedger::clip( $key, 60 ) );
	}

	protected static function permitted( string $type, string $operation ): bool {
		return Permissions::manage_options();
	}

	protected static function write( string $type, string $id, ?array $target, array $row, array $options, ?array $live ): array {
		if ( null === $target ) {
			// The row to undo is the restore of a deleted entry: the entry exists again, and removing it redoes the delete.
			return Memory::delete_by_id( (int) $id ) ? self::applied( true, 'removed', $id ) : self::refused( 'delete_failed' );
		}
		$entry = is_array( $target['entry'] ?? null ) ? $target['entry'] : [];
		if ( null !== $live ) {
			$updated = Memory::update_by_id( (int) $id, $entry );
			if ( ! $updated ) {
				return self::refused( 'update_failed' );
			}
		} else {
			$problem = Memory::restore_entry( (int) $id, $entry );
			if ( '' !== $problem ) {
				return self::refused( $problem );
			}
		}
		$differences = self::differences( $target, self::image( 'memory', $id ), [ 'entry.updated_at' ] );
		return self::applied( [] === $differences, [] === $differences ? 'restored' : 'differences:' . implode( ',', array_slice( $differences, 0, 5 ) ), $id );
	}
}
