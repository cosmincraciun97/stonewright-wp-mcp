<?php
/**
 * Authorization foundation.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Protocol;

use Stonewright\WpMcp\Authorization\Model\OAuthFault;

/** Preserves extension lists without collapsing duplicate singleton fields. */
final class ParameterBag {

	/** @param array<string, list<string>> $parameters Parsed scalar parameters. */
	public function __construct( private array $parameters ) {}

	/**
	 * A parameter sent without a value is treated as omitted (RFC 6749 sections 3.1 and 3.2).
	 *
	 * @return list<string>
	 */
	public function values( string $name ): array {
		return array_values( array_filter( $this->parameters[ $name ] ?? [], static fn ( string $value ): bool => '' !== $value ) );
	}

	public function one( string $name ): ?string {
		$values = $this->values( $name );
		if ( count( $values ) > 1 ) {
			throw new OAuthFault( 'invalid_request' );
		}
		return $values[0] ?? null;
	}
}
