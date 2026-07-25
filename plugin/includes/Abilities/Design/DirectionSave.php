<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Direction\DirectionContract;
use Stonewright\WpMcp\Design\Direction\DirectionPayload;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Ability: stonewright/design-direction-save
 *
 * Creates or updates a design direction contract. The registry requires the
 * task context token for this ability, and the write path is:
 *
 *   1. Permission check: can_manage_design().
 *   2. Payload size check before validation, so an oversized contract is
 *      rejected without walking it.
 *   3. DirectionContractValidator inside the service — allowlist-only, and it
 *      rejects unknown fields rather than stripping them.
 *   4. Revision decision and version snapshot inside the service.
 *   5. Readback: the stored contract hash must match what was written.
 *
 * The response is a compact receipt. It carries the contract hash before and
 * after so an audit trail can prove what changed; it never echoes the contract
 * back, because the caller already has it.
 *
 * @stonewright-status stable
 */
final class DirectionSave extends AbilityKernel {

	private DesignDirectionService $service;

	public function __construct( ?DesignDirectionService $service = null ) {
		$this->service = $service ?? new DesignDirectionService();
	}

	public function name(): string {
		return 'stonewright/design-direction-save';
	}

	public function label(): string {
		return __( 'Save design direction', 'stonewright' );
	}

	public function description(): string {
		return __( 'Validates and stores a design direction contract, creating a new revision when the contract changed. Returns the contract hash before and after.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'contract'    => [
					'type'        => 'object',
					'description' => 'Design direction contract. Validated allowlist-only; unknown fields are rejected.',
				],
				'slug'        => [
					'type'        => 'string',
					'description' => 'Storage slug. Defaults to the contract identity name.',
				],
				'status'      => [
					'type'        => 'string',
					'enum'        => DesignDirectionService::WRITABLE_STATUSES,
					'description' => 'Lifecycle status. Only a ready contract may be marked ready.',
				],
				'source_type' => [
					'type'        => 'string',
					'enum'        => DirectionContract::SOURCE_TYPES,
					'description' => 'Where the contract came from.',
				],
				'source_refs' => [
					'type'        => 'object',
					'description' => 'Bounded map of provenance references.',
				],
			],
			'required'             => [ 'contract' ],
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
				'versioned'              => [ 'type' => 'boolean' ],
				'contract_hash'          => [ 'type' => 'string' ],
				'previous_contract_hash' => [ 'type' => 'string' ],
				'before_sha256'          => [ 'type' => 'string' ],
				'after_sha256'           => [ 'type' => 'string' ],
				'verification_status'    => [ 'type' => 'string' ],
				'operation_class'        => [ 'type' => 'string' ],
				'resource_type'          => [ 'type' => 'string' ],
				'effect_verified'        => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'ok', 'id', 'slug', 'status', 'revision', 'versioned', 'contract_hash', 'effect_verified' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_design();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ): array|\WP_Error {
				$contract = is_array( $args['contract'] ?? null ) ? $args['contract'] : [];

				$too_large = DirectionPayload::size_error( $contract, DirectionContract::MAX_CONTRACT_BYTES );
				if ( null !== $too_large ) {
					return $too_large;
				}

				$result = $this->service->save( $args, get_current_user_id() );
				if ( $result instanceof \WP_Error ) {
					return $result;
				}

				$id     = (int) $result['id'];
				$stored = $this->service->get( $id );
				$hash   = (string) $result['hash_after'];

				if ( null === $stored || (string) ( $stored['contract_hash'] ?? '' ) !== $hash ) {
					return new \WP_Error(
						'stonewright_direction_verification_failed',
						__( 'The stored design direction does not match the contract that was written.', 'stonewright' ),
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
						'versioned'              => (bool) $result['versioned'],
						'contract_hash'          => $hash,
						'previous_contract_hash' => (string) $result['hash_before'],
						'before_sha256'          => (string) $result['hash_before'],
						'after_sha256'           => $hash,
						'operation_class'        => 'design_direction.save',
						'resource_type'          => 'design_direction',
						'verification_status'    => 'verified',
						'effect_verified'        => true,
					]
				);
			}
		);
	}
}
