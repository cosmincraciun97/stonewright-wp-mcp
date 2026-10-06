<?php
/**
 * Storage keys derived from logical credential keys.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

/**
 * Documented, one-way derivations from logical keys to the char(64) columns.
 *
 * - Access rows: sha256 of the JWT identifier (jti), the same lookup the stored rows
 *   already use; refresh rows keep sha256 of their paired jti in access_token_hash.
 * - Refresh rows, families, codes and pending consents written by this version:
 *   sha256 of a purpose prefix and the logical key.
 * - Clients identified by a metadata document: sha256 of a purpose prefix and the URL.
 * - Refresh rows and families written by the earlier version are addressed by the
 *   64-character lowercase hex value stored in the row (identifier_hash or
 *   grant_family_hash); that value is the logical key and maps to itself. Keys issued
 *   by this version are 80 characters long, so the two forms never overlap. How the
 *   earlier version derived its stored values is never computed here.
 */
final class RowKeys {

	/** Whether a logical key is a stored identifier written by the earlier version. */
	public static function is_earlier( string $key ): bool {
		return 1 === preg_match( '/^[0-9a-f]{64}$/D', $key );
	}

	public static function access( string $access_key ): string {
		return hash( 'sha256', $access_key );
	}

	public static function refresh( string $credential_key ): string {
		return self::is_earlier( $credential_key ) ? $credential_key : hash( 'sha256', 'stonewright-oauth:refresh:' . $credential_key );
	}

	public static function grant_family( string $family_key ): string {
		return self::is_earlier( $family_key ) ? $family_key : hash( 'sha256', 'stonewright-oauth:family:' . $family_key );
	}

	public static function code( string $code_key ): string {
		return hash( 'sha256', 'stonewright-oauth:code:' . $code_key );
	}

	public static function consent( string $pending_key ): string {
		return hash( 'sha256', 'stonewright-oauth:consent:' . $pending_key );
	}

	/**
	 * Client key of a client identified by a metadata document URL: 64 hex characters,
	 * so the URL never has to fit the 64-character client_id column. Registered clients
	 * keep their 32-hex identifiers, so the two forms never overlap.
	 */
	public static function client_document( string $url ): string {
		return hash( 'sha256', 'stonewright-oauth:client-document:' . $url );
	}

	/** Registering address, stored only as its sha256. */
	public static function address( string $address ): string {
		return hash( 'sha256', $address );
	}
}
