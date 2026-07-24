<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Direction;

use WP_Error;

/**
 * Resolves what a sync call is about: which stored direction, and which kit.
 *
 * Both sync abilities accept the same target arguments, and the plan a caller
 * reviewed has to describe the same direction and the same kit the apply call
 * later writes. Keeping the resolution in one place is what makes that true —
 * a plan resolved by slug and an apply resolved by id land on the same record,
 * and both default to the same active kit.
 */
final class DirectionSyncTarget {

	/**
	 * Resolves the stored direction named by `id` or `slug`.
	 *
	 * @param DesignDirectionService $service Direction service.
	 * @param array<string,mixed>    $args    Ability arguments.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function resolve( DesignDirectionService $service, array $args ) {
		$id   = isset( $args['id'] ) ? (int) $args['id'] : 0;
		$slug = isset( $args['slug'] ) && is_string( $args['slug'] ) ? $args['slug'] : '';

		if ( $id < 1 && '' === $slug ) {
			return new WP_Error(
				DirectionContract::ERROR_CODE,
				__( 'A design direction id or slug is required.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$record = $id > 0 ? $service->get( $id ) : $service->find_by_slug( $slug );

		if ( null === $record ) {
			return new WP_Error(
				DesignDirectionService::NOT_FOUND_CODE,
				__( 'That design direction does not exist.', 'stonewright' ),
				[
					'status'       => 404,
					'direction_id' => $id,
					'slug'         => $slug,
				]
			);
		}

		return $record;
	}

	/**
	 * Resolves the Elementor kit to compare against.
	 *
	 * A site with no active kit is refused rather than defaulted: writing kit
	 * globals to whatever post id happens to be lying around is worse than
	 * telling the caller Elementor is not set up.
	 *
	 * @param array<string,mixed> $args Ability arguments.
	 * @return int|WP_Error
	 */
	public static function kit_id( array $args ) {
		$kit_id = isset( $args['kit_id'] ) ? (int) $args['kit_id'] : 0;

		if ( $kit_id < 1 ) {
			$kit_id = (int) get_option( 'elementor_active_kit', 0 );
		}

		if ( $kit_id < 1 ) {
			return new WP_Error(
				ElementorKitSyncPlanner::ERROR_CODE,
				__( 'This site has no active Elementor kit to synchronize.', 'stonewright' ),
				[ 'status' => 409 ]
			);
		}

		return $kit_id;
	}
}
