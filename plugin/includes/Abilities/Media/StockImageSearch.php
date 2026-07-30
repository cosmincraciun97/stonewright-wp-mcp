<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Media;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Media\StockImageClient;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Search stock photo providers (Openverse by default).
 *
 * @stonewright-status stable
 */
final class StockImageSearch extends AbilityKernel {

	public function name(): string {
		return 'stonewright/stock-image-search';
	}

	public function label(): string {
		return __( 'Search stock images', 'stonewright' );
	}

	public function description(): string {
		return __( 'Search Creative Commons stock photos via Openverse (no API key). Unsplash/Pexels only when site API keys are configured.', 'stonewright' );
	}

	public function category(): string {
		return 'media';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'query' ],
			'properties'           => [
				'query'    => [
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 200,
					'description' => 'Search terms, e.g. "timber house exterior daylight".',
				],
				'provider' => [
					'type'        => 'string',
					'enum'        => [ 'openverse', 'unsplash', 'pexels' ],
					'default'     => 'openverse',
					'description' => 'Stock provider. Default openverse (always available).',
				],
				'page'     => [
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
					'maximum' => 50,
				],
				'per_page' => [
					'type'    => 'integer',
					'default' => 12,
					'minimum' => 1,
					'maximum' => 30,
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                  => [ 'type' => 'boolean' ],
				'provider'            => [ 'type' => 'string' ],
				'query'               => [ 'type' => 'string' ],
				'count'               => [ 'type' => 'integer' ],
				'page'                => [ 'type' => 'integer' ],
				'per_page'            => [ 'type' => 'integer' ],
				'available_providers' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'results'             => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'          => [ 'type' => 'string' ],
							'provider'    => [ 'type' => 'string' ],
							'title'       => [ 'type' => 'string' ],
							'url'         => [ 'type' => 'string' ],
							'thumbnail'   => [ 'type' => 'string' ],
							'width'       => [ 'type' => 'integer' ],
							'height'      => [ 'type' => 'integer' ],
							'creator'     => [ 'type' => 'string' ],
							'creator_url' => [ 'type' => 'string' ],
							'license'     => [ 'type' => 'string' ],
							'license_url' => [ 'type' => 'string' ],
							'landing_url' => [ 'type' => 'string' ],
							'attribution' => [ 'type' => 'string' ],
						],
						'additionalProperties' => true,
					],
				],
			],
			'required'   => [ 'ok', 'provider', 'query', 'count', 'results', 'available_providers' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$query    = isset( $args['query'] ) ? (string) $args['query'] : '';
		$provider = isset( $args['provider'] ) ? (string) $args['provider'] : StockImageClient::PROVIDER_OPENVERSE;
		$page     = isset( $args['page'] ) ? (int) $args['page'] : 1;
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 12;

		$result = StockImageClient::search( $query, $provider, $page, $per_page );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['available_providers'] = StockImageClient::available_providers();

		return $result;
	}
}
