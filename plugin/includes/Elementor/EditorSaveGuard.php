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
 */
final class EditorSaveGuard {

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
		update_option( self::option_key( $post_id ), $baseline, false );
		self::remember_user( $post_id, (int) $baseline['user_id'] );
		return $baseline;
	}

	/**
	 * Brings the baseline of every user who has one up to the document as it is now, for a Stonewright restore or undo of the
	 * post. A user without a baseline gets none, and the baselines of other posts are not touched.
	 */
	public static function refresh_all_users( int $post_id ): void {
		if ( $post_id < 1 ) {
			return;
		}
		$hash   = TreeHasher::hash( ElementorData::read( $post_id ) );
		$status = (string) get_post_status( $post_id );
		foreach ( array_unique( array_merge( [ get_current_user_id() ], self::remembered_users( $post_id ) ) ) as $user_id ) {
			$key      = self::option_key( $post_id, $user_id );
			$baseline = get_option( $key, null );
			if ( ! is_array( $baseline ) ) {
				continue;
			}
			update_option(
				$key,
				[
					'hash'        => $hash,
					'post_status' => $status,
					'user_id'     => $user_id,
					'captured_at' => time(),
				],
				false
			);
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
		$baseline = get_option( self::option_key( $post_id ), null );
		if ( ! is_array( $baseline ) || ! isset( $baseline['hash'] ) ) {
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

	/** @internal */
	public static function reset_for_tests(): void {
		$prefix = self::OPTION_PREFIX;
		foreach ( array_keys( $GLOBALS['stonewright_test_options'] ?? [] ) as $key ) {
			if ( is_string( $key ) && str_starts_with( $key, $prefix ) ) {
				unset( $GLOBALS['stonewright_test_options'][ $key ] );
			}
		}
	}

	private static function option_key( int $post_id, ?int $user_id = null ): string {
		return self::OPTION_PREFIX . $post_id . '_' . ( $user_id ?? get_current_user_id() );
	}

	private static function users_key( int $post_id ): string {
		return self::OPTION_PREFIX . 'users_' . $post_id;
	}

	/** @return list<int> */
	private static function remembered_users( int $post_id ): array {
		$stored = get_option( self::users_key( $post_id ), [] );
		return is_array( $stored ) ? array_values( array_map( 'intval', $stored ) ) : [];
	}

	private static function remember_user( int $post_id, int $user_id ): void {
		$known = self::remembered_users( $post_id );
		if ( in_array( $user_id, $known, true ) ) {
			return;
		}
		$known[] = $user_id;
		update_option( self::users_key( $post_id ), array_slice( $known, -self::MAX_USERS ), false );
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
