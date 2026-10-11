<?php
/**
 * Rollback handler for navigation menus and menu locations.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Rollback;

use Stonewright\WpMcp\Security\Adapters\MenuAdapter;

/**
 * A menu is rebuilt from its image, with its items in order and under their parents. A deleted menu comes back
 * with a new id, which the handler reports so that the rollback row names the menu that exists now. The undo of a
 * creation, and so the redo of a recreation, deletes the menu.
 */
final class MenuRollbackFamily implements RollbackFamilyHandler {

	public function families(): array {
		return [ 'menu' ];
	}

	public function live_image( array $row ): string|array|\WP_Error|null {
		return 'menu_location' === $row['resource_type']
			? MenuAdapter::location_image( (string) $row['resource_id'] )
			: MenuAdapter::image( (int) $row['resource_id'] );
	}

	public function restore( array $row, string|array|null $image, array $options ): array {
		if ( 'menu_location' === $row['resource_type'] ) {
			return is_array( $image ) ? FamilySupport::answer( MenuAdapter::restore_location( $image ) ) : [ 'status' => 'failed', 'detail' => 'image_invalid' ];
		}
		if ( null === $image ) {
			$result = MenuAdapter::delete_created( (int) $row['resource_id'] );
			if ( $result instanceof \WP_Error ) {
				return FamilySupport::answer( $result );
			}
			if ( empty( $result['ok'] ) ) {
				return [ 'status' => 'failed', 'detail' => 'not_deleted' ];
			}
			return 'already_deleted' === $result['action'] ? [ 'status' => 'noop', 'detail' => 'already_deleted' ] : [ 'status' => 'succeeded', 'detail' => '' ];
		}
		if ( ! is_array( $image ) ) {
			return [ 'status' => 'failed', 'detail' => 'image_invalid' ];
		}
		$result = MenuAdapter::restore( $image );
		$answer = FamilySupport::answer( $result );
		if ( ! $result instanceof \WP_Error && ! empty( $result['recreated'] ) ) {
			$answer['resource_id'] = (string) $result['menu_id'];
		}
		return $answer;
	}

	public function describe( array $row, string|array|null $image ): string {
		return null === $image
			? __( 'Deletes the menu that the change created, with its items.', 'stonewright' )
			: __( 'Rebuilds the menu as it was: its items in order and under their parents. A deleted menu is created again.', 'stonewright' );
	}

	public function records_own_row( array $row ): bool {
		return false;
	}

	public function requires_human( array $row ): bool {
		return false;
	}
}
