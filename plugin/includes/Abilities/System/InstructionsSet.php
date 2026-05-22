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
final class InstructionsSet extends AbilityKernel {

	public function name(): string {
		return 'stonewright/system-instructions-set';
	}

	public function label(): string {
		return __( 'Set custom instructions', 'stonewright' );
	}

	public function description(): string {
		return __( 'Replaces the Stonewright custom instructions.', 'stonewright' );
	}

	public function category(): string {
		return 'system';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'text'    => [
					'type'      => 'string',
					'maxLength' => 4000,
				],
				'enabled' => [
					'type' => 'boolean',
				],
			],
			'required'             => [ 'text' ],
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
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $a ): array {
				$text = mb_substr( $a['text'], 0, 4000 );
				update_option( 'stonewright_custom_instructions', $text );

				if ( array_key_exists( 'enabled', $a ) ) {
					update_option( 'stonewright_custom_instructions_enabled', (bool) $a['enabled'] );
				}

				return [
					'text'    => (string) get_option( 'stonewright_custom_instructions', '' ),
					'enabled' => (bool) get_option( 'stonewright_custom_instructions_enabled', true ),
				];
			}
		);
	}
}
