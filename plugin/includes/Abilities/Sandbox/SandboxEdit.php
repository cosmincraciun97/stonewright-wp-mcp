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
final class SandboxEdit extends AbilityKernel {

	use SandboxGuards;

	public function name(): string {
		return 'stonewright/sandbox-edit';
	}

	public function label(): string {
		return __( 'Edit sandbox file (exact-string replace)', 'stonewright' );
	}

	public function description(): string {
		return __( 'Performs an exact-string replacement within a sandbox draft file. old_string must appear exactly once. Destructive — requires confirmation_token in production-safe mode.', 'stonewright' );
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
				'old_string'         => [
					'type' => 'string',
				],
				'new_string'         => [
					'type' => 'string',
				],
				'confirmation_token' => [
					'type' => 'string',
				],
			],
			'required'             => [ 'name', 'old_string', 'new_string' ],
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

	protected function audit_redacted_keys(): array {
		return [ 'old_string', 'new_string' ];
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
					[
						'name'       => $a['name'],
						'old_string' => $a['old_string'],
						'new_string' => $a['new_string'],
					]
				);
				if ( null !== $token_error ) {
					return $token_error;
				}

				$result = SandboxFiles::edit( $a['name'], $a['old_string'], $a['new_string'] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return $this->ok( [ 'name' => $a['name'] ] );
			}
		);
	}
}
