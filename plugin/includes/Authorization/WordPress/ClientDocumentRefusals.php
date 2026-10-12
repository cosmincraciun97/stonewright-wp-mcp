<?php
/**
 * The latest reason a client metadata document was refused.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

/**
 * A refused client metadata document shows the person at the browser a generic page, so the
 * specific reason is kept here for the Troubleshoot page: per document host, the reason code
 * (dns_empty, non_public_address, fetch_failed, http_status, too_large, invalid_document,
 * client_id_mismatch, has_secret or metadata_refused), a short detail (a WP_Error code, an HTTP
 * status or the name of a metadata field) and the time. Only reason codes are stored, never
 * document content. The newest LIMIT hosts are kept, and a document that later resolves clears
 * its host.
 */
final class ClientDocumentRefusals {

	public const OPTION = 'stonewright_oauth_document_refusals';
	public const LIMIT = 5;

	/** Remember why a document host was refused, replacing its earlier entry. */
	public static function record( string $host, string $reason, string $detail, int $at ): void {
		$all = self::all();
		unset( $all[ $host ] );
		$all[ $host ] = [ 'reason' => $reason, 'detail' => $detail, 'at' => $at ];
		update_option( self::OPTION, array_slice( $all, -self::LIMIT, null, true ), false );
	}

	/** Forget the refusal of a host whose document resolved. */
	public static function forget( string $host ): void {
		$all = self::all();
		if ( ! isset( $all[ $host ] ) ) {
			return;
		}
		unset( $all[ $host ] );
		if ( [] === $all ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, $all, false );
	}

	/** @return array<string, array{reason: string, detail: string, at: int}> Oldest first. */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		$all = [];
		foreach ( is_array( $stored ) ? $stored : [] as $host => $entry ) {
			if ( is_string( $host ) && is_array( $entry ) && is_string( $entry['reason'] ?? null ) && is_string( $entry['detail'] ?? null ) && is_int( $entry['at'] ?? null ) ) {
				$all[ $host ] = [ 'reason' => $entry['reason'], 'detail' => $entry['detail'], 'at' => $entry['at'] ];
			}
		}
		return $all;
	}
}
