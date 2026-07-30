<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Direction\DirectionContract;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Ability: stonewright/design-direction-activate
 *
 * Points the site at a different design direction. This replaces the answer to
 * "what should this site look like" for every later render and verification
 * step, so it is gated like a destructive write: the registry requires the task
 * context token, and production-safe mode requires a confirmation token bound
 * to this exact direction id — a token issued for one direction cannot activate
 * another.
 *
 * Only a contract whose readiness reports ready can be activated; the service
 * enforces that, and the pointer is read back before the receipt claims
 * success.
 *
 * @stonewright-status stable
 */
final class DirectionActivate extends AbilityKernel {

	use ConfirmationGuard;

	private DesignDirectionService $service;

	public function __construct( ?DesignDirectionService $service = null ) {
		$this->service = $service ?? new DesignDirectionService();
	}

	public function name(): string {
		return 'stonewright/design-direction-activate';
	}

	public function label(): string {
		return __( 'Activate design direction', 'stonewright' );
	}

	public function description(): string {
		return __( 'Makes a ready design direction the active one for the site. Requires a confirmation token in production-safe mode.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'id'                 => [
					'type'        => 'integer',
					'description' => 'Direction id to activate.',
				],
				'confirmation_token' => [
					'type'        => 'string',
					'description' => 'Required in production-safe mode. Must be issued for this direction id.',
				],
			],
			'required'             => [ 'id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                 => [ 'type' => 'boolean' ],
				'id'                 => [ 'type' => 'integer' ],
				'slug'               => [ 'type' => 'string' ],
				'status'             => [ 'type' => 'string' ],
				'revision'           => [ 'type' => 'integer' ],
				'contract_hash'      => [ 'type' => 'string' ],
				'active_id'          => [ 'type' => 'integer' ],
				'previous_active_id' => [ 'type' => 'integer' ],
				'before_sha256'      => [ 'type' => 'string' ],
				'after_sha256'       => [ 'type' => 'string' ],
				'operation_class'    => [ 'type' => 'string' ],
				'resource_type'      => [ 'type' => 'string' ],
				'verification_status' => [ 'type' => 'string' ],
				'effect_verified'    => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'ok', 'id', 'active_id', 'previous_active_id', 'contract_hash', 'effect_verified' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_design();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ): array|\WP_Error {
				$id = isset( $args['id'] ) ? (int) $args['id'] : 0;

				if ( $id < 1 ) {
					return new \WP_Error(
						DirectionContract::ERROR_CODE,
						__( 'A positive design direction id is required.', 'stonewright' ),
						[ 'status' => 400 ]
					);
				}

				$blocked = $this->confirmation_token_error( $args, [ 'id' => $id ] );
				if ( null !== $blocked ) {
					return $blocked;
				}

				$result = $this->service->activate( $id, get_current_user_id() );
				if ( $result instanceof \WP_Error ) {
					return $result;
				}

				$active_id = (int) get_option( DesignDirectionService::ACTIVE_OPTION, 0 );

				if ( $active_id !== $id ) {
					return new \WP_Error(
						'stonewright_direction_verification_failed',
						__( 'The active design direction pointer does not name the activated direction.', 'stonewright' ),
						[
							'status'              => 500,
							'direction_id'        => $id,
							'active_id'           => $active_id,
							'verification_status' => 'failed',
						]
					);
				}

				return $this->ok(
					[
						'id'                  => $id,
						'slug'                => (string) $result['slug'],
						'status'              => (string) $result['status'],
						'revision'            => (int) $result['revision'],
						'contract_hash'       => (string) $result['hash_after'],
						'active_id'           => $active_id,
						'previous_active_id'  => (int) $result['previous_active_id'],
						'before_sha256'       => (string) $result['hash_before'],
						'after_sha256'        => (string) $result['hash_after'],
						'operation_class'     => 'design_direction.activate',
						'resource_type'       => 'design_direction',
						'verification_status' => 'verified',
						'effect_verified'     => true,
					]
				);
			}
		);
	}
}
