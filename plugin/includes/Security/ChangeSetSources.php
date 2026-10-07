<?php
/**
 * Change-set inputs read from the receipts and result keys the write families already return.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Elementor\Write\ElementTreeDiff;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Adapters from a write's own result to {@see ChangeSet::build()} inputs.
 *
 * Nothing here persists or recomputes a write: each adapter reads the receipt the
 * write returned (`write_receipt`, or the hashes, backup reference and verification
 * keys of a file or option write) and classifies the outcome. A write ability
 * supplies what only it knows, the planned changes and which of them would change
 * state, and calls the adapter for the rest.
 */
final class ChangeSetSources {

	/** The write ran and its independent readback matched. */
	public const OUTCOME_VERIFIED = 'verified';
	/** The write failed: refused, not persisted, rolled back, or its readback did not match. */
	public const OUTCOME_FAILED = 'failed';
	/** A gate stopped the write (approval, confirmation, permission). */
	public const OUTCOME_NOT_APPLIED = 'not_applied';
	public const OUTCOME_DRY_RUN     = 'dry_run';
	/** The request already matches the state; nothing to write. */
	public const OUTCOME_UNCHANGED = 'unchanged';
	/** The change waits for a later step, such as the browser finalizer. */
	public const OUTCOME_QUEUED = 'queued';
	/** The call succeeded but reports no verification. */
	public const OUTCOME_PENDING = 'pending';

	/** Rollback statuses that mean the earlier state is back. */
	private const ROLLED_BACK = [ 'succeeded', 'restored' ];

	/**
	 * The result array, or the error data of a failed call.
	 *
	 * @param array<string, mixed>|\WP_Error $result
	 * @return array<string, mixed>
	 */
	public static function data( array|\WP_Error $result ): array {
		if ( $result instanceof \WP_Error ) {
			$data = $result->get_error_data();
			return is_array( $data ) ? $data : [];
		}
		return $result;
	}

	/** Classify a call from its audit status and what it reported. */
	public static function outcome( string $status, bool $dry_run, string $verification_word, bool $queued = false, bool $unchanged = false ): string {
		if ( 'ok' !== $status ) {
			return 'blocked' === $status ? self::OUTCOME_NOT_APPLIED : self::OUTCOME_FAILED;
		}
		$word = strtolower( trim( $verification_word ) );
		if ( $queued || 'queued' === $word ) {
			return self::OUTCOME_QUEUED;
		}
		if ( $unchanged || 'unchanged' === $word ) {
			return self::OUTCOME_UNCHANGED;
		}
		if ( $dry_run ) {
			return self::OUTCOME_DRY_RUN;
		}
		return in_array( $word, [ 'verified', 'passed' ], true ) ? self::OUTCOME_VERIFIED : self::OUTCOME_PENDING;
	}

	/** The change-set verification status of an outcome. */
	public static function verification_status( string $outcome ): string {
		return match ( $outcome ) {
			self::OUTCOME_VERIFIED => ChangeSet::STATUS_VERIFIED,
			self::OUTCOME_FAILED   => ChangeSet::STATUS_FAILED,
			default                => ChangeSet::STATUS_UNVERIFIED,
		};
	}

	/**
	 * Split the planned changes into applied and missing for an outcome.
	 *
	 * A verified write applied every change that would alter state; a failed or
	 * refused write applied none of them and misses each; nothing is missing before
	 * a write has run. A change that alters nothing is planned only.
	 *
	 * @param list<array<string, mixed>> $effective Planned changes that would alter state.
	 * @return array{applied: list<array<string, mixed>>, missing: list<array<string, mixed>>}
	 */
	public static function lists( string $outcome, array $effective ): array {
		return match ( $outcome ) {
			self::OUTCOME_VERIFIED                          => [ 'applied' => $effective, 'missing' => [] ],
			self::OUTCOME_FAILED, self::OUTCOME_NOT_APPLIED => [ 'applied' => [], 'missing' => $effective ],
			default                                         => [ 'applied' => [], 'missing' => [] ],
		};
	}

	/**
	 * The approval a write that ran was under.
	 *
	 * @param array<string, mixed> $args
	 */
	public static function approval_reason( array $args, bool $ran ): ?string {
		if ( ! $ran ) {
			return null;
		}
		if ( '' !== trim( (string) ( $args['custom_code_grant'] ?? '' ) ) ) {
			return 'custom_code_grant';
		}
		if ( Permissions::is_production_safe() && '' !== trim( (string) ( $args['confirmation_token'] ?? '' ) ) ) {
			return 'confirmation_token';
		}
		return 'mode_policy';
	}

	/**
	 * Inputs for a write that returns an Elementor or Gutenberg `write_receipt`.
	 *
	 * @param array<string, mixed>                       $args
	 * @param array<string, mixed>|\WP_Error             $result
	 * @param list<array<string, mixed>>                 $planned
	 * @param list<array<string, mixed>>                 $effective Planned changes that would alter state.
	 * @param array{unexpected?: callable(): list<array<string, mixed>>, unchanged?: bool, seed?: list<mixed>} $options
	 *        `unexpected` is called only when the write verified and returns the unplanned changes its readback shows;
	 *        `unchanged` says the request already matches the document; `seed` adds to the derived change set id.
	 * @return array<string, mixed>
	 */
	public static function receipt( array $args, array|\WP_Error $result, string $status, array $planned, array $effective, array $options = [] ): array {
		$unexpected = $options['unexpected'] ?? null;
		$unchanged  = ! empty( $options['unchanged'] );
		$data     = self::data( $result );
		$receipt  = is_array( $data['write_receipt'] ?? null ) ? $data['write_receipt'] : [];
		$word     = strtolower( self::first( $receipt['verification_status'] ?? null, $data['verification_status'] ?? null ) );
		$dry_run  = ! empty( $args['dry_run'] ) || ! empty( $data['dry_run'] ) || ! empty( $receipt['dry_run'] );
		$queued   = ! empty( $data['queued'] );
		$outcome  = self::outcome( $status, $dry_run, $word, $queued, $unchanged );
		$lists    = self::lists( $outcome, $effective );
		$rollback = strtolower( self::first( $receipt['rollback_status'] ?? null, $data['rollback_status'] ?? null ) );
		$snapshot = self::first( $receipt['snapshot_id'] ?? null, $data['snapshot_id'] ?? null );
		$post_id  = (int) self::first( $receipt['post_id'] ?? null, $data['post_id'] ?? null, $args['post_id'] ?? null );

		$before   = self::hash( self::first( $receipt['before_hash'] ?? null, $data['before_hash'] ?? null ) );
		$expected = self::hash( $receipt['planned_hash'] ?? '' );
		$observed = self::hash( self::first( $receipt['readback_hash'] ?? null, $data['readback_hash'] ?? null ) );
		$recorded = self::hash( self::first( $receipt['after_hash'] ?? null, $data['after_hash'] ?? null ) );
		$after    = match ( $outcome ) {
			self::OUTCOME_DRY_RUN                           => '' !== $expected ? $expected : $recorded,
			self::OUTCOME_VERIFIED, self::OUTCOME_FAILED    => '' !== $observed ? $observed : $recorded,
			self::OUTCOME_UNCHANGED                         => '' !== $recorded ? $recorded : $before,
			default                                         => '',
		};

		$attempted = self::OUTCOME_VERIFIED === $outcome || ( self::OUTCOME_FAILED === $outcome && ( '' !== $snapshot || in_array( $rollback, [ 'succeeded', 'failed' ], true ) ) );
		$error     = $result instanceof \WP_Error ? sanitize_key( (string) $result->get_error_code() ) : '';

		$out = [
			'change_set_id'       => (string) ( $receipt['change_set_id'] ?? '' ),
			'seed'                => array_merge( [ 'receipt', $post_id, $before, $expected ], $options['seed'] ?? [] ),
			'planned'             => $planned,
			'applied'             => $lists['applied'],
			'missing'             => $lists['missing'],
			'unexpected'          => self::OUTCOME_VERIFIED === $outcome && null !== $unexpected ? $unexpected() : [],
			'before_hash'         => $before,
			'after_hash'          => $after,
			'verification'        => [
				'status'   => self::verification_status( $outcome ),
				'evidence' => [
					'method'          => 'readback_hash',
					'outcome'         => $outcome,
					'expected_hash'   => $expected,
					'observed_hash'   => $observed,
					'rollback_status' => $rollback,
					'root_error_code' => '' !== $error ? $error : self::first( $receipt['root_error_code'] ?? null, $data['root_error_code'] ?? null ),
					'root_error_path' => (string) ( $receipt['root_error_path'] ?? '' ),
					'architecture'    => (string) ( $receipt['architecture'] ?? '' ),
				],
			],
			'rollback_available'  => '' !== $snapshot && ( self::OUTCOME_VERIFIED === $outcome || ( self::OUTCOME_FAILED === $outcome && in_array( $rollback, [ 'failed', 'pending' ], true ) ) ),
			'rollback_recipe_ref' => '' === $snapshot ? null : [ 'kind' => 'post_snapshot', 'ref' => $snapshot, 'target' => (string) $post_id ],
			'approval_reason'     => self::approval_reason( $args, $attempted ),
		];
		return $out + ChangeSet::lineage_input( $args );
	}

	/**
	 * Inputs for a write of one file or text target that reports hashes
	 * (`before_sha256`, `after_sha256`, `readback_sha256`) and a backup reference.
	 *
	 * @param array<string, mixed>                                        $args
	 * @param array<string, mixed>|\WP_Error                              $result
	 * @param array<string, mixed>                                        $entry   The planned change (kind, ref, action).
	 * @param array{recipe_kind?: string, recipe_target?: string, restore?: bool} $options The backup reference is read
	 *        from `backup_ref` or `snapshot_id`; `restore` says the reported `before_sha256` is the restored state.
	 * @return array<string, mixed>
	 */
	public static function file( array $args, array|\WP_Error $result, string $status, array $entry, array $options = [] ): array {
		$data      = self::data( $result );
		$word      = strtolower( (string) ( $data['verification_status'] ?? '' ) );
		$before    = self::hash( $data['before_sha256'] ?? '' );
		$candidate = self::hash( $data['after_sha256'] ?? '' );
		$observed  = self::hash( $data['readback_sha256'] ?? '' );
		if ( ! empty( $options['restore'] ) ) {
			$candidate = $before;
			$before    = '';
		}
		$changed   = array_key_exists( 'changed', $data ) ? (bool) $data['changed'] : ( '' === $before || '' === $candidate || $before !== $candidate );
		$dry_run   = ! empty( $args['dry_run'] ) || ! empty( $data['dry_run'] ) || 'dry_run' === $word;
		$outcome   = self::outcome( $status, $dry_run, $word, false, ! $changed );
		$lists     = self::lists( $outcome, self::OUTCOME_UNCHANGED === $outcome ? [] : [ $entry ] );
		$rollback  = strtolower( (string) ( $data['rollback_status'] ?? '' ) );
		$backup    = self::first( $data['backup_ref'] ?? null, $data['snapshot_id'] ?? null );
		$executed  = 'ok' === (string) ( $data['execution_status'] ?? '' );
		$error     = $result instanceof \WP_Error ? sanitize_key( (string) $result->get_error_code() ) : '';
		$smoke     = is_array( $data['smoke_summary'] ?? null ) ? (string) ( $data['smoke_summary']['status'] ?? '' ) : '';

		$after = match ( $outcome ) {
			self::OUTCOME_DRY_RUN, self::OUTCOME_UNCHANGED => $candidate,
			self::OUTCOME_VERIFIED                         => '' !== $observed ? $observed : $candidate,
			self::OUTCOME_FAILED                           => '' !== $observed ? $observed : ( $executed ? $candidate : '' ),
			default                                        => '',
		};
		$recipe    = '' !== $backup && ! empty( $options['recipe_kind'] ) ? [ 'kind' => $options['recipe_kind'], 'ref' => $backup, 'target' => (string) ( $options['recipe_target'] ?? '' ) ] : null;
		$attempted = self::OUTCOME_VERIFIED === $outcome || ( self::OUTCOME_FAILED === $outcome && $executed );

		$out = [
			'seed'                => [ 'file', (string) ( $entry['ref'] ?? '' ), $before, $candidate ],
			'planned'             => [ $entry ],
			'applied'             => $lists['applied'],
			'missing'             => $lists['missing'],
			'before_hash'         => $before,
			'after_hash'          => $after,
			'verification'        => [
				'status'   => self::verification_status( $outcome ),
				'evidence' => [
					'method'          => 'readback_hash',
					'outcome'         => $outcome,
					'expected_hash'   => $candidate,
					'observed_hash'   => $observed,
					'rollback_status' => $rollback,
					'execution'       => (string) ( $data['execution_status'] ?? '' ),
					'smoke'           => $smoke,
					'changed_bytes'   => isset( $data['changed_bytes'] ) ? (int) $data['changed_bytes'] : null,
					'root_error_code' => '' !== $error ? $error : (string) ( $data['root_error_code'] ?? '' ),
				],
			],
			'rollback_available'  => null !== $recipe && ! in_array( $rollback, self::ROLLED_BACK, true )
				&& ( self::OUTCOME_VERIFIED === $outcome || ( self::OUTCOME_FAILED === $outcome && 'failed' === $rollback ) ),
			'rollback_recipe_ref' => $recipe,
			'approval_reason'     => self::approval_reason( $args, $attempted ),
		];
		return $out + ChangeSet::lineage_input( $args );
	}

	/**
	 * Elements the readback shows changed although the plan did not touch them:
	 * the live document against the snapshot taken before the write.
	 *
	 * @param list<string> $touched Ids the plan adds, updates or moves.
	 * @param list<string> $removed Ids the plan removes.
	 * @return list<array<string, mixed>>
	 */
	public static function elements_outside_plan( int $post_id, string $snapshot_id, array $touched, array $removed = [] ): array {
		if ( $post_id <= 0 || '' === $snapshot_id ) {
			return [];
		}
		$stored = Backup::get_snapshot( $post_id, $snapshot_id );
		if ( ! is_array( $stored ) || ! is_array( $stored['meta'] ?? null ) || ! array_key_exists( '_elementor_data', $stored['meta'] ) ) {
			return [];
		}
		$found = ElementTreeDiff::unexpected( ElementTreeDiff::tree_from_meta( $stored['meta']['_elementor_data'] ), ElementorData::read( $post_id ), $touched, $removed );
		return array_map( static fn ( array $change ): array => ChangeSet::entry( 'element', $change['ref'], $change['action'] ), $found );
	}

	/**
	 * Hashes of the option and theme-mod values an option snapshot covers: as the
	 * snapshot recorded them, and as they are now.
	 *
	 * @return array{before: string, after: string}
	 */
	public static function option_hashes( string $restore_id ): array {
		$store = get_option( Backup::OPTION_SNAPSHOTS, [] );
		$row   = is_array( $store ) && is_array( $store[ $restore_id ] ?? null ) ? $store[ $restore_id ] : null;
		if ( null === $row ) {
			return [ 'before' => '', 'after' => '' ];
		}
		$before = [ 'options' => (array) ( $row['options'] ?? [] ), 'theme_mods' => (array) ( $row['theme_mods'] ?? [] ) ];
		$after  = [ 'options' => [], 'theme_mods' => [] ];
		foreach ( array_keys( $before['options'] ) as $key ) {
			$sentinel                     = new \stdClass();
			$value                        = get_option( (string) $key, $sentinel );
			$exists                       = $value !== $sentinel;
			$after['options'][ $key ]     = [ 'exists' => $exists, 'value' => $exists ? $value : null ];
		}
		foreach ( array_keys( $before['theme_mods'] ) as $key ) {
			$sentinel                        = new \stdClass();
			$value                           = function_exists( 'get_theme_mod' ) ? get_theme_mod( (string) $key, $sentinel ) : $sentinel;
			$exists                          = $value !== $sentinel;
			$after['theme_mods'][ $key ]     = [ 'exists' => $exists, 'value' => $exists ? $value : null ];
		}
		return [ 'before' => TreeHasher::hash( $before ), 'after' => TreeHasher::hash( $after ) ];
	}

	/**
	 * Inputs for a write of options and theme mods that returns an option restore id.
	 *
	 * @param array<string, mixed>           $args
	 * @param array<string, mixed>|\WP_Error $result
	 * @param list<array<string, mixed>>     $planned
	 * @return array<string, mixed>
	 */
	public static function options( array $args, array|\WP_Error $result, string $status, array $planned, bool $unchanged = false ): array {
		$data     = self::data( $result );
		$dry_run  = ! empty( $data['dry_run'] ) || ( ! array_key_exists( 'dry_run', $data ) && ! empty( $args['dry_run'] ) );
		$verified = array_key_exists( 'effect_verified', $data ) ? ( true === $data['effect_verified'] ? 'verified' : 'failed' ) : '';
		$restore  = self::first( $data['snapshot_id'] ?? null, $data['restore_id'] ?? null );
		$hashes   = '' !== $restore && ! $dry_run ? self::option_hashes( $restore ) : [ 'before' => '', 'after' => '' ];
		// A write that left every covered value as it was changed nothing.
		$unchanged = $unchanged || ( 'ok' === $status && '' !== $hashes['before'] && $hashes['before'] === $hashes['after'] );
		$outcome   = self::outcome( $status, $dry_run, $verified, false, $unchanged );
		$lists     = self::lists( $outcome, $unchanged ? [] : $planned );
		if ( ! in_array( $outcome, [ self::OUTCOME_VERIFIED, self::OUTCOME_FAILED, self::OUTCOME_UNCHANGED ], true ) ) {
			$hashes = [ 'before' => '', 'after' => '' ];
		}
		$error    = $result instanceof \WP_Error ? sanitize_key( (string) $result->get_error_code() ) : '';

		$out = [
			'seed'                => [ 'options', $restore, (string) ( $data['theme'] ?? '' ), (string) wp_json_encode( $planned ) ],
			'planned'             => $planned,
			'applied'             => $lists['applied'],
			'missing'             => $lists['missing'],
			'before_hash'         => $hashes['before'],
			'after_hash'          => $hashes['after'],
			'verification'        => [
				'status'   => self::verification_status( $outcome ),
				'evidence' => [
					'method'          => 'option_readback',
					'outcome'         => $outcome,
					'root_error_code' => $error,
				],
			],
			'rollback_available'  => '' !== $restore && in_array( $outcome, [ self::OUTCOME_VERIFIED, self::OUTCOME_FAILED ], true ),
			'rollback_recipe_ref' => '' !== $restore ? [ 'kind' => 'option_snapshot', 'ref' => $restore ] : null,
			'approval_reason'     => self::approval_reason( $args, in_array( $outcome, [ self::OUTCOME_VERIFIED, self::OUTCOME_FAILED ], true ) && '' !== $restore ),
		];
		return $out + ChangeSet::lineage_input( $args );
	}

	/** The first value that is a non-empty scalar, as text. */
	private static function first( mixed ...$values ): string {
		foreach ( $values as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	private static function hash( mixed $value ): string {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}
}
