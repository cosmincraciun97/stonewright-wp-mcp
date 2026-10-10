<?php
/**
 * Rollback handler for options, entries of shared options and theme mods.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Rollback;

use Stonewright\WpMcp\Security\Adapters\OptionsAdapter;

/**
 * The live image is read over the scope the stored image describes. A restore goes through OptionsAdapter::restore()
 * with the ability that made the original change, so the allowlist of that ability still decides what may be written,
 * for a rollback row as much as for the change.
 */
final class OptionsRollbackFamily implements RollbackFamilyHandler {

	public function families(): array {
		return [ 'option' ];
	}

	public function live_image( array $row ): string|array|\WP_Error|null {
		$stored = FamilySupport::scope_image( $row );
		return null === $stored ? FamilySupport::unreadable() : OptionsAdapter::image( OptionsAdapter::scope_of( $stored ) );
	}

	public function restore( array $row, string|array|null $image, array $options ): array {
		if ( ! is_array( $image ) ) {
			return [ 'status' => 'failed', 'detail' => 'image_invalid' ];
		}
		return FamilySupport::answer( OptionsAdapter::restore( $image, (string) RollbackFamilies::root_of( $row )['ability'] ) );
	}

	public function describe( array $row, string|array|null $image ): string {
		return __( 'Writes the settings back as they were. A setting that did not exist before is removed.', 'stonewright' );
	}

	public function records_own_row( array $row ): bool {
		return false;
	}

	public function requires_human( array $row ): bool {
		return false;
	}
}
