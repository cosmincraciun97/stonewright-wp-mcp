<?php
/**
 * The rollback engine: puts a resource back as a ledger row recorded it, for any row, in any state.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Security\Adapters\CodeAdapter;
use Stonewright\WpMcp\Security\Rollback\FamilySupport;
use Stonewright\WpMcp\Security\Rollback\RollbackClaim;
use Stonewright\WpMcp\Security\Rollback\RollbackFamilies;
use Stonewright\WpMcp\Security\Rollback\RollbackFamilyHandler;
use Stonewright\WpMcp\Support\Diff\ChangeDiff;
use Stonewright\WpMcp\Support\Logger;

/**
 * `ChangeRollback::run( $change_id, $options )` undoes one row of the change ledger by writing its before image back
 * through the handler of its family (RollbackFamilies), and a redo is the same call on the rollback row.
 *
 * Options: dry_run, actor (user id for the new row; default the current user), human_approved (an administrator at
 * wp-admin pressed the button), confirmation_token (production-safe mode), expected_current_sha256 (the live state the
 * caller previewed; the whole hash or its first 32 characters), force_drift, permanent (passed to families that can
 * remove for good) and by (ability, admin-page or cli; for the audit row).
 *
 * The rules, in the order they are applied:
 *
 * 1. Permission: manage_options.
 * 2. The row must exist, be a change, rollback or redo row, not already be rolled back, be restorable and have a
 *    handler. Otherwise the run is refused with the reason.
 * 3. The plan: what is live now, the diff of the undo (live state against the before image), drift (the live state
 *    does not hash to the after image of the row), newer changes to the same resource.
 * 4. A dry run returns the plan and stops. Nothing is written but one audit row.
 * 5. Code (CodeAdapter::FAMILIES, and any family whose handler asks for it) needs human_approved, or the answer is
 *    the approval-required answer of RescueRollback. The Changes page passes it; an agent or WP-CLI call does not.
 * 6. Drift is refused unless force_drift, a state that is not the previewed one is refused, and in production-safe
 *    mode a confirmation token bound to the change id and these options is verified (only now, so that a refusal
 *    above does not use the token up).
 * 7. The resource is claimed (RollbackClaim), the live state is read again, a baseline probe is taken, the handler
 *    restores, a rollback row is written (kind rollback, or redo when the row acted on is a rollback row), and the site
 *    is probed with the retry. A probe that fails after a site that was not failing before puts the earlier state
 *    back and reports it.
 *
 * The journal link: when the Rescue journal has an entry with the same id and the entry is open (armed, incident,
 * rollback_failed), or is verified and its recipe can still run, the rollback goes through RescueRollback::run(), the
 * path the Rescue page and ability use, so the journal and the ledger stay one story: the journal entry settles as it
 * always did, and the ledger gets its rollback row. An open incident needs neither force nor an approval, because the
 * site is failing. Anything else goes through the handler of the family; if the journal still lists the change as
 * verified it is settled as rolled back.
 *
 * Every call writes one audit row, a dry run and a refusal included, and its result carries a receipt.
 */
final class ChangeRollback {

	public const ABILITY = 'stonewright/change-rollback';

	/** The wp-admin page where an administrator presses Undo. */
	private const CHANGES_PAGE = 'stonewright-changes';

	/** Shortest start of a hash that expected_current_sha256 may be. The page prints this much, never a whole hash. */
	public const MIN_HASH_PREFIX = 32;

	private const POST_FAMILIES = [ 'post', 'elementor', 'gutenberg', 'fse', 'global_styles' ];

	/** @var list<string> Statuses of a row that was undone already. */
	private const UNDONE = [ 'rolled_back', 'rolled_back_by' ];

	// -----------------------------------------------------------------------
	// Public API.
	// -----------------------------------------------------------------------

	/**
	 * What a confirmation token for a run is issued over, and verified against: the change id and the options that
	 * change what the run does.
	 *
	 * @param array<string, mixed> $options force_drift, expected_current_sha256, permanent.
	 * @return array{change_id:string,force_drift:bool,expected_current_sha256:string,permanent:bool}
	 */
	public static function confirmation_args( string $change_id, array $options ): array {
		return [
			'change_id'               => $change_id,
			'force_drift'             => ! empty( $options['force_drift'] ),
			'expected_current_sha256' => self::clean_hash( $options['expected_current_sha256'] ?? '' ),
			'permanent'               => ! empty( $options['permanent'] ),
		];
	}

	/**
	 * What a run on this row would write: a redo for a rollback row, a rollback for anything else.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function action_for( array $row ): string {
		return 'rollback' === (string) ( $row['kind'] ?? '' ) ? 'redo' : 'rollback';
	}

	/**
	 * The rollback row to redo for a change that was rolled back: the newest rollback of it that is still in effect.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>|null
	 */
	public static function redo_target( array $row ): ?array {
		if ( ! in_array( (string) ( $row['status'] ?? '' ), self::UNDONE, true ) || ! in_array( (string) ( $row['kind'] ?? '' ), [ 'change', 'redo' ], true ) ) {
			return null;
		}
		$found = null;
		foreach ( ChangeLedger::children( (string) $row['change_id'] ) as $child ) {
			if ( 'rollback' === $child['kind'] && in_array( $child['status'], [ 'verified', 'probe_unavailable' ], true ) ) {
				$found = $child;
			}
		}
		return $found;
	}

	/**
	 * Run a rollback, or with dry_run the plan of one. Writes one audit row either way.
	 *
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $change_id, array $options = [] ): array|\WP_Error {
		$options = self::normalize( $options );
		$result  = self::execute( $change_id, $options );
		return self::receipt( $change_id, $options, $result );
	}

	/**
	 * The plan of a run, for a page that shows it. The same answer as a dry run, without the audit row: viewing a
	 * change is not a run.
	 *
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function plan( string $change_id, array $options = [] ): array|\WP_Error {
		return self::execute( $change_id, array_merge( self::normalize( $options ), [ 'dry_run' => true ] ) );
	}

	// -----------------------------------------------------------------------
	// The run.
	// -----------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $options Normalized.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function execute( string $change_id, array $options ): array|\WP_Error {
		if ( ! Permissions::manage_options() ) {
			return self::error( 'stonewright_change_forbidden', __( 'Only an administrator can undo or redo a change.', 'stonewright' ), 403 );
		}
		$context = self::load( $change_id );
		if ( $context instanceof \WP_Error ) {
			return $context;
		}
		$state = self::analyse( $context );
		if ( $state instanceof \WP_Error ) {
			return $state;
		}
		$plan = $state['plan'];
		if ( $options['dry_run'] ) {
			return array_merge( [ 'ok' => true, 'dry_run' => true ], $plan );
		}

		$row = $context['row'];
		if ( $plan['approval_required'] && ! $options['human_approved'] ) {
			return self::approval_required( $row );
		}
		if ( $state['strict'] ) {
			if ( $plan['already_restored'] ) {
				return self::error( 'stonewright_change_already_restored', __( 'The item already is as it was before this change. There is nothing to undo.', 'stonewright' ), 409 );
			}
			if ( $plan['requires_force'] && ! $options['force_drift'] ) {
				return self::error( 'stonewright_change_drift', __( 'This item has changed since the recorded change, so undoing it would overwrite those later edits. Review the plan, and run again with force_drift to overwrite them.', 'stonewright' ), 409, [ 'drift' => true, 'warnings' => $plan['warnings'], 'current_sha256' => $plan['current_sha256'], 'expected_sha256' => $plan['expected_sha256'] ] );
			}
		}
		if ( '' !== $options['expected_current_sha256'] && $state['supported'] && ! self::hash_matches( $options['expected_current_sha256'], $plan['current_sha256'] ) ) {
			return self::error( 'stonewright_change_changed_since_preview', __( 'The item is not in the state that was previewed. Review the plan again.', 'stonewright' ), 409, [ 'current_sha256' => $plan['current_sha256'] ] );
		}
		if ( Permissions::is_production_safe() ) {
			$token = $options['confirmation_token'];
			if ( '' === $token ) {
				return self::error( 'stonewright_confirmation_required', __( 'Production-safe mode requires a confirmation_token.', 'stonewright' ), 403 );
			}
			$verified = ConfirmationToken::verify_or_error( $token, self::ABILITY, self::confirmation_args( $row['change_id'], $options ) );
			if ( $verified instanceof \WP_Error ) {
				return $verified;
			}
		}

		if ( ! RollbackClaim::take( $row ) ) {
			return self::error( 'stonewright_change_in_progress', __( 'A rollback of this item is already running. Check again in a minute.', 'stonewright' ), 409, [ 'change_id' => $row['change_id'] ] );
		}
		try {
			if ( $state['supported'] ) {
				$again = $context['handler']->live_image( $row );
				$hash  = $again instanceof \WP_Error ? '' : ChangeImage::hash_of( $again, (string) $row['resource_type'], (string) $row['resource_id'] );
				if ( $again instanceof \WP_Error || $hash !== $plan['current_sha256'] ) {
					return self::error( 'stonewright_change_changed_since_preview', __( 'The item changed while this run was waiting to start. Review the plan again.', 'stonewright' ), 409, [ 'current_sha256' => $hash ] );
				}
			}
			return $state['journal'] && null !== $state['entry']
				? self::run_journal( $context, $state, $options )
				: self::run_handler( $context, $state, $options );
		} finally {
			RollbackClaim::release( $row );
		}
	}

	/**
	 * The row, its handler and what kind of row a run would write. Refusals that need no live read.
	 *
	 * @return array{row:array<string,mixed>,handler:RollbackFamilyHandler,kind:string}|\WP_Error
	 */
	private static function load( string $change_id ): array|\WP_Error {
		if ( ! ChangeLedger::is_valid_id( $change_id ) ) {
			return self::error( 'stonewright_change_invalid_id', __( 'That is not a change id.', 'stonewright' ), 400 );
		}
		$row = ChangeLedger::get( $change_id );
		if ( null === $row ) {
			return self::error( 'stonewright_change_not_found', __( 'No change in the history has that id. Retention may have removed it.', 'stonewright' ), 404 );
		}
		if ( ! in_array( $row['kind'], [ 'change', 'rollback', 'redo' ], true ) ) {
			return self::error( 'stonewright_change_kind_unsupported', __( 'This entry is not a change that can be undone.', 'stonewright' ), 409 );
		}
		if ( in_array( $row['status'], self::UNDONE, true ) ) {
			$target = self::redo_target( $row );
			return self::error( 'stonewright_change_already_rolled_back', __( 'This change was already rolled back. Redo the rollback instead.', 'stonewright' ), 409, [ 'rollback_change_id' => null === $target ? '' : (string) $target['change_id'] ] );
		}
		if ( ! $row['restorable'] ) {
			return self::error(
				'stonewright_change_not_restorable',
				sprintf( /* translators: %s: reason code, for example too_large */ __( 'This change cannot be undone from the history (%s).', 'stonewright' ), '' !== (string) $row['restorable_reason'] ? (string) $row['restorable_reason'] : 'not_restorable' ),
				409,
				[ 'reason' => (string) $row['restorable_reason'] ]
			);
		}
		$handler = RollbackFamilies::handler_for( (string) $row['family'] );
		if ( null === $handler ) {
			return self::error( 'stonewright_change_family_unsupported', __( 'Changes of this kind cannot be undone from the history yet.', 'stonewright' ), 409, [ 'family' => (string) $row['family'] ] );
		}
		return [ 'row' => $row, 'handler' => $handler, 'kind' => self::action_for( $row ) ];
	}

	/**
	 * What the live state is, what the undo would change, and what to know before it.
	 *
	 * @param array{row:array<string,mixed>,handler:RollbackFamilyHandler,kind:string} $context
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function analyse( array $context ): array|\WP_Error {
		$row     = $context['row'];
		$handler = $context['handler'];
		$type    = (string) $row['resource_type'];
		$id      = (string) $row['resource_id'];

		$entry   = ChangeJournal::get( (string) $row['change_id'] );
		$open    = null !== $entry && in_array( (string) $entry['state'], [ 'armed', 'incident', 'rollback_failed' ], true );
		$journal = null !== $entry && ( $open || ( 'verified' === (string) $entry['state'] && RollbackRecipes::available( $entry ) ) );

		$live      = $handler->live_image( $row );
		$supported = ! ( $live instanceof \WP_Error && RollbackFamilyHandler::LIVE_UNSUPPORTED === $live->get_error_code() );
		if ( $live instanceof \WP_Error && $supported ) {
			return self::error( 'stonewright_change_live_unreadable', __( 'The current state of this item cannot be read, so an undo cannot be checked.', 'stonewright' ), 409, [ 'detail' => FamilySupport::short_code( $live ) ] );
		}

		$target    = null;
		$target_ok = true;
		if ( '' !== (string) $row['before_ref'] ) {
			$image = ChangeLedger::read_image( (string) $row['change_id'], 'before' );
			if ( $image instanceof \WP_Error ) {
				if ( ! $handler->records_own_row( $row ) ) {
					return self::error( 'stonewright_change_image_unreadable', __( 'The stored content of this change is no longer available, so it cannot be restored.', 'stonewright' ), 409 );
				}
				$target_ok = false;
			} else {
				$target = $image;
			}
		}

		$current = '';
		$diff    = self::no_diff( 'no_live', __( 'Stonewright cannot read the current state of this kind of item, so it cannot show what an undo would change.', 'stonewright' ) );
		$raw     = null;
		if ( $supported && ! ( $live instanceof \WP_Error ) ) {
			$raw     = $live;
			$current = ChangeImage::hash_of( $live, $type, $id );
			$masked  = ChangeImage::prepare( $live, $type, $id );
			if ( '' !== $masked['refused'] || ! $target_ok ) {
				$diff = self::no_diff( 'unreadable', __( 'There is no diff to show for this change.', 'stonewright' ) );
			} else {
				$left = null === $masked['bytes'] ? null : ChangeImage::decode( $masked['bytes'] );
				$diff = ChangeDiff::for_images( $row, $left, $target );
			}
		}

		$strict   = ! ( $journal && $open );
		$drift    = $supported && $current !== (string) $row['after_sha256'];
		$restored = $supported && $current === (string) $row['before_sha256'];
		$newer    = self::newer_changes( $row );
		$code     = in_array( (string) $row['family'], CodeAdapter::FAMILIES, true ) || $handler->requires_human( $row );

		$warnings = [];
		if ( $drift ) {
			$warnings[] = __( 'This item has changed since the recorded change (it was edited, or another tool wrote to it). Undoing it overwrites those later edits.', 'stonewright' );
		}
		if ( ! $supported ) {
			$warnings[] = __( 'Stonewright cannot compare the current state of this item with the recorded change, so it cannot tell whether it changed since.', 'stonewright' );
		}
		if ( [] !== $newer ) {
			$ids        = implode( ', ', array_map( static fn ( array $other ): string => substr( (string) preg_replace( '/^cs-/', '', (string) $other['change_id'] ), 0, 8 ), array_slice( $newer, 0, 3 ) ) );
			$warnings[] = sprintf( /* translators: %s: change ids */ __( 'A newer change to the same item exists (%s). Undoing this one also overwrites it.', 'stonewright' ), $ids );
		}

		$plan = [
			'change_id'             => (string) $row['change_id'],
			'kind'                  => $context['kind'],
			'family'                => (string) $row['family'],
			'resource_type'         => $type,
			'resource_id'           => $id,
			'summary'               => (string) $row['summary'],
			'status'                => (string) $row['status'],
			'path'                  => $journal ? 'journal' : 'ledger',
			'restorable'            => true,
			'diff'                  => $diff,
			'drift'                 => $drift,
			'drift_known'           => $supported,
			'requires_force'        => $drift && $strict,
			'current_sha256'        => $current,
			'expected_sha256'       => (string) $row['after_sha256'],
			'already_restored'      => $restored && $strict,
			'newer_changes'         => array_map(
				static fn ( array $other ): array => [
					'change_id' => (string) $other['change_id'],
					'ability'   => (string) $other['ability'],
					'since'     => gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( (string) $other['created_at'] . ' UTC' ) ),
				],
				$newer
			),
			'warnings'              => $warnings,
			'approval_required'     => $code && $strict,
			'confirmation_required' => Permissions::is_production_safe(),
			'would_apply'           => $handler->describe( $row, $target ),
		];
		if ( $plan['approval_required'] ) {
			$plan['approval_url'] = self::approval_url( (string) $row['change_id'] );
		}

		return [
			'plan'      => $plan,
			'live'      => $raw,
			'target'    => $target,
			'target_ok' => $target_ok,
			'supported' => $supported,
			'entry'     => $entry,
			'journal'   => $journal,
			'open'      => $open,
			'strict'    => $strict,
		];
	}

	// -----------------------------------------------------------------------
	// The two paths.
	// -----------------------------------------------------------------------

	/**
	 * The journal path: RescueRollback runs the recipe, claims and settles the journal entry; the ledger gets its rollback row.
	 *
	 * @param array{row:array<string,mixed>,handler:RollbackFamilyHandler,kind:string} $context
	 * @param array<string, mixed>                                                     $state
	 * @param array<string, mixed>                                                     $options
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function run_journal( array $context, array $state, array $options ): array|\WP_Error {
		$row    = $context['row'];
		$result = RescueRollback::run(
			(string) $row['change_id'],
			[
				'by'             => $options['by'],
				'user_id'        => $options['actor'],
				'human_approved' => $options['human_approved'],
			]
		);
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		$new_id   = '';
		$resource = (string) $row['resource_id'];
		$after    = self::read_live( $context['handler'], $row );
		$site     = (string) ( $result['site_status'] ?? '' );
		$created  = self::record_row( $row, $context['kind'], $state['live'], $after, 'healthy' === $site ? 'verified' : 'probe_unavailable', $resource, $options['actor'] );
		if ( null !== $created ) {
			$new_id = (string) $created['change_id'];
			self::mark_after_success( $row, $context['kind'] );
		}
		return array_merge(
			$result,
			[
				'change_id'          => (string) $row['change_id'],
				'kind'               => $context['kind'],
				'path'               => 'journal',
				'rollback_change_id' => $new_id,
				'resource_id'        => $resource,
				'drift_forced'       => $state['plan']['drift'] && $options['force_drift'],
				'restorable_again'   => '' !== $new_id,
			]
		);
	}

	/**
	 * The handler path.
	 *
	 * @param array{row:array<string,mixed>,handler:RollbackFamilyHandler,kind:string} $context
	 * @param array<string, mixed>                                                     $state
	 * @param array<string, mixed>                                                     $options
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function run_handler( array $context, array $state, array $options ): array|\WP_Error {
		$row     = $context['row'];
		$handler = $context['handler'];
		$kind    = $context['kind'];
		$own     = $handler->records_own_row( $row );
		$probe   = self::probe_context( $row, $options['actor'] );
		$base    = HealthProbe::run( $probe );
		$pre     = $state['live'];

		$restore = $handler->restore(
			$row,
			$state['target'],
			[
				'kind'                    => $kind,
				'expected_current_sha256' => $state['plan']['current_sha256'],
				'permanent'               => $options['permanent'],
				'actor'                   => $options['actor'],
			]
		);
		$status   = (string) ( $restore['status'] ?? 'failed' );
		$limits   = array_values( array_map( 'strval', (array) ( $restore['limits'] ?? [] ) ) );
		$resource = '' !== (string) ( $restore['resource_id'] ?? '' ) ? (string) $restore['resource_id'] : (string) $row['resource_id'];
		$moved    = array_merge( $row, [ 'resource_id' => $resource ] );

		if ( 'noop' === $status ) {
			return [
				'ok'                 => true,
				'change_id'          => (string) $row['change_id'],
				'kind'               => $kind,
				'path'               => 'ledger',
				'rollback_status'    => 'noop',
				'rollback_change_id' => '',
				'resource_id'        => $resource,
				'state'              => (string) $row['status'],
				'site_status'        => 'unknown',
				'verification_status' => 'unverified',
				'drift_forced'       => false,
				'limits'             => $limits,
				'restorable_again'   => false,
			];
		}
		if ( 'succeeded' !== $status ) {
			return self::failed_attempt( $context, $state, $options, $restore, $own );
		}

		$after = $state['supported'] ? self::read_live( $handler, $moved ) : null;
		if ( $own ) {
			$child = isset( $restore['rollback_change_id'] ) && is_string( $restore['rollback_change_id'] ) ? ChangeLedger::get( $restore['rollback_change_id'] ) : null;
		} else {
			$child = self::record_row( $row, $kind, $pre, $after, 'armed', $resource, $options['actor'] );
		}
		$child_id = null === $child ? '' : (string) $child['change_id'];

		[ $site, $judged ] = self::judge_site( $probe, $base, $row );
		$failing           = 'still_failing' === $site;
		$base_failed       = 'failed' === (string) ( $base['status'] ?? '' );

		if ( $failing && ! $base_failed ) {
			return self::revert( $context, $state, $options, $child, $pre, $after, $judged, $own );
		}

		$final = 'healthy' === $site ? 'verified' : 'probe_unavailable';
		if ( '' !== $child_id ) {
			self::mark( $child_id, $final );
		}
		self::settle_journal( $state['entry'], $kind );
		self::mark_after_success( $row, $kind );

		$out = [
			'ok'                  => true,
			'change_id'           => (string) $row['change_id'],
			'kind'                => $kind,
			'path'                => 'ledger',
			'rollback_status'     => 'succeeded',
			'rollback_change_id'  => $child_id,
			'resource_id'         => $resource,
			'state'               => $final,
			'site_status'         => $site,
			'verification_status' => 'healthy' === $site ? 'verified' : ( $failing ? 'failed' : 'unverified' ),
			'probe'               => RescueRollback::compact_probe( $judged ),
			'drift_forced'        => $state['plan']['drift'] && $options['force_drift'],
			'warnings'            => $failing ? [ __( 'The site was failing before this restore and still is: the restore was kept.', 'stonewright' ) ] : [],
			'limits'              => $limits,
			'restorable_again'    => '' !== $child_id,
		];
		return $out;
	}

	/**
	 * The restore did not complete. For a handler without a row of its own, record the attempt and put the earlier
	 * state back when the restore left the item half changed.
	 *
	 * @param array{row:array<string,mixed>,handler:RollbackFamilyHandler,kind:string} $context
	 * @param array<string, mixed>                                                     $state
	 * @param array<string, mixed>                                                     $options
	 * @param array<string, mixed>                                                     $restore
	 */
	private static function failed_attempt( array $context, array $state, array $options, array $restore, bool $own ): \WP_Error {
		$row      = $context['row'];
		$handler  = $context['handler'];
		$child_id = '';
		$reverted = false;
		if ( $own ) {
			$child_id = isset( $restore['rollback_change_id'] ) && is_string( $restore['rollback_change_id'] ) ? $restore['rollback_change_id'] : '';
		} else {
			$now = $state['supported'] ? self::read_live( $handler, $row ) : null;
			if ( $state['supported'] && ChangeImage::hash_of( $now, (string) $row['resource_type'], (string) $row['resource_id'] ) !== $state['plan']['current_sha256'] ) {
				$back     = $handler->restore( $row, $state['live'], [ 'kind' => 'rollback', 'expected_current_sha256' => '', 'permanent' => false, 'actor' => $options['actor'] ] );
				$reverted = 'succeeded' === ( $back['status'] ?? '' );
			}
			$attempt  = self::record_row( $row, $context['kind'], $state['live'], $state['supported'] ? self::read_live( $handler, $row ) : null, 'failed', (string) $row['resource_id'], $options['actor'] );
			$child_id = null === $attempt ? '' : (string) $attempt['change_id'];
		}
		return self::error(
			'stonewright_change_rollback_failed',
			__( 'The rollback did not complete. The change is still in effect unless the details say otherwise.', 'stonewright' ),
			500,
			[
				'rollback_status'    => 'failed',
				'detail'             => (string) ( $restore['detail'] ?? '' ),
				'rollback_change_id' => $child_id,
				'reverted'           => $reverted,
			]
		);
	}

	/**
	 * The probe failed after the restore: put the state from before the restore back, and report both.
	 *
	 * @param array{row:array<string,mixed>,handler:RollbackFamilyHandler,kind:string} $context
	 * @param array<string, mixed>                                                     $state
	 * @param array<string, mixed>                                                     $options
	 * @param array<string, mixed>|null                                                $child   The rollback row.
	 * @param string|array<mixed>|null                                                 $pre     The state before the restore.
	 * @param string|array<mixed>|null                                                 $after   The state after the restore.
	 * @param array<string, mixed>                                                     $judged  The failing probe.
	 */
	private static function revert( array $context, array $state, array $options, ?array $child, string|array|null $pre, string|array|null $after, array $judged, bool $own ): \WP_Error {
		$row      = $context['row'];
		$handler  = $context['handler'];
		$child_id = null === $child ? '' : (string) $child['change_id'];
		$data     = [
			'rollback_status'    => 'reverted',
			'site_status'        => 'still_failing',
			'probe'              => RescueRollback::compact_probe( $judged ),
			'rollback_change_id' => $child_id,
		];
		if ( null === $child ) {
			return self::error( 'stonewright_change_revert_failed', __( 'The rollback made the site fail and could not be taken back, because it was not recorded. Check the site now.', 'stonewright' ), 500, array_merge( $data, [ 'rollback_status' => 'failed', 'reverted' => false ] ) );
		}

		$hash    = ChangeImage::hash_of( $after, (string) $child['resource_type'], (string) $child['resource_id'] );
		$back    = $handler->restore( $child, $own ? null : $pre, [ 'kind' => 'redo', 'expected_current_sha256' => $hash, 'permanent' => false, 'actor' => $options['actor'] ] );
		$undone  = 'succeeded' === ( $back['status'] ?? '' );
		if ( ! $undone ) {
			self::mark( $child_id, 'probe_unavailable' );
			return self::error( 'stonewright_change_revert_failed', __( 'The rollback made the site fail and the earlier state could not be put back. Check the site now.', 'stonewright' ), 500, array_merge( $data, [ 'rollback_status' => 'failed', 'reverted' => false, 'detail' => (string) ( $back['detail'] ?? '' ) ] ) );
		}

		$probe             = self::probe_context( $row, $options['actor'] );
		[ $site, $second ] = self::judge_site( $probe, [], $row );
		$revert_id         = '';
		if ( $own ) {
			$revert_id = isset( $back['rollback_change_id'] ) && is_string( $back['rollback_change_id'] ) ? $back['rollback_change_id'] : '';
		} else {
			$moved     = array_merge( $child, [ 'family' => $row['family'] ] );
			$made      = self::record_row( $moved, 'redo', $after, self::read_live( $handler, $moved ), 'armed', (string) $child['resource_id'], $options['actor'] );
			$revert_id = null === $made ? '' : (string) $made['change_id'];
		}
		if ( '' !== $revert_id ) {
			self::mark( $revert_id, 'healthy' === $site ? 'verified' : 'probe_unavailable' );
		}
		self::mark( $child_id, 'rolled_back_by' );

		return self::error(
			'stonewright_change_rollback_reverted',
			__( 'The rollback made the site fail its health check, so the earlier state was put back. The change is still in effect.', 'stonewright' ),
			500,
			array_merge( $data, [ 'reverted' => true, 'revert_change_id' => $revert_id, 'site_after_revert' => $site, 'probe_after_revert' => RescueRollback::compact_probe( $second ) ] )
		);
	}

	// -----------------------------------------------------------------------
	// Rows and statuses.
	// -----------------------------------------------------------------------

	/**
	 * Write a rollback or redo row under $parent and settle it. Never throws: a ledger that cannot record is logged.
	 *
	 * @param array<string, mixed>     $parent
	 * @param string|array<mixed>|null $before
	 * @param string|array<mixed>|null $after
	 * @return array<string, mixed>|null The row, or null when nothing was recorded.
	 */
	private static function record_row( array $parent, string $kind, string|array|null $before, string|array|null $after, string $status, string $resource_id, int $actor ): ?array {
		try {
			$spec = [
				'ability'       => self::ABILITY,
				'family'        => (string) $parent['family'],
				'resource_type' => (string) $parent['resource_type'],
				'resource_id'   => $resource_id,
				'kind'          => $kind,
				'parent_id'     => (string) $parent['change_id'],
				'actor'         => $actor,
				'before'        => $before,
				'summary'       => ( 'redo' === $kind ? __( 'Redone: ', 'stonewright' ) : __( 'Rolled back: ', 'stonewright' ) ) . (string) $parent['summary'],
			];
			if ( null === $before && 'failed' !== $status ) {
				// The state before was an item that did not exist: undoing this row removes it.
				$spec['restorable'] = true;
			}
			$row = ChangeLedger::record( $spec );
			if ( $row instanceof \WP_Error ) {
				Logger::warning( 'change_rollback_record_failed', [ 'code' => $row->get_error_code() ] );
				return null;
			}
			$result = [ 'status' => $status ];
			if ( null !== $after ) {
				$result['after'] = $after;
			}
			$settled = ChangeLedger::settle( (string) $row['change_id'], $result );
			return $settled instanceof \WP_Error ? $row : $settled;
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_rollback_record_failed', [ 'error' => $failure::class ] );
			return null;
		}
	}

	private static function mark( string $change_id, string $status ): void {
		try {
			ChangeLedger::settle( $change_id, [ 'status' => $status ] );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_rollback_status_failed', [ 'error' => $failure::class ] );
		}
	}

	/**
	 * The row acted on is rolled back; the change it descends from is back in effect after a redo and undone after a rollback.
	 *
	 * @param array<string, mixed> $acted
	 */
	private static function mark_after_success( array $acted, string $kind ): void {
		self::mark( (string) $acted['change_id'], 'rolled_back_by' );
		$root = RollbackFamilies::root_of( $acted );
		if ( (string) $root['change_id'] !== (string) $acted['change_id'] ) {
			self::mark( (string) $root['change_id'], 'redo' === $kind ? 'verified' : 'rolled_back_by' );
		}
	}

	/**
	 * A change that the journal still lists as verified is settled as rolled back, so the journal and the ledger agree.
	 *
	 * @param array<string, mixed>|null $entry
	 */
	private static function settle_journal( ?array $entry, string $kind ): void {
		if ( null === $entry || 'rollback' !== $kind || 'verified' !== (string) $entry['state'] ) {
			return;
		}
		ChangeJournal::settle( (string) $entry['id'], 'rolled_back', [ 'note' => 'undone_from_change_history' ] );
	}

	/**
	 * The live state of a row's resource, or null when it does not exist or cannot be read.
	 *
	 * @param array<string, mixed> $row
	 * @return string|array<mixed>|null
	 */
	private static function read_live( RollbackFamilyHandler $handler, array $row ): string|array|null {
		try {
			$live = $handler->live_image( $row );
			return $live instanceof \WP_Error ? null : $live;
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_rollback_live_failed', [ 'error' => $failure::class ] );
			return null;
		}
	}

	/**
	 * Newer changes to the same resource: later rows of kind change that are still in effect. A change that was rolled
	 * back itself left the resource as this one made it. Rollback and redo rows are consequences, not changes.
	 *
	 * @param array<string, mixed> $row
	 * @return list<array<string, mixed>>
	 */
	private static function newer_changes( array $row ): array {
		try {
			$filters = 'option' === $row['family']
				? [ 'family' => 'option', 'kind' => 'change' ]
				: [ 'resource_type' => (string) $row['resource_type'], 'resource_id' => (string) $row['resource_id'], 'kind' => 'change' ];
			$list    = ChangeLedger::list( $filters, ChangeLedger::MAX_PER_PAGE );
			$names   = self::option_names( (string) $row['resource_id'] );
			$out     = [];
			foreach ( $list['items'] as $other ) {
				if ( (int) $other['id'] <= (int) $row['id'] || (string) $other['change_id'] === (string) $row['change_id'] || in_array( $other['status'], [ 'rolled_back', 'rolled_back_by', 'failed' ], true ) ) {
					continue;
				}
				if ( 'option' === $row['family'] && [] === array_intersect( $names, self::option_names( (string) $other['resource_id'] ) ) ) {
					continue;
				}
				$out[] = $other;
			}
			return array_reverse( $out );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_rollback_newer_failed', [ 'error' => $failure::class ] );
			return [];
		}
	}

	/** @return list<string> The option names of the resource id of an option row ("a,b,+2"). */
	private static function option_names( string $resource_id ): array {
		return array_values( array_filter( explode( ',', $resource_id ), static fn ( string $name ): bool => '' !== $name && ! str_starts_with( $name, '+' ) ) );
	}

	// -----------------------------------------------------------------------
	// The probe.
	// -----------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $row
	 * @return array{legs:list<string>,user_id:int,post_id?:int}
	 */
	private static function probe_context( array $row, int $actor ): array {
		if ( in_array( (string) $row['family'], self::POST_FAMILIES, true ) && 'post' === (string) $row['resource_type'] && (int) $row['resource_id'] > 0 ) {
			return [ 'legs' => [ 'home', 'post' ], 'user_id' => $actor, 'post_id' => (int) $row['resource_id'] ];
		}
		if ( in_array( (string) $row['family'], CodeAdapter::FAMILIES, true ) ) {
			return [ 'legs' => HealthProbe::legs_for( 'site' ), 'user_id' => $actor ];
		}
		return [ 'legs' => [ 'home' ], 'user_id' => $actor ];
	}

	/**
	 * Probe the site now and judge it against the legs that passed in the baseline, with the retry for a leg that got no answer.
	 *
	 * @param array<string, mixed> $context
	 * @param array<string, mixed> $baseline The probe taken before the restore, or [] for none.
	 * @param array<string, mixed> $row
	 * @return array{0:string,1:array<string,mixed>} The site status (healthy, still_failing or unknown) and the judged probe.
	 */
	private static function judge_site( array $context, array $baseline, array $row ): array {
		$entry  = [ 'baseline' => $baseline, 'resource_key' => (string) $row['resource_id'] ];
		$judged = RescueRollback::judge( HealthProbe::run( $context ), [ $entry ], $context, ! HealthProbe::loopback_cooling_down() );
		return [ RescueRollback::site_status( $judged ), $judged ];
	}

	// -----------------------------------------------------------------------
	// Answers.
	// -----------------------------------------------------------------------

	/**
	 * The approval-required answer of RescueRollback, pointing at the Changes page.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function approval_required( array $row ): \WP_Error {
		return RescueRollback::approval_required_error(
			[
				'id'            => (string) $row['change_id'],
				'ability'       => (string) $row['ability'],
				'resource_type' => (string) $row['resource_type'],
				'resource_key'  => (string) $row['resource_id'],
			],
			[
				'message'         => __( 'Undoing or redoing a change to code needs an administrator. Ask the administrator to open Stonewright > Activity > Changes, open this change and press Undo, then stop. Do not retry the call.', 'stonewright' ),
				'url'             => self::approval_url( (string) $row['change_id'] ),
				'operator_action' => 'An administrator opens Stonewright > Activity > Changes, opens this change and presses Undo.',
			]
		);
	}

	private static function approval_url( string $change_id ): string {
		return admin_url( 'admin.php?page=' . self::CHANGES_PAGE . '&change=' . rawurlencode( $change_id ) );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function error( string $code, string $message, int $status, array $data = [] ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( [ 'status' => $status ], $data ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function no_diff( string $status, string $message ): array {
		return [ 'status' => $status, 'message' => $message, 'sections' => [], 'changed' => false, 'truncated' => false, 'masked' => 0, 'image_masked' => false ];
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array{dry_run:bool,actor:int,human_approved:bool,confirmation_token:string,expected_current_sha256:string,force_drift:bool,permanent:bool,by:string}
	 */
	private static function normalize( array $options ): array {
		$by = isset( $options['by'] ) && is_string( $options['by'] ) ? strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', $options['by'] ) ) : '';
		return [
			'dry_run'                 => ! empty( $options['dry_run'] ),
			'actor'                   => isset( $options['actor'] ) && is_numeric( $options['actor'] ) ? max( 0, (int) $options['actor'] ) : (int) get_current_user_id(),
			'human_approved'          => ! empty( $options['human_approved'] ),
			'confirmation_token'      => isset( $options['confirmation_token'] ) && is_string( $options['confirmation_token'] ) ? trim( $options['confirmation_token'] ) : '',
			'expected_current_sha256' => self::clean_hash( $options['expected_current_sha256'] ?? '' ),
			'force_drift'             => ! empty( $options['force_drift'] ),
			'permanent'               => ! empty( $options['permanent'] ),
			'by'                      => '' !== $by ? substr( $by, 0, 20 ) : 'ability',
		];
	}

	private static function clean_hash( mixed $value ): string {
		return is_string( $value ) ? strtolower( trim( $value ) ) : '';
	}

	/** The whole hash, or its first MIN_HASH_PREFIX characters or more, equals the live hash. */
	private static function hash_matches( string $expected, string $live ): bool {
		$length = strlen( $expected );
		if ( $length < self::MIN_HASH_PREFIX || $length > 64 || ! ctype_xdigit( $expected ) || '' === $live ) {
			return false;
		}
		return hash_equals( substr( $live, 0, $length ), $expected );
	}

	// -----------------------------------------------------------------------
	// The audit and the receipt.
	// -----------------------------------------------------------------------

	/**
	 * Write the audit row of a run and put its receipt in the result.
	 *
	 * @param array<string, mixed>           $options
	 * @param array<string, mixed>|\WP_Error $result
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function receipt( string $change_id, array $options, array|\WP_Error $result ): array|\WP_Error {
		$valid = ChangeLedger::is_valid_id( $change_id ) ? $change_id : '';
		$data  = $result instanceof \WP_Error && is_array( $result->get_error_data() ) ? $result->get_error_data() : [];
		$new   = $result instanceof \WP_Error ? (string) ( $data['rollback_change_id'] ?? '' ) : (string) ( $result['rollback_change_id'] ?? '' );
		$set   = ChangeLedger::is_valid_id( $new ) ? $new : $valid;
		$kind  = $result instanceof \WP_Error ? 'rollback' : (string) ( $result['kind'] ?? 'rollback' );

		try {
			$meta = [
				'operation_class' => 'change_' . $kind,
				'resource_type'   => 'change',
				'resource_ref'    => $valid,
				'change_set_id'   => $set,
			];
			if ( $result instanceof \WP_Error ) {
				$meta['error_code']      = (string) $result->get_error_code();
				$meta['rollback_status'] = isset( $data['rollback_status'] ) && is_scalar( $data['rollback_status'] ) && 'reverted' !== $data['rollback_status'] ? (string) $data['rollback_status'] : ( 'reverted' === ( $data['rollback_status'] ?? '' ) ? 'failed' : 'not_attempted' );
			} else {
				$meta['rollback_status']     = $options['dry_run'] ? 'not_needed' : (string) ( $result['rollback_status'] ?? 'succeeded' );
				$meta['verification_status'] = (string) ( $result['verification_status'] ?? '' );
			}
			$args = [
				'change_id' => $valid,
				'action'    => $kind,
				'dry_run'   => $options['dry_run'],
				'by'        => $options['by'],
				'_meta'     => $meta,
			];
			if ( ! $result instanceof \WP_Error ) {
				$args['path']   = (string) ( $result['path'] ?? '' );
				$args['family'] = (string) ( $result['family'] ?? '' );
				$args['forced'] = ! empty( $result['drift_forced'] );
			}
			AuditLog::record( self::ABILITY, $args, $result instanceof \WP_Error ? self::audit_status( (string) $result->get_error_code() ) : 'ok' );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'change_rollback_audit_failed', [ 'error' => $failure::class ] );
		}

		$receipt = [ 'ability' => self::ABILITY, 'change_set_id' => $set ];
		if ( $result instanceof \WP_Error ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array_merge( $data, [ 'receipt' => $receipt ] ) );
		}
		$result['receipt'] = $receipt;
		return $result;
	}

	private static function audit_status( string $code ): string {
		foreach ( [ 'forbidden', 'approval_required', 'confirmation_' ] as $marker ) {
			if ( str_contains( $code, $marker ) ) {
				return 'blocked';
			}
		}
		return 'error';
	}
}
