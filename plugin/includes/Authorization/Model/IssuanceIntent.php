<?php
/**
 * Authorization foundation.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Model;

/**
 * Committed bounds and identifiers, with no encoded credentials. refresh_deadline is the
 * refresh credential's own expiry. A re-delivery names the existing unconsumed head as
 * refresh_key (with that head's expiry) beside a new access key; revision is the
 * committed family revision the intent was decided against.
 */
final class IssuanceIntent {

	public function __construct( public readonly string $family_key, public readonly string $access_key, public readonly string $refresh_key, public readonly int $access_deadline, public readonly int $refresh_deadline, public readonly array $access_scopes, public readonly array $resources, public readonly int $revision, public readonly bool $redelivery = false ) {}
}
