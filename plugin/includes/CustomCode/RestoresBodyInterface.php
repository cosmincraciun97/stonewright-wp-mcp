<?php
/**
 * A custom-code provider that can write a snippet body back without a provider snapshot.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\CustomCode;

/**
 * Implemented by providers whose snippets are plain bodies: the change ledger keeps the body of a
 * snippet before every write, and restoring it must not depend on the provider snapshot, which expires.
 * The provider's own rollback() is unchanged.
 */
interface RestoresBodyInterface {

	/**
	 * Save a body into an existing snippet and check it as the provider does after a write.
	 *
	 * @param string    $target_id       The provider's snippet id.
	 * @param string    $body            The code to save.
	 * @param bool|null $expected_active The active state the check expects, or null to accept the current one.
	 * @return array<string, mixed>|\WP_Error The provider's rollback result: effect_verified says whether the check passed.
	 */
	public function restore_body( string $target_id, string $body, ?bool $expected_active = null );
}
