<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WpCli;

/**
 * Checks companion WP-CLI availability.
 *
 * @stonewright-status stable
 */
final class Status extends WpCliAbility {

	public function name(): string {
		return 'stonewright/wp-cli-status';
	}

	public function label(): string {
		return __( 'WP-CLI status', 'stonewright' );
	}

	public function description(): string {
		return __( 'Checks whether the companion can run WP-CLI and returns wp cli info diagnostics.', 'stonewright' );
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $this->common_input_properties(),
		];
	}

	public function output_schema(): array {
		return $this->wp_cli_output_schema();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->companion_post( '/wp-cli/status', $args );
	}
}
