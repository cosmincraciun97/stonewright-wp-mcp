<?php
/**
 * Display names of OAuth clients for admin screens.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

/**
 * Read-only lookup of client names for identifiers recorded elsewhere (audit rows).
 * An identifier is a registration id, a stored client key, or the metadata document
 * URL a client presented; the result is keyed by the identifier as given. Uses the
 * site's database connection directly with one prepared query.
 */
final class ClientNames {

	private const BATCH = 100;

	/**
	 * @param list<string> $client_ids
	 * @return array<string, string> Identifier => client name, for named clients only.
	 */
	public static function lookup( array $client_ids ): array {
		$keys = [];
		foreach ( array_unique( $client_ids ) as $client_id ) {
			$key = ClientDocuments::client_key( (string) $client_id );
			if ( '' !== $key && strlen( $key ) <= 64 ) {
				$keys[ (string) $client_id ] = $key;
			}
		}
		if ( [] === $keys ) {
			return [];
		}
		global $wpdb;
		$names = [];
		foreach ( array_chunk( array_values( array_unique( $keys ) ), self::BATCH ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			$table = $wpdb->prefix . 'stonewright_oauth_clients';
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT client_id, client_name FROM {$table} WHERE client_id IN ({$placeholders})", ...$chunk ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal table name and a generated list of %s placeholders.
			foreach ( is_array( $rows ) ? $rows : [] as $row ) {
				$row = (array) $row;
				$name = trim( (string) ( $row['client_name'] ?? '' ) );
				if ( '' !== $name ) {
					$names[ (string) ( $row['client_id'] ?? '' ) ] = $name;
				}
			}
		}
		$result = [];
		foreach ( $keys as $client_id => $key ) {
			if ( isset( $names[ $key ] ) ) {
				$result[ (string) $client_id ] = $names[ $key ];
			}
		}
		return $result;
	}
}
