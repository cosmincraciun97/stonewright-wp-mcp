<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Sandbox;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class SandboxToggle extends AbilityKernel {

	use SandboxGuards;

	public function name(): string {
		return 'stonewright/sandbox-toggle';
	}

	public function label(): string {
		return __( 'Toggle sandbox file (disable / enable)', 'stonewright' );
	}

	public function description(): string {
		return __( 'Disables or re-enables a mu-plugins twin by adding or removing the .disabled suffix without removing the file. Destructive — requires confirmation_token in production-safe mode.', 'stonewright' );
	}

	public function category(): string {
		return 'sandbox';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'name'               => [
					'type'    => 'string',
					'pattern' => '^[a-z0-9_-]+\\.php$',
				],
				'action'             => [
					'type' => 'string',
					'enum' => [ 'disable', 'enable' ],
				],
				'confirmation_token' => [
					'type' => 'string',
				],
			],
			'required'             => [ 'name', 'action' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'     => [ 'type' => 'boolean' ],
				'name'   => [ 'type' => 'string' ],
				'action' => [ 'type' => 'string' ],
			],
			'required'   => [ 'ok', 'name', 'action' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_sandbox();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $a ): array|\WP_Error {
				$file_mods_error = $this->file_mods_disabled_error();
				if ( null !== $file_mods_error ) {
					return $file_mods_error;
				}

				$token_error = $this->production_safe_token_error(
					$a,
					[ 'name' => $a['name'], 'action' => $a['action'] ]
				);
				if ( null !== $token_error ) {
					return $token_error;
				}

				if ( 'disable' === $a['action'] ) {
					$result = SandboxFiles::disable( $a['name'] );
				} else {
					$result = SandboxFiles::enable( $a['name'] );
				}

				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return $this->ok( [ 'name' => $a['name'], 'action' => $a['action'] ] );
			}
		);
	}
}
