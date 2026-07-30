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
final class SandboxRead extends AbilityKernel {

	public function name(): string {
		return 'stonewright/sandbox-read';
	}

	public function label(): string {
		return __( 'Read sandbox file', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns the contents of a sandbox draft file.', 'stonewright' );
	}

	public function category(): string {
		return 'sandbox';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'name' => [
					'type'    => 'string',
					'pattern' => '^[a-z0-9_-]+\\.php$',
				],
			],
			'required'             => [ 'name' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'       => [ 'type' => 'boolean' ],
				'name'     => [ 'type' => 'string' ],
				'contents' => [ 'type' => 'string' ],
			],
			'required'   => [ 'ok', 'name', 'contents' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_manage_sandbox();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $a ): array|\WP_Error {
				$contents = SandboxFiles::read( $a['name'] );
				if ( is_wp_error( $contents ) ) {
					return $contents;
				}
				return $this->ok( [ 'name' => $a['name'], 'contents' => $contents ] );
			}
		);
	}
}
