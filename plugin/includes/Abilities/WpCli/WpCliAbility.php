<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WpCli;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\CompanionClient;

abstract class WpCliAbility extends AbilityKernel {

	public function category(): string {
		return 'wp-cli';
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function companion_post( string $endpoint, array $body = [] ): array|\WP_Error {
		return CompanionClient::post( $endpoint, $body );
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function wp_cli_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'          => [ 'type' => 'boolean' ],
				'available'   => [ 'type' => 'boolean' ],
				'command'     => [ 'type' => 'array' ],
				'cwd'         => [ 'type' => 'string' ],
				'stdout'      => [ 'type' => 'string' ],
				'stderr'      => [ 'type' => 'string' ],
				'exit_code'   => [ 'type' => 'integer' ],
				'duration_ms' => [ 'type' => 'integer' ],
				'parsed_json' => [ 'type' => [ 'object', 'array', 'null' ] ],
				'error'       => [ 'type' => 'string' ],
			],
			'required'   => [ 'ok', 'available', 'stdout', 'stderr', 'exit_code' ],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function common_input_properties(): array {
		return [
			'cwd'        => [
				'type'        => 'string',
				'description' => 'Optional working directory. The companion validates it against allowed WP roots.',
			],
			'path'       => [
				'type'        => 'string',
				'description' => 'Optional WP-CLI --path value for the target WordPress installation.',
			],
			'url'        => [
				'type'        => 'string',
				'description' => 'Optional WP-CLI --url value for multisite or URL-specific operations.',
			],
			'user'       => [
				'type'        => 'string',
				'description' => 'Optional WP-CLI --user value for commands that need a WordPress user context.',
			],
			'context'    => [
				'type'        => 'string',
				'description' => 'Optional WP-CLI --context value.',
			],
			'timeoutMs'  => [
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Optional timeout in milliseconds.',
			],
		];
	}
}
