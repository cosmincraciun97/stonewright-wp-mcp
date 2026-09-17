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
 */
final class EditorSaveGuard {

	private const OPTION_PREFIX = 'stonewright_elementor_editor_baseline_';

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
		return $baseline;
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

	private static function option_key( int $post_id ): string {
		return self::OPTION_PREFIX . $post_id . '_' . get_current_user_id();
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
