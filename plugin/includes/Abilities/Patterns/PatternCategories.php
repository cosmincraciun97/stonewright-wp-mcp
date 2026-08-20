<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Patterns;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * List or assign wp_pattern_category terms on user synced patterns.
 *
 * Assign is a write: Permissions, Backup, ConfirmationGuard in production-safe,
 * AbilityKernel::audit().
 *
 * @stonewright-status stable
 */
final class PatternCategories extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/patterns-categories';
	}

	public function label(): string {
		return __( 'Pattern categories', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists pattern categories or assigns them to a user synced pattern.', 'stonewright' );
	}

	public function category(): string {
		return 'patterns';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'action'             => [
					'type'    => 'string',
					'enum'    => [ 'list', 'assign' ],
					'default' => 'list',
				],
				'id'                 => [ 'type' => 'integer', 'minimum' => 1 ],
				'categories'         => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'action' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'categories'  => [ 'type' => 'array' ],
				'id'          => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => [ 'string', 'null' ] ],
			],
			'required'   => [ 'categories' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		if ( 'assign' === ( $args['action'] ?? 'list' ) ) {
			return PatternSupport::can_write();
		}
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$action = (string) ( $args['action'] ?? 'list' );
		if ( 'list' === $action ) {
			return [ 'categories' => PatternSupport::list_categories() ];
		}

		return $this->audit(
			$args,
			function ( array $args ) {
				$verify = $args;
				unset( $verify['confirmation_token'] );
				$token_error = $this->confirmation_token_error( $args, $verify );
				if ( null !== $token_error ) {
					return $token_error;
				}

				$post = PatternSupport::require_pattern( (int) ( $args['id'] ?? 0 ) );
				if ( is_wp_error( $post ) ) {
					return $post;
				}

				$snapshot_id = Backup::snapshot_post( (int) $post->ID );
				$assigned    = PatternSupport::assign_categories(
					(int) $post->ID,
					isset( $args['categories'] ) && is_array( $args['categories'] ) ? $args['categories'] : []
				);

				return [
					'id'          => (int) $post->ID,
					'categories'  => $assigned,
					'snapshot_id' => $snapshot_id,
				];
			}
		);
	}

	/**
	 * @return array<int, string>
	 */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'confirmation_token' ] );
	}
}
