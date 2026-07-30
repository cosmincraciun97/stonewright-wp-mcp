<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Site;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class Capabilities extends AbilityKernel {

	public function name(): string {
		return 'stonewright/site-capabilities';
	}

	public function label(): string {
		return __( 'Stonewright capabilities', 'stonewright' );
	}

	public function description(): string {
		return __( 'Reports which Stonewright abilities and integrations are available on this site.', 'stonewright' );
	}

	public function category(): string {
		return 'site';
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'abilities'    => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'name'     => [ 'type' => 'string' ],
							'category' => [ 'type' => 'string' ],
						],
					],
				],
				'integrations' => [
					'type'       => 'object',
					'properties' => [
						'elementor_v3' => [ 'type' => 'boolean' ],
						'elementor_v4' => [ 'type' => 'boolean' ],
						'fse'          => [ 'type' => 'boolean' ],
					],
				],
				'mode'         => [ 'type' => 'string' ],
				'feature_flags'=> [ 'type' => 'object' ],
			],
			'required'   => [ 'abilities', 'integrations', 'mode' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array {
		$abilities = [];
		foreach ( AbilityRegistry::list() as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			/** @var \Stonewright\WpMcp\Abilities\Ability $ability */
			$ability = new $class();
			$abilities[] = [
				'name'     => $ability->name(),
				'category' => $ability->category(),
			];
		}

		return [
			'abilities'    => $abilities,
			'integrations' => [
				'elementor_v3' => defined( 'ELEMENTOR_VERSION' ),
				'elementor_v4' => defined( 'ELEMENTOR_VERSION' ) && version_compare( (string) ELEMENTOR_VERSION, '4.0.0', '>=' ),
				'fse'          => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
			],
			'mode'          => (string) get_option( 'stonewright_mode', 'development' ),
			'feature_flags' => (array) get_option( 'stonewright_feature_flags', [] ),
		];
	}
}
