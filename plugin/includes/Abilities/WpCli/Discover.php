<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WpCli;

/**
 * Discovers installed WP-CLI command metadata.
 *
 * @stonewright-status stable
 */
final class Discover extends WpCliAbility {

	public function name(): string {
		return 'stonewright/wp-cli-discover';
	}

	public function label(): string {
		return __( 'Discover WP-CLI commands', 'stonewright' );
	}

	public function description(): string {
		return __( 'Runs wp cli cmd-dump through the companion so the agent can discover WordPress, Elementor, Gutenberg, ACF, CPT UI, and plugin-specific WP-CLI commands before choosing a command.', 'stonewright' );
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
		return $this->companion_post( '/wp-cli/discover', $args );
	}
}
