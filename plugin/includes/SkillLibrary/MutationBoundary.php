<?php
/**
 * Explicit authority and audit port for future authenticated persistence integration.
 *
 * @package Stonewright
 * @license GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary;

/** An adapter must use Permissions, mode/token gates, and an authenticated import receipt. */
interface MutationBoundary {
	/** Only true authorizes a mutation; false and WP_Error refuse. @param array<string, mixed> $summary */
	public function authorize( string $action, array $summary, string $token ): bool|\WP_Error;
	/** Bounded metadata only; never bodies, review payloads, or credential tokens. @param array<string, mixed> $summary */
	public function record( string $action, array $summary, string $outcome ): void;
}
