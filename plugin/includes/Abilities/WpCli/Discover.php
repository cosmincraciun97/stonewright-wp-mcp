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
		return __( 'Runs wp cli cmd-dump through the companion. Defaults to summary mode so agents can discover WordPress, Elementor, Gutenberg, ACF, CPT UI, and plugin-specific command paths without loading the full command tree.', 'stonewright' );
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array_merge(
				$this->common_input_properties(),
				[
					'responseMode'  => [
						'type'        => 'string',
						'enum'        => [ 'summary', 'full' ],
						'default'     => 'summary',
						'description' => 'Use summary for compact command paths; use full only when the raw cmd-dump tree is required.',
					],
					'commandFilter' => [
						'type'        => 'array',
						'maxItems'    => 20,
						'items'       => [ 'type' => 'string' ],
						'description' => 'Optional lowercase/partial command path filters, e.g. ["acf","cpt","post","term","option"].',
					],
					'maxCommands'   => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 500,
						'default'     => 80,
						'description' => 'Maximum command paths returned in summary mode.',
					],
				]
			),
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                     => [ 'type' => 'boolean' ],
				'available'              => [ 'type' => 'boolean' ],
				'command'                => [ 'type' => 'array' ],
				'cwd'                    => [ 'type' => 'string' ],
				'stdout'                 => [ 'type' => 'string' ],
				'stderr'                 => [ 'type' => 'string' ],
				'exit_code'              => [ 'type' => 'integer' ],
				'duration_ms'            => [ 'type' => 'integer' ],
				'parsed_json'            => [ 'type' => [ 'object', 'array', 'null' ] ],
				'stdout_bytes'           => [ 'type' => 'integer' ],
				'stderr_bytes'           => [ 'type' => 'integer' ],
				'command_count'          => [ 'type' => 'integer' ],
				'returned_command_count' => [ 'type' => 'integer' ],
				'truncated'              => [ 'type' => 'boolean' ],
				'command_paths'          => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'root_commands'          => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'command_filter'         => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'error'                  => [ 'type' => 'string' ],
				'companion_url'          => [ 'type' => 'string' ],
				'recommended_fallbacks'  => [ 'type' => 'array' ],
				'setup_hint'             => [ 'type' => 'string' ],
			],
			'required'   => [ 'ok', 'available', 'exit_code' ],
		];
	}

	public function execute( array $args ): array|\WP_Error {
		if ( ! isset( $args['responseMode'] ) ) {
			$args['responseMode'] = 'summary';
		}
		if ( ! isset( $args['maxCommands'] ) ) {
			$args['maxCommands'] = 80;
		}
		return $this->companion_post( '/wp-cli/discover', $args );
	}
}
