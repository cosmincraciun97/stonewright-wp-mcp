<?php
/**
 * Authorization foundation.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );
namespace Stonewright\WpMcp\Authorization\Model;

/** Adapter-verified logical facts, never a raw request or assumed JWT layout. */
final class CredentialFacts {
	public function __construct( public readonly string $kind, public readonly string $credential_key, public readonly ?string $family_key, public readonly string $client_key, public readonly string $subject_key, public readonly array $scopes, public readonly array $resources, public readonly int $expires_at ) {}
}
