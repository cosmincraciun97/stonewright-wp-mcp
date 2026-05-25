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
		$result = CompanionClient::post( $endpoint, $body );
		if ( $result instanceof \WP_Error && self::is_companion_unavailable( $result ) ) {
			return self::unavailable_response( $endpoint, $body, $result );
		}
		return $result;
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
				'companion_url' => [ 'type' => 'string' ],
				'recommended_fallbacks' => [ 'type' => 'array' ],
				'setup_hint'  => [ 'type' => 'string' ],
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

	private static function is_companion_unavailable( \WP_Error $error ): bool {
		return in_array(
			$error->get_error_code(),
			[
				'http_request_failed',
				'connect_failed',
			],
			true
		);
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private static function unavailable_response( string $endpoint, array $body, \WP_Error $error ): array {
		$base = rtrim( (string) get_option( 'stonewright_companion_url', 'http://127.0.0.1:8765' ), '/' );
		$command = [];
		if ( isset( $body['command'] ) && is_array( $body['command'] ) ) {
			$command = array_values( array_map( 'strval', $body['command'] ) );
		} elseif ( '/wp-cli/status' === $endpoint ) {
			$command = [ 'cli', 'info', '--format=json' ];
		} elseif ( '/wp-cli/discover' === $endpoint ) {
			$command = [ 'cli', 'cmd-dump' ];
		}

		return [
			'ok'                    => false,
			'available'             => false,
			'command'               => $command,
			'cwd'                   => '',
			'stdout'                => '',
			'stderr'                => '',
			'exit_code'             => 1,
			'duration_ms'           => 0,
			'error'                 => $error->get_error_message(),
			'companion_url'         => $base,
			'recommended_fallbacks' => [
				'companion_wp_cli_status',
				'companion_wp_cli_discover',
				'companion_wp_cli_run',
				'If the MCP client exposes companion_wp_cli_status / companion_wp_cli_run, use those direct companion tools.',
				'If direct companion WP-CLI tools are unavailable, use normal Stonewright REST abilities for page/content/template writes.',
				'Do not use sandbox files or arbitrary REST writes just to set page template or basic Elementor metadata.',
			],
			'setup_hint'            => 'Start the companion HTTP bridge with PORT=8765 and matching COMPANION_BEARER_TOKEN, or set stonewright_companion_url to the actual companion port.',
		];
	}
}
