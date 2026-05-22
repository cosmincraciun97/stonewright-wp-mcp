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
final class SandboxActivate extends AbilityKernel {

	use SandboxGuards;

	public function name(): string {
		return 'stonewright/sandbox-activate';
	}

	public function label(): string {
		return __( 'Activate sandbox file', 'stonewright' );
	}

	public function description(): string {
		return __( 'Runs StaticGuard on a sandbox draft and, if clean, copies it to mu-plugins so it is loaded on every request. Destructive — requires confirmation_token in production-safe mode.', 'stonewright' );
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
				'confirmation_token' => [
					'type' => 'string',
				],
			],
			'required'             => [ 'name' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'   => [ 'type' => 'boolean' ],
				'name' => [ 'type' => 'string' ],
			],
			'required'   => [ 'ok', 'name' ],
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

				$token_error = $this->production_safe_token_error( $a, [ 'name' => $a['name'] ] );
				if ( null !== $token_error ) {
					return $token_error;
				}

				$result = SandboxFiles::activate( $a['name'] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return $this->ok( [ 'name' => $a['name'] ] );
			}
		);
	}
}
