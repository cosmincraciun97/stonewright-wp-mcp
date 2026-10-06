<?php
/**
 * Registered OAuth clients.
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
 * sha256 of the registering address) plus the whole accepted profile as JSON.
 * Rows registered by the earlier version have no profile and read back with the
 * public-client defaults.
 */
final class ClientStore implements ClientDirectory {

	/** Dynamically registered clients unused this long, without a live family, are removed. */
	public const UNUSED_LIFETIME = 2592000;

	private const ID_ATTEMPTS = 5;
	private const NAME_LENGTH = 191;
	private const PRUNE_BATCH = 200;

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
		];
		return $client + $profile;
	}

	public function create( array $accepted_profile ): array {
		$redirects = $accepted_profile['redirect_uris'] ?? null;
		if ( ! is_array( $redirects ) || [] === $redirects || ! array_is_list( $redirects ) ) {
			throw new \InvalidArgumentException( 'A client needs redirect URIs.' );
		}
		$name = is_string( $accepted_profile['client_name'] ?? null ) ? $accepted_profile['client_name'] : '';
		$row = [
			'client_name'             => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, self::NAME_LENGTH, 'UTF-8' ) : substr( $name, 0, self::NAME_LENGTH ),
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
	 * active family before its deadline. Administrator-created clients stay.
	 */
	public function prune( int $now ): int {
		$cutoff = Database::datetime( $now - self::UNUSED_LIFETIME );
		$unused = '(last_used_at IS NULL AND created_at < %s) OR last_used_at < %s';
		$candidates = $this->db->column( 'SELECT client_id FROM ' . $this->table() . " WHERE admin_created = 0 AND ({$unused}) LIMIT " . self::PRUNE_BATCH, [ $cutoff, $cutoff ] );
		$removed = 0;
		foreach ( $candidates as $client_id ) {
			$live = $this->db->value( 'SELECT COUNT(*) FROM ' . $this->db->table( 'families' ) . " WHERE client_id = %s AND phase = 'active' AND family_expires_at > %s", [ $client_id, Database::datetime( $now ) ] );
			if ( (int) $live > 0 ) {
				continue;
			}
			$removed += $this->db->execute( 'DELETE FROM ' . $this->table() . " WHERE client_id = %s AND admin_created = 0 AND ({$unused})", [ $client_id, $cutoff, $cutoff ] );
		}
		return $removed;
	}

	private function table(): string {
		return $this->db->table( 'clients' );
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
