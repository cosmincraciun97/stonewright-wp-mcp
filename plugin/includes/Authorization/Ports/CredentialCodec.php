<?php
/**
 * Authorization foundation.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );
namespace Stonewright\WpMcp\Authorization\Ports;

use Stonewright\WpMcp\Authorization\Model\CredentialFacts;
use Stonewright\WpMcp\Authorization\Model\IssuanceIntent;
use Stonewright\WpMcp\Authorization\Model\TokenPair;

/** A future adapter supplies verified compatible formats and key lifecycle. */
interface CredentialCodec {
	/** Verify authenticity before returning facts; never export key/token material. */
	public function inspect( string $opaque_credential ): CredentialFacts;
	/**
	 * Called only with committed issuance, outside the durable state transition.
	 * A re-delivery intent names an existing unconsumed refresh key, and encoding must
	 * work for it: refresh credentials are self-contained encrypted payloads, or the
	 * adapter keeps an encrypted copy of the current credential for the duplicate
	 * window. A one-way hash of that credential alone cannot satisfy this. Every
	 * encoding of one key is the same credential; the first presentation consumes it.
	 * If an existing key cannot be encoded, fail closed with server_error (HTTP 500)
	 * and change nothing; never mint a replacement lineage.
	 */
	public function encode( IssuanceIntent $intent ): TokenPair;
}
