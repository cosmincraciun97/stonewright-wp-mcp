<?php
/**
 * Authorization foundation.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Model;

/** A protocol failure with no request or credential text in its description. */
final class OAuthFault extends \RuntimeException {

	private int $http_status;

	/**
	 * The status defaults to 500 for server_error and 400 otherwise; server_error
	 * and HTTP 500 always go together.
	 *
	 * @throws \InvalidArgumentException When server_error and HTTP 500 are not paired.
	 */
	public function __construct( private string $protocol_error, ?int $http_status = null ) {
		parent::__construct( 'The authorization request could not be completed.' );
		$this->http_status = $http_status ?? ( 'server_error' === $protocol_error ? 500 : 400 );
		if ( ( 'server_error' === $protocol_error ) !== ( 500 === $this->http_status ) ) {
			throw new \InvalidArgumentException( 'A server error is reported only with HTTP 500.' );
		}
	}

	public function error(): string {
		return $this->protocol_error;
	}

	public function status(): int {
		return $this->http_status;
	}

	public function safe_description(): string {
		return $this->getMessage();
	}
}
