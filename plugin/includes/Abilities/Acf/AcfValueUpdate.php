<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Acf;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status stable */
final class AcfValueUpdate extends AbilityKernel {

	public function name(): string {
		return 'stonewright/acf-value-update';
	}

	public function label(): string {
		return __( 'ACF: Update value', 'stonewright' );
	}

	public function description(): string {
		return __( 'Idempotently updates one ACF field on a post, snapshots only on a real change, and verifies raw readback.', 'stonewright' );
	}

	public function category(): string {
		return 'acf';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id', 'selector', 'value' ],
			'properties'           => [
				'confirmation_token' => [ 'type' => 'string' ],
				'post_id'            => [ 'type' => 'integer', 'minimum' => 1 ],
				'selector'           => [ 'type' => 'string', 'minLength' => 1 ],
				'value'              => [
					'description' => 'Stored field value. This property must be present; omitting it is not a deletion. Pass value:null to store null explicitly.',
					'type'        => [ 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ],
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'required'             => [ 'post_id', 'selector', 'ok', 'changed', 'execution_status', 'verification_status', 'effect_verified' ],
			'properties'           => [
				'post_id'             => [ 'type' => 'integer' ],
				'selector'            => [ 'type' => 'string' ],
				'ok'                  => [ 'type' => 'boolean' ],
				'value'               => [
					'description' => 'Raw stored value from get_field(..., false).',
				],
				'changed'             => [ 'type' => 'boolean' ],
				'execution_status'    => [
					'type' => 'string',
					'enum' => [ 'unchanged', 'applied', 'failed' ],
				],
				'verification_status' => [
					'type' => 'string',
					'enum' => [ 'verified', 'failed', 'limited' ],
				],
				'effect_verified'     => [ 'type' => 'boolean' ],
				'error_code'          => [ 'type' => 'string' ],
				'error_message'       => [ 'type' => 'string' ],
				'limitation'          => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			static function ( array $args ) {
				if ( ! AcfRuntime::is_active() || ! function_exists( 'update_field' ) || ! function_exists( 'get_field' ) ) {
					return new \WP_Error(
						'stonewright_plugin_missing',
						__( 'ACF is not active on this site.', 'stonewright' ),
						[ 'status' => 409 ]
					);
				}

				$post_id  = (int) ( $args['post_id'] ?? 0 );
				$selector = (string) ( $args['selector'] ?? '' );
				if ( $post_id < 1 || '' === $selector ) {
					return new \WP_Error(
						'stonewright_acf_invalid_args',
						__( 'post_id and selector are required.', 'stonewright' ),
						[ 'status' => 400 ]
					);
				}

				if ( ! Permissions::can_edit_post( $post_id ) ) {
					return new \WP_Error(
						'stonewright_permission_denied',
						__( 'Current user cannot edit this post.', 'stonewright' ),
						[ 'status' => 403 ]
					);
				}

				if ( ! array_key_exists( 'value', $args ) ) {
					return new \WP_Error(
						'stonewright_acf_value_required',
						__( 'value is required; omitting it is not a deletion. Pass value:null to store null explicitly.', 'stonewright' ),
						[ 'status' => 400 ]
					);
				}

				$field = AcfRuntime::resolve_field( $selector, $post_id );
				if ( $field instanceof \WP_Error ) {
					return $field;
				}

				$expected = $args['value'];
				$valid    = AcfRuntime::validate_value( $expected, $field );
				if ( $valid instanceof \WP_Error ) {
					return $valid;
				}

				$field_key   = AcfRuntime::field_key( $field );
				$current     = AcfRuntime::read_raw( $field_key, $post_id );
				$current_ref = AcfRuntime::read_reference( $field, $post_id );
				$comparable  = AcfRuntime::is_comparable( $field );

				if ( ! AcfRuntime::references_valid( $expected, $field ) ) {
					return new \WP_Error(
						'stonewright_acf_invalid_reference',
						__( 'ACF reference value points at a missing post or user.', 'stonewright' ),
						[ 'status' => 400 ]
					);
				}

				$value_same = $comparable && AcfRuntime::values_equal( $expected, $current, $field );
				$ref_same   = $field_key === $current_ref;
				if ( $value_same && $ref_same ) {
					return AcfRuntime::result(
						$post_id,
						$selector,
						$current,
						[
							'ok'                  => true,
							'changed'             => false,
							'execution_status'    => 'unchanged',
							'verification_status' => 'verified',
							'effect_verified'     => true,
						]
					);
				}

				$snapshot_id = Backup::snapshot_post( $post_id );
				if ( '' === $snapshot_id ) {
					return new \WP_Error(
						'stonewright_backup_failed',
						__( 'Snapshot failed before the ACF write.', 'stonewright' ),
						[ 'status' => 500 ]
					);
				}

				update_field( $field_key, $expected, $post_id );
				AcfRuntime::flush_value_cache( $post_id, $field );
				$readback     = AcfRuntime::read_raw( $field_key, $post_id );
				$readback_ref = AcfRuntime::read_reference( $field, $post_id );
				$changed      = ! AcfRuntime::values_equal( $current, $readback, $field )
					|| $current_ref !== $readback_ref;

				if ( ! $comparable ) {
					return AcfRuntime::result(
						$post_id,
						$selector,
						$readback,
						[
							'ok'                  => true,
							'changed'             => $changed,
							'execution_status'    => $changed ? 'applied' : 'unchanged',
							'verification_status' => 'limited',
							'effect_verified'     => false,
							'limitation'          => sprintf(
								'No comparator for ACF field type "%s"; raw readback is returned without effect verification.',
								AcfRuntime::bound_type( $field )
							),
						]
					);
				}

				$matches = AcfRuntime::values_equal( $expected, $readback, $field )
					&& AcfRuntime::references_valid( $readback, $field )
					&& $field_key === $readback_ref;
				if ( $matches ) {
					return AcfRuntime::result(
						$post_id,
						$selector,
						$readback,
						[
							'ok'                  => true,
							'changed'             => $changed,
							'execution_status'    => $changed ? 'applied' : 'unchanged',
							'verification_status' => 'verified',
							'effect_verified'     => true,
						]
					);
				}

				return AcfRuntime::result(
					$post_id,
					$selector,
					$readback,
					[
						'ok'                  => false,
						'changed'             => $changed,
						'execution_status'    => $changed ? 'applied' : 'failed',
						'verification_status' => 'failed',
						'effect_verified'     => false,
						'error_code'          => 'stonewright_acf_readback_mismatch',
						'error_message'       => 'ACF readback did not match the requested value.',
					]
				);
			}
		);
	}

	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'value' ] );
	}
}
