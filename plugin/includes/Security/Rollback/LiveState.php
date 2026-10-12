<?php
/**
 * Reading what a resource is now, and comparing it with a state a person was shown.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Rollback;

use Stonewright\WpMcp\Security\ChangeImage;

/**
 * The one place that turns "the live image of a ledger row" into the hash that drift is judged by. The Changes engine
 * (ChangeRollback) and the Rescue path (RescueRollback) both ask it, so a hash that one screen shows is a hash the other
 * accepts.
 */
final class LiveState {

	/** Shortest start of a hash that a caller may confirm. The pages print this much, never a whole hash. */
	public const MIN_HASH_PREFIX = 32;

	/**
	 * What the resource of a row is now.
	 *
	 * @param array<string, mixed> $row
	 * @return array{supported:bool,unreadable:bool,live:string|array<mixed>|null,current_sha256:string,detail:string} supported is false when the family cannot say (the comparison is impossible); unreadable is true when it could and did not.
	 */
	public static function read( array $row, RollbackFamilyHandler $handler ): array {
		try {
			$live = $handler->live_image( $row );
		} catch ( \Throwable $failure ) {
			unset( $failure );
			return [ 'supported' => true, 'unreadable' => true, 'live' => null, 'current_sha256' => '', 'detail' => 'exception' ];
		}
		if ( $live instanceof \WP_Error ) {
			if ( RollbackFamilyHandler::LIVE_UNSUPPORTED === $live->get_error_code() ) {
				return [ 'supported' => false, 'unreadable' => false, 'live' => null, 'current_sha256' => '', 'detail' => 'unsupported' ];
			}
			return [ 'supported' => true, 'unreadable' => true, 'live' => null, 'current_sha256' => '', 'detail' => FamilySupport::short_code( $live ) ];
		}
		return [
			'supported'      => true,
			'unreadable'     => false,
			'live'           => $live,
			'current_sha256' => ChangeImage::hash_of( $live, (string) $row['resource_type'], (string) $row['resource_id'] ),
			'detail'         => '',
		];
	}

	/** A hash as a caller sent it: lower case, without space. */
	public static function clean_hash( mixed $value ): string {
		return is_string( $value ) ? strtolower( trim( $value ) ) : '';
	}

	/** The whole hash, or its first MIN_HASH_PREFIX characters or more, equals the live hash. */
	public static function hash_matches( string $expected, string $live ): bool {
		$length = strlen( $expected );
		if ( $length < self::MIN_HASH_PREFIX || $length > 64 || ! ctype_xdigit( $expected ) || '' === $live ) {
			return false;
		}
		return hash_equals( substr( $live, 0, $length ), $expected );
	}
}
