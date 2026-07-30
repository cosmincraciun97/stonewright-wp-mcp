<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\BrandKits;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\DesignTokens\BrandKit;
use Stonewright\WpMcp\Security\Permissions;

/**
 * @stonewright-status stable
 */
final class ListBrandKits extends AbilityKernel {

	public function name(): string {
		return 'stonewright/brand-kit-list';
	}

	public function label(): string {
		return __( 'List brand kits', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists bundled Stonewright brand kits (palette, fonts, radius, spacing).', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'search' => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'brand_kits' => [ 'type' => 'array' ],
				'count'      => [ 'type' => 'integer' ],
			],
			'required'   => [ 'brand_kits', 'count' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$search = isset( $args['search'] ) ? (string) $args['search'] : '';
		$list   = BrandKit::list( $search );

		return [
			'brand_kits' => $list,
			'count'      => count( $list ),
		];
	}
}
