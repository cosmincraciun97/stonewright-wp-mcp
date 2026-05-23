<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\System;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\CompanionClient;

/**
 * Ability: stonewright/system-run-wpcli
 *
 * Runs a WP-CLI command via the Node companion bridge (no PHP exec/proc_open).
 * In production-safe mode only read-only subcommands are permitted.
 */
final class RunWpCli extends AbilityKernel {

	/**
	 * Subcommands that are safe to run in production-safe mode.
	 * Write operations (update, delete, flush, activate, etc.) are not included.
	 *
	 * @var list<string>
	 */
	private const READONLY_SUBCOMMANDS = [
		'get', 'list', 'status', 'check', 'check-update',
		'verify-checksums', 'pluck', 'size', 'type',
		'is-installed', 'url', 'tables', 'search',
	];

	public function name(): string { return 'stonewright/system-run-wpcli'; }
	public function label(): string { return 'Run WP-CLI command'; }
	public function category(): string { return 'system'; }

	public function description(): string {
		return 'Run a WP-CLI command via the companion bridge (no PHP exec). Commands are validated against a hard allowlist. In production-safe mode only read-only subcommands (get, list, status, check, verify-checksums, …) are permitted.';
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'stdout', 'stderr', 'exit_code' ],
			'properties' => [
				'stdout'    => [ 'type' => 'string', 'description' => 'Standard output from WP-CLI.' ],
				'stderr'    => [ 'type' => 'string', 'description' => 'Standard error output from WP-CLI.' ],
				'exit_code' => [ 'type' => 'integer', 'description' => 'Exit code (0 = success).' ],
			],
		];
	}

	public function input_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'command' ],
			'properties' => [
				'command' => [
					'type'        => 'string',
					'description' => 'WP-CLI command name, e.g. "option", "plugin", "cache".',
				],
				'args'    => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Arguments after the command, e.g. ["get", "siteurl"].',
					'default'     => [],
				],
				'cwd'     => [
					'type'        => 'string',
					'description' => 'WordPress root directory (optional; companion defaults to its own cwd).',
				],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): mixed {
		$mode     = (string) get_option( 'stonewright_mode', 'development' );
		$command  = sanitize_text_field( (string) ( $args['command'] ?? '' ) );
		$cli_args = array_map( 'strval', (array) ( $args['args'] ?? [] ) );
		$cwd      = isset( $args['cwd'] ) ? sanitize_text_field( (string) $args['cwd'] ) : null;

		if ( '' === $command ) {
			return new \WP_Error( 'stonewright_invalid_input', __( 'command is required.', 'stonewright' ) );
		}

		// In production-safe mode restrict to read-only subcommands only.
		if ( 'production-safe' === $mode ) {
			$subcommand = $cli_args[0] ?? '';
			if ( ! in_array( $subcommand, self::READONLY_SUBCOMMANDS, true ) ) {
				return new \WP_Error(
					'stonewright_production_safe',
					sprintf(
						/* translators: 1: subcommand attempted, 2: comma-separated list of allowed subcommands */
						__( 'WP-CLI subcommand "%1$s" is not allowed in production-safe mode. Permitted read-only subcommands: %2$s', 'stonewright' ),
						$subcommand,
						implode( ', ', self::READONLY_SUBCOMMANDS )
					)
				);
			}
		}

		$payload = [ 'command' => $command, 'args' => $cli_args ];
		if ( null !== $cwd ) {
			$payload['cwd'] = $cwd;
		}

		$result = CompanionClient::post( '/wpcli', $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}
}
