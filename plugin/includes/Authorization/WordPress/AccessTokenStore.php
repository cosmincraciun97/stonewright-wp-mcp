<?php
/**
 * Access credential rows.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Model\IssuanceIntent;

/**
 * One row per access credential, keyed by sha256 of its jti. Rows written by this
 * version also name their family and the refresh credential they were delivered
 * with; rows written by the earlier version reach their family through the refresh
 * row whose access_token_hash pairs with them.
 */
final class AccessTokenStore {

	private const REVOKE_BATCH = 100;

	public function __construct( private Database $db ) {}

	/**
	 * Store the access credential of a committed issuance.
	 *
	 * @throws StorageFailure When the row cannot be written.
	 */
	public function record( IssuanceIntent $intent, string $client_key, string $subject_key, string $family_key, string $refresh_row_key ): void {
		$this->db->insert_or_fail(
			$this->table(),
			[
				'identifier_hash'         => RowKeys::access( $intent->access_key ),
				'client_id'               => $client_key,
				'user_id'                 => (int) $subject_key,
				'expires_at'              => Database::datetime( $intent->access_deadline ),
				'scopes'                  => Database::json( array_values( $intent->access_scopes ) ),
				'revoked'                 => 0,
				'family_key'              => $family_key,
				'refresh_identifier_hash' => $refresh_row_key,
			]
		);
	}

	/**
	 * @return array{client_key: string, subject_key: string, expires_at: ?int, scopes: list<string>, revoked: bool, family_key: ?string}|null
	 */
	public function find( string $access_key ): ?array {
		$row = $this->db->row( 'SELECT * FROM ' . $this->table() . ' WHERE identifier_hash = %s LIMIT 1', [ RowKeys::access( $access_key ) ] );
		if ( null === $row ) {
			return null;
		}
		return [
			'client_key'  => (string) $row['client_id'],
			'subject_key' => (string) $row['user_id'],
			'expires_at'  => Database::epoch( $row['expires_at'] ?? null ),
			'scopes'      => Database::string_list( $row['scopes'] ?? null ) ?? [],
			'revoked'     => '1' === (string) $row['revoked'],
			'family_key'  => is_string( $row['family_key'] ?? null ) && '' !== $row['family_key'] ? $row['family_key'] : null,
		];
	}

	/**
	 * Owner of an access row addressed by its stored hash.
	 *
	 * @return array{client_key: string, subject_key: string}|null
	 */
	public function owner( string $identifier_hash ): ?array {
		$row = $this->db->row( 'SELECT client_id, user_id FROM ' . $this->table() . ' WHERE identifier_hash = %s LIMIT 1', [ $identifier_hash ] );
		return null === $row ? null : [
			'client_key'  => (string) $row['client_id'],
			'subject_key' => (string) $row['user_id'],
		];
	}

	/** Logical family of an access credential whose row predates the family column. */
	public function earlier_family( string $access_key ): ?string {
		$row = $this->db->row( 'SELECT grant_family_hash, credential_key FROM ' . $this->db->table( 'refresh_tokens' ) . ' WHERE access_token_hash = %s LIMIT 1', [ RowKeys::access( $access_key ) ] );
		if ( null === $row ) {
			return null;
		}
		$hash = (string) $row['grant_family_hash'];
		$key = $this->db->value( 'SELECT family_key FROM ' . $this->db->table( 'families' ) . ' WHERE family_hash = %s LIMIT 1', [ $hash ] );
		if ( null !== $key ) {
			return $key;
		}
		return RowKeys::is_earlier( $hash ) ? $hash : null;
	}

	public function revoke( string $access_key ): void {
		$this->db->execute( 'UPDATE ' . $this->table() . ' SET revoked = 1 WHERE identifier_hash = %s AND revoked = 0', [ RowKeys::access( $access_key ) ] );
	}

	/** Revoke every access credential of a family, including those paired by the earlier version. */
	public function revoke_family( string $family_key, string $family_hash ): void {
		$this->db->execute( 'UPDATE ' . $this->table() . ' SET revoked = 1 WHERE family_key = %s AND revoked = 0', [ $family_key ] );
		$paired = $this->db->column( 'SELECT access_token_hash FROM ' . $this->db->table( 'refresh_tokens' ) . ' WHERE grant_family_hash = %s', [ $family_hash ] );
		foreach ( array_chunk( array_values( array_unique( $paired ) ), self::REVOKE_BATCH ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			$this->db->execute( 'UPDATE ' . $this->table() . " SET revoked = 1 WHERE revoked = 0 AND identifier_hash IN ({$placeholders})", $chunk );
		}
	}

	/** Revoke the access credentials delivered with a refresh credential that was just consumed. */
	public function revoke_issued_with( string $refresh_row_key, string $paired_access_hash ): void {
		$this->db->execute( 'UPDATE ' . $this->table() . ' SET revoked = 1 WHERE revoked = 0 AND (identifier_hash = %s OR refresh_identifier_hash = %s)', [ $paired_access_hash, $refresh_row_key ] );
	}

	private function table(): string {
		return $this->db->table( 'access_tokens' );
	}
}
