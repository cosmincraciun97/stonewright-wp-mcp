<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Security\Adapters\CodeAdapter;

/**
 * Atomic theme-file write with backup, readback, journal, health probe, and rollback.
 */
final class ThemeWriteTransaction {

	public const DEFAULT_MAX_CHANGED_BYTES = 65536;
	private const BACKUP_INDEX_OPTION       = 'stonewright_theme_backup_index';

	/** Age a backup file needs before the prune may remove it. */
	public const ORPHAN_GRACE_SECONDS = 3600;

	/**
	 * Apply a verified candidate to an allowlisted theme path.
	 *
	 * @param array<string, mixed> $plan Absolute path, relative path, before/after bytes, language, smoke options.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function apply( array $plan ) {
		$absolute = (string) ( $plan['absolute'] ?? '' );
		$relative = (string) ( $plan['relative'] ?? '' );
		$before   = (string) ( $plan['before'] ?? '' );
		$after    = (string) ( $plan['after'] ?? '' );
		$language = strtolower( (string) ( $plan['language'] ?? self::detect_language( $relative ) ) );
		$max_bytes = (int) ( $plan['max_changed_bytes'] ?? self::DEFAULT_MAX_CHANGED_BYTES );

		if ( '' === $absolute || '' === $relative ) {
			return self::err( 'theme_write_path_required', __( 'Theme write path is required.', 'stonewright' ) );
		}

		$before_hash = hash( 'sha256', $before );
		$after_hash  = hash( 'sha256', $after );
		$changed_bytes = self::changed_bytes( $before, $after );

		if ( $changed_bytes > $max_bytes ) {
			return self::err(
				'theme_write_change_budget_exceeded',
				__( 'Theme file change exceeds the default byte budget. Prefer marker-bounded replacements or raise the budget explicitly with operator approval.', 'stonewright' ),
				[
					'changed_bytes'     => $changed_bytes,
					'max_changed_bytes' => $max_bytes,
				]
			);
		}

		// Optimistic concurrency: never overwrite bytes changed since the
		// ability built its candidate.
		$target_exists = is_file( $absolute );
		$current       = $target_exists ? file_get_contents( $absolute ) : false;
		if (
			( false === $current && '' !== $before )
			|| ( false !== $current && ! hash_equals( $before_hash, hash( 'sha256', (string) $current ) ) )
		) {
			return self::err(
				'theme_write_precondition_failed',
				__( 'Theme file changed after the candidate was prepared. Run dry_run again and request a new operator grant.', 'stonewright' ),
				[
					'execution_status'    => 'blocked',
					'verification_status' => 'stale_candidate',
					'before_sha256'       => $before_hash,
					'current_sha256'      => false === $current ? '' : hash( 'sha256', (string) $current ),
				]
			);
		}

		// Full-file validation before any target mutation.
		$validation = self::validate_candidate( $after, $language );
		if ( $validation instanceof \WP_Error ) {
			return $validation;
		}

		$backup = self::write_backup( $absolute, $relative, $before );
		if ( $backup instanceof \WP_Error ) {
			return $backup;
		}

		$dir = dirname( $absolute );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return self::err( 'theme_file_mkdir_failed', __( 'Could not create theme directory for the target path.', 'stonewright' ) );
		}

		// Journal the write before it happens, and ask the site how it is now (the baseline the check after
		// the write is judged against). A write that changes nothing needs neither. A write the journal cannot
		// describe is still checked from memory: it is never made unchecked.
		$rescue = null;
		$change = null;
		if ( $before_hash !== $after_hash ) {
			$rescue_spec    = self::rescue_spec( $absolute, $relative, $language, $backup, $target_exists );
			$rescue_options = [
				'baseline' => empty( $plan['skip_smoke'] ),
				'url'      => isset( $plan['smoke_url'] ) ? (string) $plan['smoke_url'] : '',
			];
			$rescue         = RescueGuard::arm_standalone( $rescue_spec, $rescue_options ) ?? RescueGuard::memory_entry( $rescue_spec, $rescue_options );

			// The change history records the file as it is, under the id the journal uses. A history that
			// cannot record never stops the write.
			$change = CodeAdapter::begin_theme_file(
				$absolute,
				$relative,
				$before,
				$target_exists,
				sprintf( '%s: %d to %d bytes', $relative, strlen( $before ), strlen( $after ) ),
				(string) ( $rescue['id'] ?? '' )
			);
		}

		$temp = $absolute . '.sw-tmp-' . bin2hex( random_bytes( 4 ) );
		$written = file_put_contents( $temp, $after, LOCK_EX );
		if ( false === $written ) {
			@unlink( $temp );
			CodeAdapter::finish( $change, 'failed' );
			return self::err( 'theme_file_write_failed', __( 'Failed to write temporary theme file.', 'stonewright' ) );
		}
		@chmod( $temp, 0644 );

		if ( ! @rename( $temp, $absolute ) ) {
			@unlink( $temp );
			CodeAdapter::finish( $change, 'failed' );
			return self::err(
				'theme_file_atomic_replace_failed',
				__( 'Atomic theme file replacement failed; the original target was left untouched.', 'stonewright' ),
				[
					'execution_status'    => 'error',
					'verification_status' => 'not_applied',
					'before_sha256'       => $before_hash,
					'after_sha256'        => $after_hash,
				]
			);
		}

		// Readback must match candidate hash exactly.
		$readback = is_file( $absolute ) ? (string) file_get_contents( $absolute ) : '';
		$read_hash = hash( 'sha256', $readback );
		if ( ! hash_equals( $after_hash, $read_hash ) ) {
			$rollback = self::restore_original( $absolute, $before, $before_hash, $target_exists );
			CodeAdapter::finish( $change, 'succeeded' === $rollback['status'] ? 'rolled_back' : 'rollback_failed' );
			return self::err(
				'theme_write_readback_mismatch',
				__( 'Theme file readback did not match the candidate. Rollback attempted.', 'stonewright' ),
				[
					'execution_status'    => 'ok',
					'verification_status' => 'failed',
					'rollback_status'     => $rollback['status'],
					'before_sha256'       => $before_hash,
					'after_sha256'        => $after_hash,
					'readback_sha256'     => $read_hash,
					'backup_path'         => $backup,
					'backup_ref'          => $backup,
					'recovery_ref'        => 'failed' === $rollback['status'] ? $backup : '',
					'rollback'            => $rollback,
				]
			);
		}

		$smoke      = [
			'status'  => 'skipped',
			'reason'  => 'not_requested',
		];
		$site_probe = 'skipped';
		if ( empty( $plan['skip_smoke'] ) && null !== $rescue ) {
			$raw_rollback = [];
			$verdict      = RescueGuard::verify_or_rollback(
				$rescue,
				[
					'url'      => isset( $plan['smoke_url'] ) ? (string) $plan['smoke_url'] : '',
					'rollback' => static function () use ( &$raw_rollback, $absolute, $before, $before_hash, $target_exists ): array {
						$raw_rollback = self::restore_original( $absolute, $before, $before_hash, $target_exists );
						return [
							'status' => (string) $raw_rollback['status'],
							'recipe' => 'theme_backup',
							'detail' => (string) ( $raw_rollback['error'] ?? '' ),
						];
					},
				]
			);
			$smoke      = HealthProbe::smoke_summary( $verdict['probe'] );
			$site_probe = 'verified' === $verdict['status'] ? 'passed' : ( 'unavailable' === $verdict['status'] ? 'unavailable' : 'failed' );
			if ( in_array( $verdict['status'], [ 'rolled_back', 'rollback_failed' ], true ) ) {
				CodeAdapter::finish( $change, (string) $verdict['status'] );
				$failure = [
					'execution_status'    => 'ok',
					'verification_status' => 'failed',
					'rollback_status'     => (string) ( $raw_rollback['status'] ?? 'failed' ),
					'before_sha256'       => $before_hash,
					'after_sha256'        => $after_hash,
					'backup_path'         => $backup,
					'backup_ref'          => $backup,
					'recovery_ref'        => 'failed' === ( $raw_rollback['status'] ?? 'failed' ) ? $backup : '',
					'smoke_summary'       => $smoke,
					'post_rollback_smoke' => HealthProbe::smoke_summary( (array) $verdict['post_rollback_probe'] ),
					'rollback'            => $raw_rollback,
					'incident_id'         => $verdict['incident_id'],
					'change_set_id'       => $verdict['incident_id'],
					'site_status'         => $verdict['site_status'],
					'probe'               => RescueRollback::compact_probe( $verdict['probe'] ),
				];
				$message = 'rolled_back' === $verdict['status']
					? __( 'Fresh WordPress bootstrap smoke failed after theme write. Original file restored.', 'stonewright' )
					: __( 'Fresh WordPress bootstrap smoke failed after theme write and the original file could not be restored. Use Stonewright > Rescue or stonewright-rescue-rollback.', 'stonewright' );
				return self::err(
					'theme_write_smoke_failed',
					$message . ' ' . RescueRollback::evidence_json(
						[
							'rollback_status' => 'rolled_back' === $verdict['status'] ? 'succeeded' : 'failed',
							'incident_id'     => $verdict['incident_id'],
							'site_status'     => $verdict['site_status'],
							'probe'           => $failure['probe'],
						]
					),
					$failure
				);
			}
		}

		// The file readback matched. The write is verified only when the site was checked afterwards and
		// answered: a check that could not run, or was skipped, leaves it unverified. A write that changed
		// nothing has no effect to check.
		$changed  = $before_hash !== $after_hash;
		$verified = ! $changed || 'passed' === $site_probe;
		if ( $changed ) {
			CodeAdapter::finish( $change, $verified ? 'verified' : 'probe_unavailable', $after );
		}
		$result   = [
			'ok'                  => true,
			'changed'             => $changed,
			'path'                => $relative,
			'before_sha256'       => $before_hash,
			'after_sha256'        => $after_hash,
			'changed_bytes'       => $changed_bytes,
			'backup_path'         => $backup,
			'backup_ref'          => $backup,
			'execution_status'    => 'ok',
			'verification_status' => $verified ? 'verified' : 'unverified',
			'rollback_status'     => 'not_needed',
			'validator_summary'   => [
				'language' => $language,
				'result'   => 'pass',
			],
			'smoke_summary'       => $smoke,
			'site_probe'          => $site_probe,
			'effect_verified'     => $verified,
		];
		if ( 'unavailable' === $site_probe ) {
			$result['probe_unavailable'] = true;
		}
		return $result;
	}

	/**
	 * The journal entry for one theme file write: the way back is the backup taken a moment ago,
	 * or removing the file when the write created it.
	 *
	 * @return array<string, mixed>
	 */
	private static function rescue_spec( string $absolute, string $relative, string $language, ?string $backup, bool $existed ): array {
		$path = ChangeJournal::relative_path( $absolute );
		$name = '' !== $path ? $path : $relative;
		$ref  = null !== $backup ? $backup : ( $existed ? 'empty:' . $name : 'absent:' . $name );
		$file = self::backup_file_name( $backup );
		return [
			'ability'       => RescueGuard::current_ability( 'stonewright/theme-file-patch' ),
			'resource_type' => 'theme_file',
			'resource_key'  => $relative,
			'recipe'        => [ 'type' => 'theme_backup', 'ref' => $ref ],
			'recipe_detail' => array_merge( [ 'absolute' => $absolute, 'absent_before' => ! $existed ], '' !== $file ? [ 'backup_file' => $file ] : [] ),
			'paths'         => '' !== $path ? [ $path ] : [],
			'scope'         => 'php' === $language ? 'site' : 'light',
		];
	}

	/**
	 * Put the target back as it was before the write: the original bytes, or no file at all when
	 * the write created it.
	 *
	 * @return array<string, mixed>
	 */
	private static function restore_original( string $absolute, string $before, string $before_hash, bool $existed ): array {
		if ( $existed ) {
			return self::rollback( $absolute, $before, $before_hash );
		}
		$removed = self::remove_created_file( $absolute );
		if ( 'failed' === $removed['status'] ) {
			return [
				'status'        => 'failed',
				'before_sha256' => $before_hash,
				'error'         => $removed['detail'],
				'recovery_ref'  => 'uploads/stonewright-theme-backups',
				'severity'      => 'p0',
			];
		}
		return [
			'status'        => 'succeeded',
			'before_sha256' => $before_hash,
		];
	}

	/**
	 * Count bytes removed plus bytes inserted after trimming the unchanged
	 * prefix and suffix. Equal-length replacements therefore cannot evade the
	 * operator grant and transaction budgets.
	 */
	public static function changed_bytes( string $before, string $after ): int {
		if ( $before === $after ) {
			return 0;
		}

		$before_len = strlen( $before );
		$after_len  = strlen( $after );
		$prefix     = 0;
		$limit      = min( $before_len, $after_len );
		while ( $prefix < $limit && $before[ $prefix ] === $after[ $prefix ] ) {
			++$prefix;
		}

		$suffix = 0;
		while (
			$suffix < ( $before_len - $prefix )
			&& $suffix < ( $after_len - $prefix )
			&& $before[ $before_len - 1 - $suffix ] === $after[ $after_len - 1 - $suffix ]
		) {
			++$suffix;
		}

		return ( $before_len - $prefix - $suffix ) + ( $after_len - $prefix - $suffix );
	}

	/**
	 * Restore original bytes and verify hash.
	 *
	 * @return array{status:string,before_sha256:string,readback_sha256?:string,error?:string}
	 */
	public static function rollback( string $absolute, string $original_bytes, string $original_hash ): array {
		$temp = $absolute . '.sw-rollback-' . bin2hex( random_bytes( 3 ) );
		if ( false === file_put_contents( $temp, $original_bytes, LOCK_EX ) ) {
			return [
				'status'         => 'failed',
				'before_sha256'  => $original_hash,
				'error'          => 'rollback_temp_write_failed',
				'recovery_ref'   => 'uploads/stonewright-theme-backups',
				'severity'       => 'p0',
			];
		}
		if ( ! @rename( $temp, $absolute ) ) {
			@unlink( $temp );
			return [
				'status'        => 'failed',
				'before_sha256' => $original_hash,
				'error'         => 'rollback_atomic_replace_failed',
				'recovery_ref'  => 'uploads/stonewright-theme-backups',
				'severity'      => 'p0',
			];
		}

		$read = is_file( $absolute ) ? (string) file_get_contents( $absolute ) : '';
		$rh   = hash( 'sha256', $read );
		if ( ! hash_equals( $original_hash, $rh ) ) {
			return [
				'status'           => 'failed',
				'before_sha256'    => $original_hash,
				'readback_sha256'  => $rh,
				'error'            => 'rollback_readback_mismatch',
				'recovery_ref'     => 'uploads/stonewright-theme-backups',
				'severity'         => 'p0',
			];
		}

		return [
			'status'          => 'succeeded',
			'before_sha256'   => $original_hash,
			'readback_sha256' => $rh,
		];
	}

	/**
	 * A one-off loopback check, answered in the shape the theme write has always reported.
	 * The probe itself is HealthProbe; a theme write runs it through RescueGuard.
	 *
	 * @return array<string, mixed>
	 */
	public static function fresh_bootstrap_smoke( ?string $url = null ): array {
		$custom = null !== $url && '' !== trim( $url );
		return HealthProbe::smoke_summary(
			HealthProbe::run(
				$custom
					? [ 'legs' => [ 'custom' ], 'url' => $url ]
					: [ 'legs' => [ 'rest' ] ]
			)
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function validate_candidate( string $after, string $language ) {
		return match ( $language ) {
			'php' => PhpSyntaxValidator::validate_complete_file( $after ),
			'css' => self::validate_css( $after ),
			'js'  => self::validate_js( $after ),
			default => true,
		};
	}

	/** @return true|\WP_Error */
	private static function validate_css( string $source ) {
		// Balanced braces / no obvious null bytes — production-safe lightweight check.
		if ( str_contains( $source, "\0" ) ) {
			return self::err( 'css_candidate_invalid', __( 'CSS candidate contains null bytes.', 'stonewright' ) );
		}
		$open  = substr_count( $source, '{' );
		$close = substr_count( $source, '}' );
		if ( $open !== $close ) {
			return self::err( 'css_candidate_invalid', __( 'CSS candidate has unbalanced braces.', 'stonewright' ) );
		}
		return true;
	}

	/** @return true|\WP_Error */
	private static function validate_js( string $source ) {
		if ( str_contains( $source, "\0" ) ) {
			return self::err( 'js_candidate_invalid', __( 'JS candidate contains null bytes.', 'stonewright' ) );
		}
		// Cheap paren/brace balance; not a full parser.
		foreach ( [ [ '{', '}' ], [ '(', ')' ], [ '[', ']' ] ] as [ $a, $b ] ) {
			if ( substr_count( $source, $a ) !== substr_count( $source, $b ) ) {
				return self::err( 'js_candidate_invalid', __( 'JS candidate has unbalanced brackets.', 'stonewright' ) );
			}
		}
		return true;
	}

	public static function detect_language( string $relative ): string {
		$lower = strtolower( $relative );
		if ( str_ends_with( $lower, '.php' ) ) {
			return 'php';
		}
		if ( str_ends_with( $lower, '.css' ) ) {
			return 'css';
		}
		if ( str_ends_with( $lower, '.js' ) ) {
			return 'js';
		}
		return 'text';
	}

	/**
	 * Restore a Stonewright-owned backup through the same transaction gates.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function restore_owned_backup( string $backup_ref, string $expected_absolute, ?string $smoke_url = null ) {
		$entry = self::backup_entry( $backup_ref );
		if ( $entry instanceof \WP_Error ) {
			return $entry;
		}
		if ( ! hash_equals( wp_normalize_path( $expected_absolute ), wp_normalize_path( (string) $entry['absolute'] ) ) ) {
			return self::err( 'theme_backup_target_mismatch', __( 'Backup target does not match the currently resolved theme path.', 'stonewright' ) );
		}
		$backup_path = (string) $entry['backup_path'];
		$bytes       = is_file( $backup_path ) ? file_get_contents( $backup_path ) : false;
		if ( false === $bytes || ! hash_equals( (string) $entry['sha256'], hash( 'sha256', (string) $bytes ) ) ) {
			return self::err( 'theme_backup_integrity_failed', __( 'Stonewright backup is missing or failed its integrity hash.', 'stonewright' ) );
		}
		$current = is_file( $expected_absolute ) ? (string) file_get_contents( $expected_absolute ) : '';
		return self::apply(
			[
				'absolute'  => $expected_absolute,
				'relative'  => (string) $entry['relative'],
				'before'    => $current,
				'after'     => (string) $bytes,
				'language'  => self::detect_language( (string) $entry['relative'] ),
				'smoke_url' => $smoke_url,
				// A restore may legitimately exceed ordinary patch delta size;
				// it is already bound to a Stonewright-owned exact backup.
				'max_changed_bytes' => max( self::DEFAULT_MAX_CHANGED_BYTES, self::changed_bytes( $current, (string) $bytes ) ),
			]
		);
	}

	/**
	 * Put back the bytes a Stonewright-owned backup holds, without any probe of its own.
	 * Used by the rescue rollback, which probes the site once afterwards.
	 *
	 * @return array{status:string,detail:string}
	 */
	public static function restore_for_rescue( string $backup_ref ): array {
		$entry = self::backup_entry( $backup_ref );
		if ( $entry instanceof \WP_Error ) {
			return [ 'status' => 'failed', 'detail' => 'backup_not_found' ];
		}
		$absolute = wp_normalize_path( (string) ( $entry['absolute'] ?? '' ) );
		if ( ! self::inside_theme_root( $absolute ) ) {
			return [ 'status' => 'failed', 'detail' => 'path_outside_theme' ];
		}
		$backup_path = (string) ( $entry['backup_path'] ?? '' );
		$bytes       = '' !== $backup_path && is_file( $backup_path ) ? file_get_contents( $backup_path ) : false;
		if ( false === $bytes || ! hash_equals( (string) ( $entry['sha256'] ?? '' ), hash( 'sha256', (string) $bytes ) ) ) {
			return [ 'status' => 'failed', 'detail' => 'integrity_failed' ];
		}
		$rolled = self::rollback( $absolute, (string) $bytes, hash( 'sha256', (string) $bytes ) );
		return 'succeeded' === $rolled['status']
			? [ 'status' => 'succeeded', 'detail' => '' ]
			: [ 'status' => 'failed', 'detail' => (string) ( $rolled['error'] ?? 'restore_failed' ) ];
	}

	/**
	 * Save what a rescue rollback is about to overwrite, so the rollback can be undone: the current bytes
	 * of the file in a Stonewright-owned backup, or the marker for a file that is empty or absent now.
	 * The returned reference is one RollbackRecipes can run again to put the file back.
	 *
	 * @param string $backup_ref The reference the rollback will restore, or an absent: / empty: marker.
	 * @param string $absolute   The path the marker names (a restore from a backup takes its path from the backup).
	 * @return array{status:string,detail:string,ref?:string,absolute?:string}
	 */
	public static function capture_for_rescue( string $backup_ref, string $absolute ): array {
		$relative = '';
		if ( str_starts_with( $backup_ref, 'sw-theme-backup-' ) ) {
			$entry = self::backup_entry( $backup_ref );
			if ( $entry instanceof \WP_Error ) {
				return [ 'status' => 'failed', 'detail' => 'backup_not_found' ];
			}
			$absolute = (string) ( $entry['absolute'] ?? '' );
			$relative = (string) ( $entry['relative'] ?? '' );
		}
		$absolute = wp_normalize_path( $absolute );
		if ( ! self::inside_theme_root( $absolute ) ) {
			return [ 'status' => 'failed', 'detail' => 'path_outside_theme' ];
		}
		$relative = '' !== $relative ? $relative : basename( $absolute );
		clearstatcache( true, $absolute );
		if ( ! file_exists( $absolute ) ) {
			return [ 'status' => 'ok', 'detail' => '', 'ref' => 'absent:' . $relative, 'absolute' => $absolute ];
		}
		$bytes = is_file( $absolute ) ? file_get_contents( $absolute ) : false;
		if ( false === $bytes ) {
			return [ 'status' => 'failed', 'detail' => 'unreadable' ];
		}
		if ( '' === $bytes ) {
			return [ 'status' => 'ok', 'detail' => '', 'ref' => 'empty:' . $relative, 'absolute' => $absolute ];
		}
		$ref = self::write_backup( $absolute, $relative, $bytes );
		if ( ! is_string( $ref ) ) {
			return [ 'status' => 'failed', 'detail' => 'backup_failed' ];
		}
		// The backup must read back as the bytes it was made from before it counts as a way back.
		$stored = self::backup_entry( $ref );
		$copy   = ! $stored instanceof \WP_Error && is_file( (string) ( $stored['backup_path'] ?? '' ) ) ? file_get_contents( (string) $stored['backup_path'] ) : false;
		if ( false === $copy || ! hash_equals( hash( 'sha256', $bytes ), hash( 'sha256', $copy ) ) ) {
			return [ 'status' => 'failed', 'detail' => 'backup_unreadable' ];
		}
		return [ 'status' => 'ok', 'detail' => '', 'ref' => $ref, 'absolute' => $absolute ];
	}
	/**
	 * Remove a file a write created. Only a file inside a theme directory is ever removed.
	 *
	 * @return array{status:string,detail:string}
	 */
	public static function remove_created_file( string $absolute ): array {
		$absolute = wp_normalize_path( $absolute );
		if ( ! self::inside_theme_root( $absolute ) ) {
			return [ 'status' => 'failed', 'detail' => 'path_outside_theme' ];
		}
		if ( ! file_exists( $absolute ) ) {
			return [ 'status' => 'noop', 'detail' => '' ];
		}
		return @unlink( $absolute ) || self::is_gone( $absolute )
			? [ 'status' => 'succeeded', 'detail' => '' ]
			: [ 'status' => 'failed', 'detail' => 'unlink_failed' ];
	}

	/** Whether a path no longer exists, as the file system says now rather than as a cached stat did. */
	private static function is_gone( string $absolute ): bool {
		clearstatcache( true, $absolute );
		return ! file_exists( $absolute );
	}

	/**
	 * Return a file that was empty before a write to empty.
	 *
	 * @return array{status:string,detail:string}
	 */
	public static function empty_file( string $absolute ): array {
		$absolute = wp_normalize_path( $absolute );
		if ( ! self::inside_theme_root( $absolute ) ) {
			return [ 'status' => 'failed', 'detail' => 'path_outside_theme' ];
		}
		$rolled = self::rollback( $absolute, '', hash( 'sha256', '' ) );
		return 'succeeded' === $rolled['status']
			? [ 'status' => 'succeeded', 'detail' => '' ]
			: [ 'status' => 'failed', 'detail' => (string) ( $rolled['error'] ?? 'restore_failed' ) ];
	}

	/**
	 * Whether a path lies inside the active theme directories (or the themes root), after
	 * following symbolic links of the part that exists.
	 */
	public static function inside_theme_root( string $absolute ): bool {
		$absolute = wp_normalize_path( $absolute );
		if ( '' === $absolute || str_contains( $absolute, "\0" ) || in_array( '..', explode( '/', $absolute ), true ) ) {
			return false;
		}
		$roots = [ (string) get_stylesheet_directory(), (string) get_template_directory() ];
		if ( function_exists( 'get_theme_root' ) ) {
			$roots[] = (string) get_theme_root();
		}
		$probe = $absolute;
		// Walk up to the nearest folder that exists, so a file the write has not created yet can still be placed.
		$depth = 0;
		while ( $depth < 64 ) {
			if ( file_exists( $probe ) ) {
				break;
			}
			$parent = dirname( $probe );
			if ( $parent === $probe ) {
				break;
			}
			$probe = $parent;
			++$depth;
		}
		$real = realpath( $probe );
		foreach ( array_unique( $roots ) as $root ) {
			$root = rtrim( wp_normalize_path( $root ), '/' );
			if ( '' === $root || ! str_starts_with( $absolute, $root . '/' ) ) {
				continue;
			}
			$real_root = realpath( $root );
			if ( false === $real || false === $real_root ) {
				return true;
			}
			$real      = rtrim( wp_normalize_path( $real ), '/' );
			$real_root = rtrim( wp_normalize_path( $real_root ), '/' );
			if ( $real === $real_root || str_starts_with( $real, $real_root . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array{backup_ref:string,relative:string,sha256:string,created_at:string}|\WP_Error
	 */
	public static function backup_metadata( string $backup_ref ) {
		$entry = self::backup_entry( $backup_ref );
		if ( $entry instanceof \WP_Error ) {
			return $entry;
		}
		return [
			'backup_ref' => $backup_ref,
			'relative'   => (string) $entry['relative'],
			'sha256'     => (string) $entry['sha256'],
			'created_at' => (string) $entry['created_at'],
		];
	}

	/** @return string|null|\WP_Error */
	private static function write_backup( string $absolute, string $relative, string $before ) {
		if ( '' === $before ) {
			return null;
		}
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return self::err( 'theme_file_backup_failed', __( 'Could not resolve uploads directory for theme backup.', 'stonewright' ) );
		}
		$dir = trailingslashit( (string) $upload['basedir'] ) . 'stonewright-theme-backups';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return self::err( 'theme_file_backup_failed', __( 'Could not create theme backup directory.', 'stonewright' ) );
		}
		$protection = self::protect_backup_directory( $dir );
		if ( $protection instanceof \WP_Error ) {
			return $protection;
		}
		$basename = basename( $absolute );
		$stamp    = $dir . '/' . gmdate( 'Ymd-His' ) . '-' . hash( 'sha256', $absolute );
		$target   = $stamp . '-' . $basename . '.swbak';
		// The name is created exclusively: a second write to the same file in the same second gets a name
		// of its own, so it never replaces the backup of the first.
		$handle = false;
		for ( $n = 2; $n < 1000; ++$n ) {
			$handle = @fopen( $target, 'xb' );
			if ( false !== $handle || ! file_exists( $target ) ) {
				break;
			}
			$target = $stamp . '-' . $n . '-' . $basename . '.swbak';
		}
		if ( false === $handle ) {
			return self::err( 'theme_file_backup_failed', __( 'Could not write theme backup file.', 'stonewright' ) );
		}
		$written = fwrite( $handle, $before );
		fclose( $handle );
		if ( strlen( $before ) !== $written ) {
			@unlink( $target );
			return self::err( 'theme_file_backup_failed', __( 'Could not write theme backup file.', 'stonewright' ) );
		}
		@chmod( $target, 0600 );
		$backup_ref = 'sw-theme-backup-' . wp_generate_uuid4();
		$index      = get_option( self::BACKUP_INDEX_OPTION, [] );
		$index      = is_array( $index ) ? $index : [];
		$index[ $backup_ref ] = [
			'absolute'    => wp_normalize_path( $absolute ),
			'relative'    => $relative,
			'backup_path' => wp_normalize_path( $target ),
			'sha256'      => hash( 'sha256', $before ),
			'created_at'  => current_time( 'mysql', true ),
		];
		$trimmed = count( $index ) > 100;
		if ( $trimmed ) {
			$index = array_slice( $index, -100, null, true );
		}
		update_option( self::BACKUP_INDEX_OPTION, $index, false );
		if ( $trimmed ) {
			// The entries that fell out leave files nothing refers to: remove those.
			try {
				self::prune_orphan_backups();
			} catch ( \Throwable $failure ) {
				unset( $failure );
			}
		}
		return $backup_ref;
	}

	/** @return true|\WP_Error */
	private static function protect_backup_directory( string $dir ) {
		$files = [
			'.htaccess' => "Require all denied\nDeny from all\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>\n",
			'index.html' => '',
		];
		foreach ( $files as $name => $contents ) {
			$path = trailingslashit( $dir ) . $name;
			if ( is_file( $path ) ) {
				continue;
			}
			if ( false === file_put_contents( $path, $contents, LOCK_EX ) ) {
				return self::err( 'theme_file_backup_protection_failed', __( 'Could not protect the theme backup directory from web access.', 'stonewright' ) );
			}
			@chmod( $path, 0600 );
		}
		@chmod( $dir, 0700 );
		return true;
	}

	/**
	 * Remove backup files that nothing refers to: no entry of the backup index, and no entry of the change
	 * journal. A file younger than the grace period is kept, because its index entry is written a moment
	 * after it. Only files with a backup file name are looked at. When the references cannot be listed,
	 * nothing is removed.
	 *
	 * @param int $min_age_seconds Age a file needs before it may be removed.
	 * @return array{removed:int,kept:int}
	 */
	public static function prune_orphan_backups( int $min_age_seconds = self::ORPHAN_GRACE_SECONDS ): array {
		$out    = [ 'removed' => 0, 'kept' => 0 ];
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return $out;
		}
		$dir = trailingslashit( (string) $upload['basedir'] ) . 'stonewright-theme-backups';
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		$referenced = self::referenced_backup_files();
		if ( null === $referenced ) {
			return $out;
		}
		$names = scandir( $dir );
		foreach ( false === $names ? [] : $names as $name ) {
			if ( 1 !== preg_match( '/^\d{8}-\d{6}-[a-f0-9]{64}-.+\.swbak$/D', $name ) ) {
				continue;
			}
			$path = $dir . '/' . $name;
			if ( is_link( $path ) || ! is_file( $path ) ) {
				continue;
			}
			$modified = filemtime( $path );
			if ( isset( $referenced[ $name ] ) || false === $modified || time() - $modified < $min_age_seconds ) {
				++$out['kept'];
				continue;
			}
			if ( @unlink( $path ) ) {
				++$out['removed'];
			} else {
				++$out['kept'];
			}
		}
		return $out;
	}

	/**
	 * Names of the backup files that the index and the change journal refer to, or null when they cannot be read.
	 *
	 * @return array<string, true>|null
	 */
	private static function referenced_backup_files(): ?array {
		$names = [];
		try {
			$index = get_option( self::BACKUP_INDEX_OPTION, [] );
			foreach ( is_array( $index ) ? $index : [] as $entry ) {
				if ( is_array( $entry ) && isset( $entry['backup_path'] ) && is_string( $entry['backup_path'] ) && '' !== $entry['backup_path'] ) {
					$names[ basename( $entry['backup_path'] ) ] = true;
				}
			}
			foreach ( ChangeJournal::recent( ChangeJournalFile::MAX_ENTRIES ) as $entry ) {
				$file = $entry['recipe_detail']['backup_file'] ?? '';
				if ( is_string( $file ) && '' !== $file ) {
					$names[ basename( $file ) ] = true;
				}
			}
		} catch ( \Throwable $failure ) {
			unset( $failure );
			return null;
		}
		return $names;
	}

	/** The file name of the backup a reference names, or ''. */
	private static function backup_file_name( ?string $backup_ref ): string {
		if ( null === $backup_ref ) {
			return '';
		}
		$entry = self::backup_entry( $backup_ref );
		return $entry instanceof \WP_Error ? '' : basename( (string) ( $entry['backup_path'] ?? '' ) );
	}

	/**
	 * Delete a theme file through the transaction's gates: the path must lie inside a theme folder, the file
	 * must still hold the bytes the caller saw, and the bytes are kept in a backup first. The undo of a write
	 * that created a file. It does not check the site afterwards; the caller does.
	 *
	 * @param array<string, mixed> $plan absolute, relative and before (the bytes the file must still hold).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function delete_file( array $plan ) {
		$absolute = wp_normalize_path( (string) ( $plan['absolute'] ?? '' ) );
		$relative = (string) ( $plan['relative'] ?? '' );
		if ( '' === $absolute || '' === $relative ) {
			return self::err( 'theme_write_path_required', __( 'Theme write path is required.', 'stonewright' ) );
		}
		if ( ! self::inside_theme_root( $absolute ) ) {
			return self::err( 'theme_delete_outside_theme', __( 'Only a file inside a theme folder can be deleted.', 'stonewright' ) );
		}
		if ( ! file_exists( $absolute ) ) {
			return [
				'ok'                  => true,
				'changed'             => false,
				'path'                => $relative,
				'execution_status'    => 'ok',
				'verification_status' => 'verified',
				'rollback_status'     => 'not_needed',
			];
		}
		$current = is_file( $absolute ) ? file_get_contents( $absolute ) : false;
		if ( false === $current ) {
			return self::err( 'theme_file_delete_failed', __( 'The theme file could not be read before it was deleted.', 'stonewright' ) );
		}
		$before_hash = hash( 'sha256', (string) ( $plan['before'] ?? '' ) );
		if ( ! hash_equals( $before_hash, hash( 'sha256', $current ) ) ) {
			return self::err(
				'theme_write_precondition_failed',
				__( 'Theme file changed after the candidate was prepared. Run dry_run again and request a new operator grant.', 'stonewright' ),
				[
					'execution_status'    => 'blocked',
					'verification_status' => 'stale_candidate',
					'before_sha256'       => $before_hash,
					'current_sha256'      => hash( 'sha256', $current ),
				]
			);
		}
		$backup = self::write_backup( $absolute, $relative, $current );
		if ( $backup instanceof \WP_Error ) {
			return $backup;
		}
		$change  = CodeAdapter::begin_theme_file( $absolute, $relative, $current, true, sprintf( '%s: deleted, %d bytes', $relative, strlen( $current ) ) );
		$removed = self::remove_created_file( $absolute );
		if ( 'failed' === $removed['status'] ) {
			CodeAdapter::finish( $change, 'failed' );
			return self::err( 'theme_file_delete_failed', __( 'The theme file could not be deleted.', 'stonewright' ), [ 'backup_ref' => $backup ] );
		}
		CodeAdapter::applied( $change );
		return [
			'ok'                  => true,
			'changed'             => true,
			'path'                => $relative,
			'before_sha256'       => $before_hash,
			'backup_ref'          => $backup,
			'execution_status'    => 'ok',
			'verification_status' => 'verified',
			'rollback_status'     => 'not_needed',
		];
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function backup_entry( string $backup_ref ) {
		if ( 1 !== preg_match( '/^sw-theme-backup-[a-f0-9-]{36}$/i', $backup_ref ) ) {
			return self::err( 'theme_backup_ref_invalid', __( 'Invalid Stonewright theme backup reference.', 'stonewright' ) );
		}
		$index = get_option( self::BACKUP_INDEX_OPTION, [] );
		$entry = is_array( $index ) && is_array( $index[ $backup_ref ] ?? null ) ? $index[ $backup_ref ] : null;
		if ( null === $entry ) {
			return self::err( 'theme_backup_not_found', __( 'Stonewright theme backup reference was not found.', 'stonewright' ) );
		}
		return $entry;
	}


	/**
	 * @param array<string, mixed> $data
	 */
	private static function err( string $code, string $message, array $data = [] ): \WP_Error {
		return new \WP_Error(
			'stonewright_' . $code,
			$message,
			array_merge(
				[
					'status'              => 400,
					'retryable'           => false,
					'execution_status'    => 'blocked',
					'verification_status' => 'not_applied',
				],
				$data
			)
		);
	}
}
