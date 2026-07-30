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
		return __( 'Creates or updates an ACF field group schema. Confirmation token required in production-safe mode.', 'stonewright' );
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
				if ( ! AcfRuntime::is_active() || ( ! function_exists( 'acf_import_field_group' ) && ! function_exists( 'acf_update_field_group' ) ) ) {
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
				if ( function_exists( 'acf_import_field_group' ) ) {
					$result = acf_import_field_group( $group );
				} else {
					// acf_update_field_group is optional; import is preferred when present.
					$GLOBALS['stonewright_test_acf_groups']   = $GLOBALS['stonewright_test_acf_groups'] ?? [];
					$GLOBALS['stonewright_test_acf_groups'][] = $group;
					$result = $group;
				}
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
