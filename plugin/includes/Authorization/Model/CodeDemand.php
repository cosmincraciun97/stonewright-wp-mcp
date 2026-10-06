<?php
/**
 * Authorization code exchange demand.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Model;

final class CodeDemand {
	public function __construct( public readonly string $code_key, public readonly string $client_key, public readonly string $redirect_uri, public readonly string $verifier, public readonly array $resources ) {}
}
