<?php
/**
 * Refresh families: atomic state transitions over the refresh table.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Model\FamilyState;
use Stonewright\WpMcp\Authorization\Model\IssuanceIntent;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Model\RefreshOutcome;
use Stonewright\WpMcp\Authorization\Ports\Clock;
use Stonewright\WpMcp\Authorization\Ports\FamilyLedger;
use Stonewright\WpMcp\Support\Logger;

/**
 * FamilyLedger over the families and refresh_tokens tables.
 *
 * Mapping: each refresh row is one history entry, keyed by its credential_key (rows
 * written by this version) or by its stored identifier_hash (rows written by the
 * earlier version). The successor of a row is the row whose parent_identifier_hash
 * points at it; a row is consumed when consumed_at is set, revoked_reason is
 * 'rotated', or it has a successor; a revoked current row makes the family revoked;
 * the deadline is the stored family_expires_at and is never extended. Families that
 * only have earlier rows get their families row lazily, from the owner recorded in
 * the paired access row or from the authenticated refresh payload (adopt()).
 *
 * Atomicity: state is read without locks and checked against the family revision
 * (read again after the rows, so a torn read is retried). A transition then claims
 * the next revision with a compare-and-swap UPDATE inside a transaction that also
 * writes every row effect; a lost claim rolls back and the decision runs again on
 * fresh state. An unchanged outcome is confirmed against the current revision; a
 * duplicate re-delivery claims the family row at that revision (a delivery counter,
 * not state) in the transaction that records its additional access credential.
 */
final class FamilyStore implements FamilyLedger {

	public const MAX_ATTEMPTS = 5;

	/** Default bound of live_grants(). */
	public const LIVE_GRANT_LIMIT = 500;

	private const ROTATED = 'rotated';
	private const REPLAYED = 'replayed';

	private int $conflicts = 0;

	/** @var \Closure(int): void */
	private \Closure $pause;

	/**
	 * @param list<string>           $earlier_resources Resources consented for families adopted from the earlier version.
	 * @param (\Closure(int): void)|null $pause           Backoff between conflicting attempts.
	 */
	public function __construct( private Database $db, private AccessTokenStore $access, private ClientStore $clients, private Clock $clock, private array $earlier_resources, ?\Closure $pause = null ) {
		$this->pause = $pause ?? static function ( int $attempt ): void {
			usleep( random_int( 2000, 12000 ) * $attempt );
		};
	}

	public function change( string $family_key, callable $decide ): RefreshOutcome {
		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt ) {
			$snapshot = $this->snapshot( $family_key );
			if ( null !== $snapshot ) {
				$outcome = $decide( $snapshot['state'] );
				if ( ! $outcome instanceof RefreshOutcome ) {
					throw new \LogicException( 'A family decision must return a refresh outcome.' );
				}
				if ( $this->commit( $snapshot, $outcome ) ) {
					return $outcome;
				}
			}
			++$this->conflicts;
			( $this->pause )( $attempt );
		}
		throw new StorageFailure( 'The grant family kept changing; nothing was committed.' );
	}

	/** Lost compare-and-swap claims and torn reads seen by this instance. */
	public function conflicts(): int {
		return $this->conflicts;
	}

	/** Current state, or null for an unknown or unusable family. */
	public function load( string $family_key ): ?FamilyState {
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt ) {
			try {
				$snapshot = $this->snapshot( $family_key );
			} catch ( OAuthFault $unknown ) {
				return null;
			}
			if ( null !== $snapshot ) {
				return $snapshot['state'];
			}
		}
		return null;
	}

	/**
	 * Client and subject the family was granted to.
	 *
	 * @return array{client_key: string, subject_key: string}|null
	 */
	public function binding( string $family_key ): ?array {
		$row = $this->stored_family( RowKeys::grant_family( $family_key ) );
		if ( null === $row || (string) $row['family_key'] !== $family_key ) {
			return null;
		}
		return [
			'client_key'  => (string) $row['client_id'],
			'subject_key' => (string) $row['user_id'],
		];
	}

	/**
	 * Persist a family created by an authorization code exchange. Runs inside the
	 * caller's transaction, together with the code consumption.
	 *
	 * A refused write is a StorageFailure.
	 *
	 * @throws \LogicException When the family is not a new one-credential family.
	 */
	public function create( FamilyState $family, IssuanceIntent $issuance ): void {
		$state = $family->to_array();
		if ( RowKeys::is_earlier( $state['family_key'] ) || 1 !== count( $state['credential_history'] ) ) {
			throw new \LogicException( 'A new family has a new key and one credential.' );
		}
		$now = $this->clock->now();
		$hash = RowKeys::grant_family( $state['family_key'] );
		$this->db->insert_or_fail(
			$this->db->table( 'families' ),
			[
				'family_hash'       => $hash,
				'family_key'        => $state['family_key'],
				'client_id'         => $state['client_key'],
				'user_id'           => (int) $state['subject_key'],
				'scopes'            => Database::json( $state['consented_scopes'] ),
				'resources'         => Database::json( $state['consented_resources'] ),
				'phase'             => $state['phase'],
				'revision'          => $state['revision'],
				'compacted_entries' => $state['compacted_entries'],
				'family_expires_at' => Database::datetime( $state['family_deadline'] ),
				'created_at'        => Database::datetime( $now ),
				'updated_at'        => Database::datetime( $now ),
			]
		);
		foreach ( $state['credential_history'] as $key => $entry ) {
			$this->insert_credential( $state, $hash, (string) $key, $entry, null, $issuance );
		}
	}

	/**
	 * Give a family written by the earlier version its families row, using owner facts
	 * from an authenticated refresh payload. An existing row must name the same owner.
	 *
	 * @param list<string> $scopes
	 * @throws OAuthFault When the family has no rows or another owner (invalid_grant).
	 */
	public function adopt( string $family_hash, string $client_key, string $subject_key, array $scopes ): void {
		$existing = $this->stored_family( $family_hash );
		if ( null === $existing ) {
			$rows = $this->credential_rows( $family_hash );
			if ( [] === $rows ) {
				throw new OAuthFault( 'invalid_grant' );
			}
			$this->insert_adopted( $family_hash, $rows, $client_key, $subject_key, $scopes );
			$existing = $this->stored_family( $family_hash );
		}
		if ( null === $existing || (string) $existing['client_id'] !== $client_key || (string) $existing['user_id'] !== $subject_key ) {
			throw new OAuthFault( 'invalid_grant' );
		}
	}

	/**
	 * The refresh row the earlier version paired with an access credential identifier.
	 *
	 * @return array<string, mixed>|null
	 */
	public function earlier_credential( string $access_key ): ?array {
		$rows = $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'refresh_tokens' ) . ' WHERE access_token_hash = %s LIMIT 2', [ RowKeys::access( $access_key ) ] );
		if ( 1 !== count( $rows ) || null !== $rows[0]['credential_key'] ) {
			return null;
		}
		return $rows[0];
	}

	/**
	 * Close a family (client revocation or administrative disconnect). A family that
	 * cannot be read as state has its live rows closed directly. Unknown families are
	 * ignored, so revocation stays idempotent.
	 */
	public function revoke( string $family_key ): void {
		try {
			$this->change( $family_key, static fn ( FamilyState $current ): RefreshOutcome => new RefreshOutcome( $current->revoke(), null, null ) );
		} catch ( OAuthFault $unreadable ) {
			$hash = RowKeys::grant_family( $family_key );
			$this->db->transaction(
				function () use ( $family_key, $hash ): void {
					$this->close_rows( $hash, null );
					$this->access->revoke_family( $family_key, $hash );
				}
			);
		}
	}

	/**
	 * Grants a client can still use, for the administrator's list of connected clients.
	 *
	 * Families in the active phase whose deadline is still ahead come first, newest
	 * first. Families the earlier version wrote that have no families row yet follow
	 * when they still hold a live refresh row; their owner is the one recorded on that
	 * row or, when it names none, on the access row it was paired with. Reading changes
	 * nothing: no family is adopted. Every family_key is accepted by revoke(). With a
	 * client key, only that client's grants are read, up to $limit of them.
	 *
	 * @return list<array{family_key: string, client_key: string, subject_key: string, granted_at: ?int, expires_at: ?int}>
	 */
	public function live_grants( int $now, int $limit = self::LIVE_GRANT_LIMIT, ?string $client_key = null ): array {
		$limit = max( 1, $limit );
		$moment = Database::datetime( $now );
		$grants = [];
		$listed = [];
		$rows = $this->db->rows(
			'SELECT family_hash, family_key, client_id, user_id, created_at, family_expires_at FROM ' . $this->db->table( 'families' ) . " WHERE phase = 'active' AND family_expires_at > %s" . ( null === $client_key ? '' : ' AND client_id = %s' ) . ' ORDER BY created_at DESC LIMIT ' . $limit,
			null === $client_key ? [ $moment ] : [ $moment, $client_key ]
		);
		foreach ( $rows as $row ) {
			$listed[ (string) $row['family_hash'] ] = true;
			$grants[] = [
				'family_key'  => (string) $row['family_key'],
				'client_key'  => (string) $row['client_id'],
				'subject_key' => (string) $row['user_id'],
				'granted_at'  => Database::epoch( $row['created_at'] ?? null ),
				'expires_at'  => Database::epoch( $row['family_expires_at'] ?? null ),
			];
		}
		if ( count( $grants ) >= $limit ) {
			return $grants;
		}
		return array_merge( $grants, $this->earlier_live_grants( $moment, $limit - count( $grants ), $listed, $client_key ) );
	}

	/**
	 * Live families written by the earlier version that have no families row.
	 *
	 * @param array<string, true> $listed Family hashes already listed.
	 * @return list<array{family_key: string, client_key: string, subject_key: string, granted_at: ?int, expires_at: ?int}>
	 */
	private function earlier_live_grants( string $moment, int $limit, array $listed, ?string $client_key ): array {
		$heads = [];
		$rows = $this->db->rows(
			'SELECT grant_family_hash, access_token_hash, client_id, user_id, family_expires_at, expires_at FROM ' . $this->db->table( 'refresh_tokens' ) . ' WHERE revoked = 0 AND credential_key IS NULL AND expires_at > %s' . ( null === $client_key ? '' : ' AND (client_id = %s OR client_id IS NULL)' ) . ' LIMIT ' . $limit,
			null === $client_key ? [ $moment ] : [ $moment, $client_key ]
		);
		foreach ( $rows as $row ) {
			$hash = (string) $row['grant_family_hash'];
			if ( RowKeys::is_earlier( $hash ) && ! isset( $listed[ $hash ] ) && ! isset( $heads[ $hash ] ) ) {
				$heads[ $hash ] = $row;
			}
		}
		if ( [] === $heads ) {
			return [];
		}
		// A families row decides the family once it exists, whatever its phase.
		foreach ( $this->db->column( 'SELECT family_hash FROM ' . $this->db->table( 'families' ) . ' WHERE family_hash IN (' . self::placeholders( count( $heads ) ) . ')', array_keys( $heads ) ) as $adopted ) {
			unset( $heads[ $adopted ] );
		}
		$paired = [];
		foreach ( $heads as $row ) {
			if ( null === $row['client_id'] || null === $row['user_id'] ) {
				$paired[] = (string) $row['access_token_hash'];
			}
		}
		$owners = [];
		if ( [] !== $paired ) {
			$paired = array_values( array_unique( $paired ) );
			foreach ( $this->db->rows( 'SELECT identifier_hash, client_id, user_id FROM ' . $this->db->table( 'access_tokens' ) . ' WHERE identifier_hash IN (' . self::placeholders( count( $paired ) ) . ')', $paired ) as $access ) {
				$owners[ (string) $access['identifier_hash'] ] = $access;
			}
		}
		$grants = [];
		foreach ( $heads as $hash => $row ) {
			$owner = null !== $row['client_id'] && null !== $row['user_id'] ? $row : ( $owners[ (string) $row['access_token_hash'] ] ?? null );
			if ( null === $owner || '' === (string) $owner['client_id'] || ( null !== $client_key && (string) $owner['client_id'] !== $client_key ) ) {
				continue;
			}
			$grants[] = [
				'family_key'  => (string) $hash,
				'client_key'  => (string) $owner['client_id'],
				'subject_key' => (string) $owner['user_id'],
				'granted_at'  => null,
				'expires_at'  => Database::epoch( $row['family_expires_at'] ?? null ) ?? Database::epoch( $row['expires_at'] ?? null ),
			];
		}
		return $grants;
	}

	private static function placeholders( int $count ): string {
		return implode( ', ', array_fill( 0, $count, '%s' ) );
	}

	/**
	 * Drop consumed ancestors that can no longer be duplicates, keeping the current
	 * credential and its predecessor. Families with rows from the earlier version are
	 * left alone: those rows are found by their paired access hash and must stay until
	 * the family ends. True when something was dropped.
	 */
	public function compact( string $family_hash, int $window ): bool {
		$family = $this->stored_family( $family_hash );
		if ( null === $family ) {
			return false;
		}
		foreach ( $this->credential_rows( $family_hash ) as $row ) {
			if ( null === $row['credential_key'] ) {
				return false;
			}
		}
		$dropped = false;
		$this->change(
			(string) $family['family_key'],
			function ( FamilyState $current ) use ( $window, &$dropped ): RefreshOutcome {
				$next = $current->compact( $this->clock->now(), $window );
				$dropped = $next !== $current;
				return new RefreshOutcome( $next, null, null );
			}
		);
		return $dropped;
	}

	/**
	 * @return array{hash: string, family: array<string, mixed>, rows: array<string, array<string, mixed>>, state: FamilyState}|null
	 *         Null when the family moved while it was being read.
	 * @throws OAuthFault For an unknown or unusable family (invalid_grant).
	 */
	private function snapshot( string $family_key ): ?array {
		$hash = RowKeys::grant_family( $family_key );
		$family = $this->stored_family( $hash ) ?? $this->adopt_from_rows( $family_key, $hash );
		if ( null === $family || (string) $family['family_key'] !== $family_key ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		$rows = $this->credential_rows( $hash );
		if ( $this->revision( $hash ) !== (int) $family['revision'] ) {
			return null;
		}
		if ( [] === $rows ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		return [
			'hash'   => $hash,
			'family' => $family,
			'rows'   => $rows,
			'state'  => $this->state( $family, $rows ),
		];
	}

	/**
	 * @param array<string, mixed>               $family
	 * @param array<string, array<string, mixed>> $rows Logical key => refresh row.
	 * @throws OAuthFault When the rows cannot form one lineage (invalid_grant).
	 */
	private function state( array $family, array $rows ): FamilyState {
		$deadline = Database::epoch( $family['family_expires_at'] ?? null );
		$scopes = Database::string_list( $family['scopes'] ?? null );
		$resources = Database::string_list( $family['resources'] ?? null );
		if ( null === $deadline || null === $scopes || null === $resources ) {
			self::unreadable();
		}
		$keys_by_row = [];
		foreach ( $rows as $key => $row ) {
			$keys_by_row[ (string) $row['identifier_hash'] ] = (string) $key;
		}
		$children = [];
		foreach ( $rows as $key => $row ) {
			$parent = $row['parent_identifier_hash'] ?? null;
			if ( is_string( $parent ) && isset( $keys_by_row[ $parent ] ) ) {
				$children[ $keys_by_row[ $parent ] ][] = (string) $key;
			}
		}
		$history = [];
		$heads = [];
		foreach ( $rows as $key => $row ) {
			$key = (string) $key;
			$successors = $children[ $key ] ?? [];
			if ( count( $successors ) > 1 ) {
				self::unreadable();
			}
			$successor = $successors[0] ?? null;
			$consumed = null !== $successor || null !== ( $row['consumed_at'] ?? null ) || self::ROTATED === ( $row['revoked_reason'] ?? null );
			$consumed_at = $consumed ? Database::epoch( $row['consumed_at'] ?? null ) : null;
			if ( null !== $consumed_at && $consumed_at > $deadline ) {
				$consumed_at = null;
			}
			$history[ $key ] = [
				'expires_at'    => max( 1, min( Database::epoch( $row['expires_at'] ?? null ) ?? $deadline, $deadline ) ),
				'consumed'      => $consumed,
				'consumed_at'   => $consumed_at,
				'successor_key' => $successor,
			];
			if ( null === $successor ) {
				$heads[] = $key;
			}
		}
		if ( 1 !== count( $heads ) ) {
			self::unreadable();
		}
		$phase = (string) $family['phase'];
		if ( 'active' === $phase && '1' === (string) $rows[ $heads[0] ]['revoked'] ) {
			$phase = 'revoked';
		}
		try {
			return FamilyState::from_array(
				[
					'family_key'          => (string) $family['family_key'],
					'client_key'          => (string) $family['client_id'],
					'subject_key'         => (string) $family['user_id'],
					'consented_scopes'    => $scopes,
					'consented_resources' => $resources,
					'family_deadline'     => $deadline,
					'phase'               => $phase,
					'current_refresh_key' => $heads[0],
					'revision'            => (int) $family['revision'],
					'compacted_entries'   => (int) $family['compacted_entries'],
					'credential_history'  => $history,
				]
			);
		} catch ( \InvalidArgumentException | OAuthFault $invalid ) {
			self::unreadable();
		}
	}

	/**
	 * @param array{hash: string, family: array<string, mixed>, rows: array<string, array<string, mixed>>, state: FamilyState} $snapshot
	 * @throws \LogicException When the outcome breaks the revision rules.
	 * @throws \Throwable Whatever a write raised, after the rollback.
	 */
	private function commit( array $snapshot, RefreshOutcome $outcome ): bool {
		$seen = $snapshot['state']->to_array();
		$next = $outcome->state->to_array();
		if ( $next['family_key'] !== $seen['family_key'] ) {
			throw new \LogicException( 'An outcome must keep its family.' );
		}
		if ( $next['revision'] === $seen['revision'] ) {
			if ( $next !== $seen ) {
				throw new \LogicException( 'A changed family must advance its revision.' );
			}
			if ( null === $outcome->issuance ) {
				return $this->revision( $snapshot['hash'] ) === $seen['revision'];
			}
			return $this->deliver_again( $snapshot, $outcome->issuance );
		}
		if ( $next['revision'] !== $seen['revision'] + 1 ) {
			throw new \LogicException( 'A family advances one revision at a time.' );
		}
		$this->db->begin();
		try {
			$claimed = $this->db->claim(
				'UPDATE ' . $this->db->table( 'families' ) . ' SET revision = %d, phase = %s, compacted_entries = %d, updated_at = %s WHERE family_hash = %s AND revision = %d',
				[ $next['revision'], $next['phase'], $next['compacted_entries'], Database::datetime( $this->clock->now() ), $snapshot['hash'], $seen['revision'] ]
			);
			if ( 1 !== $claimed ) {
				$this->db->rollback();
				return false;
			}
			$this->apply( $snapshot, $outcome );
			$this->db->commit();
			return true;
		} catch ( \Throwable $error ) {
			$this->db->rollback();
			throw $error;
		}
	}

	/**
	 * @param array{hash: string, family: array<string, mixed>, rows: array<string, array<string, mixed>>, state: FamilyState} $snapshot
	 * @throws \LogicException When a new credential has no matching issuance.
	 */
	private function apply( array $snapshot, RefreshOutcome $outcome ): void {
		$seen = $snapshot['state']->to_array();
		$next = $outcome->state->to_array();
		$before = $seen['credential_history'];
		$after = $next['credential_history'];
		$refresh = $this->db->table( 'refresh_tokens' );
		foreach ( array_keys( array_diff_key( $before, $after ) ) as $dropped ) {
			$this->db->execute( "DELETE FROM {$refresh} WHERE identifier_hash = %s", [ (string) $snapshot['rows'][ (string) $dropped ]['identifier_hash'] ] );
		}
		foreach ( $after as $key => $entry ) {
			$key = (string) $key;
			if ( isset( $before[ $key ] ) && ! $before[ $key ]['consumed'] && $entry['consumed'] ) {
				$row = $snapshot['rows'][ $key ];
				$this->db->execute(
					"UPDATE {$refresh} SET consumed_at = %s, revoked = 1, revoked_reason = %s WHERE identifier_hash = %s",
					[ Database::datetime( $entry['consumed_at'] ?? $this->clock->now() ), self::ROTATED, (string) $row['identifier_hash'] ]
				);
				$this->access->revoke_issued_with( (string) $row['identifier_hash'], (string) $row['access_token_hash'] );
			}
		}
		foreach ( array_diff_key( $after, $before ) as $key => $entry ) {
			$key = (string) $key;
			if ( null === $outcome->issuance || $outcome->issuance->redelivery || $outcome->issuance->refresh_key !== $key ) {
				throw new \LogicException( 'A new credential needs its committed issuance.' );
			}
			$parent = null;
			foreach ( $after as $candidate => $item ) {
				if ( $item['successor_key'] === $key ) {
					$parent = (string) $snapshot['rows'][ (string) $candidate ]['identifier_hash'];
				}
			}
			$this->insert_credential( $next, $snapshot['hash'], $key, $entry, $parent, $outcome->issuance );
		}
		if ( 'active' === $seen['phase'] && 'revoked' === $next['phase'] ) {
			$this->close_rows( $snapshot['hash'], null === $outcome->fault ? null : self::REPLAYED );
			$this->access->revoke_family( $seen['family_key'], $snapshot['hash'] );
		}
	}

	/**
	 * Record the additional access credential of a duplicate delivery. The family row is
	 * claimed at the observed revision while it is still active (its delivery counter
	 * moves, its state does not), in the same transaction as the access row, so a
	 * concurrent rotation or revocation is ordered before or after this delivery and a
	 * revocation cascade always reaches the new access credential. A current credential
	 * written by the earlier version has no re-deliverable encoding, so the delivery
	 * fails closed before anything is written.
	 *
	 * @param array{hash: string, family: array<string, mixed>, rows: array<string, array<string, mixed>>, state: FamilyState} $snapshot
	 * @throws \LogicException When the issuance does not name the current credential.
	 * @throws OAuthFault When the current credential cannot be delivered again (server_error).
	 * @throws \Throwable Whatever a write raised, after the rollback.
	 */
	private function deliver_again( array $snapshot, IssuanceIntent $issuance ): bool {
		$state = $snapshot['state']->to_array();
		$head = $issuance->refresh_key;
		if ( ! $issuance->redelivery || $head !== $state['current_refresh_key'] || ! isset( $snapshot['rows'][ $head ] ) ) {
			throw new \LogicException( 'Only the current credential is delivered again.' );
		}
		if ( null === $snapshot['rows'][ $head ]['credential_key'] ) {
			throw new OAuthFault( 'server_error', 500 );
		}
		$this->db->begin();
		try {
			$claimed = $this->db->claim(
				'UPDATE ' . $this->db->table( 'families' ) . " SET delivery_count = delivery_count + 1, updated_at = %s WHERE family_hash = %s AND revision = %d AND phase = 'active'",
				[ Database::datetime( $this->clock->now() ), $snapshot['hash'], $state['revision'] ]
			);
			if ( 1 !== $claimed ) {
				$this->db->rollback();
				return false;
			}
			$this->access->record( $issuance, $state['client_key'], $state['subject_key'], $state['family_key'], (string) $snapshot['rows'][ $head ]['identifier_hash'] );
			$this->clients->touch( $state['client_key'], $this->clock->now() );
			$this->db->commit();
			return true;
		} catch ( \Throwable $error ) {
			$this->db->rollback();
			throw $error;
		}
	}

	/**
	 * @param array<string, mixed>                                                                       $state
	 * @param array{expires_at: int, consumed: bool, consumed_at: ?int, successor_key: ?string} $entry
	 */
	private function insert_credential( array $state, string $hash, string $key, array $entry, ?string $parent_row, IssuanceIntent $issuance ): void {
		$row_key = RowKeys::refresh( $key );
		$this->db->insert_or_fail(
			$this->db->table( 'refresh_tokens' ),
			[
				'identifier_hash'        => $row_key,
				'access_token_hash'      => RowKeys::access( $issuance->access_key ),
				'grant_family_hash'      => $hash,
				'client_id'              => $state['client_key'],
				'user_id'                => (int) $state['subject_key'],
				'parent_identifier_hash' => $parent_row,
				'family_expires_at'      => Database::datetime( $state['family_deadline'] ),
				'consumed_at'            => null,
				'revoked_reason'         => null,
				'expires_at'             => Database::datetime( $entry['expires_at'] ),
				'revoked'                => 0,
				'credential_key'         => $key,
			]
		);
		$this->access->record( $issuance, $state['client_key'], $state['subject_key'], $state['family_key'], $row_key );
		$this->clients->touch( $state['client_key'], $this->clock->now() );
	}

	/** Mark live refresh rows of a family revoked, with 'replayed' after reuse detection. */
	private function close_rows( string $hash, ?string $reason ): void {
		$refresh = $this->db->table( 'refresh_tokens' );
		if ( null === $reason ) {
			$this->db->execute( "UPDATE {$refresh} SET revoked = 1 WHERE grant_family_hash = %s AND revoked = 0", [ $hash ] );
			return;
		}
		$this->db->execute( "UPDATE {$refresh} SET revoked = 1, revoked_reason = %s WHERE grant_family_hash = %s AND revoked = 0", [ $reason, $hash ] );
	}

	/**
	 * Families row for a family with only earlier rows, owned by the client and subject of
	 * its rows or of a paired access row. Null when no owner is recorded anywhere.
	 *
	 * @return array<string, mixed>|null
	 */
	private function adopt_from_rows( string $family_key, string $hash ): ?array {
		if ( ! RowKeys::is_earlier( $family_key ) ) {
			return null;
		}
		$rows = $this->credential_rows( $hash );
		if ( [] === $rows ) {
			return null;
		}
		$owner = null;
		foreach ( $rows as $row ) {
			if ( null !== $row['client_id'] && null !== $row['user_id'] ) {
				$owner = [ (string) $row['client_id'], (string) $row['user_id'], null ];
				break;
			}
		}
		if ( null === $owner ) {
			$paired = array_values( array_unique( array_map( static fn ( array $row ): string => (string) $row['access_token_hash'], $rows ) ) );
			$placeholders = implode( ', ', array_fill( 0, count( $paired ), '%s' ) );
			$access = $this->db->row( 'SELECT client_id, user_id, scopes FROM ' . $this->db->table( 'access_tokens' ) . " WHERE identifier_hash IN ({$placeholders}) LIMIT 1", $paired );
			if ( null === $access ) {
				return null;
			}
			$owner = [ (string) $access['client_id'], (string) $access['user_id'], Database::string_list( $access['scopes'] ?? null ) ];
		}
		$this->insert_adopted( $hash, $rows, $owner[0], $owner[1], $owner[2] ?? [ 'mcp' ] );
		return $this->stored_family( $hash );
	}

	/**
	 * @param array<string, array<string, mixed>> $rows
	 * @param list<string>                        $scopes
	 * @throws OAuthFault When the owner facts are malformed (invalid_grant).
	 */
	private function insert_adopted( string $hash, array $rows, string $client_key, string $subject_key, array $scopes ): void {
		$deadline = null;
		foreach ( $rows as $row ) {
			$candidate = Database::epoch( $row['family_expires_at'] ?? null ) ?? Database::epoch( $row['expires_at'] ?? null );
			if ( null !== $candidate && ( null === $deadline || $candidate < $deadline ) ) {
				$deadline = $candidate;
			}
		}
		$scopes = array_values( array_filter( $scopes, static fn ( $scope ): bool => is_string( $scope ) && '' !== $scope ) );
		if ( null === $deadline || '' === $client_key || strlen( $client_key ) > 64 || null === PermissionSubjectAuthority::user_id( $subject_key ) || [] === $this->earlier_resources ) {
			throw new OAuthFault( 'invalid_grant' );
		}
		$now = Database::datetime( $this->clock->now() );
		$this->db->insert(
			$this->db->table( 'families' ),
			[
				'family_hash'       => $hash,
				'family_key'        => $hash,
				'client_id'         => $client_key,
				'user_id'           => (int) $subject_key,
				'scopes'            => Database::json( [] === $scopes ? [ 'mcp' ] : $scopes ),
				'resources'         => Database::json( $this->earlier_resources ),
				'phase'             => 'active',
				'revision'          => 0,
				'compacted_entries' => 0,
				'family_expires_at' => Database::datetime( $deadline ),
				'created_at'        => $now,
				'updated_at'        => $now,
			]
		);
	}

	/** @return array<string, mixed>|null */
	private function stored_family( string $hash ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'families' ) . ' WHERE family_hash = %s LIMIT 1', [ $hash ] );
	}

	/** @return array<string, array<string, mixed>> Logical key => refresh row. */
	private function credential_rows( string $hash ): array {
		$rows = [];
		foreach ( $this->db->rows( 'SELECT * FROM ' . $this->db->table( 'refresh_tokens' ) . ' WHERE grant_family_hash = %s', [ $hash ] ) as $row ) {
			$key = is_string( $row['credential_key'] ?? null ) && '' !== $row['credential_key'] ? $row['credential_key'] : (string) $row['identifier_hash'];
			$rows[ $key ] = $row;
		}
		return $rows;
	}

	private function revision( string $hash ): ?int {
		$value = $this->db->value( 'SELECT revision FROM ' . $this->db->table( 'families' ) . ' WHERE family_hash = %s LIMIT 1', [ $hash ] );
		return null === $value ? null : (int) $value;
	}

	/** @throws OAuthFault Always: the stored family cannot be read as one lineage (invalid_grant). */
	private static function unreadable(): never {
		Logger::warning( 'oauth_family_unreadable' );
		throw new OAuthFault( 'invalid_grant' );
	}
}
