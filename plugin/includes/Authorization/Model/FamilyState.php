<?php
/**
 * Authorization foundation.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Model;

use Stonewright\WpMcp\Authorization\Protocol\ResourceRules;
use Stonewright\WpMcp\Authorization\Protocol\ScopeRules;

/**
 * Logical state only; these member names are not a legacy persistence schema.
 * History entries hold expires_at, consumed, consumed_at (null when unknown) and
 * successor_key; compacted_entries counts ancestors dropped by compaction.
 */
final class FamilyState {

	private function __construct( private array $snapshot ) {}

	public static function from_array( array $state ): self {
		foreach ( [ 'family_key', 'client_key', 'subject_key', 'current_refresh_key' ] as $name ) {
			if ( ! isset( $state[ $name ] ) || ! is_string( $state[ $name ] ) || '' === $state[ $name ] ) {
				throw new \InvalidArgumentException( 'Invalid family identity.' );
			}
		}
		if ( ! isset( $state['family_deadline'], $state['revision'], $state['phase'] ) || ! is_int( $state['family_deadline'] ) || $state['family_deadline'] < 1 || ! is_int( $state['revision'] ) || $state['revision'] < 0 || ! in_array( $state['phase'], [ 'active', 'revoked', 'expired' ], true ) ) {
			throw new \InvalidArgumentException( 'Invalid family bounds.' );
		}
		// A family without a compaction count has dropped nothing.
		$state['compacted_entries'] = $state['compacted_entries'] ?? 0;
		if ( ! is_int( $state['compacted_entries'] ) || $state['compacted_entries'] < 0 ) {
			throw new \InvalidArgumentException( 'Invalid family bounds.' );
		}
		foreach ( [ 'consented_scopes', 'consented_resources' ] as $name ) {
			if ( ! isset( $state[ $name ] ) || ! is_array( $state[ $name ] ) || [] === $state[ $name ] || ! array_is_list( $state[ $name ] ) ) {
				throw new \InvalidArgumentException( 'Invalid family consent.' );
			}
			foreach ( $state[ $name ] as $value ) {
				if ( ! is_string( $value ) || '' === $value ) {
					throw new \InvalidArgumentException( 'Invalid family consent.' );
				}
			}
		}
		$state['consented_scopes'] = ( new ScopeRules() )->normalize( $state['consented_scopes'] );
		$state['consented_resources'] = ( new ResourceRules() )->require_authorized( $state['consented_resources'], $state['consented_resources'] );
		$history = $state['credential_history'] ?? null;
		if ( ! is_array( $history ) || ! isset( $history[ $state['current_refresh_key'] ] ) ) {
			throw new \InvalidArgumentException( 'Invalid family history.' );
		}
		$entries = [];
		$fresh = [];
		foreach ( $history as $key => $entry ) {
			$entries[ $key ] = self::entry( $entry, (string) $key, $history, $state['family_deadline'] );
			if ( ! $entries[ $key ]['consumed'] ) {
				$fresh[] = (string) $key;
			}
		}
		$state['credential_history'] = $entries;
		if ( count( $fresh ) > 1 || ( 'active' === $state['phase'] && $fresh !== [ $state['current_refresh_key'] ] ) ) {
			throw new \InvalidArgumentException( 'A family cannot branch.' );
		}
		$predecessors = self::predecessors( $entries );
		$roots = array_diff_key( $entries, $predecessors );
		if ( count( $roots ) !== 1 ) {
			throw new \InvalidArgumentException( 'Family lineage needs one origin.' );
		}
		$cursor = (string) array_key_first( $roots );
		$visited = [];
		while ( ! isset( $visited[ $cursor ] ) ) {
			$visited[ $cursor ] = true;
			$successor = $entries[ $cursor ]['successor_key'];
			if ( null === $successor ) {
				break;
			}
			$cursor = $successor;
		}
		if ( count( $visited ) !== count( $entries ) || $cursor !== $state['current_refresh_key'] || null !== $entries[ $cursor ]['successor_key'] ) {
			throw new \InvalidArgumentException( 'Family lineage is disconnected or cyclic.' );
		}
		return new self( $state );
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return $this->snapshot;
	}

	public function advance( array $changes ): self {
		$before = $this->snapshot;
		$after = array_replace( $before, $changes );
		foreach ( [ 'family_key', 'client_key', 'subject_key', 'consented_scopes', 'consented_resources', 'family_deadline', 'compacted_entries' ] as $name ) {
			if ( $before[ $name ] !== $after[ $name ] ) {
				throw new \InvalidArgumentException( 'Family authority cannot change.' );
			}
		}
		if ( $after['revision'] !== $before['revision'] + 1 || ( 'active' !== $before['phase'] && $after['phase'] !== $before['phase'] ) ) {
			throw new \InvalidArgumentException( 'Family transition is not forward.' );
		}
		$next = self::from_array( $after );
		$history = $next->snapshot['credential_history'];
		foreach ( $before['credential_history'] as $key => $entry ) {
			$new_entry = $history[ $key ] ?? null;
			if ( null === $new_entry || $entry['expires_at'] !== $new_entry['expires_at'] || ( $entry['consumed'] && $entry !== $new_entry ) ) {
				throw new \InvalidArgumentException( 'Consumed history cannot change.' );
			}
			if ( ! $entry['consumed'] && $new_entry['consumed'] && null === $new_entry['consumed_at'] ) {
				throw new \InvalidArgumentException( 'A new consumption records its time.' );
			}
		}
		$added = array_diff_key( $history, $before['credential_history'] );
		$head = $next->snapshot['current_refresh_key'];
		if ( $head !== $before['current_refresh_key'] ) {
			$parent = $history[ $before['current_refresh_key'] ];
			if ( 'active' !== $before['phase'] || 'active' !== $next->snapshot['phase'] || count( $added ) !== 1 || ! isset( $added[ $head ] ) || ! $parent['consumed'] || $parent['successor_key'] !== $head ) {
				throw new \InvalidArgumentException( 'Invalid successor transition.' );
			}
		} elseif ( [] !== $added ) {
			throw new \InvalidArgumentException( 'Unexpected successor.' );
		}
		return $next;
	}

	/** Close an active family (client revocation or administrative disconnect); a closed family keeps its phase. */
	public function revoke(): self {
		if ( 'active' !== $this->snapshot['phase'] ) {
			return $this;
		}
		return $this->advance( [ 'phase' => 'revoked', 'revision' => $this->snapshot['revision'] + 1 ] );
	}

	/**
	 * Drop the oldest consumed ancestors whose consumption is at least $window seconds old
	 * (an unknown time counts as old). The current credential and its immediate predecessor
	 * always stay. Nothing to drop leaves the state unchanged.
	 *
	 * @throws \InvalidArgumentException When the window is negative.
	 */
	public function compact( int $now, int $window ): self {
		if ( $window < 0 ) {
			throw new \InvalidArgumentException( 'Invalid compaction window.' );
		}
		$history = $this->snapshot['credential_history'];
		$head = $this->snapshot['current_refresh_key'];
		$predecessors = self::predecessors( $history );
		$keep_from = $predecessors[ $head ] ?? $head;
		$cursor = (string) array_key_first( array_diff_key( $history, $predecessors ) );
		$dropped = [];
		while ( $cursor !== $keep_from ) {
			$consumed_at = $history[ $cursor ]['consumed_at'];
			if ( null !== $consumed_at && max( 0, $now - $consumed_at ) < $window ) {
				break;
			}
			$dropped[ $cursor ] = true;
			$cursor = (string) $history[ $cursor ]['successor_key'];
		}
		if ( [] === $dropped ) {
			return $this;
		}
		$next = $this->snapshot;
		$next['credential_history'] = array_diff_key( $history, $dropped );
		$next['compacted_entries'] += count( $dropped );
		++$next['revision'];
		return self::from_array( $next );
	}

	/** Only the unconsumed, unexpired current credential of an active family before its deadline is live. */
	public function credential_active( string $key, int $now ): bool {
		$entry = $this->snapshot['credential_history'][ $key ] ?? null;
		return null !== $entry && 'active' === $this->snapshot['phase'] && $now < $this->snapshot['family_deadline'] && ! $entry['consumed'] && $now < $entry['expires_at'];
	}

	/**
	 * Validate one history entry and return it in canonical form. An absent consumed_at
	 * reads as null: not consumed, or consumed at an unknown time.
	 *
	 * @return array{expires_at: int, consumed: bool, consumed_at: ?int, successor_key: ?string}
	 * @throws \InvalidArgumentException When the entry is malformed or breaks the lineage rules.
	 */
	private static function entry( mixed $entry, string $key, array $history, int $deadline ): array {
		if ( ! is_array( $entry ) || ! isset( $entry['expires_at'], $entry['consumed'] ) || ! is_int( $entry['expires_at'] ) || $entry['expires_at'] < 1 || $entry['expires_at'] > $deadline || ! is_bool( $entry['consumed'] ) || ! array_key_exists( 'successor_key', $entry ) ) {
			throw new \InvalidArgumentException( 'Invalid family lineage.' );
		}
		$successor = $entry['successor_key'];
		if ( null !== $successor && ( ! is_string( $successor ) || ! isset( $history[ $successor ] ) || ! $entry['consumed'] || $key === $successor ) ) {
			throw new \InvalidArgumentException( 'Invalid family lineage.' );
		}
		$consumed_at = $entry['consumed_at'] ?? null;
		if ( null !== $consumed_at && ( ! $entry['consumed'] || ! is_int( $consumed_at ) || $consumed_at < 1 || $consumed_at > $deadline ) ) {
			throw new \InvalidArgumentException( 'Invalid consumption time.' );
		}
		return [ 'expires_at' => $entry['expires_at'], 'consumed' => $entry['consumed'], 'consumed_at' => $consumed_at, 'successor_key' => $successor ];
	}

	/**
	 * @return array<string, string> Successor key => predecessor key.
	 * @throws \InvalidArgumentException When two entries name the same successor.
	 */
	private static function predecessors( array $entries ): array {
		$predecessors = [];
		foreach ( $entries as $key => $entry ) {
			$successor = $entry['successor_key'];
			if ( null !== $successor ) {
				if ( isset( $predecessors[ $successor ] ) ) {
					throw new \InvalidArgumentException( 'Family lineage cannot merge.' );
				}
				$predecessors[ $successor ] = (string) $key;
			}
		}
		return $predecessors;
	}
}
