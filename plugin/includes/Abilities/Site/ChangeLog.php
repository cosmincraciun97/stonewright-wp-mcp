<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Site;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Compact change timeline over Stonewright post snapshots.
 *
 * List view never returns heavy post_content / meta payloads.
 * Pass snapshot_id + post_id for a single full snapshot payload.
 *
 * @stonewright-status stable
 */
final class ChangeLog extends AbilityKernel {

	public function name(): string {
		return 'stonewright/change-log';
	}

	public function label(): string {
		return __( 'Change log', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists Stonewright post snapshots compactly (id, post, when). Full payload only when snapshot_id is requested.', 'stonewright' );
	}

	public function category(): string {
		return 'site';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'     => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Limit to one post.',
				],
				'snapshot_id' => [
					'type'        => 'string',
					'description' => 'When set with post_id, returns the full snapshot payload for that entry.',
				],
				'limit'       => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 200,
					'default' => 50,
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'entries'  => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => true,
					],
				],
				'count'    => [ 'type' => 'integer' ],
				'snapshot' => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
			],
			'required'   => [ 'entries', 'count' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( $post_id > 0 ) {
			if ( ! Permissions::edit_post( $post_id ) && ! Permissions::manage_options() ) {
				return $this->error( 'cannot_view_changes', __( 'You cannot view snapshots for this post.', 'stonewright' ) );
			}
			return true;
		}

		return Permissions::manage_options() || Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$post_id     = (int) ( $args['post_id'] ?? 0 );
		$snapshot_id = isset( $args['snapshot_id'] ) ? (string) $args['snapshot_id'] : '';
		$limit       = (int) ( $args['limit'] ?? 50 );

		if ( '' !== $snapshot_id ) {
			if ( $post_id <= 0 ) {
				return $this->error( 'post_id_required', __( 'post_id is required when requesting a snapshot payload.', 'stonewright' ) );
			}
			$snapshot = Backup::get_snapshot( $post_id, $snapshot_id );
			if ( null === $snapshot ) {
				return $this->error( 'snapshot_not_found', __( 'Snapshot not found.', 'stonewright' ) );
			}

			return [
				'entries'  => [],
				'count'    => 1,
				'snapshot' => array_merge(
					[ 'post_id' => $post_id ],
					$snapshot
				),
			];
		}

		$entries = Backup::list_timeline( $limit, $post_id );

		return [
			'entries' => $entries,
			'count'   => count( $entries ),
		];
	}
}
