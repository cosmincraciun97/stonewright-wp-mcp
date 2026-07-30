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
final class Environment extends AbilityKernel {

	public function name(): string {
		return 'stonewright/site-environment';
	}

	public function label(): string {
		return __( 'Site environment', 'stonewright' );
	}

	public function description(): string {
		return __( 'Reports the runtime environment (memory limit, debug flag, environment type).', 'stonewright' );
	}

	public function category(): string {
		return 'site';
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'environment_type' => [ 'type' => 'string' ],
				'memory_limit'     => [ 'type' => 'string' ],
				'max_upload_size'  => [ 'type' => 'integer' ],
				'wp_debug'         => [ 'type' => 'boolean' ],
				'wp_cron'          => [ 'type' => 'boolean' ],
				'is_ssl'           => [ 'type' => 'boolean' ],
				'rest_prefix'      => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array {
		return [
			'environment_type' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'memory_limit'     => (string) ini_get( 'memory_limit' ),
			'max_upload_size'  => (int) wp_max_upload_size(),
			'wp_debug'         => defined( 'WP_DEBUG' ) && constant( 'WP_DEBUG' ),
			'wp_cron'          => ! ( defined( 'DISABLE_WP_CRON' ) && constant( 'DISABLE_WP_CRON' ) ),
			'is_ssl'           => is_ssl(),
			'rest_prefix'      => rest_get_url_prefix(),
		];
	}
}
