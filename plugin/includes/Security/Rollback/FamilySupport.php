<?php
/**
 * What the built-in rollback handlers share.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Rollback;

use Stonewright\WpMcp\Security\ChangeLedger;

/**
 * Reading the image a row stores, and turning the result of an adapter into the answer the engine reads.
 */
final class FamilySupport {

	/**
	 * The image stored with a row: the after image when there is one, else the before image. It tells a handler
	 * which parts of the resource the change covered (which meta keys, which options, which widgets).
	 *
	 * @param array<string, mixed> $row
	 * @return array<mixed>|null
	 */
	public static function scope_image( array $row ): ?array {
		foreach ( [ 'after', 'before' ] as $which ) {
			if ( '' === (string) ( $row[ $which . '_ref' ] ?? '' ) ) {
				continue;
			}
			$image = ChangeLedger::read_image( (string) $row['change_id'], $which );
			if ( is_array( $image ) ) {
				return $image;
			}
		}
		return null;
	}

	public static function unreadable(): \WP_Error {
		return new \WP_Error( 'stonewright_change_live_unreadable', __( 'The current state of this item cannot be read.', 'stonewright' ) );
	}

	/** A short code for an error, without the plugin prefix. */
	public static function short_code( \WP_Error $error ): string {
		return substr( (string) preg_replace( '/^stonewright_/', '', sanitize_key( (string) $error->get_error_code() ) ), 0, 64 );
	}

	/**
	 * The answer of an adapter that returns { ok, skipped, differences } or a WP_Error.
	 *
	 * @param array<string, mixed>|\WP_Error $result
	 * @param array<string, mixed>           $extra  Keys to add to a succeeded answer.
	 * @return array{status:string,detail:string,limits:list<string>}
	 */
	public static function answer( array|\WP_Error $result, array $extra = [] ): array {
		if ( $result instanceof \WP_Error ) {
			return [ 'status' => 'failed', 'detail' => self::short_code( $result ), 'limits' => [] ] + $extra;
		}
		$limits = [];
		foreach ( (array) ( $result['skipped'] ?? [] ) as $skipped ) {
			$limits[] = sprintf( /* translators: %s: the part that was not restored */ __( 'Not restored: %s', 'stonewright' ), (string) $skipped );
		}
		if ( empty( $result['ok'] ) ) {
			$detail = 'differences:' . implode( ',', array_slice( array_map( 'strval', (array) ( $result['differences'] ?? [] ) ), 0, 6 ) );
			return [ 'status' => 'failed', 'detail' => substr( $detail, 0, 120 ), 'limits' => $limits ] + $extra;
		}
		return [ 'status' => 'succeeded', 'detail' => '', 'limits' => $limits ] + $extra;
	}
}
