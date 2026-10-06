<?php
/**
 * Authorization code rows and atomic code exchange.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Model\CodeExchangeOutcome;
use Stonewright\WpMcp\Authorization\Model\CodeGrantState;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Ports\CodeLedger;

/**
 * CodeLedger over the auth_codes table. A row is addressed by RowKeys::code() of the
 * code key carried inside the encrypted code; codes written by the earlier version are
 * never matched. An exchange claims the row (revoked 0 -> 1, family_key set) with a
 * compare-and-swap UPDATE in the same transaction that writes the new family, its
 * first refresh row and access row; a lost claim decides again, which turns the
 * second use into a replay. A replay revokes the family the code created before the
 * outcome is returned. Used codes stay until expiry plus
 * CodeGrantState::USED_CODE_RETENTION (Housekeeping removes them). The unused codes of a
 * disconnected client are revoked without a family (revoke_unused_for_client()), which
 * state() refuses like any code it cannot read.
 */
final class CodeStore implements CodeLedger {

	public const MAX_ATTEMPTS = 3;

	public function __construct( private Database $db, private FamilyStore $families ) {}

	public function change( string $code_key, callable $decide ): CodeExchangeOutcome {
		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt ) {
			$outcome = $decide( $this->state( $code_key ) );
			if ( ! $outcome instanceof CodeExchangeOutcome ) {
				throw new \LogicException( 'A code decision must return an exchange outcome.' );
			}
			if ( null !== $outcome->family && null !== $outcome->issuance ) {
				if ( $this->commit_exchange( $code_key, $outcome ) ) {
					return $outcome;
				}
				continue;
			}
			if ( null !== $outcome->revoke_family_key ) {
				$this->families->revoke( $outcome->revoke_family_key );
			}
			return $outcome;
		}
		throw new StorageFailure( 'The authorization code kept changing; nothing was committed.' );
	}

	/**
	 * Store an approved code (from ConsentDecision) inside the consent transaction.
	 *
	 * A refused write is a StorageFailure.
	 *
	 * @param array<string, mixed> $code
	 * @throws \InvalidArgumentException When the code is incomplete.
	 */
	public function create( array $code ): void {
		foreach ( [ 'code_key', 'client_key', 'subject_key', 'redirect_uri', 'code_challenge' ] as $name ) {
			if ( ! isset( $code[ $name ] ) || ! is_string( $code[ $name ] ) || '' === $code[ $name ] ) {
				throw new \InvalidArgumentException( 'Incomplete authorization code.' );
			}
		}
		if ( ! isset( $code['expires_at'], $code['scopes'], $code['resources'] ) || ! is_int( $code['expires_at'] ) || ! is_array( $code['scopes'] ) || ! is_array( $code['resources'] ) ) {
			throw new \InvalidArgumentException( 'Incomplete authorization code.' );
		}
		$this->db->insert_or_fail(
			$this->db->table( 'auth_codes' ),
			[
				'identifier_hash' => RowKeys::code( $code['code_key'] ),
				'client_id'       => $code['client_key'],
				'user_id'         => (int) $code['subject_key'],
				'expires_at'      => Database::datetime( $code['expires_at'] ),
				'scopes'          => Database::json( array_values( $code['scopes'] ) ),
				'redirect_uri'    => $code['redirect_uri'],
				'revoked'         => 0,
				'code_challenge'  => $code['code_challenge'],
				'resources'       => Database::json( array_values( $code['resources'] ) ),
				'family_key'      => null,
			]
		);
	}

	/**
	 * Make every unused code of one client unusable, for example when an administrator
	 * disconnects it. A row marked revoked without a family reads as an unreadable code,
	 * which state() refuses (invalid_grant); a used code keeps the family it created, so
	 * a replay still revokes that family. Returns how many codes were revoked.
	 *
	 * @throws StorageFailure When the database refuses the statement.
	 */
	public function revoke_unused_for_client( string $client_key ): int {
		return $this->db->execute( 'UPDATE ' . $this->db->table( 'auth_codes' ) . ' SET revoked = 1 WHERE client_id = %s AND revoked = 0', [ $client_key ] );
	}

	/** @throws OAuthFault For an unknown, unreadable or earlier-version code (invalid_grant). */
	private function state( string $code_key ): CodeGrantState {
		$row = '' === $code_key ? null : $this->db->row( 'SELECT * FROM ' . $this->db->table( 'auth_codes' ) . ' WHERE identifier_hash = %s LIMIT 1', [ RowKeys::code( $code_key ) ] );
		if ( null === $row ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		$used = '1' === (string) $row['revoked'];
		$family = is_string( $row['family_key'] ?? null ) && '' !== $row['family_key'] ? $row['family_key'] : null;
		$scopes = Database::string_list( $row['scopes'] ?? null );
		$resources = Database::string_list( $row['resources'] ?? null );
		$expires = Database::epoch( $row['expires_at'] ?? null );
		$challenge = $row['code_challenge'] ?? null;
		if ( null === $scopes || null === $resources || null === $expires || ! is_string( $challenge ) || ( $used && null === $family ) ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		try {
			return CodeGrantState::from_array(
				[
					'code_key'          => $code_key,
					'client_key'        => (string) $row['client_id'],
					'subject_key'       => (string) $row['user_id'],
					'redirect_uri'      => (string) $row['redirect_uri'],
					'code_challenge'    => $challenge,
					'expires_at'        => $expires,
					'used'              => $used,
					'scopes'            => $scopes,
					'resources'         => $resources,
					'issued_family_key' => $used ? $family : null,
				]
			);
		} catch ( \InvalidArgumentException | OAuthFault $unreadable ) {
			throw new OAuthFault( 'invalid_grant' );
		}
	}

	private function commit_exchange( string $code_key, CodeExchangeOutcome $outcome ): bool {
		$family = $outcome->family;
		$issuance = $outcome->issuance;
		if ( null === $family || null === $issuance ) {
			return false;
		}
		$this->db->begin();
		try {
			$claimed = $this->db->claim(
				'UPDATE ' . $this->db->table( 'auth_codes' ) . ' SET revoked = 1, family_key = %s WHERE identifier_hash = %s AND revoked = 0',
				[ $family->to_array()['family_key'], RowKeys::code( $code_key ) ]
			);
			if ( 1 !== $claimed ) {
				$this->db->rollback();
				return false;
			}
			$this->families->create( $family, $issuance );
			$this->db->commit();
			return true;
		} catch ( \Throwable $error ) {
			$this->db->rollback();
			throw $error;
		}
	}
}
