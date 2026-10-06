<?php
/**
 * One-use pending consent requests.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Ports\Clock;
use Stonewright\WpMcp\Authorization\Ports\ConsentLedger;

/**
 * ConsentLedger over the consents table. open() stores a validated authorization
 * request for the signed-in user for ten minutes and returns a random 32-hex key for
 * the consent screen URL; only sha256 of that key is stored. A decision is taken for
 * the user who opened the request, then the row is deleted (affected rows must be 1)
 * in the same transaction that stores the approved code, so two submissions can never
 * both produce a code and a denial is consumed as well.
 */
final class PendingConsentStore implements ConsentLedger {

	public const LIFETIME = 600;

	private const KEY_ATTEMPTS = 3;

	/** @var \Closure(): string */
	private \Closure $current_subject;

	/** @var \Closure(): string */
	private \Closure $keys;

	/**
	 * @param (\Closure(): string)|null $current_subject User ID of the signed-in user, as a string.
	 * @param (\Closure(): string)|null $keys            New pending keys.
	 */
	public function __construct( private Database $db, private CodeStore $codes, private Clock $clock, ?\Closure $current_subject = null, ?\Closure $keys = null ) {
		$this->current_subject = $current_subject ?? static fn (): string => (string) get_current_user_id();
		$this->keys = $keys ?? static fn (): string => bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Store a validated authorization request and return its pending key. Expected
	 * fields: subject_key, client_key, redirect_uri, code_challenge,
	 * code_challenge_method, scopes, resources, native_client, registered_redirects and
	 * optionally state. A missing or malformed field is an InvalidArgumentException.
	 *
	 * @param array<string, mixed> $request
	 * @throws StorageFailure When the request cannot be stored.
	 */
	public function open( array $request ): string {
		$fields = self::fields( $request );
		$now = $this->clock->now();
		for ( $attempt = 0; $attempt < self::KEY_ATTEMPTS; ++$attempt ) {
			$pending_key = ( $this->keys )();
			$stored = $this->db->insert(
				$this->table(),
				[
					'consent_hash' => RowKeys::consent( $pending_key ),
					'user_id'      => (int) $fields['subject_key'],
					'client_id'    => $fields['client_key'],
					'request_json' => Database::json( $fields ),
					'created_at'   => Database::datetime( $now ),
					'expires_at'   => Database::datetime( $now + self::LIFETIME ),
				]
			);
			if ( $stored ) {
				return $pending_key;
			}
		}
		throw new StorageFailure( 'The consent request could not be stored.' );
	}

	/**
	 * The live request for the consent screen, only for the user who opened it.
	 *
	 * @return array<string, mixed>|null
	 */
	public function peek( string $pending_key ): ?array {
		$pending = $this->pending( $pending_key );
		if ( null === $pending || $pending['subject_key'] !== ( $this->current_subject )() || $this->clock->now() >= $pending['expires_at'] ) {
			return null;
		}
		return $pending;
	}

	public function change( string $pending_key, callable $decide ): array {
		$pending = $this->pending( $pending_key );
		if ( null === $pending || $pending['subject_key'] !== ( $this->current_subject )() ) {
			throw new OAuthFault( 'invalid_request' );
		}
		$outcome = $decide( $pending );
		if ( ! is_array( $outcome ) || ! array_key_exists( 'code', $outcome ) ) {
			throw new \LogicException( 'A consent decision must describe its code.' );
		}
		$this->db->begin();
		try {
			$consumed = $this->db->execute( 'DELETE FROM ' . $this->table() . ' WHERE consent_hash = %s', [ RowKeys::consent( $pending_key ) ] );
			if ( 1 !== $consumed ) {
				throw new OAuthFault( 'invalid_request' );
			}
			if ( is_array( $outcome['code'] ) ) {
				$this->codes->create( $outcome['code'] );
			}
			$this->db->commit();
		} catch ( \Throwable $error ) {
			$this->db->rollback();
			throw $error;
		}
		return $outcome;
	}

	/** @return array<string, mixed>|null */
	private function pending( string $pending_key ): ?array {
		if ( ! preg_match( '/^[0-9a-f]{32}$/D', $pending_key ) ) {
			return null;
		}
		$row = $this->db->row( 'SELECT * FROM ' . $this->table() . ' WHERE consent_hash = %s LIMIT 1', [ RowKeys::consent( $pending_key ) ] );
		$expires = null === $row ? null : Database::epoch( $row['expires_at'] ?? null );
		$request = null === $row ? null : json_decode( (string) $row['request_json'], true );
		if ( null === $expires || ! is_array( $request ) ) {
			return null;
		}
		try {
			$fields = self::fields( $request );
		} catch ( \InvalidArgumentException $unreadable ) {
			return null;
		}
		return [
			'pending_key' => $pending_key,
			'expires_at'  => $expires,
			'used'        => false,
		] + $fields;
	}

	/**
	 * @param array<string, mixed> $request
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When a field is missing or malformed.
	 */
	private static function fields( array $request ): array {
		$fields = [];
		foreach ( [ 'subject_key', 'client_key', 'redirect_uri', 'code_challenge', 'code_challenge_method' ] as $name ) {
			if ( ! isset( $request[ $name ] ) || ! is_string( $request[ $name ] ) || '' === $request[ $name ] ) {
				throw new \InvalidArgumentException( 'Incomplete consent request.' );
			}
			$fields[ $name ] = $request[ $name ];
		}
		if ( null === PermissionSubjectAuthority::user_id( $fields['subject_key'] ) || strlen( $fields['client_key'] ) > 64 ) {
			throw new \InvalidArgumentException( 'Incomplete consent request.' );
		}
		foreach ( [ 'scopes', 'resources', 'registered_redirects' ] as $name ) {
			$list = $request[ $name ] ?? null;
			if ( ! is_array( $list ) || ! array_is_list( $list ) || [] !== array_filter( $list, static fn ( $item ): bool => ! is_string( $item ) ) ) {
				throw new \InvalidArgumentException( 'Incomplete consent request.' );
			}
			$fields[ $name ] = $list;
		}
		if ( ! isset( $request['native_client'] ) || ! is_bool( $request['native_client'] ) ) {
			throw new \InvalidArgumentException( 'Incomplete consent request.' );
		}
		$fields['native_client'] = $request['native_client'];
		if ( isset( $request['state'] ) ) {
			if ( ! is_string( $request['state'] ) ) {
				throw new \InvalidArgumentException( 'Incomplete consent request.' );
			}
			$fields['state'] = $request['state'];
		}
		return $fields;
	}

	private function table(): string {
		return $this->db->table( 'consents' );
	}
}
