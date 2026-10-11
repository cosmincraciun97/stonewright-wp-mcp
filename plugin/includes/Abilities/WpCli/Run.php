<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WpCli;

use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Runs tokenized WP-CLI commands through the local companion.
 *
 * @stonewright-status stable
 */
final class Run extends WpCliAbility {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/wp-cli-run';
	}

	public function label(): string {
		return __( 'Run WP-CLI command', 'stonewright' );
	}

	public function description(): string {
		return __( 'Runs a tokenized WP-CLI command through the companion. Supports WordPress content, option, cache, rewrite and installed plugin commands. Refuses eval, shell, package, config, plugin and theme install/update/delete/activate, db, user and role changes, core download/update, cron event run, and search-replace without --dry-run. Requires edit_plugins and manage_options (a super admin on multisite). Use stonewright/php-execute for PHP runtime snippets.', 'stonewright' );
	}

	/**
	 * WP-CLI commands can download from and call hosts outside the site.
	 *
	 * @return array<string, mixed>
	 */
	public function meta(): array {
		return [
			'annotations' => [
				'openWorldHint' => true,
			],
		];
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'command' ],
			'properties'           => array_merge(
				$this->common_input_properties(),
				[
					'command'   => [
						'type'        => 'array',
						'minItems'    => 1,
						'items'       => [ 'type' => 'string' ],
						'description' => 'WP-CLI argv tokens after wp, e.g. ["post","create","--post_type=page"].',
					],
					'parseJson' => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Parse stdout as JSON when the command uses --format=json.',
					],
					'confirmation_token' => [
						'type'        => 'string',
						'description' => 'Required in production-safe mode because WP-CLI commands may write WordPress state.',
					],
				]
			),
		];
	}

	public function output_schema(): array {
		return $this->wp_cli_output_schema();
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_run_code();
	}
	public function execute( array $args ): array|\WP_Error {
		if ( empty( $args['command'] ) || ! is_array( $args['command'] ) ) {
			return $this->error( 'wp_cli_invalid_command', __( 'A non-empty command argv array is required.', 'stonewright' ), [ 'status' => 400 ] );
		}

		$url_error = $this->validate_url_arg( $args );
		if ( $url_error instanceof \WP_Error ) {
			return $url_error;
		}

		return $this->audit(
			$args,
			function ( array $a ): array|\WP_Error {
				$request_args = array_filter(
					$a,
					static fn( string $key ): bool => 'confirmation_token' !== $key,
					ARRAY_FILTER_USE_KEY
				);
				$policy_error = $this->command_policy_error( [ $a['command'] ] );
				if ( null !== $policy_error ) {
					return $policy_error;
				}

				$token_error = $this->confirmation_token_error( $a, $request_args );
				if ( null !== $token_error ) {
					return $token_error;
				}

				return $this->companion_post( '/wp-cli/run', $request_args );
			}
		);
	}
}
