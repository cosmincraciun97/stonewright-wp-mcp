<?php
/**
 * Server-bound receipt that ties an import to the review this site produced.
 *
 * @package Stonewright
 * @license AGPL-3.0-or-later
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary\Site;

/**
 * A receipt is an expiry time and an HMAC over the review hash, the reviewing
 * user, and that expiry, keyed with the site's authentication salt. The browser
 * carries it back unchanged; echoed hashes alone never authorize an import.
 */
final class ImportReceipt {

	/** Seconds a review stays valid for import. */
	public const LIFETIME = 1800;

	public static function issue( string $review_hash, int $user_id, ?int $issued_at = null ): string {
		$expires = ( $issued_at ?? time() ) + self::LIFETIME;
		return $expires . '.' . self::signature( $review_hash, $user_id, $expires );
	}

	public static function verify( string $receipt, string $review_hash, int $user_id ): bool|\WP_Error {
		if ( 1 !== preg_match( '/^(\d{1,12})\.([a-f0-9]{64})\z/', $receipt, $parts ) || 1 !== preg_match( '/^[a-f0-9]{64}\z/', $review_hash ) ) {
			return self::refused();
		}
		$expires = (int) $parts[1];
		if ( ! hash_equals( self::signature( $review_hash, $user_id, $expires ), $parts[2] ) ) {
			return self::refused();
		}
		if ( $expires < time() ) {
			return new \WP_Error( 'stonewright_skill_import_review_expired', 'The review expired. Inspect the file again before importing it.', [ 'status' => 400 ] );
		}
		return true;
	}

	private static function signature( string $review_hash, int $user_id, int $expires ): string {
		$key = hash_hmac( 'sha256', 'stonewright-skill-import-receipt', wp_salt( 'auth' ) );
		return hash_hmac( 'sha256', $review_hash . '|' . $user_id . '|' . $expires, $key );
	}

	private static function refused(): \WP_Error {
		return new \WP_Error( 'stonewright_skill_import_review_required', 'Inspect the file before importing it.', [ 'status' => 400 ] );
	}
}
