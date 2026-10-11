<?php
/**
 * The central hook of the rescue guard for the user, comment, media, catalog and site families, and the
 * router of their restores.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Adapters;

use Stonewright\WpMcp\Security\ChangeImage;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\Rollback\RollbackFamilyHandler;
use Stonewright\WpMcp\Support\Logger;

/**
 * RescueGuard::enter() calls begin() with the ability name and its arguments, and RescueGuard::leave()
 * calls finish() with the result. begin() asks the adapter that claims the ability for the images of the
 * resources the call names and for the few values it needs later; the arguments themselves are not kept,
 * because they can hold a password. finish() reads each resource again, records a row for every one that
 * changed (a create, an update or a delete) and settles it with the outcome, records the resources the
 * result says the call created, and records the events an adapter reports: a password changed, an
 * application password created or revoked, a plugin deleted, a snippet run.
 *
 * Nothing here ever throws into the ability, and a call that changed nothing leaves no row. The row of a
 * change is written when the call ends, with the image taken when it began; unlike the post family it is
 * not written before the write, so a request that dies in the middle of a write leaves no row.
 *
 * Skills, design directions and memory are recorded where their own services write (RevisionLinkAdapter,
 * MemoryAdapter), and the settings of admin screens through SiteAdapter::note_admin_write().
 *
 * restore() sends a row to the adapter of its resource type, and live_image() reads the resource of a row as it is now.
 * Rollback\OtherRollbackFamilies registers both with the rollback engine.
 */
final class OtherFamilies {

	/** Adapters that watch ability calls. */
	private const WATCHING = [
		UserAdapter::class,
		MediaAdapter::class,
		CommentAdapter::class,
		WooCommerceAdapter::class,
		SiteAdapter::class,
	];

	/** Adapters that restore rows. */
	private const RESTORING = [
		UserAdapter::class,
		MediaAdapter::class,
		CommentAdapter::class,
		WooCommerceAdapter::class,
		SiteAdapter::class,
		MemoryAdapter::class,
		RevisionLinkAdapter::class,
	];

	/** Resource types whose live state an adapter reads, so that a plan can show a diff and tell drift. */
	private const READABLE = [
		'user',
		'attachment',
		'comment',
		'wc_product',
		'wc_variation',
		'wc_term',
		'wc_attribute',
		'theme_switch',
		'memory',
		'skill',
		'design_direction',
		'design_direction_pointer',
	];

	public static function reset_for_tests(): void {
		FamilyLedger::reset_for_tests();
	}

	/**
	 * Start watching an ability call.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|null What finish() needs, or null when the call is not watched.
	 */
	public static function begin( string $ability, array $args ): ?array {
		foreach ( self::WATCHING as $adapter ) {
			if ( ! $adapter::claims( $ability ) ) {
				continue;
			}
			try {
				if ( FamilyLedger::restoring() || ! FamilyLedger::ready() ) {
					return null;
				}
				return [
					'adapter' => $adapter,
					'ability' => $ability,
					'context' => $adapter::context( $ability, $args ),
					'targets' => $adapter::watch( $ability, $args ),
				];
			} catch ( \Throwable $failure ) {
				Logger::warning( 'change_ledger_capture_failed', [ 'error' => $failure::class, 'ability' => $ability ] );
				return null;
			}
		}
		return null;
	}

	/**
	 * Record what a watched call did.
	 *
	 * @param array<string, mixed>|null $pending What begin() returned.
	 * @param mixed                     $result  The result of the call.
	 */
	public static function finish( ?array $pending, mixed $result ): void {
		if ( null === $pending ) {
			return;
		}
		/** @var class-string<FamilyAdapter> $adapter */
		$adapter = $pending['adapter'];
		$ability = (string) $pending['ability'];
		$context = (array) $pending['context'];
		$failed  = ! FamilyLedger::succeeded( $result );

		foreach ( (array) $pending['targets'] as $target ) {
			self::record_change( $adapter, $ability, (string) $target['type'], (string) $target['id'], $target['before'], $failed );
		}
		try {
			$created = $failed ? [] : $adapter::discover( $ability, $context, $result );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_record_failed', [ 'error' => $failure::class, 'ability' => $ability ] );
			$created = [];
		}
		foreach ( $created as $target ) {
			self::record_change( $adapter, $ability, $target['type'], $target['id'], null, false );
		}
		try {
			$events = $adapter::events( $ability, $context, $result );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_record_failed', [ 'error' => $failure::class, 'ability' => $ability ] );
			$events = [];
		}
		foreach ( $events as $event ) {
			self::record_event( $adapter, $ability, $event );
		}
	}

	/**
	 * Restore a change, or undo the creation it recorded, through the adapter of its resource type.
	 *
	 * @param array<string, mixed> $options expected_current_sha256, and permanent for the undo of an upload.
	 * @return array{status:string,detail:string,recipe:string,change_id:string,rollback_change_id:?string,limits:list<string>}
	 *         status is succeeded, noop, failed or not_available (the resource type belongs to no adapter here).
	 */
	public static function restore( string $change_id, array $options = [] ): array {
		$row = ChangeLedger::get( $change_id );
		if ( null === $row ) {
			return self::unavailable( 'failed', 'change_not_found', $change_id );
		}
		$adapter = self::adapter_of( (string) $row['resource_type'] );
		return null === $adapter ? self::unavailable( 'not_available', 'unsupported_family', $change_id ) : $adapter::restore( $change_id, $options );
	}

	/**
	 * The hash the resource of a row has now, or null when no adapter here reads it.
	 */
	public static function live_sha256( string $change_id ): ?string {
		$row = ChangeLedger::get( $change_id );
		if ( null === $row ) {
			return null;
		}
		$adapter = self::adapter_of( (string) $row['resource_type'] );
		return null === $adapter ? null : $adapter::live_sha256( $change_id );
	}

	/**
	 * The resource of a row as it is now, in the shape of the images the adapter stores, unmasked (the rollback engine
	 * masks before it compares or shows anything). Null when the resource does not exist.
	 *
	 * @param array<string, mixed> $row A ledger row.
	 * @return array<string, mixed>|\WP_Error|null A WP_Error with the code LIVE_UNSUPPORTED when no adapter here reads the resource type.
	 */
	public static function live_image( array $row ): array|\WP_Error|null {
		$type    = (string) ( $row['resource_type'] ?? '' );
		$adapter = self::adapter_of( $type );
		if ( null === $adapter || ! in_array( $type, self::READABLE, true ) ) {
			return new \WP_Error( RollbackFamilyHandler::LIVE_UNSUPPORTED, 'The live state of this resource cannot be read.' );
		}
		try {
			return $adapter::image( $type, (string) ( $row['resource_id'] ?? '' ) );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_live_failed', [ 'error' => $failure::class ] );
			return new \WP_Error( 'stonewright_change_live_unreadable', 'The current state of this resource cannot be read.' );
		}
	}

	/**
	 * The adapter that owns a resource type.
	 *
	 * @return class-string<FamilyAdapter>|null
	 */
	private static function adapter_of( string $type ): ?string {
		foreach ( self::RESTORING as $adapter ) {
			if ( in_array( $type, $adapter::types(), true ) ) {
				return $adapter;
			}
		}
		return null;
	}

	/**
	 * @param class-string<FamilyAdapter> $adapter
	 * @param array<string, mixed>|null   $before
	 */
	private static function record_change( string $adapter, string $ability, string $type, string $id, ?array $before, bool $failed ): void {
		try {
			$after = $adapter::image( $type, $id );
			if ( null === $before && null === $after ) {
				return;
			}
			if ( null !== $before && null !== $after && ChangeImage::hash_of( $before, $type, $id ) === ChangeImage::hash_of( $after, $type, $id ) ) {
				return;
			}
			$spec = [
				'ability'       => $ability,
				'family'        => $adapter::ledger_family( $type ),
				'resource_type' => $type,
				'resource_id'   => $id,
				'before'        => $before,
				'summary'       => $adapter::summarize( $ability, $type, $id, $before, $after ),
			];
			if ( null === $before ) {
				$spec['restorable'] = true;
			}
			FamilyLedger::settle( FamilyLedger::record( $spec ), $failed ? 'failed' : 'verified', $after );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_record_failed', [ 'error' => $failure::class, 'ability' => $ability ] );
		}
	}

	/**
	 * @param class-string<FamilyAdapter>                                                   $adapter
	 * @param array{type:string,id:string,summary:string,reason:string,failed:bool} $event
	 */
	private static function record_event( string $adapter, string $ability, array $event ): void {
		try {
			$change = FamilyLedger::record(
				[
					'ability'           => $ability,
					'family'            => $adapter::ledger_family( $event['type'] ),
					'resource_type'     => $event['type'],
					'resource_id'       => $event['id'],
					'before'            => null,
					'restorable'        => false,
					'restorable_reason' => $event['reason'],
					'summary'           => $event['summary'],
				]
			);
			FamilyLedger::settle( $change, $event['failed'] ? 'failed' : 'verified' );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_record_failed', [ 'error' => $failure::class, 'ability' => $ability ] );
		}
	}

	/**
	 * @return array{status:string,detail:string,recipe:string,change_id:string,rollback_change_id:?string,limits:list<string>}
	 */
	private static function unavailable( string $status, string $detail, string $change_id ): array {
		return [
			'status'             => $status,
			'detail'             => $detail,
			'recipe'             => 'ledger',
			'change_id'          => $change_id,
			'rollback_change_id' => null,
			'limits'             => [],
		];
	}
}
