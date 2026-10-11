<?php
/**
 * Skills and design directions in the change ledger: a row links to the revision their own stores keep.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Adapters;

use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\RescueGuard;
use Stonewright\WpMcp\SkillLibrary\Repository;
use Stonewright\WpMcp\SkillLibrary\Site\SkillLibraryService;
use Stonewright\WpMcp\SkillLibrary\Site\WordPressRepository;
use Stonewright\WpMcp\Support\Logger;

/**
 * Skills and design directions keep every revision of their content in tables of their own, and restore
 * through their own services. The ledger keeps no second copy: the image of a row is a link, the identity
 * of the record, the number of the revision, and the hash of its content.
 *
 * Skills (resource type skill, family skill): the resource id is the slug. A change that moved the skill to a new revision is
 * restorable: a restore asks the skill service to roll back to the revision the before link names, which
 * writes a new revision. A skill that a save created is undone by moving it to the trash. A change that
 * keeps no revision (switching a skill on or off, the trash and bringing it back) is recorded and not
 * restorable (no_revision_stored), and so is a permanent delete, which erases the history with the skill
 * (skill_history_deleted).
 *
 * Design directions (resource type design_direction, family design_direction): the resource id is the id of
 * the direction. A save or a restore
 * that made a new revision links the revision before and after; a restore asks the direction service to
 * restore that revision, which writes a new one. A direction that a save created is undone by archiving it.
 * A save that changed only the status, and archiving, make no revision and are not recorded.
 *
 * The active direction (resource type design_direction_pointer, family design_direction). The image holds the id of the
 * active direction, and a restore activates it again, or clears it.
 *
 * The services call skill_before() and skill_after(), direction_changed() and pointer_changed(); a restore
 * that runs the services records nothing through them, because its own rollback row describes it.
 */
final class RevisionLinkAdapter extends FamilyAdapter {

	public const FAMILY = 'skill';

	public const RECIPE = 'revision';

	/** Ability names for the rows the services write, when no ability call is open. */
	private const SKILL_ABILITIES = [
		'save'     => 'stonewright/skills-save',
		'toggle'   => 'stonewright/skills-toggle',
		'trash'    => 'stonewright/skills-trash',
		'restore'  => 'stonewright/skills-restore',
		'destroy'  => 'stonewright/skills-destroy',
		'rollback' => 'stonewright/skills-rollback',
	];

	private static ?DesignDirectionService $direction_service = null;

	/** Use this direction service instead of the default one, or the default again with null. For tests. */
	public static function use_direction_service_for_tests( ?DesignDirectionService $service ): void {
		self::$direction_service = $service;
	}

	public static function types(): array {
		return [ 'skill', 'design_direction', 'design_direction_pointer' ];
	}

	public static function ledger_family( string $type ): string {
		return 'skill' === $type ? 'skill' : 'design_direction';
	}

	public static function image( string $type, string $id ): ?array {
		return match ( $type ) {
			'skill'                    => self::skill_link( self::skill_repository()->find_slug( $id ) ),
			'design_direction'         => self::direction_link( 1 === preg_match( '/^[1-9][0-9]{0,18}$/D', $id ) ? self::direction_service()->get( (int) $id ) : null ),
			'design_direction_pointer' => [ 'v' => self::IMAGE_VERSION, 'active_id' => (int) get_option( DesignDirectionService::ACTIVE_OPTION, 0 ) ],
			default                    => null,
		};
	}

	// -----------------------------------------------------------------------
	// Skills.
	// -----------------------------------------------------------------------

	/**
	 * Take the link of a skill before a write.
	 *
	 * @param int|string $key The id or the slug of the skill.
	 * @return array{slug:string,link:array<string,mixed>|null}|null Null when the write is not recorded.
	 */
	public static function skill_before( Repository $repository, int|string $key ): ?array {
		try {
			if ( FamilyLedger::restoring() || ! FamilyLedger::ready() ) {
				return null;
			}
			$record = is_int( $key ) ? $repository->find_id( $key ) : $repository->find_slug( $key );
			return [
				'slug' => is_array( $record ) && is_string( $record['slug'] ?? null ) ? $record['slug'] : ( is_string( $key ) ? $key : '' ),
				'link' => self::skill_link( $record ),
			];
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_capture_failed', [ 'error' => $failure::class, 'family' => 'skill' ] );
			return null;
		}
	}

	/**
	 * Record a skill write.
	 *
	 * @param string                                                  $action One of save, toggle, trash, restore, destroy, rollback.
	 * @param array{slug:string,link:array<string,mixed>|null}|null   $held   What skill_before() returned.
	 * @param mixed                                                   $result The result of the write: a bool, an id or a WP_Error.
	 */
	public static function skill_after( string $action, ?array $held, Repository $repository, mixed $result ): void {
		if ( null === $held || '' === $held['slug'] ) {
			return;
		}
		try {
			$before  = $held['link'];
			$after   = self::skill_link( $repository->find_slug( $held['slug'] ) );
			$failed  = $result instanceof \WP_Error || false === $result;
			if ( ( null === $before && null === $after ) || ( null !== $before && null !== $after && self::canonical( $before ) === self::canonical( $after ) ) ) {
				return;
			}
			$spec = [
				'ability'       => RescueGuard::current_ability( self::SKILL_ABILITIES[ $action ] ?? 'stonewright/skills-save' ),
				'family'        => 'skill',
				'resource_type' => 'skill',
				'resource_id'   => $held['slug'],
				'before'        => $before,
			];
			if ( null === $before ) {
				$spec['restorable'] = true;
				$spec['summary']    = FamilyLedger::CREATED_SUMMARY . 'skill ' . $held['slug'];
			} elseif ( null === $after ) {
				$spec['restorable']        = false;
				$spec['restorable_reason'] = 'skill_history_deleted';
				$spec['summary']           = 'Deleted skill ' . $held['slug'] . '; its revision history went with it';
			} elseif ( (int) $after['revision'] > (int) $before['revision'] ) {
				$spec['summary'] = 'Updated skill ' . $held['slug'] . ': revision ' . (int) $before['revision'] . ' to ' . (int) $after['revision'];
			} else {
				$spec['restorable']        = false;
				$spec['restorable_reason'] = 'no_revision_stored';
				$spec['summary']           = 'Changed skill ' . $held['slug'] . ' (' . $action . '); no revision is stored for this change';
			}
			$change = FamilyLedger::record( $spec );
			FamilyLedger::settle( $change, $failed ? 'failed' : 'verified', $after );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_record_failed', [ 'error' => $failure::class, 'family' => 'skill' ] );
		}
	}

	/**
	 * @param array<string, mixed>|null $record A skill as the repository returns it.
	 * @return array<string, mixed>|null
	 */
	private static function skill_link( ?array $record ): ?array {
		if ( null === $record || ! is_string( $record['slug'] ?? null ) ) {
			return null;
		}
		return [
			'content_sha256' => hash( 'sha256', (string) ( $record['content'] ?? '' ) ),
			'enabled'        => ! empty( $record['enabled'] ),
			'revision'       => (int) ( $record['revision'] ?? 0 ),
			'skill_id'       => (int) ( $record['id'] ?? 0 ),
			'slug'           => $record['slug'],
			'status'         => (string) ( $record['status'] ?? '' ),
			'v'              => self::IMAGE_VERSION,
		];
	}

	private static function skill_repository(): Repository {
		return new WordPressRepository();
	}

	// -----------------------------------------------------------------------
	// Design directions.
	// -----------------------------------------------------------------------

	/**
	 * Record a save or a restore of a design direction that made a revision.
	 *
	 * @param string               $action save or restore.
	 * @param array<string, mixed> $result What the service returned: id, slug, status, revision, versioned, hash_before, hash_after.
	 */
	public static function direction_changed( string $action, array $result ): void {
		try {
			if ( FamilyLedger::restoring() || true !== ( $result['versioned'] ?? false ) || ! FamilyLedger::ready() ) {
				return;
			}
			$id       = (int) ( $result['id'] ?? 0 );
			$revision = (int) ( $result['revision'] ?? 0 );
			$slug     = (string) ( $result['slug'] ?? '' );
			if ( $id < 1 || $revision < 1 ) {
				return;
			}
			$after  = [ 'contract_hash' => (string) ( $result['hash_after'] ?? '' ), 'direction_id' => $id, 'revision' => $revision, 'slug' => $slug, 'status' => (string) ( $result['status'] ?? '' ), 'v' => self::IMAGE_VERSION ];
			$before = '' === (string) ( $result['hash_before'] ?? '' ) || $revision < 2
				? null
				: [ 'contract_hash' => (string) $result['hash_before'], 'direction_id' => $id, 'revision' => $revision - 1, 'slug' => $slug, 'v' => self::IMAGE_VERSION ];
			$spec   = [
				'ability'       => RescueGuard::current_ability( 'restore' === $action ? 'stonewright/design-direction-restore' : 'stonewright/design-direction-save' ),
				'family'        => 'design_direction',
				'resource_type' => 'design_direction',
				'resource_id'   => (string) $id,
				'before'        => $before,
			];
			if ( null === $before ) {
				$spec['restorable'] = true;
				$spec['summary']    = FamilyLedger::CREATED_SUMMARY . 'design direction ' . $slug;
			} else {
				$spec['summary'] = 'Updated design direction ' . $slug . ': revision ' . ( $revision - 1 ) . ' to ' . $revision;
			}
			$change = FamilyLedger::record( $spec );
			FamilyLedger::settle( $change, 'verified', $after );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_record_failed', [ 'error' => $failure::class, 'family' => 'design_direction' ] );
		}
	}

	/**
	 * Record a change of the active design direction.
	 *
	 * @param string $action activate or deactivate.
	 */
	public static function pointer_changed( string $action, int $previous, int $current ): void {
		try {
			if ( FamilyLedger::restoring() || $previous === $current || ! FamilyLedger::ready() ) {
				return;
			}
			$change = FamilyLedger::record(
				[
					'ability'       => RescueGuard::current_ability( 'stonewright/design-direction-activate' ),
					'family'        => 'design_direction',
					'resource_type' => 'design_direction_pointer',
					'resource_id'   => 'active',
					'before'        => [ 'v' => self::IMAGE_VERSION, 'active_id' => $previous ],
					'summary'       => 'activate' === $action ? 'Activated design direction ' . $current : 'Cleared the active design direction (was ' . $previous . ')',
				]
			);
			FamilyLedger::settle( $change, 'verified', [ 'v' => self::IMAGE_VERSION, 'active_id' => $current ] );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_ledger_record_failed', [ 'error' => $failure::class, 'family' => 'design_direction' ] );
		}
	}

	private static function direction_service(): DesignDirectionService {
		return self::$direction_service ?? new DesignDirectionService();
	}

	/**
	 * @param array<string, mixed>|null $record A direction as the service returns it.
	 * @return array<string, mixed>|null
	 */
	private static function direction_link( ?array $record ): ?array {
		if ( null === $record ) {
			return null;
		}
		return [
			'contract_hash' => (string) ( $record['contract_hash'] ?? '' ),
			'direction_id'  => (int) ( $record['id'] ?? 0 ),
			'revision'      => (int) ( $record['revision'] ?? 0 ),
			'slug'          => (string) ( $record['slug'] ?? '' ),
			'status'        => (string) ( $record['status'] ?? '' ),
			'v'             => self::IMAGE_VERSION,
		];
	}

	// -----------------------------------------------------------------------
	// Restore.
	// -----------------------------------------------------------------------

	protected static function subject( string $type, ?array $image, string $id ): string {
		return match ( $type ) {
			'skill'                    => 'skill ' . FamilyLedger::clip( $id, 60 ),
			'design_direction_pointer' => 'the active design direction',
			default                    => 'design direction ' . ( is_array( $image ) && '' !== (string) ( $image['slug'] ?? '' ) ? FamilyLedger::clip( (string) $image['slug'], 60 ) : '#' . $id ),
		};
	}

	protected static function removed( string $type, ?array $live ): bool {
		return null === $live || ( 'skill' === $type && 'trashed' === ( $live['status'] ?? '' ) ) || ( 'design_direction' === $type && 'archived' === ( $live['status'] ?? '' ) );
	}

	/**
	 * A skill or a direction is as an image says when its content is: a restore writes a new revision, so the
	 * numbers of the revisions differ.
	 */
	protected static function matches( string $type, array $target, array $live ): bool {
		return match ( $type ) {
			'skill'            => (string) ( $target['content_sha256'] ?? '' ) === (string) ( $live['content_sha256'] ?? '' ),
			'design_direction' => (string) ( $target['contract_hash'] ?? '' ) === (string) ( $live['contract_hash'] ?? '' ),
			default            => self::canonical( $target ) === self::canonical( $live ),
		};
	}

	protected static function permitted( string $type, string $operation ): bool {
		return 'skill' === $type ? Permissions::manage_options() : Permissions::can_manage_design();
	}

	protected static function write( string $type, string $id, ?array $target, array $row, array $options, ?array $live ): array {
		return match ( $type ) {
			'skill'                    => self::write_skill( $id, $target, $live ),
			'design_direction'         => self::write_direction( $id, $target, $live ),
			'design_direction_pointer' => self::write_pointer( $target ),
			default                    => self::refused( 'not_restorable' ),
		};
	}

	/**
	 * @param array<string, mixed>|null $target
	 * @param array<string, mixed>|null $live
	 * @return array{ok:bool,applied:bool,detail:string,id:string,limits:list<string>}
	 */
	private static function write_skill( string $slug, ?array $target, ?array $live ): array {
		$library = SkillLibraryService::open();
		if ( null === $live ) {
			return self::refused( 'skill_missing' );
		}
		if ( null === $target ) {
			$done = $library->move_to_trash( (int) ( $live['skill_id'] ?? 0 ) );
			if ( $done instanceof \WP_Error ) {
				return self::refused( self::short_code( $done ) );
			}
			$after = self::image( 'skill', $slug );
			return self::applied( null !== $after && 'trashed' === $after['status'], 'trashed', $slug );
		}
		if ( (int) $target['revision'] >= (int) $live['revision'] ) {
			return self::refused( 'no_revision_to_restore' );
		}
		$done = $library->roll_back_skill( $slug, (int) $target['revision'] );
		if ( $done instanceof \WP_Error ) {
			return self::refused( self::short_code( $done ) );
		}
		$after = self::image( 'skill', $slug );
		return self::applied( null !== $after && $after['content_sha256'] === $target['content_sha256'], 'rolled_back', $slug );
	}

	/**
	 * @param array<string, mixed>|null $target
	 * @param array<string, mixed>|null $live
	 * @return array{ok:bool,applied:bool,detail:string,id:string,limits:list<string>}
	 */
	private static function write_direction( string $id, ?array $target, ?array $live ): array {
		$service = self::direction_service();
		if ( null === $live ) {
			return self::refused( 'direction_missing' );
		}
		if ( null === $target ) {
			$done = $service->archive( (int) $id, (int) get_current_user_id() );
			if ( $done instanceof \WP_Error ) {
				return self::refused( self::short_code( $done ) );
			}
			$after = self::image( 'design_direction', $id );
			return self::applied( null !== $after && 'archived' === $after['status'], 'archived', $id );
		}
		$done = $service->restore( (int) $id, (int) $target['revision'], (int) get_current_user_id() );
		if ( $done instanceof \WP_Error ) {
			return self::refused( self::short_code( $done ) );
		}
		$after = self::image( 'design_direction', $id );
		return self::applied( null !== $after && $after['contract_hash'] === $target['contract_hash'], 'restored', $id );
	}

	/**
	 * @param array<string, mixed>|null $target
	 * @return array{ok:bool,applied:bool,detail:string,id:string,limits:list<string>}
	 */
	private static function write_pointer( ?array $target ): array {
		if ( null === $target ) {
			return self::refused( 'not_restorable' );
		}
		$service = self::direction_service();
		$wanted  = (int) ( $target['active_id'] ?? 0 );
		$done    = $wanted > 0 ? $service->activate( $wanted, (int) get_current_user_id() ) : $service->deactivate( (int) get_current_user_id() );
		if ( $done instanceof \WP_Error ) {
			return self::refused( self::short_code( $done ) );
		}
		return self::applied( (int) get_option( DesignDirectionService::ACTIVE_OPTION, 0 ) === $wanted, 'restored', 'active' );
	}
}
