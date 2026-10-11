<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Acf;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status stable */
final class AcfFieldGroupSave extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/acf-field-group-save';
	}

	public function label(): string {
		return __( 'ACF: Save field group', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates or updates an ACF field group and its fields through ACF\'s own import, and returns an error when ACF cannot import. The field group and field posts are recorded in the change ledger. Confirmation token required in production-safe mode.', 'stonewright' );
	}

	public function category(): string {
		return 'acf';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'group' ],
			'properties'           => [
				'group'              => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'confirmation_token' => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object', 'additionalProperties' => true ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_acf();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$verify = $args;
				unset( $verify['confirmation_token'] );
				$err = $this->confirmation_token_error( $args, $verify );
				if ( null !== $err ) {
					return $err;
				}
				if ( ! AcfRuntime::is_active() ) {
					return new \WP_Error(
						'stonewright_plugin_missing',
						__( 'ACF is not active on this site.', 'stonewright' ),
						[ 'status' => 409 ]
					);
				}
				$group = (array) ( $args['group'] ?? [] );
				if ( '' === (string) ( $group['key'] ?? '' ) ) {
					return new \WP_Error( 'stonewright_acf_group_key_required', 'group.key is required.' );
				}
				// acf_import_field_group saves the group and its fields. Without it nothing is saved, and the call says so.
				$result = AcfRuntime::import_field_group( $group );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				// Persist Stonewright-side contract mirror used by content-model flows.
				$stored = get_option( 'stonewright_acf_field_groups', [] );
				$stored = is_array( $stored ) ? $stored : [];
				$key    = (string) $group['key'];
				$stored[ $key ] = $group;
				update_option( 'stonewright_acf_field_groups', $stored, false );

				return [
					'ok'  => true,
					'key' => $key,
				];
			}
		);
	}
}
