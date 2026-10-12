<?php
/**
 * Rollback of a journaled change, shared by the ability, the admin page and WP-CLI.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Core\RescueInstaller;
use Stonewright\WpMcp\Security\Rollback\LiveState;
use Stonewright\WpMcp\Security\Rollback\RollbackFamilies;
use Stonewright\WpMcp\Security\Rollback\RollbackFamilyHandler;

/**
 * Runs a journal entry's rollback recipe, probes the site afterwards, and records the outcome
 * on the entry. The automatic rollback after a failed probe (RescueGuard) and the manual one
 * (the rescue-rollback ability, the Rescue page, `wp stonewright rescue rollback`) share these
 * steps, so the journal tells the same story whichever path ran.
 *
 * A manual rollback is one of two things. The normal one replaces what is live now with an older state, so it
 * refuses to overwrite a later edit until the person confirmed the state they saw (force_drift, with
 * expected_current_sha256), saves the state it replaces before it writes, and puts that state back when the
 * restore breaks a site that worked. The emergency one recovers a site that fails now: the journal recorded a
 * fault against the change (an incident, or a rollback that failed) and a probe fails at this moment. It needs no
 * confirmation and is never put back, because the cure is the point. An entry that is only armed, or an incident
 * that is long past on a site that loads, is the normal kind.
 */
final class RescueRollback {

	/** States in which an entry can still be re-checked. */
	public const OPEN_STATES = [ 'incident', 'rollback_failed', 'armed' ];

	/** States in which an entry can be rolled back: the open ones, and a verified change that is undone on request. */
	public const ROLLBACK_STATES = [ 'incident', 'rollback_failed', 'armed', 'verified' ];

	/** States that record a fault against the change. They are history: alone they do not prove that the site fails now. */
	public const FAULT_STATES = [ 'incident', 'rollback_failed' ];

	/** Resource types that are code: undoing a verified change to one needs an administrator, not an agent. */
	private const CODE_RESOURCE_TYPES = [ 'theme_file', 'custom_code', 'sandbox' ];

	/** Abilities whose changes are code although the item they touch is a post (the Customizer CSS post). */
	private const CODE_ABILITIES = [ 'stonewright/theme-custom-css' ];

	/** The wp-admin page where an administrator rolls a change back. */
	private const RESCUE_PAGE = 'stonewright-rescue';

	/** The rescue helper's way to run code with the stored plugin and theme selection in place. */
	private const SELECTION_RUNNER = [ '\Stonewright\WpMcp\Security\RescueSafeBoot', 'with_stored_selection' ];

	/** @var callable(callable):mixed|null */
	private static $selection_runner = null;

	/**
	 * Run an entry's recipe and note when and by whom.
	 *
	 * @param array<string, mixed>   $entry
	 * @param callable():array<string,string>|null $override Replaces the recipe (an in-process rollback that already holds the bytes).
	 * @return array{status:string,recipe:string,detail:string,at:int,by:string}
	 */
	public static function apply_recipe( array $entry, string $by, ?callable $override = null ): array {
		$outcome = null !== $override ? $override() : self::with_stored_selection( static fn (): array => RollbackRecipes::run( $entry ) );
		return [
			'status' => (string) ( $outcome['status'] ?? 'failed' ),
			'recipe' => (string) ( $outcome['recipe'] ?? ( $entry['recipe']['type'] ?? 'none' ) ),
			'detail' => (string) ( $outcome['detail'] ?? '' ),
			'at'     => time(),
			'by'     => $by,
		];
	}

	/**
	 * Replace how the stored plugin and theme selection is put in place around a recipe. For tests.
	 *
	 * @param callable(callable):mixed|null $runner
	 */
	public static function set_selection_runner( ?callable $runner ): void {
		self::$selection_runner = $runner;
	}

	/**
	 * Run a recipe with the stored plugin and theme selection in place. While a request runs in safe
	 * mode, the rescue helper filters those options and ignores writes to them, so a recipe that
	 * activates a plugin or restores options would report success without changing anything. Outside
	 * safe mode, or without the helper, the callback simply runs.
	 *
	 * @param callable():array<string, string> $callback
	 * @return array<string, string>
	 */
	private static function with_stored_selection( callable $callback ): array {
		$runner = self::$selection_runner ?? self::SELECTION_RUNNER;
		if ( ! is_callable( $runner ) ) {
			return $callback();
		}
		$ran     = false;
		$outcome = [];
		try {
			$runner(
				static function () use ( $callback, &$ran, &$outcome ): array {
					$ran     = true;
					$outcome = $callback();
					return $outcome;
				}
			);
		} catch ( \Throwable $failure ) {
			unset( $failure );
		}
		// The helper never decides the outcome. If it did not run the recipe at all, run it here, once.
		return $ran ? $outcome : $callback();
	}

	/**
	 * Record the outcome of a rollback on the entry.
	 *
	 * @param array<string, mixed> $rollback    From apply_recipe().
	 * @param array<string, mixed> $probe_before The failing probe that caused the rollback, or [].
	 * @param array<string, mixed> $probe_after  The probe run after the rollback.
	 * @param string               $note         A short note kept on the entry, for example that a verified change was undone.
	 * @return array<string, mixed>|null The updated entry.
	 */
	public static function conclude( string $id, array $rollback, array $probe_before, array $probe_after, string $note = '' ): ?array {
		$ok   = self::succeeded( $rollback );
		$site = self::site_status( $probe_after );
		$extra = [
			'rollback' => [
				'status' => $rollback['status'],
				'at'     => $rollback['at'],
				'by'     => $rollback['by'],
				'user'   => (int) ( $rollback['user'] ?? 0 ),
				'recipe' => $rollback['recipe'],
				'detail' => $rollback['detail'],
				'site'   => $site,
			],
			'residual' => $ok && 'still_failing' === $site,
		];
		if ( '' !== $note ) {
			$extra['note'] = $note;
		}
		if ( [] !== $probe_before ) {
			$extra['probe'] = $probe_before;
		} elseif ( [] !== $probe_after ) {
			$extra['probe'] = $probe_after;
		}
		return ChangeJournal::settle( $id, $ok ? 'rolled_back' : 'rollback_failed', $extra );
	}

	/** @param array<string, mixed> $rollback */
	public static function succeeded( array $rollback ): bool {
		return in_array( (string) ( $rollback['status'] ?? '' ), [ 'succeeded', 'noop' ], true );
	}

	/**
	 * Judge a probe against the baselines the entries took before their writes: a leg that passed
	 * then and cannot be reached now counts as failed (see HealthProbe::compare()). For the probe taken
	 * right after a write, $retry asks such a leg once more first, in case it was only slow (see
	 * HealthProbe::retry_silent_legs()).
	 *
	 * @param array<string, mixed>       $probe   A probe taken after the change.
	 * @param list<array<string, mixed>> $entries The entries the probe covers.
	 * @param array<string, mixed>       $context The context the probe was run with.
	 * @param bool                       $retry   Ask a leg that got no answer once more before judging it.
	 * @return array<string, mixed>
	 */
	public static function judge( array $probe, array $entries, array $context, bool $retry = false ): array {
		$passed  = [];
		$post_id = (int) ( $context['post_id'] ?? 0 );
		foreach ( $entries as $entry ) {
			$legs = is_array( $entry['baseline']['legs'] ?? null ) ? $entry['baseline']['legs'] : [];
			foreach ( $legs as $leg ) {
				if ( ! is_array( $leg ) || 'passed' !== ( $leg['status'] ?? '' ) ) {
					continue;
				}
				$name = (string) ( $leg['leg'] ?? '' );
				// The post leg belongs to the post it was taken for.
				if ( 'post' === $name && (int) ( $entry['resource_key'] ?? 0 ) !== $post_id ) {
					continue;
				}
				$passed[ $name ] = true;
			}
		}
		$passed = array_keys( $passed );
		if ( $retry ) {
			$probe = HealthProbe::retry_silent_legs( $probe, $passed, $context );
		}
		return HealthProbe::compare( $probe, $passed );
	}

	/**
	 * What a probe says about the site.
	 *
	 * @param array<string, mixed> $probe
	 */
	public static function site_status( array $probe ): string {
		return match ( (string) ( $probe['status'] ?? '' ) ) {
			'passed' => 'healthy',
			'failed' => 'still_failing',
			default  => 'unknown',
		};
	}

	/**
	 * The probe request for an entry: its legs, its post, and the user to probe as.
	 *
	 * @param array<string, mixed> $entry
	 * @return array{legs:list<string>,user_id:int,post_id?:int}
	 */
	public static function probe_context( array $entry, int $user_id ): array {
		$context = [
			'legs'    => HealthProbe::legs_for( (string) ( $entry['scope'] ?? 'site' ) ),
			'user_id' => $user_id,
		];
		if ( 'post' === ( $entry['resource_type'] ?? '' ) && (int) ( $entry['resource_key'] ?? 0 ) > 0 ) {
			$context['post_id'] = (int) $entry['resource_key'];
		}
		return $context;
	}

	/**
	 * The probe without anything an agent or a page does not need.
	 *
	 * @param array<string, mixed> $probe
	 * @return array<string, mixed>
	 */
	public static function compact_probe( array $probe ): array {
		$legs = [];
		foreach ( is_array( $probe['legs'] ?? null ) ? $probe['legs'] : [] as $leg ) {
			if ( ! is_array( $leg ) ) {
				continue;
			}
			$entry = [
				'leg'    => (string) ( $leg['leg'] ?? '' ),
				'status' => (string) ( $leg['status'] ?? '' ),
				'http'   => (int) ( $leg['http'] ?? 0 ),
				'reason' => (string) ( $leg['reason'] ?? '' ),
			];
			if ( ! empty( $leg['retried'] ) ) {
				$entry['retried'] = true;
			}
			$legs[] = $entry;
		}
		$out = [
			'status'   => (string) ( $probe['status'] ?? 'unavailable' ),
			'coverage' => (string) ( $probe['coverage'] ?? 'none' ),
			'legs'     => $legs,
		];
		if ( isset( $probe['unavailable_reason'] ) ) {
			$out['unavailable_reason'] = (string) $probe['unavailable_reason'];
		}
		return $out;
	}

	/**
	 * What a rollback of this entry would do, and what to know before it: how old the change is and
	 * whether a newer change to the same item exists, which the rollback would overwrite.
	 *
	 * It also says whether the item is as the change left it: drift (it is not), drift_known (it could be compared
	 * with the history row of the change), requires_force (a rollback needs the person's confirmation first) and the
	 * current and expected hashes. current_sha256 is what expected_current_sha256 confirms.
	 *
	 * @param array<string, mixed>      $entry
	 * @param array<string, mixed>|null $inspection The answer of inspect() when the caller has it already.
	 * @return array<string, mixed>
	 */
	public static function plan( array $entry, ?array $inspection = null ): array {
		$inspection            = $inspection ?? self::inspect( $entry );
		$plan                  = self::summarize( $entry );
		$newer                 = ChangeJournal::newer_changes( $entry );
		$plan['age_seconds']   = max( 0, ChangeJournal::now() - (int) $entry['armed_at'] );
		$plan['newer_changes'] = array_map(
			static fn ( array $other ): array => [
				'incident_id' => (string) $other['id'],
				'ability'     => (string) $other['ability'],
				'since'       => gmdate( 'Y-m-d\TH:i:s\Z', (int) $other['armed_at'] ),
			],
			$newer
		);
		$plan['drift']           = $inspection['drift'];
		$plan['drift_known']     = $inspection['known'];
		$plan['requires_force']  = self::needs_confirmation( $inspection, $newer );
		$plan['current_sha256']  = $inspection['current_sha256'];
		$plan['expected_sha256'] = $inspection['expected_sha256'];
		$plan['redo_available']  = 'row' === $inspection['ledger'] && $inspection['known'];
		if ( self::needs_human_approval( $entry ) ) {
			$plan['approval_required'] = true;
			$plan['approval_url']      = self::approval_url();
		}
		$warnings = [];
		if ( [] !== $newer ) {
			$ids        = implode( ', ', array_map( static fn ( array $other ): string => (string) $other['id'], array_slice( $newer, 0, 3 ) ) );
			$warnings[] = sprintf(
				/* translators: %s: change set ids */
				__( 'A newer change to the same item exists (%s). Rolling this one back also overwrites it.', 'stonewright' ),
				$ids
			);
		}
		if ( $inspection['drift'] ) {
			$warnings[] = __( 'This item has changed since the recorded change (it was edited, or another tool wrote to it). Rolling this one back overwrites those later edits.', 'stonewright' );
		}
		if ( [] !== $warnings ) {
			$plan['warnings'] = $warnings;
		}
		return $plan;
	}

	/**
	 * Whether a rollback needs the person's confirmation: the item is not as the change left it, or the history cannot say
	 * and the journal knows of a newer change to the same item.
	 *
	 * @param array<string, mixed>       $inspection
	 * @param list<array<string, mixed>> $newer
	 */
	private static function needs_confirmation( array $inspection, array $newer ): bool {
		return $inspection['drift'] || ( ! $inspection['known'] && [] !== $newer );
	}

	/**
	 * What the item is now, set against the history row of the same change: the live state, its hash, the hash the
	 * change left, and whether they differ. The history is read, never required: when it is unavailable, has no row for
	 * the change, or cannot compare the item, `known` is false and a rollback judges by the journal alone.
	 *
	 * @param array<string, mixed> $entry
	 * @return array{ledger:string,row:?array<string,mixed>,handler:?RollbackFamilyHandler,known:bool,unreadable:bool,live:string|array<mixed>|null,current_sha256:string,expected_sha256:string,drift:bool}
	 *         ledger is row (the change can be compared and restored from the history), not_restorable (a row the history cannot restore or compare), no_row or unavailable.
	 */
	private static function inspect( array $entry ): array {
		$none = [
			'ledger'          => 'no_row',
			'row'             => null,
			'handler'         => null,
			'known'           => false,
			'unreadable'      => false,
			'live'            => null,
			'current_sha256'  => '',
			'expected_sha256' => '',
			'drift'           => false,
		];
		$id = (string) ( $entry['id'] ?? '' );
		if ( ! ChangeLedger::is_valid_id( $id ) ) {
			return $none;
		}
		try {
			$row = ChangeLedger::get( $id );
			if ( null === $row ) {
				return $none;
			}
			$handler = RollbackFamilies::handler_for( (string) $row['family'] );
			if ( null === $handler || ! $row['restorable'] || ! in_array( (string) $row['kind'], [ 'change', 'redo' ], true ) || in_array( (string) $row['status'], [ 'rolled_back', 'rolled_back_by' ], true ) ) {
				return array_merge( $none, [ 'ledger' => 'not_restorable' ] );
			}
			$state = LiveState::read( $row, $handler );
		} catch ( \Throwable $failure ) {
			unset( $failure );
			return array_merge( $none, [ 'ledger' => 'unavailable' ] );
		}
		$known = $state['supported'] && ! $state['unreadable'];
		return [
			'ledger'          => 'row',
			'row'             => $row,
			'handler'         => $handler,
			'known'           => $known,
			'unreadable'      => $state['unreadable'],
			'live'            => $state['live'],
			'current_sha256'  => $state['current_sha256'],
			'expected_sha256' => (string) $row['after_sha256'],
			'drift'           => $known && $state['current_sha256'] !== (string) $row['after_sha256'],
		];
	}

	private static function not_open_error( string $id, string $state ): \WP_Error {
		return new \WP_Error(
			'stonewright_rescue_not_open',
			__( 'That change is already rolled back. Nothing is left to roll back.', 'stonewright' ),
			[ 'status' => 409, 'incident_id' => $id, 'state' => $state ]
		);
	}

	private static function in_progress_error( string $id ): \WP_Error {
		return new \WP_Error(
			'stonewright_rescue_in_progress',
			__( 'A rollback of this change set is already running. Check again in a minute.', 'stonewright' ),
			[ 'status' => 409, 'incident_id' => $id ]
		);
	}

	/**
	 * Whether an entry is a change to code: a theme file, a custom-code snippet, a sandbox file or
	 * the Customizer CSS.
	 *
	 * @param array<string, mixed> $entry
	 */
	public static function is_code_change( array $entry ): bool {
		return in_array( (string) ( $entry['resource_type'] ?? '' ), self::CODE_RESOURCE_TYPES, true )
			|| in_array( (string) ( $entry['ability'] ?? '' ), self::CODE_ABILITIES, true );
	}

	/**
	 * Whether undoing this entry needs an administrator in wp-admin. A verified change to code is not
	 * undone on an agent's call; an open incident of code still is, because the site is failing.
	 *
	 * @param array<string, mixed> $entry
	 */
	public static function needs_human_approval( array $entry ): bool {
		return 'verified' === (string) ( $entry['state'] ?? '' ) && self::is_code_change( $entry );
	}

	/** The Rescue page, where an administrator approves the undo of a code change. */
	public static function approval_url(): string {
		return admin_url( 'admin.php?page=' . self::RESCUE_PAGE );
	}

	/**
	 * What a caller that is not an administrator at the Rescue page is told about an entry, or null
	 * when it may go on. For callers that want to answer before they ask for anything else.
	 */
	public static function approval_refusal( string $incident_id ): ?\WP_Error {
		$entry = ChangeJournal::get( $incident_id );
		return null !== $entry && self::needs_human_approval( $entry ) ? self::approval_required_error( $entry ) : null;
	}

	/**
	 * The answer to a call that would undo a verified code change without an administrator. It
	 * carries the same approval envelope as the custom-code gate: the agent shows it and stops.
	 *
	 * @param array<string, mixed>       $entry
	 * @param array{message?:string,url?:string,operator_action?:string} $where Another page than Rescue where the administrator presses the button.
	 */
	public static function approval_required_error( array $entry, array $where = [] ): \WP_Error {
		$message  = $where['message'] ?? __( 'Undoing a verified change to code needs an administrator. Ask the administrator to open Stonewright > Activity > Rescue and press Roll back for this change, then stop. Do not retry the call.', 'stonewright' );
		$proposal = CustomCodeGrant::missing_grant_proposal(
			[
				'error_code'          => 'stonewright_rescue_approval_required',
				'message'             => $message,
				'approval_url'        => $where['url'] ?? self::approval_url(),
				'recommended_next'    => 'show the change and the approval URL, then stop for the administrator',
				'operator_action'     => $where['operator_action'] ?? 'An administrator opens Stonewright > Activity > Rescue and presses Roll back for this change.',
				'incident_id'         => (string) $entry['id'],
				'change_set_id'       => (string) $entry['id'],
				'ability'             => (string) $entry['ability'],
				'execution_status'    => 'blocked',
				'verification_status' => 'blocked',
				'rollback_status'     => 'not_needed',
				'effect_verified'     => false,
				'resource_type'       => (string) $entry['resource_type'],
				'resource_ref'        => (string) $entry['resource_key'],
			]
		);
		return new \WP_Error(
			'stonewright_rescue_approval_required',
			$message,
			array_merge( [ 'status' => 400, 'retryable' => false ], $proposal )
		);
	}

	/**
	 * One entry as the ability and the page show it.
	 *
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	public static function summarize( array $entry ): array {
		$incident = is_array( $entry['incident'] ?? null ) ? $entry['incident'] : null;
		$since    = null !== $incident && (int) $incident['recorded_at'] > 0 ? (int) $incident['recorded_at'] : ( (int) ( $entry['settled_at'] ?? 0 ) > 0 ? (int) $entry['settled_at'] : (int) $entry['armed_at'] );
		return [
			'incident_id'   => (string) $entry['id'],
			'ability'       => (string) $entry['ability'],
			'resource_type' => (string) $entry['resource_type'],
			'resource_key'  => (string) $entry['resource_key'],
			'state'         => (string) $entry['state'],
			'since'         => gmdate( 'Y-m-d\TH:i:s\Z', $since ),
			'client'        => (string) ( $entry['client'] ?? '' ),
			'recipe'        => [
				'type'      => (string) ( $entry['recipe']['type'] ?? 'none' ),
				'available' => RollbackRecipes::available( $entry ),
				'plan'      => RollbackRecipes::describe( $entry ),
			],
			'probe'         => is_array( $entry['probe'] ?? null ) ? self::compact_probe( $entry['probe'] ) : null,
		];
	}

	/**
	 * The data behind rescue-status and the Rescue page.
	 *
	 * @return array<string, mixed>
	 */
	public static function status(): array {
		$map = static fn ( array $entries ): array => array_map( [ self::class, 'summarize' ], array_slice( $entries, 0, 5 ) );
		return [
			'open_incidents' => $map( ChangeJournal::open_incidents() ),
			'unconfirmed'    => $map( ChangeJournal::unconfirmed() ),
			'recent'         => array_map(
				static fn ( array $entry ): array => [
					'incident_id' => (string) $entry['id'],
					'ability'     => (string) $entry['ability'],
					'state'       => (string) $entry['state'],
					'armed_at'    => gmdate( 'Y-m-d\TH:i:s\Z', (int) $entry['armed_at'] ),
				],
				ChangeJournal::recent( 5 )
			),
			'journal'        => ChangeJournal::storage_status(),
			'helper'         => RescueInstaller::summary(),
		];
	}

	/**
	 * Roll a change back by hand, then probe. The rules, in the order they are applied (every refusal comes before anything
	 * is saved, written, claimed or probed for the first time, and before a confirmation token is spent by the callers
	 * that ask for one, see preflight_refusal()):
	 *
	 * 1. The entry exists and can be rolled back: it is open (an incident, or one never verified), or verified.
	 * 2. A verified change to code needs `human_approved`: only the Rescue page, where an administrator presses the
	 *    button, passes it.
	 * 3. A recipe that can run, with the snapshot or restore point it restores from still there.
	 * 4. The confirmation. A rollback over a later edit (the item is not as the change left it, or the journal knows of
	 *    a newer change and the history cannot compare) is refused unless `force_drift` is set. `expected_current_sha256`,
	 *    the current_sha256 of the plan, binds the run to the state the person saw. A site that fails now needs no
	 *    confirmation when the journal recorded a fault against the change (an emergency, see the class comment).
	 * 5. The entry is claimed, and the live state is read once more under the claim: a state that is not the one
	 *    judged is refused.
	 * 6. The state about to be replaced is saved. A normal rollback stops when it cannot be saved; an emergency one goes on.
	 * 7. The recipe runs and the site is probed. A normal rollback that breaks a site that worked is put back.
	 * 8. With a history row for the change, the restore is recorded under it as a rollback row, which is what a Redo acts on.
	 *
	 * Options: by, dry_run, user_id, human_approved, force_drift, expected_current_sha256.
	 *
	 * @param array{by?:string,dry_run?:bool,user_id?:int,human_approved?:bool,force_drift?:bool,expected_current_sha256?:string} $options
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $incident_id, array $options = [] ): array|\WP_Error {
		$entry = ChangeJournal::get( $incident_id );
		if ( null === $entry ) {
			return new \WP_Error(
				'stonewright_rescue_incident_not_found',
				__( 'No rescue incident or armed change has that id.', 'stonewright' ),
				[ 'status' => 404 ]
			);
		}
		if ( ! in_array( $entry['state'], self::ROLLBACK_STATES, true ) ) {
			return self::not_open_error( $entry['id'], (string) $entry['state'] );
		}
		$inspection = self::inspect( $entry );
		$plan       = self::plan( $entry, $inspection );
		if ( ! empty( $options['dry_run'] ) ) {
			return array_merge( [ 'ok' => true, 'dry_run' => true ], $plan );
		}
		if ( self::needs_human_approval( $entry ) && empty( $options['human_approved'] ) ) {
			return self::approval_required_error( $entry );
		}
		if ( ! RollbackRecipes::available( $entry ) ) {
			return new \WP_Error(
				'stonewright_rescue_no_recipe',
				__( 'No automatic rollback is available for this change. Undo it by hand, then call stonewright-rescue-rollback with action "recheck", or use Stonewright > Rescue in wp-admin.', 'stonewright' ),
				[ 'status' => 409, 'incident_id' => $entry['id'], 'rollback_status' => 'not_available' ]
			);
		}
		$verdict = self::judge_restore( $entry, $options, $inspection );
		if ( $verdict instanceof \WP_Error ) {
			return $verdict;
		}

		// Claim the entry before anything runs: a double click, or the page and an agent together, run the recipe once.
		$by = (string) ( $options['by'] ?? 'ability' );
		if ( null === ChangeJournal::claim( $entry['id'], $by ) ) {
			$fresh = ChangeJournal::get( $entry['id'] );
			return null !== $fresh && ! in_array( $fresh['state'], self::ROLLBACK_STATES, true )
				? self::not_open_error( $entry['id'], (string) $fresh['state'] )
				: self::in_progress_error( $entry['id'] );
		}

		// A normal rollback replaces something that works with something older, so it is judged against the site as it is
		// and put back when it breaks it. A change with a fault recorded against it is the cure itself and is never put back.
		$guard = ! in_array( (string) $entry['state'], self::FAULT_STATES, true );
		try {
			$user    = isset( $options['user_id'] ) ? (int) $options['user_id'] : (int) get_current_user_id();
			$context = self::probe_context( $entry, $user );
			// The state judged above is the one that is replaced: a run that waited for the claim, or a second submit, finds another.
			$again = self::inspect( $entry );
			if ( $inspection['known'] && ( ! $again['known'] || $again['current_sha256'] !== $inspection['current_sha256'] ) ) {
				return self::changed_error( $entry, $again );
			}
			// Save what the recipe is about to replace. Without that there is no way back, and a normal rollback does not go on;
			// an emergency one does, because the site is failing and the cure comes first. A change with a fault recorded against
			// it that nothing came after leaves nothing to save: the item is as the change made it.
			$saved = $guard || $verdict['needs'] ? self::save_current_state( $entry, ! $verdict['emergency'] ) : [];
			if ( $saved instanceof \WP_Error ) {
				return $saved;
			}
			$restore  = $saved;
			$baseline = [];
			if ( $guard ) {
				// Ask the site how it is now: without it there is no way to judge what the rollback did to it.
				$baseline = HealthProbe::run( $context );
			}
			$rollback         = self::apply_recipe( $entry, $by );
			$rollback['user'] = $user;
			// A rollback that is put back is judged against the probe taken just before it, not the one from when the change was made.
			$judged = $guard ? [ [ 'baseline' => $baseline, 'resource_key' => $entry['resource_key'] ] ] : [ $entry ];
			$after  = self::judge( HealthProbe::run( $context ), $judged, $context, $guard );
			$undo_guard = $guard ? self::undo_guard( $baseline, $after ) : '';
			if ( 'reverted' === $undo_guard ) {
				return self::put_back( $entry, $restore, $rollback, $after, $judged, $context, $plan );
			}
			$concluded = self::conclude( $entry['id'], $rollback, [], $after, 'verified' === (string) $entry['state'] ? 'undone_after_verified' : '' );
			$link      = self::succeeded( $rollback )
				? self::link_history( $again, $user, self::site_status( $after ) )
				: [ 'rollback_change_id' => '', 'restorable_again' => false, 'history' => 'not_recorded' ];
		} finally {
			// conclude() settles the entry and clears the claim; this covers anything that threw before it.
			ChangeJournal::release_claim( $entry['id'] );
		}
		$ok    = self::succeeded( $rollback );
		$site  = self::site_status( $after );

		$result = [
			'ok'                  => $ok,
			'incident_id'         => (string) $entry['id'],
			'change_set_id'       => (string) $entry['id'],
			'rollback_status'     => $ok ? 'succeeded' : 'failed',
			'state'               => (string) ( $concluded['state'] ?? $entry['state'] ),
			'site_status'         => $site,
			'verification_status' => 'healthy' === $site ? 'verified' : ( 'still_failing' === $site ? 'failed' : 'unverified' ),
			'recipe'              => (string) $rollback['recipe'],
			'detail'              => (string) $rollback['detail'],
			'probe'               => self::compact_probe( $after ),
			'resource_type'       => (string) $entry['resource_type'],
			'mode'                => $verdict['emergency'] ? 'emergency' : 'normal',
			'drift_forced'        => $verdict['confirmed'],
			'rollback_change_id'  => $link['rollback_change_id'],
			'restorable_again'    => $link['restorable_again'],
			'history'             => $link['history'],
		];
		if ( isset( $plan['warnings'] ) ) {
			$result['warnings'] = $plan['warnings'];
		}
		if ( $ok && '' !== $undo_guard ) {
			$result['undo_guard'] = $undo_guard;
			if ( 'site_already_failing' === $undo_guard ) {
				$result['warnings'][] = __( 'The site was already failing before this undo, so the undo was kept and not put back.', 'stonewright' );
			}
		}
		if ( $ok && 'recorded' !== $link['history'] ) {
			$result['warnings'][] = self::history_limit( $link['history'] );
		}
		if ( ! $ok ) {
			return new \WP_Error(
				'stonewright_rescue_rollback_failed',
				sprintf(
					/* translators: %s: compact JSON evidence. */
					__( 'The rollback did not complete. Nothing was verified. Use Stonewright > Rescue in wp-admin, or undo the change by hand and re-check. %s', 'stonewright' ),
					self::evidence_json( $result )
				),
				array_merge( $result, [ 'status' => 500, 'retryable' => false, 'execution_status' => 'failed' ] )
			);
		}
		return $result;
	}

	/**
	 * The refusal a rollback would answer with before it saves, writes or claims anything, or null when it may go on. For the
	 * callers that look at a confirmation token (the ability, the Rescue page, the Changes engine): they ask this first, so
	 * that a drift, a state that changed since the preview, or a state that cannot be read does not use a token up. It
	 * writes nothing. When the journal recorded a fault against the change it may make one request to the site, to see
	 * whether the site fails now.
	 *
	 * @param array{force_drift?:bool,expected_current_sha256?:string,user_id?:int} $options The same options as run().
	 */
	public static function preflight_refusal( string $incident_id, array $options = [] ): ?\WP_Error {
		$entry = ChangeJournal::get( $incident_id );
		if ( null === $entry || ! in_array( $entry['state'], self::ROLLBACK_STATES, true ) || ! RollbackRecipes::available( $entry ) ) {
			// run() has its own answers for these, in its own order.
			return null;
		}
		$verdict = self::judge_restore( $entry, $options, self::inspect( $entry ) );
		return $verdict instanceof \WP_Error ? $verdict : null;
	}

	/**
	 * Whether the rollback may go on, and as what.
	 *
	 * @param array<string, mixed> $entry
	 * @param array<string, mixed> $options
	 * @param array<string, mixed> $inspection From inspect().
	 * @return array{emergency:bool,confirmed:bool,needs:bool}|\WP_Error needs: a later edit may be replaced; confirmed: the person confirmed that.
	 */
	private static function judge_restore( array $entry, array $options, array $inspection ): array|\WP_Error {
		$fault    = in_array( (string) $entry['state'], self::FAULT_STATES, true );
		$force    = ! empty( $options['force_drift'] );
		$expected = LiveState::clean_hash( $options['expected_current_sha256'] ?? '' );
		if ( '' !== $expected && $inspection['known'] && ! LiveState::hash_matches( $expected, $inspection['current_sha256'] ) ) {
			return self::changed_error( $entry, $inspection );
		}
		// A normal rollback cannot be judged, saved or put back for an item it cannot read. A failing site is recovered anyway.
		if ( $inspection['unreadable'] && ! $fault ) {
			return new \WP_Error(
				'stonewright_change_live_unreadable',
				__( 'The current state of this item cannot be read, so a rollback cannot be checked. Nothing was changed.', 'stonewright' ),
				[ 'status' => 409, 'retryable' => false, 'incident_id' => (string) $entry['id'], 'change_set_id' => (string) $entry['id'], 'execution_status' => 'blocked', 'verification_status' => 'not_applied', 'rollback_status' => 'not_attempted', 'resource_type' => (string) $entry['resource_type'] ]
			);
		}
		$newer = ChangeJournal::newer_changes( $entry );
		$needs = self::needs_confirmation( $inspection, $newer );
		$proof = null;
		if ( $needs && ! $force ) {
			if ( $fault ) {
				$user  = isset( $options['user_id'] ) ? (int) $options['user_id'] : (int) get_current_user_id();
				$proof = HealthProbe::run( self::probe_context( $entry, $user ) );
			}
			// An armed change, or an incident that is long past on a site that loads, is not an emergency.
			if ( null === $proof || 'failed' !== ( $proof['status'] ?? '' ) ) {
				return self::drift_error( $entry, $inspection, $newer );
			}
		}
		return [
			'emergency' => $fault && ( ! $needs || null !== $proof ),
			'confirmed' => $needs && $force,
			'needs'     => $needs,
		];
	}

	/**
	 * @param array<string, mixed>       $entry
	 * @param array<string, mixed>       $inspection
	 * @param list<array<string, mixed>> $newer
	 */
	private static function drift_error( array $entry, array $inspection, array $newer ): \WP_Error {
		$plan = self::plan( $entry, $inspection );
		return new \WP_Error(
			'stonewright_change_drift',
			__( 'This item has changed since the recorded change, so rolling it back would overwrite those later edits. Nothing was changed. Review the plan, and run again with force_drift and the expected_current_sha256 of the plan to overwrite them.', 'stonewright' ),
			[
				'status'              => 409,
				'retryable'           => false,
				'drift'               => true,
				'incident_id'         => (string) $entry['id'],
				'change_set_id'       => (string) $entry['id'],
				'warnings'            => $plan['warnings'] ?? [],
				'newer_changes'       => $plan['newer_changes'],
				'current_sha256'      => $inspection['current_sha256'],
				'expected_sha256'     => $inspection['expected_sha256'],
				'execution_status'    => 'blocked',
				'verification_status' => 'not_applied',
				'rollback_status'     => 'not_attempted',
				'resource_type'       => (string) $entry['resource_type'],
			]
		);
	}

	/**
	 * @param array<string, mixed> $entry
	 * @param array<string, mixed> $inspection The state found now.
	 */
	private static function changed_error( array $entry, array $inspection ): \WP_Error {
		return new \WP_Error(
			'stonewright_change_changed_since_preview',
			__( 'The item is not in the state that was previewed. Nothing was changed. Review the plan again.', 'stonewright' ),
			[
				'status'              => 409,
				'retryable'           => false,
				'incident_id'         => (string) $entry['id'],
				'change_set_id'       => (string) $entry['id'],
				'current_sha256'      => $inspection['current_sha256'],
				'execution_status'    => 'blocked',
				'verification_status' => 'not_applied',
				'rollback_status'     => 'not_attempted',
				'resource_type'       => (string) $entry['resource_type'],
			]
		);
	}

	/**
	 * Record the restore under the history row of the change, so that the Changes screen lists it and a Redo can act on
	 * it. Nothing is recorded, and nothing is promised, when the history has no row it can restore from, or the state that
	 * was replaced could not be read: a Redo needs that image.
	 *
	 * @param array<string, mixed> $before inspect() as it was when the restore began.
	 * @return array{rollback_change_id:string,restorable_again:bool,history:string} history is recorded, ledger_unavailable, no_ledger_row, not_restorable or not_recorded.
	 */
	private static function link_history( array $before, int $user, string $site ): array {
		$none = static fn ( string $why ): array => [ 'rollback_change_id' => '', 'restorable_again' => false, 'history' => $why ];
		if ( 'unavailable' === $before['ledger'] ) {
			return $none( 'ledger_unavailable' );
		}
		if ( 'no_row' === $before['ledger'] ) {
			return $none( 'no_ledger_row' );
		}
		if ( ! $before['known'] || ! is_array( $before['row'] ) || ! $before['handler'] instanceof RollbackFamilyHandler ) {
			return $none( 'not_restorable' );
		}
		try {
			$after = $before['handler']->live_image( $before['row'] );
			$id    = ChangeRollback::record_journal_restore( $before['row'], $before['live'], $after instanceof \WP_Error ? null : $after, 'healthy' === $site ? 'verified' : 'probe_unavailable', $user );
		} catch ( \Throwable $failure ) {
			unset( $failure );
			return $none( 'not_recorded' );
		}
		return '' === $id ? $none( 'not_recorded' ) : [ 'rollback_change_id' => $id, 'restorable_again' => true, 'history' => 'recorded' ];
	}

	/** A sentence for what the history could not do, shown next to a rollback that worked. */
	private static function history_limit( string $history ): string {
		return match ( $history ) {
			'ledger_unavailable' => __( 'The change history is unavailable, so this rollback is not listed there and cannot be redone from it.', 'stonewright' ),
			'no_ledger_row'      => __( 'The change history has no entry for this change, so this rollback is not listed there and cannot be redone from it.', 'stonewright' ),
			default              => __( 'The change history could not record this rollback, so it cannot be redone from there.', 'stonewright' ),
		};
	}

	/**
	 * What an undo of a verified change did to a site that worked, judged from the probes either side of it.
	 *
	 * @param array<string, mixed> $baseline The probe taken before the recipe ran.
	 * @param array<string, mixed> $after    The probe taken after it, judged against the baseline.
	 * @return string reverted (the undo broke a working site), site_already_failing, unchecked (a probe could not say) or kept.
	 */
	private static function undo_guard( array $baseline, array $after ): string {
		$before = (string) ( $baseline['status'] ?? '' );
		if ( 'failed' === $before ) {
			return 'site_already_failing';
		}
		if ( 'passed' !== $before ) {
			return 'unchecked';
		}
		return match ( (string) ( $after['status'] ?? '' ) ) {
			'failed' => 'reverted',
			'passed' => 'kept',
			default  => 'unchecked',
		};
	}

	/**
	 * Save what the recipe of an entry is about to overwrite, with the way to put it back.
	 *
	 * @param array<string, mixed> $entry
	 * @param bool                 $required True for a normal rollback, which does not go on without the saved state. False for an emergency one, which saves what it can and goes on.
	 * @return array<string, mixed>|\WP_Error The entry-shaped recipe that restores the saved state ([] when an emergency rollback could not save it), or the refusal.
	 */
	private static function save_current_state( array $entry, bool $required = true ): array|\WP_Error {
		$saved = [ 'status' => 'failed', 'detail' => 'exception' ];
		self::with_stored_selection(
			static function () use ( $entry, &$saved ): array {
				$saved = RollbackRecipes::capture( $entry );
				return [];
			}
		);
		if ( 'ok' === ( $saved['status'] ?? '' ) && is_array( $saved['restore'] ?? null ) ) {
			return $saved['restore'];
		}
		if ( ! $required ) {
			return [];
		}
		return new \WP_Error(
			'stonewright_rescue_undo_capture_failed',
			__( 'The undo was refused. The current state could not be saved first, so there would be no way back. Nothing was changed.', 'stonewright' ),
			[
				'status'              => 409,
				'retryable'           => false,
				'incident_id'         => (string) $entry['id'],
				'change_set_id'       => (string) $entry['id'],
				'execution_status'    => 'blocked',
				'verification_status' => 'not_applied',
				'rollback_status'     => 'not_attempted',
				'detail'              => substr( sanitize_key( (string) ( $saved['detail'] ?? '' ) ), 0, 64 ),
				'resource_type'       => (string) $entry['resource_type'],
			]
		);
	}

	/**
	 * An undo made a working site fail: put the saved state back, probe once more, and say so. The change
	 * stays verified, because it is still in effect. When the saved state cannot be put back either, the site
	 * is failing with nothing to cure it, which is an open incident.
	 *
	 * @param array<string, mixed>       $entry
	 * @param array<string, mixed>       $restore The entry-shaped recipe that puts the saved state back.
	 * @param array<string, mixed>       $applied The undo, as apply_recipe() reported it.
	 * @param array<string, mixed>       $failed  The probe that failed after the undo.
	 * @param list<array<string, mixed>> $judged
	 * @param array<string, mixed>       $context
	 * @param array<string, mixed>       $plan
	 */
	private static function put_back( array $entry, array $restore, array $applied, array $failed, array $judged, array $context, array $plan ): \WP_Error {
		$put_back = self::with_stored_selection( static fn (): array => RollbackRecipes::run( $restore ) );
		$final    = self::judge( HealthProbe::run( $context ), $judged, $context, true );
		$site     = self::site_status( $final );
		$restored = self::succeeded( $put_back );
		$result   = [
			'incident_id'         => (string) $entry['id'],
			'change_set_id'       => (string) $entry['id'],
			'rollback_status'     => $restored ? 'reverted' : 'failed',
			'undo_guard'          => 'reverted',
			'state'               => $restored ? 'verified' : 'rollback_failed',
			'site_status'         => $site,
			'verification_status' => 'failed',
			'recipe'              => (string) $applied['recipe'],
			'detail'              => (string) $put_back['detail'],
			'probe'               => self::compact_probe( $failed ),
			'resource_type'       => (string) $entry['resource_type'],
		];
		if ( isset( $plan['warnings'] ) ) {
			$result['warnings'] = $plan['warnings'];
		}
		if ( $restored ) {
			ChangeJournal::settle(
				(string) $entry['id'],
				'verified',
				[
					'note'     => 'undo_reverted',
					'probe'    => $failed,
					'residual' => false,
					'rollback' => [
						'status' => 'reverted',
						'at'     => time(),
						'by'     => (string) $applied['by'],
						'user'   => (int) ( $applied['user'] ?? 0 ),
						'recipe' => (string) $applied['recipe'],
						'detail' => 'site_failed_after_undo',
						'site'   => $site,
					],
				]
			);
			$message = 'healthy' === $site
				? __( 'The undo would have broken the site, so it was put back. The change is still in effect and the site loads.', 'stonewright' )
				: __( 'The undo made the site stop loading and was put back, but the site still fails to load, so the fault may not come from the undo. Check the site, then call stonewright-rescue-status.', 'stonewright' );
			return new \WP_Error(
				'stonewright_rescue_undo_reverted',
				$message . ' ' . self::evidence_json( $result ),
				array_merge( $result, [ 'status' => 409, 'retryable' => false, 'execution_status' => 'ok' ] )
			);
		}
		self::conclude(
			(string) $entry['id'],
			[
				'status' => 'failed',
				'recipe' => (string) $put_back['recipe'],
				'detail' => (string) $put_back['detail'],
				'at'     => time(),
				'by'     => (string) $applied['by'],
				'user'   => (int) ( $applied['user'] ?? 0 ),
			],
			$failed,
			$final,
			'undo_revert_failed'
		);
		return new \WP_Error(
			'stonewright_rescue_undo_revert_failed',
			sprintf(
				/* translators: %s: compact JSON evidence. */
				__( 'The undo broke the site and the earlier state could not be put back. The site may be failing. Use safe mode at Stonewright > Activity > Rescue, or undo the change by hand and check the site again. %s', 'stonewright' ),
				self::evidence_json( $result )
			),
			array_merge( $result, [ 'status' => 500, 'retryable' => false, 'execution_status' => 'failed' ] )
		);
	}

	/**
	 * Probe again and close the incident when the site loads: for a change someone undid by hand.
	 *
	 * @param array{user_id?:int} $options
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function recheck( string $incident_id, array $options = [] ): array|\WP_Error {
		$entry = ChangeJournal::get( $incident_id );
		if ( null === $entry ) {
			return new \WP_Error( 'stonewright_rescue_incident_not_found', __( 'No rescue incident or armed change has that id.', 'stonewright' ), [ 'status' => 404 ] );
		}
		if ( ! in_array( $entry['state'], self::OPEN_STATES, true ) ) {
			return new \WP_Error( 'stonewright_rescue_not_open', __( 'That change is already verified or rolled back. Nothing is left to roll back.', 'stonewright' ), [ 'status' => 409, 'incident_id' => $entry['id'], 'state' => $entry['state'] ] );
		}
		if ( ChangeJournal::is_claimed( $entry ) ) {
			// A probe now would judge a site in the middle of being put back.
			return self::in_progress_error( $entry['id'] );
		}
		$user  = isset( $options['user_id'] ) ? (int) $options['user_id'] : (int) get_current_user_id();
		$probe = HealthProbe::run( self::probe_context( $entry, $user ) );
		$site  = self::site_status( $probe );
		if ( 'healthy' === $site ) {
			$entry = ChangeJournal::settle( $entry['id'], 'verified', [ 'probe' => $probe, 'note' => 'confirmed_by_probe' ] ) ?? $entry;
		} else {
			$entry = ChangeJournal::record_probe( $entry['id'], $probe ) ?? $entry;
		}
		return [
			'ok'                  => true,
			'incident_id'         => (string) $entry['id'],
			'action'              => 'recheck',
			'state'               => (string) $entry['state'],
			'resolved'            => 'verified' === $entry['state'],
			'site_status'         => $site,
			'verification_status' => 'healthy' === $site ? 'verified' : ( 'still_failing' === $site ? 'failed' : 'unverified' ),
			'probe'               => self::compact_probe( $probe ),
			'resource_type'       => (string) $entry['resource_type'],
		];
	}

	/**
	 * Record a rollback or re-check made outside the ability (the Rescue page, WP-CLI) in the audit log.
	 * The ability's own calls are audited by the kernel.
	 *
	 * @param array<string, mixed>|\WP_Error $result
	 */
	public static function audit_outcome( string $incident_id, array|\WP_Error $result, string $by, string $action ): void {
		try {
			$meta = [
				'operation_class' => 'rescue_' . $action,
				'resource_type'   => 'rescue_incident',
				'resource_ref'    => $incident_id,
				'change_set_id'   => $incident_id,
			];
			if ( $result instanceof \WP_Error ) {
				$data                = is_array( $result->get_error_data() ) ? $result->get_error_data() : [];
				$meta['error_code']  = (string) $result->get_error_code();
				$meta['rollback_status'] = isset( $data['rollback_status'] ) && is_scalar( $data['rollback_status'] ) ? (string) $data['rollback_status'] : 'not_attempted';
			} else {
				$meta['rollback_status']     = (string) ( $result['rollback_status'] ?? 'not_needed' );
				$meta['verification_status'] = (string) ( $result['verification_status'] ?? '' );
			}
			AuditLog::record(
				'stonewright/rescue-rollback',
				[
					'incident_id' => $incident_id,
					'action'      => $action,
					'by'          => $by,
					'_meta'       => $meta,
				],
				$result instanceof \WP_Error ? 'error' : 'ok'
			);
		} catch ( \Throwable $failure ) {
			unset( $failure );
		}
	}

	/**
	 * The evidence as compact JSON, for clients that only read an error message.
	 *
	 * @param array<string, mixed> $result
	 */
	public static function evidence_json( array $result ): string {
		$evidence = [
			'rollback_status' => (string) ( $result['rollback_status'] ?? '' ),
			'incident_id'     => (string) ( $result['incident_id'] ?? '' ),
			'site'            => (string) ( $result['site_status'] ?? '' ),
			'probe'           => isset( $result['probe'] ) && is_array( $result['probe'] ) ? self::message_probe( $result['probe'] ) : [],
		];
		$json = wp_json_encode( $evidence, JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * @param array<string, mixed> $probe
	 * @return array<string, mixed>
	 */
	private static function message_probe( array $probe ): array {
		$legs = [];
		foreach ( is_array( $probe['legs'] ?? null ) ? $probe['legs'] : [] as $leg ) {
			if ( is_array( $leg ) && 'passed' !== ( $leg['status'] ?? '' ) ) {
				$entry = [
					'leg'    => (string) ( $leg['leg'] ?? '' ),
					'http'   => (int) ( $leg['http'] ?? 0 ),
					'reason' => (string) ( $leg['reason'] ?? '' ),
				];
				if ( ! empty( $leg['retried'] ) ) {
					$entry['retried'] = true;
				}
				$legs[] = $entry;
			}
		}
		return [
			'status' => (string) ( $probe['status'] ?? '' ),
			'legs'   => $legs,
		];
	}
}
