<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Seo;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status stable */
final class SeoStatus extends AbilityKernel {

	public function name(): string {
		return 'stonewright/seo-status';
	}

	public function label(): string {
		return __( 'SEO: Status', 'stonewright' );
	}

	public function description(): string {
		return __( 'Reports which SEO plugin is active and a likely sitemap URL.', 'stonewright' );
	}

	public function category(): string {
		return 'seo';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object', 'additionalProperties' => true ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			static function ( array $args ) {
				$plugin = SeoAdapter::detect();
				if ( null === $plugin ) {
					return [
						'active'  => false,
						'plugin'  => null,
						'sitemap' => '/wp-sitemap.xml',
						'note'    => 'No supported SEO plugin detected; core sitemaps may still apply.',
					];
				}
				return [
					'active'  => true,
					'plugin'  => $plugin['id'],
					'label'   => $plugin['label'],
					'sitemap' => $plugin['sitemap'],
				];
			}
		);
	}
}
