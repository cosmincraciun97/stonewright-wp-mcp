<?php
/**
 * Arm, probe and roll back around risky writes.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Support\AgentNotices;
use Stonewright\WpMcp\Support\Logger;

/**
 * What a write site calls. Each risky write makes one small call before it changes anything
 * (arm_post_write, arm_option_write, arm_plugin_write, arm_sandbox_write,
 * arm_custom_code_write, or arm_standalone for a write that settles itself). When the ability
 * call finishes, leave() runs once: it probes the site and either verifies the entries, or
 * rolls them back, probes again, and turns the result into a structured error that carries the
 * evidence.
 *
 * An ability call is a frame. AbilityKernel opens one around every audited ability and closes
 * it before the audit row is written, so the row shows the real outcome. A write made outside
 * any frame (an admin screen, a test) is not journaled: nothing would settle it.
 *
 * Nothing here ever throws into the ability: a failure inside the guard leaves the write and
 * the result as they were.
 */
final class RescueGuard {

	/** @var list<array{ability:string,user:int,ids:list<string>,posts:array<int,string>,baseline:array<string,array<string,mixed>>}> */
	private static array $frames = [];

	public static function enter( string $ability ): void {
		self::$frames[] = [
			'ability' => $ability,
			'user'    => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'ids'      => [],
			'posts'    => [],
			// What each leg did before the first write of the call, so one call probes each leg once.
			'baseline' => [],
		];
	}

	/**
	 * Close the innermost frame: settle what its call armed.
	 *
	 * @param mixed $result The ability's result.
	 * @return mixed The result, or a WP_Error when the site broke and the change was rolled back.
	 */
	public static function leave( mixed $result ): mixed {
		$frame = array_pop( self::$frames );
		if ( null === $frame || [] === $frame['ids'] ) {
			return $result;
		}
		try {
			return self::settle_frame( $frame, $result );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'rescue_guard_settle_failed', [ 'error' => $failure::class ] );
			return $result;
		}
	}

	/**
	 * A post is about to be written; its snapshot is the way back. Called by Backup::snapshot_post.
	 */
	public static function arm_post_write( int $post_id, string $snapshot_id ): ?string {
		$index = self::top();
		if ( null === $index || $post_id < 1 || '' === $snapshot_id ) {
			return null;
		}
		if ( isset( self::$frames[ $index ]['posts'][ $post_id ] ) ) {
			// The first snapshot is the state before this call touched the post.
			return self::$frames[ $index ]['posts'][ $post_id ];
		}
		$id = self::arm_in_frame(
			$index,
			[
				'resource_type' => 'post',
				'resource_key'  => (string) $post_id,
				'recipe'        => [ 'type' => 'post_snapshot', 'ref' => $snapshot_id ],
				'recipe_detail' => [ 'post_id' => $post_id, 'snapshot_id' => $snapshot_id ],
				'scope'         => 'post',
			]
		);
		if ( null !== $id ) {
			self::$frames[ $index ]['posts'][ $post_id ] = $id;
		}
		return $id;
	}

	/**
	 * Options or theme settings are about to be written; the restore point is the way back.
	 * Called by Backup::snapshot_options.
	 *
	 * @param list<string> $option_keys
	 */
	public static function arm_option_write( array $option_keys, string $restore_id ): ?string {
		$index = self::top();
		if ( null === $index || '' === $restore_id ) {
			return null;
		}
		return self::arm_in_frame(
			$index,
			[
				'resource_type' => 'option',
				'resource_key'  => implode( ',', array_slice( array_map( 'strval', $option_keys ), 0, 8 ) ),
				'recipe'        => [ 'type' => 'option_restore', 'ref' => $restore_id ],
				'recipe_detail' => [ 'restore_id' => $restore_id ],
				'scope'         => 'light',
			]
		);
	}

	/**
	 * A plugin is about to be activated or deactivated.
	 *
	 * @param bool $was_active Whether it is active now, before the write.
	 */
	public static function arm_plugin_write( string $plugin, bool $was_active ): ?string {
		$index = self::top();
		if ( null === $index || '' === $plugin ) {
			return null;
		}
		$paths = [];
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$file = ChangeJournal::relative_path( (string) constant( 'WP_PLUGIN_DIR' ) . '/' . $plugin );
			if ( '' !== $file ) {
				$paths[] = $file;
				$dir     = dirname( $file );
				if ( str_contains( $plugin, '/' ) && '.' !== $dir ) {
					$paths[] = $dir . '/';
				}
			}
		}
		return self::arm_in_frame(
			$index,
			[
				'resource_type' => 'plugin',
				'resource_key'  => $plugin,
				'recipe'        => [ 'type' => 'plugin_state', 'ref' => $plugin ],
				'recipe_detail' => [ 'plugin' => $plugin, 'was_active' => $was_active ],
				'paths'         => $paths,
				'scope'         => 'site',
			]
		);
	}

	/**
	 * A sandbox file is about to be activated as a must-use plugin.
	 */
	public static function arm_sandbox_write( string $name ): ?string {
		$index = self::top();
		if ( null === $index || '' === $name ) {
			return null;
		}
		$twin = \Stonewright\WpMcp\Sandbox\SandboxFiles::mu_dir() . '/' . \Stonewright\WpMcp\Sandbox\SandboxFiles::active_prefix() . $name;
		$path = ChangeJournal::relative_path( $twin );
		return self::arm_in_frame(
			$index,
			[
				'resource_type' => 'sandbox',
				'resource_key'  => $name,
				'recipe'        => [ 'type' => 'sandbox_file', 'ref' => $name ],
				'recipe_detail' => [ 'name' => $name ],
				'paths'         => '' !== $path ? [ $path ] : [],
				'scope'         => 'site',
			]
		);
	}

	/**
	 * A custom-code snippet (WPCode, Code Snippets) is about to be saved. The provider's own
	 * snapshot is attached with note_provider_snapshot() once it exists.
	 */
	public static function arm_custom_code_write( string $provider, string $target_id ): ?string {
		$index = self::top();
		if ( null === $index || '' === $provider ) {
			return null;
		}
		return self::arm_in_frame(
			$index,
			[
				'resource_type' => 'custom_code',
				'resource_key'  => $provider . ':' . $target_id,
				'recipe'        => [ 'type' => 'none', 'ref' => '' ],
				'recipe_detail' => [ 'provider' => $provider, 'target_id' => $target_id ],
				'scope'         => 'site',
			]
		);
	}

	/**
	 * The provider took its snapshot; attach it to the entry armed for that snippet.
	 * Called by ProviderSupport::snapshot_record.
	 */
	public static function note_provider_snapshot( string $provider, string $target_id, string $snapshot_id ): void {
		$index = self::top();
		if ( null === $index || '' === $snapshot_id ) {
			return;
		}
		try {
			foreach ( array_reverse( self::$frames[ $index ]['ids'] ) as $id ) {
				$entry = ChangeJournal::get( $id );
				if ( null === $entry || 'custom_code' !== $entry['resource_type'] ) {
					continue;
				}
				$detail = $entry['recipe_detail'];
				if ( ( $detail['provider'] ?? '' ) === $provider && ( $detail['target_id'] ?? '' ) === $target_id ) {
					ChangeJournal::attach_recipe( $id, [ 'type' => 'none', 'ref' => $snapshot_id ], [ 'snapshot_id' => $snapshot_id ] );
					return;
				}
			}
		} catch ( \Throwable $failure ) {
			Logger::warning( 'rescue_guard_note_failed', [ 'error' => $failure::class ] );
		}
	}

	/**
	 * Arm a write that settles itself (the theme file transaction), and take the baseline the site
	 * gives before it. Works with or without a frame.
	 *
	 * @param array<string, mixed>              $spec    A ChangeJournal::arm() spec.
	 * @param array{baseline?:bool,url?:string} $options baseline false skips the baseline; url is one more place to check.
	 * @return array<string, mixed>|null The entry. It is returned even when the journal could not store it, so the caller can still verify and roll back from memory. Null when the journal cannot describe the write at all: use memory_entry().
	 */
	public static function arm_standalone( array $spec, array $options = [] ): ?array {
		try {
			$entry = ChangeJournal::arm( $spec );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'rescue_guard_arm_failed', [ 'error' => $failure::class ] );
			return null;
		}
		return null === $entry ? null : self::with_baseline( $entry, $options );
	}

	/**
	 * An entry that lives only in memory, for a write the journal cannot describe (an ability name it
	 * cannot hold, a journal that refused it). The write is still checked and undone; there is simply
	 * no journal entry to point at afterwards.
	 *
	 * @param array<string, mixed>              $spec    A ChangeJournal::arm() spec.
	 * @param array{baseline?:bool,url?:string} $options As arm_standalone().
	 * @return array<string, mixed>
	 */
	public static function memory_entry( array $spec, array $options = [] ): array {
		$ability = isset( $spec['ability'] ) && is_string( $spec['ability'] ) ? strtolower( $spec['ability'] ) : '';
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,47}\/[a-z0-9][a-z0-9_-]{0,63}$/D', $ability ) ) {
			$ability = 'stonewright/unjournaled-write';
		}
		$type  = isset( $spec['resource_type'] ) && is_string( $spec['resource_type'] ) && in_array( $spec['resource_type'], ChangeJournalFile::RESOURCE_TYPES, true ) ? $spec['resource_type'] : 'option';
		$scope = isset( $spec['scope'] ) && is_string( $spec['scope'] ) && in_array( $spec['scope'], [ 'site', 'post', 'light' ], true ) ? $spec['scope'] : 'site';
		$now   = time();
		$entry = [
			'id'             => 'cs-' . bin2hex( random_bytes( 12 ) ),
			'ability'        => $ability,
			'resource_type'  => $type,
			'resource_key'   => isset( $spec['resource_key'] ) && is_scalar( $spec['resource_key'] ) ? (string) $spec['resource_key'] : '',
			'recipe'         => is_array( $spec['recipe'] ?? null ) ? $spec['recipe'] : [ 'type' => 'none', 'ref' => '' ],
			'recipe_detail'  => is_array( $spec['recipe_detail'] ?? null ) ? $spec['recipe_detail'] : [],
			'paths'          => [],
			'armed_at'       => $now,
			'probe_deadline' => $now + ChangeJournal::WINDOW,
			'state'          => 'armed',
			'incident'       => null,
			'scope'          => $scope,
			'actor'          => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'persisted'      => false,
		];
		return self::with_baseline( $entry, $options );
	}

	/**
	 * The ability whose call is open, or a fallback for a write made outside any call.
	 */
	public static function current_ability( string $fallback ): string {
		$index = self::top();
		return null === $index ? $fallback : self::$frames[ $index ]['ability'];
	}

	/**
	 * Probe the site for one armed entry and settle it: verified, left armed when the probe is
	 * unavailable, or rolled back and probed again. For a write that settles itself.
	 *
	 * @param array<string, mixed>|string $entry The armed entry, or its id. An entry the journal could not keep is verified from memory.
	 * @param array{legs?:list<string>,user_id?:int,post_id?:int,url?:string,rollback?:callable():array<string,string>} $options
	 * @return array{status:string,incident_id:string,probe:array<string,mixed>,rollback:array<string,mixed>|null,post_rollback_probe:array<string,mixed>|null,site_status:string}
	 */
	public static function verify_or_rollback( array|string $entry, array $options = [] ): array {
		if ( is_string( $entry ) ) {
			$entry = ChangeJournal::get( $entry );
		}
		$id   = is_array( $entry ) ? (string) ( $entry['id'] ?? '' ) : '';
		// An entry only this call knows has no id anyone can use afterwards.
		$none = [ 'status' => 'unknown', 'incident_id' => '' !== $id && null !== ChangeJournal::get( $id ) ? $id : '', 'probe' => [], 'rollback' => null, 'post_rollback_probe' => null, 'site_status' => 'unknown' ];
		if ( null === $entry || [] === $entry ) {
			return $none;
		}
		$context = RescueRollback::probe_context( $entry, isset( $options['user_id'] ) ? (int) $options['user_id'] : (int) ( $entry['actor'] ?? 0 ) );
		foreach ( [ 'legs', 'post_id' ] as $key ) {
			if ( isset( $options[ $key ] ) ) {
				$context[ $key ] = $options[ $key ];
			}
		}
		if ( isset( $options['url'] ) && '' !== $options['url'] ) {
			// A URL the caller asked to have checked is one more leg.
			$context['legs'][] = 'custom';
			$context['url']    = $options['url'];
		}
		$probe = RescueRollback::judge( HealthProbe::run( $context ), [ $entry ], $context );
		if ( 'passed' === $probe['status'] ) {
			ChangeJournal::settle( $id, 'verified', [ 'probe' => $probe ] );
			return array_merge( $none, [ 'status' => 'verified', 'probe' => $probe, 'site_status' => 'healthy' ] );
		}
		if ( 'unavailable' === $probe['status'] ) {
			ChangeJournal::record_probe( $id, $probe );
			self::note_unavailable( $probe );
			return array_merge( $none, [ 'status' => 'unavailable', 'probe' => $probe ] );
		}
		$rollback = RescueRollback::apply_recipe( $entry, 'auto', $options['rollback'] ?? null );
		$after    = RescueRollback::judge( HealthProbe::run( $context ), [ $entry ], $context );
		$settled  = RescueRollback::conclude( $id, $rollback, $probe, $after );
		return [
			'status'              => (string) ( $settled['state'] ?? ( RescueRollback::succeeded( $rollback ) ? 'rolled_back' : 'rollback_failed' ) ),
			'incident_id'         => $none['incident_id'],
			'probe'               => $probe,
			'rollback'            => $rollback,
			'post_rollback_probe' => $after,
			'site_status'         => RescueRollback::site_status( $after ),
		];
	}

	public static function reset_for_tests(): void {
		self::$frames = [];
	}

	// -----------------------------------------------------------------------
	// Settling a frame.
	// -----------------------------------------------------------------------

	/**
	 * @param array{ability:string,user:int,ids:list<string>,posts:array<int,string>} $frame
	 * @param mixed                                                                  $result
	 * @return mixed
	 */
	private static function settle_frame( array $frame, mixed $result ): mixed {
		$errored = $result instanceof \WP_Error || ( is_array( $result ) && false === ( $result['ok'] ?? true ) );

		$pending = [];
		foreach ( $frame['ids'] as $id ) {
			$entry = ChangeJournal::get( $id );
			if ( null !== $entry && 'armed' === $entry['state'] ) {
				$pending[] = $entry;
			}
		}
		$changed = [];
		foreach ( $pending as $entry ) {
			if ( self::changed( $entry, $errored ) ) {
				$changed[] = $entry;
			} else {
				ChangeJournal::settle( $entry['id'], 'verified', [ 'note' => 'no_change' ] );
			}
		}
		if ( [] === $changed ) {
			return $result;
		}

		$context = self::context_for( $changed, $frame['user'] );
		$probe   = RescueRollback::judge( HealthProbe::run( $context ), $changed, $context );

		if ( 'passed' === $probe['status'] ) {
			foreach ( $changed as $entry ) {
				ChangeJournal::settle( $entry['id'], 'verified', [ 'probe' => $probe ] );
			}
			return $result;
		}
		if ( 'unavailable' === $probe['status'] ) {
			foreach ( $changed as $entry ) {
				ChangeJournal::record_probe( $entry['id'], $probe );
			}
			self::note_unavailable( $probe );
			return $result;
		}

		$rollbacks = [];
		foreach ( array_reverse( $changed ) as $entry ) {
			$rollbacks[ $entry['id'] ] = RescueRollback::apply_recipe( $entry, 'auto' );
		}
		$after = RescueRollback::judge( HealthProbe::run( $context ), $changed, $context );
		foreach ( $changed as $entry ) {
			RescueRollback::conclude( $entry['id'], $rollbacks[ $entry['id'] ], $probe, $after );
		}
		return self::failure_error( $changed, $rollbacks, $probe, $after, $result );
	}

	/**
	 * Whether a call really changed what an entry guards. An unreadable snapshot counts as a
	 * change when the call succeeded.
	 *
	 * @param array<string, mixed> $entry
	 */
	private static function changed( array $entry, bool $errored ): bool {
		$ref    = (string) ( $entry['recipe']['ref'] ?? '' );
		$differs = match ( $entry['recipe']['type'] ?? '' ) {
			'post_snapshot'  => Backup::differs_from_snapshot( (int) ( $entry['recipe_detail']['post_id'] ?? $entry['resource_key'] ), $ref ),
			'option_restore' => Backup::options_differ_from_snapshot( $ref ),
			default          => null,
		};
		return null === $differs ? ! $errored : $differs;
	}

	/**
	 * One probe for everything a call changed: the union of the legs, the first post, the caller.
	 *
	 * @param list<array<string, mixed>> $entries
	 * @return array{legs:list<string>,user_id:int,post_id?:int}
	 */
	private static function context_for( array $entries, int $user_id ): array {
		$legs    = [];
		$post_id = 0;
		foreach ( $entries as $entry ) {
			$context = RescueRollback::probe_context( $entry, $user_id );
			$legs    = array_merge( $legs, $context['legs'] );
			if ( 0 === $post_id && isset( $context['post_id'] ) ) {
				$post_id = (int) $context['post_id'];
			}
		}
		$legs    = array_values( array_unique( $legs ) );
		$context = [ 'legs' => $legs, 'user_id' => $user_id ];
		if ( $post_id > 0 ) {
			$context['post_id'] = $post_id;
		}
		return $context;
	}

	/**
	 * @param list<array<string, mixed>>           $entries
	 * @param array<string, array<string, mixed>>  $rollbacks
	 * @param array<string, mixed>                 $probe
	 * @param array<string, mixed>                 $after
	 * @param mixed                                $original The ability's own result.
	 */
	private static function failure_error( array $entries, array $rollbacks, array $probe, array $after, mixed $original ): \WP_Error {
		$primary = $entries[0];
		$ok      = true;
		foreach ( $entries as $entry ) {
			if ( ! RescueRollback::succeeded( $rollbacks[ $entry['id'] ] ) ) {
				$ok      = false;
				$primary = $entry;
				break;
			}
		}
		$site = RescueRollback::site_status( $after );
		$data = [
			'status'              => 500,
			'retryable'           => false,
			'execution_status'    => 'ok',
			'verification_status' => 'failed',
			'rollback_status'     => $ok ? 'succeeded' : 'failed',
			'incident_id'         => (string) $primary['id'],
			'change_set_id'       => (string) $primary['id'],
			'site_status'         => $site,
			'probe'               => RescueRollback::compact_probe( $probe ),
			'resource_type'       => (string) $primary['resource_type'],
			'root_error_code'     => 'stonewright_rescue_probe_failed',
		];
		if ( $original instanceof \WP_Error ) {
			$data['original_error_code'] = (string) $original->get_error_code();
		}
		$evidence = RescueRollback::evidence_json( $data );

		if ( $ok ) {
			$message = match ( $site ) {
				'healthy'       => __( 'Stonewright rolled this change back because the site stopped loading after it. The site loads again.', 'stonewright' ),
				'still_failing' => __( 'Stonewright rolled this change back, but the site still fails to load, so the fault may not come from this change. Check the site, then call stonewright-rescue-status.', 'stonewright' ),
				default         => __( 'Stonewright rolled this change back because the site stopped loading after it. The check afterwards was unavailable, so recovery is not confirmed.', 'stonewright' ),
			};
			return new \WP_Error( 'stonewright_rescue_write_rolled_back', $message . ' ' . $evidence, $data );
		}
		return new \WP_Error(
			'stonewright_rescue_rollback_failed',
			sprintf(
				/* translators: 1: incident id, 2: compact JSON evidence. */
				__( 'The site stopped loading after this change and the automatic rollback failed. Call stonewright-rescue-rollback with incident_id %1$s, or use Stonewright > Rescue in wp-admin. Do not retry the change. %2$s', 'stonewright' ),
				(string) $primary['id'],
				$evidence
			),
			$data
		);
	}

	/**
	 * @param array<string, mixed> $probe
	 */
	private static function note_unavailable( array $probe ): void {
		$reason = (string) ( $probe['unavailable_reason'] ?? 'no_evidence' );
		AgentNotices::push(
			'rescue_probe',
			sprintf( 'rescue: health probe unavailable on this host (%s); recent changes are armed but not verified.', preg_replace( '/[^a-z0-9_]/', '', strtolower( $reason ) ) ),
			900
		);
	}

	// -----------------------------------------------------------------------
	// Frames.
	// -----------------------------------------------------------------------

	private static function top(): ?int {
		return [] === self::$frames ? null : array_key_last( self::$frames );
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private static function arm_in_frame( int $index, array $spec ): ?string {
		try {
			$entry = ChangeJournal::arm( array_merge( $spec, [ 'ability' => self::$frames[ $index ]['ability'] ] ) );
			if ( null === $entry ) {
				return null;
			}
			self::$frames[ $index ]['ids'][] = (string) $entry['id'];
		} catch ( \Throwable $failure ) {
			Logger::warning( 'rescue_guard_arm_failed', [ 'error' => $failure::class ] );
			return null;
		}
		// Before the caller writes anything: what the site does now.
		self::with_baseline( $entry, [] );
		return (string) $entry['id'];
	}

	/**
	 * Ask the site how it is before the write, so that silence afterwards can be told from a site that
	 * never answered. One call probes each leg once, and the post page only for its first post (the
	 * probe after the write checks that one). The result is kept on the entry; a failure here never
	 * stops the write from being armed.
	 *
	 * @param array<string, mixed>              $entry
	 * @param array{baseline?:bool,url?:string} $options
	 * @return array<string, mixed> The entry, with its baseline when there is one.
	 */
	private static function with_baseline( array $entry, array $options ): array {
		if ( false === ( $options['baseline'] ?? true ) ) {
			return $entry;
		}
		try {
			$index   = self::top();
			$user    = null !== $index ? self::$frames[ $index ]['user'] : ( function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0 );
			$context = RescueRollback::probe_context( $entry, $user );
			$legs    = $context['legs'];
			$url     = isset( $options['url'] ) ? (string) $options['url'] : '';
			if ( '' !== $url ) {
				$legs[]         = 'custom';
				$context['url'] = $url;
			}
			$known = null !== $index ? self::$frames[ $index ]['baseline'] : [];
			$need  = array_values( array_filter( $legs, static fn ( string $leg ): bool => ! isset( $known[ $leg ] ) ) );
			$fresh = [];
			if ( [] !== $need ) {
				$context['legs'] = $need;
				$probe           = HealthProbe::run( $context );
				foreach ( $probe['legs'] as $leg ) {
					$fresh[ (string) $leg['leg'] ] = $leg;
				}
				if ( null !== $index ) {
					self::$frames[ $index ]['baseline'] += $fresh;
				}
			}
			$own = [];
			foreach ( $legs as $leg ) {
				if ( isset( $fresh[ $leg ] ) ) {
					$own[] = $fresh[ $leg ];
				} elseif ( 'post' !== $leg && isset( $known[ $leg ] ) ) {
					// The post leg belongs to the entry that probed it; the others describe the whole site.
					$own[] = $known[ $leg ];
				}
			}
			if ( [] === $own ) {
				return $entry;
			}
			$baseline = HealthProbe::summary_of( $own );
			ChangeJournal::record_baseline( (string) $entry['id'], $baseline );
			$entry['baseline'] = $baseline;
		} catch ( \Throwable $failure ) {
			Logger::warning( 'rescue_guard_baseline_failed', [ 'error' => $failure::class ] );
		}
		return $entry;
	}
}
