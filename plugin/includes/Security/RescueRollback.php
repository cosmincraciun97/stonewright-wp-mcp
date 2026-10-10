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

/**
 * Runs a journal entry's rollback recipe, probes the site afterwards, and records the outcome
 * on the entry. The automatic rollback after a failed probe (RescueGuard) and the manual one
 * (the rescue-rollback ability, the Rescue page, `wp stonewright rescue rollback`) share these
 * steps, so the journal tells the same story whichever path ran.
 */
final class RescueRollback {

	/** States in which an entry can still be re-checked. */
	public const OPEN_STATES = [ 'incident', 'rollback_failed', 'armed' ];

	/** States in which an entry can be rolled back: the open ones, and a verified change that is undone on request. */
	public const ROLLBACK_STATES = [ 'incident', 'rollback_failed', 'armed', 'verified' ];

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
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	public static function plan( array $entry ): array {
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
		if ( self::needs_human_approval( $entry ) ) {
			$plan['approval_required'] = true;
			$plan['approval_url']      = self::approval_url();
		}
		if ( [] !== $newer ) {
			$ids = implode( ', ', array_map( static fn ( array $other ): string => (string) $other['id'], array_slice( $newer, 0, 3 ) ) );
			$plan['warnings'] = [
				sprintf(
					/* translators: %s: change set ids */
					__( 'A newer change to the same item exists (%s). Rolling this one back also overwrites it.', 'stonewright' ),
					$ids
				),
			];
		}
		return $plan;
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
	 * @param array<string, mixed> $entry
	 */
	private static function approval_required_error( array $entry ): \WP_Error {
		$message  = __( 'Undoing a verified change to code needs an administrator. Ask the administrator to open Stonewright > Activity > Rescue and press Roll back for this change, then stop. Do not retry the call.', 'stonewright' );
		$proposal = CustomCodeGrant::missing_grant_proposal(
			[
				'error_code'          => 'stonewright_rescue_approval_required',
				'message'             => $message,
				'approval_url'        => self::approval_url(),
				'recommended_next'    => 'show the change and the approval URL, then stop for the administrator',
				'operator_action'     => 'An administrator opens Stonewright > Activity > Rescue and presses Roll back for this change.',
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
	 * Roll a change back by hand, then probe. An open change (an incident, or one never verified) can
	 * always be rolled back; a verified change can be undone on request, except a verified change to
	 * code, which needs `human_approved`: only the Rescue page, where an administrator presses the
	 * button, passes it.
	 *
	 * @param array{by?:string,dry_run?:bool,user_id?:int,human_approved?:bool} $options
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
		$plan = self::plan( $entry );
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

		// Claim the entry before anything runs: a double click, or the page and an agent together, run the recipe once.
		$by = (string) ( $options['by'] ?? 'ability' );
		if ( null === ChangeJournal::claim( $entry['id'], $by ) ) {
			$fresh = ChangeJournal::get( $entry['id'] );
			return null !== $fresh && ! in_array( $fresh['state'], self::ROLLBACK_STATES, true )
				? self::not_open_error( $entry['id'], (string) $fresh['state'] )
				: self::in_progress_error( $entry['id'] );
		}

		$undo = 'verified' === (string) $entry['state'];
		try {
			$user     = isset( $options['user_id'] ) ? (int) $options['user_id'] : (int) get_current_user_id();
			$context  = self::probe_context( $entry, $user );
			$restore  = [];
			$baseline = [];
			if ( $undo ) {
				// The site works and the recipe is about to replace what works with something older. Save what it
				// replaces first, and ask the site how it is now: without both there is no way back to judge by.
				$saved = self::save_current_state( $entry );
				if ( $saved instanceof \WP_Error ) {
					return $saved;
				}
				$restore  = $saved;
				$baseline = HealthProbe::run( $context );
			}
			$rollback         = self::apply_recipe( $entry, $by );
			$rollback['user'] = $user;
			// An undo is judged against the probe taken just before it, not the one from when the change was made.
			$judged = $undo ? [ [ 'baseline' => $baseline, 'resource_key' => $entry['resource_key'] ] ] : [ $entry ];
			$after  = self::judge( HealthProbe::run( $context ), $judged, $context, $undo );
			$guard  = $undo ? self::undo_guard( $baseline, $after ) : '';
			if ( 'reverted' === $guard ) {
				return self::put_back( $entry, $restore, $rollback, $after, $judged, $context, $plan );
			}
			$concluded = self::conclude( $entry['id'], $rollback, [], $after, $undo ? 'undone_after_verified' : '' );
		} finally {
			// conclude() settles the entry and clears the claim; this covers anything that threw before it.
			ChangeJournal::release_claim( $entry['id'] );
		}
		$ok   = self::succeeded( $rollback );
		$site = self::site_status( $after );

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
		];
		if ( isset( $plan['warnings'] ) ) {
			$result['warnings'] = $plan['warnings'];
		}
		if ( $ok && '' !== $guard ) {
			$result['undo_guard'] = $guard;
			if ( 'site_already_failing' === $guard ) {
				$result['warnings'][] = __( 'The site was already failing before this undo, so the undo was kept and not put back.', 'stonewright' );
			}
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
	 * Save what the recipe of a verified change is about to overwrite, with the way to put it back.
	 *
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>|\WP_Error The entry-shaped recipe that restores the saved state, or the refusal.
	 */
	private static function save_current_state( array $entry ): array|\WP_Error {
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
