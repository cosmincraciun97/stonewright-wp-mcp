<?php
/**
 * Authorization foundation.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );
namespace Stonewright\WpMcp\Authorization\Ports;

interface SubjectAuthority {
	/** The adapter must check the grant subject's current operation permissions. */
	public function require_allowed( string $subject_key, string $operation, array $context ): void;
}
