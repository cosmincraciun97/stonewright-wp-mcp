<?php
/**
 * Authorization foundation.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );
namespace Stonewright\WpMcp\Authorization\Ports;

interface ClientDirectory {
	public function find( string $client_key ): ?array;
	/** Atomically persist the accepted profile unchanged and assign a collision-checked client_id; return after durability. */
	public function create( array $accepted_profile ): array;
}
