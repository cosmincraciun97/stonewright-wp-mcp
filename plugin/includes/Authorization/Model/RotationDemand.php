<?php
/**
 * Authorization foundation.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Model;

/**
 * Constructed from authenticated credential facts by the future transport. Null or an
 * empty scope list means the scope was not requested, so the consented scopes apply
 * (RFC 6749 sections 3.2 and 6).
 */
final class RotationDemand {

	public function __construct( public readonly string $family_key, public readonly string $refresh_key, public readonly string $client_key, public readonly array $resources, public readonly ?array $scopes ) {}
}
