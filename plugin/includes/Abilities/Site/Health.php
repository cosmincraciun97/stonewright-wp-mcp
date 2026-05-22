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
final class Health extends AbilityKernel {

	public function name(): string {
		return 'stonewright/site-health';
	}

	public function label(): string {
		return __( 'Site health', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns a summary of WordPress site-health tests for this installation.', 'stonewright' );
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

		$health = \WP_Site_Health::get_instance();
		$tests  = $health->get_tests();
		$out    = [];

		foreach ( $tests['direct'] as $test_name => $test ) {
			if ( empty( $test['test'] ) || ! is_callable( $test['test'] ) ) {
				continue;
			}
			$result = call_user_func( $test['test'] );
			if ( ! is_array( $result ) ) {
				continue;
			}
			$out[] = [
				'name'   => $test_name,
				'status' => (string) ( $result['status'] ?? 'unknown' ),
				'label'  => (string) ( $result['label'] ?? '' ),
			];
		}

		return [ 'tests' => $out ];
	}
}
