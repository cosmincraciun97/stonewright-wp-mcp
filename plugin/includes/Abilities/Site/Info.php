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
final class Info extends AbilityKernel {

	public function name(): string {
		return 'stonewright/site-info';
	}

	public function label(): string {
		return __( 'Site information', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns basic descriptive metadata about this WordPress site.', 'stonewright' );
	}

	public function category(): string {
		return 'site';
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'name'         => [ 'type' => 'string' ],
				'description'  => [ 'type' => 'string' ],
				'url'          => [ 'type' => 'string' ],
				'admin_url'    => [ 'type' => 'string' ],
				'language'     => [ 'type' => 'string' ],
				'timezone'     => [ 'type' => 'string' ],
				'multisite'    => [ 'type' => 'boolean' ],
				'wp_version'   => [ 'type' => 'string' ],
				'php_version'  => [ 'type' => 'string' ],
				'stonewright'  => [ 'type' => 'string' ],
				'active_theme' => [ 'type' => 'string' ],
			],
			'required'   => [ 'name', 'url', 'wp_version' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array {
		$theme = wp_get_theme();
		return [
			'name'         => (string) get_bloginfo( 'name' ),
			'description'  => (string) get_bloginfo( 'description' ),
			'url'          => home_url(),
			'admin_url'    => admin_url(),
			'language'     => determine_locale(),
			'timezone'     => wp_timezone_string(),
			'multisite'    => is_multisite(),
			'wp_version'   => (string) get_bloginfo( 'version' ),
			'php_version'  => PHP_VERSION,
			'stonewright'  => STONEWRIGHT_VERSION,
			'active_theme' => $theme ? $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) : '',
		];
	}
}
