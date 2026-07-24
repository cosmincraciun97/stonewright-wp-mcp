<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Direction\DirectionContract;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Ability: stonewright/design-direction-restore
 *
 * Brings an older revision's contract back as the current one. Restoring
 * replaces live design intent, so it carries the destructive envelope: the
 * registry requires the task context token, and production-safe mode requires a
 * confirmation token bound to this exact direction id and revision.
 *
 * History stays append-only. The restored revision is left untouched and the
 * direction moves forward to a new revision carrying its contract, so the
 * receipt reports both the new revision and the revision it came from.
 *
 * @stonewright-status stable
 */
final class DirectionRestore extends AbilityKernel {

	use ConfirmationGuard;

	private DesignDirectionService $service;

	public function __construct( ?DesignDirectionService $service = null ) {
		$this->service = $service ?? new DesignDirectionService();
	}

	public function name(): string {
		return 'stonewright/design-direction-restore';
	}

	public function label(): string {
		return __( 'Restore design direction revision', 'stonewright' );
	}

	public function description(): string {
		return __( 'Writes a stored design direction revision back as a new revision, leaving history intact. Requires a confirmation token in production-safe mode.', 'stonewright' );
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
					'description' => 'Direction id.',
				],
				'revision'           => [
					'type'        => 'integer',
					'description' => 'Revision to restore. Read the available revisions with stonewright/design-direction-get.',
				],
				'confirmation_token' => [
					'type'        => 'string',
					'description' => 'Required in production-safe mode. Must be issued for this direction id and revision.',
				],
			],
			'required'             => [ 'id', 'revision' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                     => [ 'type' => 'boolean' ],
				'id'                     => [ 'type' => 'integer' ],
				'slug'                   => [ 'type' => 'string' ],
				'status'                 => [ 'type' => 'string' ],
				'revision'               => [ 'type' => 'integer' ],
				'restored_revision'      => [ 'type' => 'integer' ],
				'versioned'              => [ 'type' => 'boolean' ],
				'contract_hash'          => [ 'type' => 'string' ],
				'previous_contract_hash' => [ 'type' => 'string' ],
				'before_sha256'          => [ 'type' => 'string' ],
				'after_sha256'           => [ 'type' => 'string' ],
				'operation_class'        => [ 'type' => 'string' ],
				'resource_type'          => [ 'type' => 'string' ],
				'verification_status'    => [ 'type' => 'string' ],
				'effect_verified'        => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'ok', 'id', 'revision', 'restored_revision', 'versioned', 'contract_hash', 'effect_verified' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_design();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ): array|\WP_Error {
				$id       = isset( $args['id'] ) ? (int) $args['id'] : 0;
				$revision = isset( $args['revision'] ) ? (int) $args['revision'] : 0;

				if ( $id < 1 || $revision < 1 ) {
					return new \WP_Error(
						DirectionContract::ERROR_CODE,
						__( 'A positive design direction id and revision are required.', 'stonewright' ),
						[ 'status' => 400 ]
					);
				}

				$blocked = $this->confirmation_token_error(
					$args,
					[
						'id'       => $id,
						'revision' => $revision,
					]
				);
				if ( null !== $blocked ) {
					return $blocked;
				}

				$result = $this->service->restore( $id, $revision, get_current_user_id() );
				if ( $result instanceof \WP_Error ) {
					return $result;
				}

				$hash   = (string) $result['hash_after'];
				$stored = $this->service->get( $id );

				if ( null === $stored || (string) ( $stored['contract_hash'] ?? '' ) !== $hash ) {
					return new \WP_Error(
						'stonewright_direction_verification_failed',
						__( 'The stored design direction does not match the restored revision.', 'stonewright' ),
						[
							'status'              => 500,
							'direction_id'        => $id,
							'verification_status' => 'failed',
							'after_sha256'        => $hash,
						]
					);
				}

				return $this->ok(
					[
						'id'                     => $id,
						'slug'                   => (string) $result['slug'],
						'status'                 => (string) $result['status'],
						'revision'               => (int) $result['revision'],
						'restored_revision'      => (int) $result['restored_revision'],
						'versioned'              => (bool) $result['versioned'],
						'contract_hash'          => $hash,
						'previous_contract_hash' => (string) $result['hash_before'],
						'before_sha256'          => (string) $result['hash_before'],
						'after_sha256'           => $hash,
						'operation_class'        => 'design_direction.restore',
						'resource_type'          => 'design_direction',
						'verification_status'    => 'verified',
						'effect_verified'        => true,
					]
				);
			}
		);
	}
}
