<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\System;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class InstructionsGet extends AbilityKernel {

	public function name(): string {
		return 'stonewright/system-instructions-get';
	}

	public function label(): string {
		return __( 'Get custom instructions', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns the current Stonewright custom instructions (system prompt prefix).', 'stonewright' );
	}

	public function category(): string {
		return 'system';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'text'    => [ 'type' => 'string' ],
				'enabled' => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'text', 'enabled' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array {
		return [
			'text'    => (string) get_option( 'stonewright_custom_instructions', '' ),
			'enabled' => (bool) get_option( 'stonewright_custom_instructions_enabled', true ),
		];
	}
}
