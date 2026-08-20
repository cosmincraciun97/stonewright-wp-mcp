<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Direction\DirectionContract;
use Stonewright\WpMcp\Design\Direction\DirectionPayload;
use Stonewright\WpMcp\Design\Direction\ElementorDirectionCapture;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Ability: stonewright/design-direction-capture
 *
 * Proposes a design direction from evidence an agent already read with the typed
 * Elementor abilities. It never reads Elementor itself, so there is exactly one
 * place where kit evidence is produced and one place where it is interpreted.
 *
 * The default is a preview: the contract comes back, nothing is stored. Storing
 * requires an explicit `save=true`, and even then the result lands as a draft
 * that is never marked ready and never becomes the active direction. Promotion
 * stays a separate, separately gated decision.
 *
 * Preview is gated exactly like the write, because one flag turns it into one.
 * The registry also forces the task context token on this ability for the same
 * reason.
 *
 * @stonewright-status stable
 */
final class DirectionCapture extends AbilityKernel {

	private DesignDirectionService $service;

	public function __construct( ?DesignDirectionService $service = null ) {
		$this->service = $service ?? new DesignDirectionService();
	}

	public function name(): string {
		return 'stonewright/design-direction-capture';
	}

	public function label(): string {
		return __( 'Capture design direction from Elementor', 'stonewright' );
	}

	public function description(): string {
		return __( 'Turns compact Elementor kit evidence into a draft design direction contract with provenance. Previews by default; stores a draft only when save is true.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'confirmation_token' => [ 'type' => 'string' ],
				'evidence' => [
					'type'                 => 'object',
					'description'          => 'Compact kit evidence, as returned by stonewright/elementor-v3-get-kit-globals. Unknown fields are rejected.',
					'additionalProperties' => false,
					'properties'           => [
						'kit_id'      => [
							'type'        => 'integer',
							'description' => 'Elementor kit post id the evidence came from.',
						],
						'kit_title'   => [
							'type'        => 'string',
							'description' => 'Kit title, used as the direction identity name.',
						],
						'colors'      => [
							'type'        => 'array',
							'description' => 'Global color entries with id, title, and color.',
						],
						'typography'  => [
							'type'        => 'array',
							'description' => 'Global typography entries with id, title, and font properties.',
						],
						'breakpoints' => [
							'type'        => 'object',
							'description' => 'Named breakpoint pixel values.',
						],
						'layout'      => [
							'type'        => 'object',
							'description' => 'Kit layout values such as container_width and widget_spacing.',
						],
						'buttons'     => [
							'type'        => 'object',
							'description' => 'Kit button values such as border_radius and background_color.',
						],
					],
					'required'             => [ 'kit_id' ],
				],
				'save'     => [
					'type'        => 'boolean',
					'description' => 'Store the captured contract as a draft. Defaults to false, which previews only.',
				],
				'slug'     => [
					'type'        => 'string',
					'description' => 'Storage slug when saving. Defaults to the kit title.',
				],
			],
			'required'             => [ 'evidence' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                  => [ 'type' => 'boolean' ],
				'saved'               => [ 'type' => 'boolean' ],
				'id'                  => [ 'type' => 'integer' ],
				'slug'                => [ 'type' => 'string' ],
				'status'              => [ 'type' => 'string' ],
				'revision'            => [ 'type' => 'integer' ],
				'contract'            => [ 'type' => 'object' ],
				'contract_hash'       => [ 'type' => 'string' ],
				'mapped'              => [ 'type' => 'object' ],
				'issues'              => [ 'type' => 'array' ],
				'conflicts'           => [ 'type' => 'array' ],
				'unmapped'            => [ 'type' => 'array' ],
				'after_sha256'        => [ 'type' => 'string' ],
				'verification_status' => [ 'type' => 'string' ],
				'operation_class'     => [ 'type' => 'string' ],
				'resource_type'       => [ 'type' => 'string' ],
				'effect_verified'     => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'ok', 'saved', 'id', 'contract', 'contract_hash', 'issues', 'conflicts', 'unmapped', 'effect_verified' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_design();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			function ( array $args ): array|\WP_Error {
				$evidence = is_array( $args['evidence'] ?? null ) ? $args['evidence'] : [];

				$too_large = DirectionPayload::size_error( $evidence, DirectionContract::MAX_CONTRACT_BYTES, 'evidence' );
				if ( null !== $too_large ) {
					return $too_large;
				}

				$captured = ElementorDirectionCapture::from_evidence( $evidence );
				if ( $captured instanceof \WP_Error ) {
					return $captured;
				}

				$contract = $captured['contract'];
				$hash     = DesignDirectionService::hash( $contract );

				$report = [
					'contract'      => $contract,
					'contract_hash' => $hash,
					'mapped'        => $captured['mapped'],
					'issues'        => $captured['issues'],
					'conflicts'     => $captured['conflicts'],
					'unmapped'      => $captured['unmapped'],
				];

				if ( true !== ( $args['save'] ?? false ) ) {
					return $this->ok(
						array_merge(
							$report,
							[
								'saved'            => false,
								'id'               => 0,
								'slug'             => '',
								'status'           => '',
								'revision'         => 0,
								'operation_class'  => 'design_direction.capture_preview',
								'resource_type'    => 'design_direction',
								'effect_verified'  => true,
							]
						)
					);
				}

				$save = [
					'contract'    => $contract,
					'status'      => 'draft',
					'source_type' => 'capture',
					'source_refs' => [ 'kit' => 'kit:' . (int) $evidence['kit_id'] ],
				];

				if ( isset( $args['slug'] ) && is_string( $args['slug'] ) ) {
					$save['slug'] = $args['slug'];
				}

				$result = $this->service->save( $save, get_current_user_id() );
				if ( $result instanceof \WP_Error ) {
					return $result;
				}

				$id     = (int) $result['id'];
				$stored = $this->service->get( $id );

				if ( null === $stored || (string) ( $stored['contract_hash'] ?? '' ) !== $hash ) {
					return new \WP_Error(
						'stonewright_direction_verification_failed',
						__( 'The stored captured direction does not match the contract that was written.', 'stonewright' ),
						[
							'status'              => 500,
							'direction_id'        => $id,
							'verification_status' => 'failed',
							'after_sha256'        => $hash,
						]
					);
				}

				return $this->ok(
					array_merge(
						$report,
						[
							'saved'               => true,
							'id'                  => $id,
							'slug'                => (string) $result['slug'],
							'status'              => (string) $result['status'],
							'revision'            => (int) $result['revision'],
							'after_sha256'        => $hash,
							'verification_status' => 'verified',
							'operation_class'     => 'design_direction.capture',
							'resource_type'       => 'design_direction',
							'effect_verified'     => true,
						]
					)
				);
			}
		);
	}
}
