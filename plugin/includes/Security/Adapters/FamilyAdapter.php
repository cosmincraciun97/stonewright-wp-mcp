<?php
/**
 * The shape every adapter of the remaining families has: what it watches, what its image is, and the one
 * restore that writes an image back.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Adapters;

use Stonewright\WpMcp\Security\ChangeImage;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\RescueGuard;
use Stonewright\WpMcp\Support\Logger;

/**
 * An adapter describes the resources of one family to the ledger as images (an array that holds what a
 * restore needs and never a credential), and writes an image back.
 *
 * Recording. OtherFamilies calls watch() when an ability call starts, to take the image of every resource
 * the call names, and discover() and events() when it ends, to find the resources the call created and the
 * things that happened and cannot be imaged. A change is recorded when the call ends, with the image taken
 * at the start as its before image and the image of the resource as it is then as its after image. A call
 * that changed nothing records nothing.
 *
 * Restoring. restore() writes the before image of a row back through the same functions the ability used,
 * reads the resource to confirm, and records the restore as a rollback row under the change. A row that
 * created its resource has no before image: its undo removes the resource, in the gentlest way the family
 * has. restore() checks the capability the family's writes need; it checks no confirmation token and no
 * newer change, because the code that calls it does. When it is given expected_current_sha256, the resource
 * must still hash to it, or nothing is written.
 */
abstract class FamilyAdapter {

	public const IMAGE_VERSION = 1;

	/** The name of the restore, for the result of a restore. */
	public const RECIPE = 'other';

	/** @var list<string> Abilities whose calls this adapter watches. */
	protected const ABILITIES = [];

	/** Resource types of this adapter, restorable or not. @return list<string> */
	abstract public static function types(): array;

	abstract public static function ledger_family( string $type ): string;

	/**
	 * The image of a resource as it is now, or null when it does not exist.
	 *
	 * @return array<string, mixed>|null
	 */
	abstract public static function image( string $type, string $id ): ?array;

	/**
	 * Write an image onto a resource, or remove the resource the image is null for.
	 *
	 * @param array<string, mixed>|null $target The image to write, or null to undo a creation.
	 * @param array<string, mixed>      $row    The ledger row.
	 * @param array<string, mixed>      $options The options of restore().
	 * @param array<string, mixed>|null $live   The image of the resource as it is now.
	 * @return array{ok:bool,applied:bool,detail:string,id:string,limits:list<string>}
	 */
	abstract protected static function write( string $type, string $id, ?array $target, array $row, array $options, ?array $live ): array;

	/**
	 * Whether the current user may do what a restore does.
	 *
	 * @param string $operation update, create (the resource is gone and is written again) or delete (a creation is undone).
	 */
	abstract protected static function permitted( string $type, string $operation ): bool;

	/**
	 * Whether a resource that a change created is already gone, so that its undo has nothing to do: it does
	 * not exist, or it is in the trash.
	 *
	 * @param array<string, mixed>|null $live
	 */
	protected static function removed( string $type, ?array $live ): bool {
		return null === $live;
	}

	/**
	 * Whether the resource as it is now already is what an image says, so that a restore has nothing to do.
	 *
	 * @param array<string, mixed> $target
	 * @param array<string, mixed> $live
	 */
	protected static function matches( string $type, array $target, array $live ): bool {
		return self::canonical( $target ) === self::canonical( $live );
	}

	/** What the resource is called in a summary, for example "user alice". */
	abstract protected static function subject( string $type, ?array $image, string $id ): string;

	public static function claims( string $ability ): bool {
		return in_array( $ability, static::ABILITIES, true );
	}

	/**
	 * The resources a call names, with their images as they are before the call.
	 *
	 * @param array<string, mixed> $args
	 * @return list<array{type:string,id:string,before:array<string,mixed>|null}>
	 */
	public static function watch( string $ability, array $args ): array {
		return [];
	}

	/**
	 * What the call needs to remember until it ends. Never an argument as it was given: only values the
	 * adapter picked, and never a credential.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function context( string $ability, array $args ): array {
		return [];
	}

	/**
	 * The resources a call created, from its result.
	 *
	 * @param array<string, mixed> $context
	 * @return list<array{type:string,id:string}>
	 */
	public static function discover( string $ability, array $context, mixed $result ): array {
		return [];
	}

	/**
	 * Things that happened and are recorded without an image, from the result.
	 *
	 * @param array<string, mixed> $context
	 * @return list<array{type:string,id:string,summary:string,reason:string,failed:bool}>
	 */
	public static function events( string $ability, array $context, mixed $result ): array {
		return [];
	}

	/**
	 * What a restore of a row cannot bring back, in short phrases.
	 *
	 * @param array<string, mixed>|null $before
	 * @param array<string, mixed>|null $after
	 * @return list<string>
	 */
	public static function limits( string $ability, string $type, ?array $before, ?array $after ): array {
		return [];
	}

	/**
	 * The sentence of a row.
	 *
	 * @param array<string, mixed>|null $before
	 * @param array<string, mixed>|null $after
	 */
	public static function summarize( string $ability, string $type, string $id, ?array $before, ?array $after ): string {
		if ( null === $before ) {
			$text = FamilyLedger::CREATED_SUMMARY . static::subject( $type, $after, $id );
		} elseif ( null === $after ) {
			$text = 'Deleted ' . static::subject( $type, $before, $id );
		} else {
			$text  = 'Updated ' . static::subject( $type, $after, $id );
			$parts = self::changed_parts( $before, $after );
			if ( [] !== $parts ) {
				$text .= ': ' . implode( ', ', array_slice( $parts, 0, 5 ) );
			}
		}
		$limits = static::limits( $ability, $type, $before, $after );
		if ( [] !== $limits ) {
			$text .= ' (partly restorable: ' . implode( '; ', $limits ) . ')';
		}
		return $text;
	}

	/**
	 * The hash the resource has now, for expected_current_sha256 of a restore and for a person to see that it
	 * was edited since. Null when the resource cannot be read or the row is not of this adapter.
	 */
	public static function live_sha256( string $change_id ): ?string {
		try {
			$row = ChangeLedger::get( $change_id );
			if ( null === $row || ! in_array( (string) $row['resource_type'], static::types(), true ) ) {
				return null;
			}
			$type = (string) $row['resource_type'];
			$id   = (string) $row['resource_id'];
			return ChangeImage::hash_of( static::image( $type, $id ), $type, $id );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_live_hash_failed', [ 'error' => $failure::class ] );
			return null;
		}
	}

	/**
	 * Restore a change from its before image, or undo the creation it recorded.
	 *
	 * @param array<string, mixed> $options expected_current_sha256, and permanent (a family that must delete to undo a creation asks for it).
	 * @return array{status:string,detail:string,recipe:string,change_id:string,rollback_change_id:?string,limits:list<string>}
	 *         status is succeeded, noop (already as it was), or failed.
	 */
	public static function restore( string $change_id, array $options = [] ): array {
		$row = ChangeLedger::get( $change_id );
		if ( null === $row ) {
			return self::result( 'failed', 'change_not_found', $change_id );
		}
		$type = (string) $row['resource_type'];
		if ( ! in_array( $type, static::types(), true ) ) {
			return self::result( 'failed', 'wrong_family', $change_id );
		}
		if ( ! $row['restorable'] ) {
			return self::result( 'failed', 'not_restorable', $change_id );
		}
		$id = (string) $row['resource_id'];
		try {
			$live = static::image( $type, $id );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_restore_failed', [ 'error' => $failure::class, 'stage' => 'read' ] );
			return self::result( 'failed', 'current_unreadable', $change_id );
		}
		$expected = isset( $options['expected_current_sha256'] ) && is_string( $options['expected_current_sha256'] ) ? strtolower( trim( $options['expected_current_sha256'] ) ) : '';
		if ( '' !== $expected && ! hash_equals( $expected, ChangeImage::hash_of( $live, $type, $id ) ) ) {
			return self::result( 'failed', 'current_changed', $change_id );
		}

		$created = FamilyLedger::is_created_row( $row );
		$target  = null;
		if ( $created ) {
			if ( static::removed( $type, $live ) ) {
				return self::result( 'noop', 'already_removed', $change_id );
			}
		} else {
			$image = ChangeLedger::read_image( $change_id, 'before' );
			if ( $image instanceof \WP_Error ) {
				return self::result( 'failed', 'image_unreadable', $change_id );
			}
			if ( ! is_array( $image ) || self::IMAGE_VERSION !== ( $image['v'] ?? null ) ) {
				return self::result( 'failed', 'image_invalid', $change_id );
			}
			$target = $image;
			if ( null !== $live && static::matches( $type, $target, $live ) ) {
				return self::result( 'noop', 'already_as_before', $change_id );
			}
		}

		$operation = null === $target ? 'delete' : ( null === $live ? 'create' : 'update' );
		if ( ! static::permitted( $type, $operation ) ) {
			return self::result( 'failed', 'permission_denied', $change_id );
		}

		try {
			$written = FamilyLedger::while_restoring( static fn (): array => static::write( $type, $id, $target, $row, $options, $live ) );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_restore_failed', [ 'error' => $failure::class, 'stage' => 'write' ] );
			return self::result( 'failed', 'write_failed', $change_id );
		}
		$limits = null === $target ? [] : static::limits( (string) $row['ability'], $type, $target, $live );
		$limits = array_values( array_unique( array_merge( $limits, $written['limits'] ) ) );
		if ( ! $written['applied'] ) {
			return self::result( 'failed', $written['detail'], $change_id, null, $limits );
		}

		$new_id = '' !== $written['id'] ? $written['id'] : $id;
		try {
			$after = static::image( $type, $new_id );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_restore_failed', [ 'error' => $failure::class, 'stage' => 'confirm' ] );
			$after = null;
		}
		$child = self::record_rollback( $row, $type, $new_id, $live, $after, $written['ok'], null === $target );
		return self::result( $written['ok'] ? 'succeeded' : 'failed', $written['detail'], $change_id, $child, $limits );
	}

	// -----------------------------------------------------------------------
	// Helpers for the adapters.
	// -----------------------------------------------------------------------

	/**
	 * @return array{ok:bool,applied:bool,detail:string,id:string,limits:list<string>}
	 */
	protected static function refused( string $detail ): array {
		return [ 'ok' => false, 'applied' => false, 'detail' => $detail, 'id' => '', 'limits' => [] ];
	}

	/**
	 * @param list<string> $limits
	 * @return array{ok:bool,applied:bool,detail:string,id:string,limits:list<string>}
	 */
	protected static function applied( bool $ok, string $detail, string $id = '', array $limits = [] ): array {
		return [ 'ok' => $ok, 'applied' => true, 'detail' => $detail, 'id' => $id, 'limits' => $limits ];
	}

	/** A short code for a WP_Error, without the plugin prefix. */
	protected static function short_code( \WP_Error $error ): string {
		return substr( (string) preg_replace( '/^stonewright_/', '', sanitize_key( (string) $error->get_error_code() ) ), 0, 64 );
	}

	/**
	 * The parts of two images that differ, by name: the keys of the nested maps (fields, profile, meta) that
	 * differ, and the other keys that differ. Names only, never values.
	 *
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return list<string>
	 */
	protected static function changed_parts( array $before, array $after ): array {
		$names = [];
		foreach ( self::changed_paths( $before, $after ) as $path ) {
			$names[] = str_contains( $path, '.' ) ? substr( $path, (int) strpos( $path, '.' ) + 1 ) : $path;
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * The names of the parts of a live image that differ from the image that was wanted.
	 *
	 * @param array<string, mixed>      $wanted
	 * @param array<string, mixed>|null $live
	 * @param list<string>              $ignore Paths to leave out, as part.key.
	 * @return list<string>
	 */
	protected static function differences( array $wanted, ?array $live, array $ignore = [] ): array {
		if ( null === $live ) {
			return [ 'resource' ];
		}
		$out = [];
		foreach ( self::changed_paths( $wanted, $live ) as $path ) {
			if ( ! in_array( $path, $ignore, true ) && ! in_array( explode( '.', $path )[0], $ignore, true ) ) {
				$out[] = $path;
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return list<string>
	 */
	private static function changed_paths( array $before, array $after ): array {
		$paths = [];
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $key ) {
			if ( 'v' === $key ) {
				continue;
			}
			$here  = $before[ $key ] ?? null;
			$there = $after[ $key ] ?? null;
			if ( is_array( $here ) && is_array( $there ) && ! array_is_list( $here ) && ! array_is_list( $there ) ) {
				foreach ( array_unique( array_merge( array_keys( $here ), array_keys( $there ) ) ) as $sub ) {
					if ( self::canonical( $here[ $sub ] ?? null ) !== self::canonical( $there[ $sub ] ?? null ) ) {
						$paths[] = $key . '.' . $sub;
					}
				}
			} elseif ( self::canonical( $here ) !== self::canonical( $there ) ) {
				$paths[] = (string) $key;
			}
		}
		return $paths;
	}

	protected static function canonical( mixed $value ): string {
		$json = json_encode( self::sorted( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR );
		return is_string( $json ) ? $json : '';
	}

	/**
	 * Sort the keys of every map, so that equal values have equal text.
	 *
	 * @return mixed
	 */
	protected static function sorted( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sorted( $item );
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, mixed>|null $live
	 * @param array<string, mixed>|null $after
	 */
	private static function record_rollback( array $row, string $type, string $id, ?array $live, ?array $after, bool $ok, bool $undoes_creation ): ?string {
		if ( ! FamilyLedger::ready() ) {
			return null;
		}
		$subject = static::subject( $type, $after ?? $live, $id );
		if ( null === $live ) {
			$summary = FamilyLedger::CREATED_SUMMARY . $subject;
		} elseif ( $undoes_creation ) {
			$summary = 'Undid creation of ' . $subject;
		} else {
			$summary = 'Restored ' . $subject;
		}
		$spec = [
			'ability'       => RescueGuard::current_ability( 'stonewright/change-rollback' ),
			'family'        => static::ledger_family( $type ),
			'resource_type' => $type,
			'resource_id'   => $id,
			'kind'          => 'rollback',
			'parent_id'     => (string) $row['change_id'],
			'before'        => $live,
			'summary'       => $summary,
		];
		if ( null === $live ) {
			$spec['restorable'] = true;
		}
		$child = FamilyLedger::record( $spec );
		FamilyLedger::settle( $child, $ok ? 'verified' : 'failed', $after );
		return $child;
	}

	/**
	 * @param list<string> $limits
	 * @return array{status:string,detail:string,recipe:string,change_id:string,rollback_change_id:?string,limits:list<string>}
	 */
	private static function result( string $status, string $detail, string $change_id, ?string $child = null, array $limits = [] ): array {
		return [
			'status'             => $status,
			'detail'             => $detail,
			'recipe'             => 'ledger_' . static::RECIPE,
			'change_id'          => $change_id,
			'rollback_change_id' => $child,
			'limits'             => $limits,
		];
	}
}
