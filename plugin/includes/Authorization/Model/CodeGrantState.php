<?php
/**
 * Logical authorization code state.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Model;

use Stonewright\WpMcp\Authorization\Protocol\CodeProof;
use Stonewright\WpMcp\Authorization\Protocol\RedirectRules;
use Stonewright\WpMcp\Authorization\Protocol\ResourceRules;
use Stonewright\WpMcp\Authorization\Protocol\ScopeRules;

/** Logical names define a port contract, not a database row or opaque credential layout. */
final class CodeGrantState {

	/** Seconds a used code stays recognizable after its expiry, so a late replay still revokes its family. */
	public const USED_CODE_RETENTION = 3600;

	private function __construct( private array $snapshot ) {}

	public static function from_array( array $state ): self {
		foreach ( [ 'code_key', 'client_key', 'subject_key', 'redirect_uri', 'code_challenge' ] as $key ) {
			if ( ! isset( $state[ $key ] ) || ! is_string( $state[ $key ] ) || '' === $state[ $key ] ) {
				throw new \InvalidArgumentException( 'Invalid code identity.' );
			}
		}
		if ( ! isset( $state['expires_at'], $state['used'], $state['scopes'], $state['resources'] ) || ! is_int( $state['expires_at'] ) || $state['expires_at'] < 1 || $state['expires_at'] > PHP_INT_MAX - self::USED_CODE_RETENTION || ! is_bool( $state['used'] ) || ! is_array( $state['scopes'] ) || ! is_array( $state['resources'] ) || ! array_key_exists( 'issued_family_key', $state ) || ( $state['used'] ? ! is_string( $state['issued_family_key'] ) || '' === $state['issued_family_key'] : null !== $state['issued_family_key'] ) ) {
			throw new \InvalidArgumentException( 'Invalid code state.' );
		}
		( new RedirectRules() )->approve( $state['redirect_uri'], [ $state['redirect_uri'] ], true );
		( new CodeProof() )->require_s256( 'S256', $state['code_challenge'] );
		$state['scopes'] = ( new ScopeRules() )->normalize( $state['scopes'] );
		$state['resources'] = ( new ResourceRules() )->require_authorized( $state['resources'], $state['resources'] );
		return new self( $state );
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return $this->snapshot;
	}

	/**
	 * Earliest time a ledger may forget this code. An unused code can go at expiry; a used
	 * code stays until expiry plus USED_CODE_RETENTION so a replay still finds the family
	 * it created and revokes it.
	 */
	public function retained_until(): int {
		return $this->snapshot['expires_at'] + ( $this->snapshot['used'] ? self::USED_CODE_RETENTION : 0 );
	}

	public function consume( string $family_key ): self {
		if ( $this->snapshot['used'] || '' === $family_key ) {
			throw new \InvalidArgumentException( 'A code may be consumed once.' );
		}
		return self::from_array( array_replace( $this->snapshot, [ 'used' => true, 'issued_family_key' => $family_key ] ) );
	}
}
