<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class BackupPage extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-backup-page';
	}

	public function label(): string {
		return __( 'Backup Elementor page', 'stonewright' );
	}

	public function description(): string {
		return __( 'Forces a manual snapshot of an Elementor page (post + meta + _elementor_data).', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'label'   => [ 'type' => 'string', 'maxLength' => 100 ],
			],
			'required'             => [ 'post_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'snapshot_id' => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$post_id = (int) $args['post_id'];
				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}
				// A manual snapshot changes nothing, so it arms no rescue: there is no write to probe or roll back.
				$snapshot_id = Backup::snapshot_post( $post_id, [], false );
				if ( '' === $snapshot_id ) {
					return $this->backup_failed_error();
				}
				return [ 'snapshot_id' => $snapshot_id ];
			}
		);
	}
}
