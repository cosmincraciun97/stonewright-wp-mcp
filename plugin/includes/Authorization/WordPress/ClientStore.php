<?php
/**
 * Registered OAuth clients.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Ports\ClientDirectory;
use Stonewright\WpMcp\Authorization\Ports\Clock;

/**
 * Public clients in the clients table. A registration stores the observed columns
 * (32-hex client_id, client_name, redirect_uris as a JSON array, UTC created_at,
 * the keyed hash RowKeys::address() makes of the registering address) plus the whole
 * accepted profile as JSON.
 * Rows registered by the earlier version have no profile and read back with the
 * public-client defaults.
 *
 * Clients identified by a client ID metadata document are rows too: client_id is the
 * 64-hex RowKeys::client_document() of the document URL (the URL itself may be longer
 * than the column), registration_purpose is DOCUMENT_PURPOSE, registration_expires_at
 * ends the cached copy, and the profile holds the URL as client_id_metadata_document.
 * A diagnostics self-test registration is marked SELF_TEST_PURPOSE until it is removed.
 */
final class ClientStore implements ClientDirectory {

	public const DOCUMENT_PURPOSE = 'metadata_document';
	public const SELF_TEST_PURPOSE = 'self_test';

	/** Dynamically registered clients unused this long, without a live family, are removed. */
	public const UNUSED_LIFETIME = 2592000;

	private const ID_ATTEMPTS = 5;
	private const NAME_LENGTH = 191;
	private const PRUNE_BATCH = 200;
	private const PRUNE_BATCHES = 25;

	/** @var \Closure(): string */
	private \Closure $address;

	/** @var \Closure(): string */
	private \Closure $identifiers;

	/**
	 * @param (\Closure(): string)|null $address     The registering client's address.
	 * @param (\Closure(): string)|null $identifiers New 32-hex client identifiers.
	 */
	public function __construct( private Database $db, private Clock $clock, ?\Closure $address = null, ?\Closure $identifiers = null ) {
		$this->address = $address ?? static fn (): string => self::remote_address();
		$this->identifiers = $identifiers ?? static fn (): string => bin2hex( random_bytes( 16 ) );
	}

	public function find( string $client_key ): ?array {
		if ( '' === $client_key || strlen( $client_key ) > 64 ) {
			return null;
		}
		$row = $this->db->row( 'SELECT * FROM ' . $this->table() . ' WHERE client_id = %s LIMIT 1', [ $client_key ] );
		if ( null === $row ) {
			return null;
		}
		$metadata = is_string( $row['client_metadata'] ?? null ) ? json_decode( $row['client_metadata'], true ) : null;
		$profile = is_array( $metadata ) && ! array_is_list( $metadata ) ? $metadata : [];
		$client = [
			'client_id'                  => (string) $row['client_id'],
			'client_name'                => is_string( $profile['client_name'] ?? null ) ? $profile['client_name'] : (string) $row['client_name'],
			'redirect_uris'              => Database::string_list( $row['redirect_uris'] ?? null ) ?? [],
			'grant_types'                => self::list_or( $profile['grant_types'] ?? null, [ 'authorization_code', 'refresh_token' ] ),
			'response_types'             => self::list_or( $profile['response_types'] ?? null, [ 'code' ] ),
			'token_endpoint_auth_method' => is_string( $profile['token_endpoint_auth_method'] ?? null ) ? $profile['token_endpoint_auth_method'] : ( '1' === (string) $row['is_confidential'] ? 'client_secret_basic' : 'none' ),
			'created_at'                 => Database::epoch( $row['created_at'] ?? null ),
			'last_used_at'               => Database::epoch( $row['last_used_at'] ?? null ),
			'admin_created'              => '1' === (string) $row['admin_created'],
			'registration_purpose'       => is_string( $row['registration_purpose'] ?? null ) ? $row['registration_purpose'] : null,
			'registration_expires_at'    => Database::epoch( $row['registration_expires_at'] ?? null ),
		];
		return $client + $profile;
	}

	/**
	 * Store, or refresh in place, the client a fetched metadata document describes.
	 * created_at and last_used_at of an existing row are kept.
	 *
	 * @param array<string, mixed> $profile Accepted metadata; client_id_metadata_document holds the document URL.
	 * @throws \InvalidArgumentException When the profile has no redirect URIs or the key is not 64 hex characters.
	 * @throws StorageFailure When the row cannot be written.
	 */
	public function save_document( string $client_key, array $profile, int $fresh_until ): void {
		$redirects = $profile['redirect_uris'] ?? null;
		if ( ! preg_match( '/^[0-9a-f]{64}$/D', $client_key ) || ! is_array( $redirects ) || [] === $redirects || ! array_is_list( $redirects ) ) {
			throw new \InvalidArgumentException( 'A metadata document client needs its key and redirect URIs.' );
		}
		$values = [
			'client_name'             => self::column_name( $profile ),
			'redirect_uris'           => Database::json( $redirects ),
			'registration_purpose'    => self::DOCUMENT_PURPOSE,
			'registration_expires_at' => Database::datetime( $fresh_until ),
			'client_metadata'         => Database::json( $profile ),
		];
		$inserted = $this->db->insert(
			$this->table(),
			[
				'client_id'             => $client_key,
				'is_confidential'       => 0,
				'client_secret_hash'    => null,
				'created_at'            => Database::datetime( $this->clock->now() ),
				'last_used_at'          => null,
				'registered_by_ip_hash' => RowKeys::address( ( $this->address )() ),
				'admin_created'         => 0,
			] + $values
		);
		if ( $inserted ) {
			return;
		}
		$this->db->execute(
			'UPDATE ' . $this->table() . ' SET client_name = %s, redirect_uris = %s, registration_expires_at = %s, client_metadata = %s WHERE client_id = %s AND registration_purpose = %s',
			[ $values['client_name'], $values['redirect_uris'], $values['registration_expires_at'], $values['client_metadata'], $client_key, self::DOCUMENT_PURPOSE ]
		);
		if ( null === $this->db->value( 'SELECT client_id FROM ' . $this->table() . ' WHERE client_id = %s AND registration_purpose = %s LIMIT 1', [ $client_key, self::DOCUMENT_PURPOSE ] ) ) {
			throw new StorageFailure( 'The client metadata document could not be stored.' );
		}
	}

	/** Mark a registration as a diagnostics self-test that must be gone by $until. */
	public function mark_self_test( string $client_id, int $until ): void {
		$this->db->execute( 'UPDATE ' . $this->table() . ' SET registration_purpose = %s, registration_expires_at = %s WHERE client_id = %s AND admin_created = 0', [ self::SELF_TEST_PURPOSE, Database::datetime( $until ), $client_id ] );
	}

	/** Remove one dynamically registered client (a finished diagnostics self-test). */
	public function forget( string $client_id ): void {
		$this->db->execute( 'DELETE FROM ' . $this->table() . ' WHERE client_id = %s AND admin_created = 0', [ $client_id ] );
	}

	/** Diagnostics self-test registrations that were not removed. */
	public function self_test_clients(): int {
		return (int) $this->db->value( 'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE registration_purpose = %s', [ self::SELF_TEST_PURPOSE ] );
	}

	public function create( array $accepted_profile ): array {
		$redirects = $accepted_profile['redirect_uris'] ?? null;
		if ( ! is_array( $redirects ) || [] === $redirects || ! array_is_list( $redirects ) ) {
			throw new \InvalidArgumentException( 'A client needs redirect URIs.' );
		}
		$row = [
			'client_name'             => self::column_name( $accepted_profile ),
			'redirect_uris'           => Database::json( $redirects ),
			'is_confidential'         => 0,
			'client_secret_hash'      => null,
			'created_at'              => Database::datetime( $this->clock->now() ),
			'last_used_at'            => null,
			'registered_by_ip_hash'   => RowKeys::address( ( $this->address )() ),
			'admin_created'           => 0,
			'registration_purpose'    => null,
			'registration_expires_at' => null,
			'client_metadata'         => Database::json( $accepted_profile ),
		];
		for ( $attempt = 0; $attempt < self::ID_ATTEMPTS; ++$attempt ) {
			$client_id = ( $this->identifiers )();
			if ( ! preg_match( '/^[0-9a-f]{32}$/D', $client_id ) ) {
				throw new \UnexpectedValueException( 'Client identifiers are 32 lowercase hex characters.' );
			}
			if ( $this->db->insert( $this->table(), [ 'client_id' => $client_id ] + $row ) ) {
				return [ 'client_id' => $client_id ] + $accepted_profile;
			}
			if ( null === $this->db->value( 'SELECT client_id FROM ' . $this->table() . ' WHERE client_id = %s LIMIT 1', [ $client_id ] ) ) {
				throw new StorageFailure( 'The client registration could not be stored.' );
			}
		}
		throw new StorageFailure( 'No unused client identifier was found.' );
	}

	/** Record a grant for the client (authorization code exchange, refresh, re-delivery). */
	public function touch( string $client_key, int $now ): void {
		$this->db->execute( 'UPDATE ' . $this->table() . ' SET last_used_at = %s WHERE client_id = %s', [ Database::datetime( $now ), $client_key ] );
	}

	/**
	 * Remove dynamically registered clients unused for UNUSED_LIFETIME that have no
	 * active family before its deadline, and self-test registrations past their end.
	 * Administrator-created clients stay. Unused clients are worked through in batches
	 * of PRUNE_BATCH until none are left, or PRUNE_BATCHES batches have run, so one run
	 * keeps up with a day of registrations without growing without bound.
	 */
	public function prune( int $now ): int {
		$expired_self_tests = $this->db->execute( 'DELETE FROM ' . $this->table() . ' WHERE registration_purpose = %s AND registration_expires_at < %s AND admin_created = 0', [ self::SELF_TEST_PURPOSE, Database::datetime( $now ) ] );
		return $expired_self_tests + $this->prune_unused( $now );
	}

	private function prune_unused( int $now ): int {
		$cutoff = Database::datetime( $now - self::UNUSED_LIFETIME );
		$unused = '(last_used_at IS NULL AND created_at < %s) OR last_used_at < %s';
		$removed = 0;
		$after = 0;
		for ( $batch = 0; $batch < self::PRUNE_BATCHES; ++$batch ) {
			// Each batch starts after the last row of the one before, so a client that stays does not hold up the ones behind it.
			$candidates = $this->db->rows( 'SELECT id, client_id FROM ' . $this->table() . " WHERE admin_created = 0 AND id > %d AND ({$unused}) ORDER BY id LIMIT " . self::PRUNE_BATCH, [ $after, $cutoff, $cutoff ] );
			foreach ( $candidates as $candidate ) {
				$after = max( $after, (int) $candidate['id'] );
				$client_id = (string) $candidate['client_id'];
				$live = $this->db->value( 'SELECT COUNT(*) FROM ' . $this->db->table( 'families' ) . " WHERE client_id = %s AND phase = 'active' AND family_expires_at > %s", [ $client_id, Database::datetime( $now ) ] );
				if ( (int) $live > 0 ) {
					continue;
				}
				$removed += $this->db->execute( 'DELETE FROM ' . $this->table() . " WHERE client_id = %s AND admin_created = 0 AND ({$unused})", [ $client_id, $cutoff, $cutoff ] );
			}
			if ( count( $candidates ) < self::PRUNE_BATCH ) {
				break;
			}
		}
		return $removed;
	}

	private function table(): string {
		return $this->db->table( 'clients' );
	}

	/** The client name as the varchar(191) column holds it; the profile keeps the full name. */
	private static function column_name( array $profile ): string {
		$name = is_string( $profile['client_name'] ?? null ) ? $profile['client_name'] : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, self::NAME_LENGTH, 'UTF-8' ) : substr( $name, 0, self::NAME_LENGTH );
	}

	/**
	 * @param list<string> $fallback
	 * @return list<string>
	 */
	private static function list_or( mixed $value, array $fallback ): array {
		if ( ! is_array( $value ) || [] === $value || ! array_is_list( $value ) ) {
			return $fallback;
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				return $fallback;
			}
		}
		return $value;
	}

	private static function remote_address(): string {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		return false === filter_var( $address, FILTER_VALIDATE_IP ) ? '' : $address;
	}
}
