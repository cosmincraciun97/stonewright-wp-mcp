<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Site;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class Ping extends AbilityKernel {

	public function name(): string {
		return 'stonewright/ping';
	}

	public function label(): string {
		return __( 'Stonewright ping', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns a static response confirming the MCP server is reachable.', 'stonewright' );
	}

	public function category(): string {
		return 'site';
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'      => [ 'type' => 'boolean' ],
				'message' => [ 'type' => 'string' ],
				'time'    => [ 'type' => 'string' ],
				'version' => [ 'type' => 'string' ],
			],
			'required'   => [ 'ok', 'message', 'time', 'version' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array {
		return [
			'ok'      => true,
			'message' => 'pong',
			'time'    => gmdate( 'c' ),
			'version' => STONEWRIGHT_VERSION,
		];
	}
}
