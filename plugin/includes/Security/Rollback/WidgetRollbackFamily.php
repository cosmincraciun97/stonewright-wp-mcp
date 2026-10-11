<?php
/**
 * Rollback handler for widget sidebars.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Rollback;

use Stonewright\WpMcp\Security\Adapters\WidgetAdapter;

/**
 * The live image is the sidebar and the settings of the widgets the stored image covers. A restore writes the sidebar
 * list and those settings back and leaves other sidebars and widgets alone.
 */
final class WidgetRollbackFamily implements RollbackFamilyHandler {

	public function families(): array {
		return [ 'widget' ];
	}

	public function live_image( array $row ): string|array|\WP_Error|null {
		$stored = FamilySupport::scope_image( $row );
		if ( null === $stored ) {
			return FamilySupport::unreadable();
		}
		return WidgetAdapter::image( (string) ( $stored['sidebar'] ?? $row['resource_id'] ), array_map( 'strval', array_keys( (array) ( $stored['instances'] ?? [] ) ) ) );
	}

	public function restore( array $row, string|array|null $image, array $options ): array {
		return is_array( $image ) ? FamilySupport::answer( WidgetAdapter::restore( $image ) ) : [ 'status' => 'failed', 'detail' => 'image_invalid' ];
	}

	public function describe( array $row, string|array|null $image ): string {
		return __( 'Puts the sidebar back as it was: its widgets in order and the settings of the widgets the change touched.', 'stonewright' );
	}

	public function records_own_row( array $row ): bool {
		return false;
	}

	public function requires_human( array $row ): bool {
		return false;
	}
}
