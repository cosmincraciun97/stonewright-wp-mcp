<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Site;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\SiteHealthRunner;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class Health extends AbilityKernel {

	public function name(): string {
		return 'stonewright/site-health';
	}

	public function label(): string {
		return __( 'Site health', 'stonewright' );
	}

	public function description(): string {
		return __( 'Runs the direct WordPress Site Health tests and returns the status and label of each. Use site-health-test for the loopback, update, HTTPS and cache checks.', 'stonewright' );
	}

	public function category(): string {
		return 'site';
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'tests' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'name'   => [ 'type' => 'string' ],
							'status' => [ 'type' => 'string' ],
							'label'  => [ 'type' => 'string' ],
						],
					],
				],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array {
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}

		SiteHealthRunner::load_admin_includes();

		return [ 'tests' => SiteHealthRunner::run_direct_tests( \WP_Site_Health::get_instance() ) ];
	}
}
