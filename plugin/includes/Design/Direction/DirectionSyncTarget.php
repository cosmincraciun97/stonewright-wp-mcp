<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Direction;

use Stonewright\WpMcp\Security\Permissions;
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

	/** @var string Structured error code for a kit_id that is not an Elementor kit. */
	public const INVALID_KIT_CODE = 'stonewright_direction_sync_invalid_kit';

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
	 * A kit_id that is not the active kit has to be a post of the Elementor kit
	 * type, and the current user has to be allowed to edit the kit post, for the
	 * plan (which reads it) as well as the apply (which writes it).
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

		$active = (int) get_option( 'elementor_active_kit', 0 );
		$post   = get_post( $kit_id );

		if ( $kit_id !== $active && ( ! $post || ! self::is_kit_post( $post ) ) ) {
			return new WP_Error(
				self::INVALID_KIT_CODE,
				__( 'kit_id must be the active Elementor kit or a post of the Elementor kit type.', 'stonewright' ),
				[
					'status' => 422,
					'kit_id' => $kit_id,
				]
			);
		}

		// A kit post that does not exist is reported by the writer as a kit that cannot be snapshotted.
		if ( $post && ! Permissions::can_edit_post( $kit_id ) ) {
			return new WP_Error(
				'stonewright_forbidden',
				__( 'You are not allowed to edit this Elementor kit.', 'stonewright' ),
				[
					'status' => 403,
					'kit_id' => $kit_id,
				]
			);
		}

		return $kit_id;
	}

	/**
	 * Whether a post is an Elementor kit: a library post whose template type is "kit".
	 */
	private static function is_kit_post( object $post ): bool {
		return 'elementor_library' === (string) ( $post->post_type ?? '' )
			&& 'kit' === (string) get_post_meta( (int) $post->ID, '_elementor_template_type', true );
	}
}
