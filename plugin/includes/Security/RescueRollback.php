<?php
/**
 * Rollback of a journaled change, shared by the ability, the admin page and WP-CLI.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Runs a journal entry's rollback recipe, probes the site afterwards, and records the outcome
 * on the entry. The automatic rollback after a failed probe (RescueGuard) and the manual one
 * (the rescue-rollback ability, the Rescue page, `wp stonewright rescue rollback`) share these
 * steps, so the journal tells the same story whichever path ran.
 */
final class RescueRollback {

	/** States in which an entry can still be rolled back or re-checked. */
	public const OPEN_STATES = [ 'incident', 'rollback_failed', 'armed' ];

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
	 * @return array<string, mixed>|null The updated entry.
	 */
	public static function conclude( string $id, array $rollback, array $probe_before, array $probe_after ): ?array {
		$ok   = self::succeeded( $rollback );
		$site = self::site_status( $probe_after );
		$extra = [
			'rollback' => [
				'status' => $rollback['status'],
				'at'     => $rollback['at'],
				'by'     => $rollback['by'],
				'recipe' => $rollback['recipe'],
				'detail' => $rollback['detail'],
				'site'   => $site,
			],
			'residual' => $ok && 'still_failing' === $site,
		];
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
	 * then and cannot be reached now counts as failed (see HealthProbe::compare()).
	 *
	 * @param array<string, mixed>       $probe   A probe taken after the change.
	 * @param list<array<string, mixed>> $entries The entries the probe covers.
	 * @param array<string, mixed>       $context The context the probe was run with.
	 * @return array<string, mixed>
	 */
	public static function judge( array $probe, array $entries, array $context ): array {
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
		return HealthProbe::compare( $probe, array_keys( $passed ) );
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
			$legs[] = [
				'leg'    => (string) ( $leg['leg'] ?? '' ),
				'status' => (string) ( $leg['status'] ?? '' ),
				'http'   => (int) ( $leg['http'] ?? 0 ),
				'reason' => (string) ( $leg['reason'] ?? '' ),
			];
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

	private static function in_progress_error( string $id ): \WP_Error {
		return new \WP_Error(
			'stonewright_rescue_in_progress',
			__( 'A rollback of this change set is already running. Check again in a minute.', 'stonewright' ),
			[ 'status' => 409, 'incident_id' => $id ]
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
		];
	}

	/**
	 * Roll an open change back by hand, then probe.
	 *
	 * @param array{by?:string,dry_run?:bool,user_id?:int} $options
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
		if ( ! in_array( $entry['state'], self::OPEN_STATES, true ) ) {
			return new \WP_Error(
				'stonewright_rescue_not_open',
				__( 'That change is already verified or rolled back. Nothing is left to roll back.', 'stonewright' ),
				[ 'status' => 409, 'incident_id' => $entry['id'], 'state' => $entry['state'] ]
			);
		}
		$plan = self::plan( $entry );
		if ( ! empty( $options['dry_run'] ) ) {
			return array_merge( [ 'ok' => true, 'dry_run' => true ], $plan );
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
			return null !== $fresh && ! in_array( $fresh['state'], self::OPEN_STATES, true )
				? new \WP_Error( 'stonewright_rescue_not_open', __( 'That change is already verified or rolled back. Nothing is left to roll back.', 'stonewright' ), [ 'status' => 409, 'incident_id' => $entry['id'], 'state' => $fresh['state'] ] )
				: self::in_progress_error( $entry['id'] );
		}

		try {
			$user      = isset( $options['user_id'] ) ? (int) $options['user_id'] : (int) get_current_user_id();
			$rollback  = self::apply_recipe( $entry, $by );
			$context   = self::probe_context( $entry, $user );
			$after     = self::judge( HealthProbe::run( $context ), [ $entry ], $context );
			$concluded = self::conclude( $entry['id'], $rollback, [], $after );
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
				$legs[] = [
					'leg'    => (string) ( $leg['leg'] ?? '' ),
					'http'   => (int) ( $leg['http'] ?? 0 ),
					'reason' => (string) ( $leg['reason'] ?? '' ),
				];
			}
		}
		return [
			'status' => (string) ( $probe['status'] ?? '' ),
			'legs'   => $legs,
		];
	}
}
