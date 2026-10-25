<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Optimistic concurrency for a stale Elementor editor versus a later MCP write.
 *
 * Baseline is the server tree hash and post_status at editor load, never the
 * in-memory dirty tree. Persist re-reads the server; a change between
 * precheck and persist still blocks with zero overwrite.
 *
 * A Stonewright restore or undo of a post puts the document back on purpose, so it refreshes the baseline of
 * every user who has one ({@see self::refresh_all_users()}); otherwise the next Elementor save of that post
 * would be refused as a stale editor although nothing is open.
 *
 * A baseline is a transient that lasts seven days from the last time it was written, so an editor that nobody opens
 * again leaves no row behind. A baseline that expired or was evicted counts as missing: assert_persist() lets the
 * save through, as it does for an editor that never captured one. Baselines an earlier version stored as options are
 * still read, and move to a transient the first time they are.
 */
final class EditorSaveGuard {

	/** Seconds a baseline, and the list of users who have one, is kept after the last editor load, save or restore. */
	public const BASELINE_TTL = 7 * DAY_IN_SECONDS;

	/** Name prefix of the baselines. They were options before they expired, so the same names are read as a fallback. */
	private const OPTION_PREFIX = 'stonewright_elementor_editor_baseline_';

	/** Most users whose baseline of one post is remembered, so a restore can refresh them all. */
	private const MAX_USERS = 50;

	public static function register(): void {
		add_action( 'elementor/editor/init', [ self::class, 'capture_from_editor' ], 20 );
		add_action( 'elementor/document/before_save', [ self::class, 'on_before_save' ], 1, 2 );
		add_action( 'elementor/document/after_save', [ self::class, 'on_after_save' ], 20, 2 );
	}

	public static function capture( int $post_id ): array|\WP_Error {
		if ( $post_id < 1 ) {
			return new \WP_Error(
				'stonewright_elementor_invalid_post',
				__( 'A valid Elementor document is required to capture a save baseline.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}
		$baseline = [
			'hash'        => TreeHasher::hash( ElementorData::read( $post_id ) ),
			'post_status' => (string) get_post_status( $post_id ),
			'user_id'     => get_current_user_id(),
			'captured_at' => time(),
		];
		set_transient( self::option_key( $post_id ), $baseline, self::BASELINE_TTL );
		self::remember_user( $post_id, (int) $baseline['user_id'] );
		return $baseline;
	}

	/**
	 * Brings the current user's baseline up to the document as it is now, after a Stonewright write by that user. The writer made
	 * the change on purpose, so its own earlier baseline must not make its next Elementor save look like a stale editor. The
	 * baselines of other users are left as they are: an editor another user has open still has to be told the document changed.
	 * A user without a baseline gets none.
	 */
	public static function refresh_current_user( int $post_id ): void {
		if ( $post_id < 1 ) {
			return;
		}
		if ( null === self::read_baseline( $post_id, get_current_user_id() ) ) {
			return;
		}
		self::capture( $post_id );
	}

	/**
	 * Brings the baseline of every user who has one up to the document as it is now, for a Stonewright restore or undo of the
	 * post. A user without a baseline gets none, and the baselines of other posts are not touched.
	 */
	public static function refresh_all_users( int $post_id ): void {
		if ( $post_id < 1 ) {
			return;
		}
		$hash       = TreeHasher::hash( ElementorData::read( $post_id ) );
		$status     = (string) get_post_status( $post_id );
		$remembered = self::remembered_users( $post_id );
		foreach ( array_unique( array_merge( [ get_current_user_id() ], $remembered ) ) as $user_id ) {
			if ( null === self::read_baseline( $post_id, $user_id ) ) {
				continue;
			}
			set_transient(
				self::option_key( $post_id, $user_id ),
				[
					'hash'        => $hash,
					'post_status' => $status,
					'user_id'     => $user_id,
					'captured_at' => time(),
				],
				self::BASELINE_TTL
			);
		}
		if ( [] !== $remembered ) {
			// The list lives as long as the newest baseline it names: a later restore must still find every user.
			set_transient( self::users_key( $post_id ), $remembered, self::BASELINE_TTL );
		}
	}

	public static function capture_from_editor(): void {
		$post_id = self::request_post_id();
		if ( $post_id > 0 ) {
			self::capture( $post_id );
		}
	}

	/**
	 * @param object $document Elementor document.
	 * @param mixed  $data     Incoming save payload. Unused: compare server state, not the local tree.
	 * @throws \RuntimeException When persist is blocked for a stale editor.
	 */
	public static function on_before_save( object $document, mixed $data = null ): void {
		unset( $data );
		$error = self::assert_persist( self::document_post_id( $document ) );
		if ( $error instanceof \WP_Error ) {
			throw new \RuntimeException( $error->get_error_message() );
		}
	}

	/**
	 * @param object $document Elementor document.
	 * @param mixed  $data     Incoming save payload.
	 */
	public static function on_after_save( object $document, mixed $data = null ): void {
		unset( $data );
		$post_id = self::document_post_id( $document );
		if ( $post_id > 0 ) {
			self::refresh_after_save( $post_id );
		}
	}

	public static function assert_persist( int $post_id ): ?\WP_Error {
		if ( $post_id < 1 ) {
			return new \WP_Error(
				'stonewright_elementor_invalid_post',
				__( 'A valid Elementor document is required.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}
		$baseline = self::read_baseline( $post_id, get_current_user_id() );
		if ( null === $baseline || ! isset( $baseline['hash'] ) ) {
			return null;
		}
		$current_hash   = TreeHasher::hash( ElementorData::read( $post_id ) );
		$current_status = (string) get_post_status( $post_id );
		if ( hash_equals( (string) $baseline['hash'], $current_hash )
			&& (string) ( $baseline['post_status'] ?? '' ) === $current_status ) {
			return null;
		}
		return new \WP_Error(
			'stonewright_elementor_stale_editor',
			__( 'The Elementor document changed on the server after this editor loaded. Reload before saving. The local editor draft was not discarded and the server copy was not overwritten.', 'stonewright' ),
			[
				'status'          => 409,
				'post_id'         => $post_id,
				'post_status'     => $current_status,
				'baseline_status' => (string) ( $baseline['post_status'] ?? '' ),
				'current_hash'    => $current_hash,
				'baseline_hash'   => (string) $baseline['hash'],
				'rollback_status' => 'not_attempted',
			]
		);
	}

	public static function refresh_after_save( int $post_id ): void {
		self::capture( $post_id );
	}

	/**
	 * Delete every baseline and user list of the current site: the transients and their expiry rows, and the options
	 * an earlier version stored. Deactivation and uninstall do the same through RuntimeState.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function forget_all(): int {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return 0;
		}
		$removed = 0;
		foreach ( [ '_transient_' . self::OPTION_PREFIX, '_transient_timeout_' . self::OPTION_PREFIX, self::OPTION_PREFIX ] as $prefix ) {
			$found = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The options table name is WordPress's own; the value is prepared.
			foreach ( is_array( $found ) ? $found : [] as $name ) {
				if ( delete_option( (string) $name ) ) {
					++$removed;
				}
			}
		}
		return $removed;
	}

	/** @internal */
	public static function reset_for_tests(): void {
		$prefix = self::OPTION_PREFIX;
		foreach ( [ 'stonewright_test_options', 'stonewright_test_transients', 'stonewright_test_transient_ttls' ] as $store ) {
			foreach ( array_keys( $GLOBALS[ $store ] ?? [] ) as $key ) {
				if ( is_string( $key ) && str_starts_with( $key, $prefix ) ) {
					unset( $GLOBALS[ $store ][ $key ] );
				}
			}
		}
	}

	private static function option_key( int $post_id, ?int $user_id = null ): string {
		return self::OPTION_PREFIX . $post_id . '_' . ( $user_id ?? get_current_user_id() );
	}

	private static function users_key( int $post_id ): string {
		return self::OPTION_PREFIX . 'users_' . $post_id;
	}

	/**
	 * The baseline this user holds for the post, from the transient or, for one an earlier version stored as an option,
	 * from the option: that one moves to a transient now, so the row can expire.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function read_baseline( int $post_id, int $user_id ): ?array {
		$key    = self::option_key( $post_id, $user_id );
		$stored = get_transient( $key );
		if ( is_array( $stored ) ) {
			return $stored;
		}
		$legacy = get_option( $key, null );
		if ( ! is_array( $legacy ) ) {
			return null;
		}
		set_transient( $key, $legacy, self::BASELINE_TTL );
		delete_option( $key );
		return $legacy;
	}

	/** @return list<int> */
	private static function remembered_users( int $post_id ): array {
		$key    = self::users_key( $post_id );
		$stored = get_transient( $key );
		if ( ! is_array( $stored ) ) {
			$stored = get_option( $key, [] );
			if ( is_array( $stored ) && [] !== $stored ) {
				set_transient( $key, $stored, self::BASELINE_TTL );
				delete_option( $key );
			}
		}
		return is_array( $stored ) ? array_values( array_map( 'intval', $stored ) ) : [];
	}

	/** Lists the user, and keeps the list as long as the newest baseline it names. */
	private static function remember_user( int $post_id, int $user_id ): void {
		$known = self::remembered_users( $post_id );
		if ( ! in_array( $user_id, $known, true ) ) {
			$known[] = $user_id;
		}
		set_transient( self::users_key( $post_id ), array_slice( $known, -self::MAX_USERS ), self::BASELINE_TTL );
	}

	private static function document_post_id( object $document ): int {
		if ( method_exists( $document, 'get_main_id' ) ) {
			return (int) $document->get_main_id();
		}
		if ( method_exists( $document, 'get_id' ) ) {
			return (int) $document->get_id();
		}
		return 0;
	}

	private static function request_post_id(): int {
		$raw = null;
		if ( isset( $_REQUEST['editor_post_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = wp_unslash( $_REQUEST['editor_post_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_REQUEST['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = wp_unslash( $_REQUEST['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return is_scalar( $raw ) ? (int) $raw : 0;
	}
}
