<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\System;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Compact catalog for the discover-execute MCP profile.
 *
 * @stonewright-status stable
 */
final class DiscoverAbilities extends AbilityKernel {

	private const DESCRIPTION_CHARS = 240;
	private const DEFAULT_LIMIT     = 200;
	private const MAX_LIMIT         = 500;

	public function name(): string {
		return 'stonewright/discover-abilities';
	}

	public function label(): string {
		return __( 'Discover Stonewright abilities', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns a compact list of registered Stonewright abilities (name, MCP tool name, label, description, category, enabled) without schemas. Use get-ability-info for one ability, then execute-ability to run it.', 'stonewright' );
	}

	public function category(): string {
		return 'system';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'category' => [
					'type'        => 'string',
					'description' => 'Optional category filter such as gutenberg, elementor, or system.',
				],
				'search'   => [
					'type'        => 'string',
					'description' => 'Optional case-insensitive filter matched against name, label, and description.',
				],
				'limit'    => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::MAX_LIMIT,
					'default'     => self::DEFAULT_LIMIT,
					'description' => 'Maximum rows to return. Defaults to 200.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'abilities'  => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'name'          => [ 'type' => 'string' ],
							'mcp_tool_name' => [ 'type' => 'string' ],
							'label'         => [ 'type' => 'string' ],
							'description'   => [ 'type' => 'string' ],
							'category'      => [ 'type' => 'string' ],
							'enabled'       => [ 'type' => 'boolean' ],
						],
					],
				],
				'count'      => [ 'type' => 'integer' ],
				'total'      => [ 'type' => 'integer' ],
				'truncated'  => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'abilities', 'count', 'total', 'truncated' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array {
		$category = isset( $args['category'] ) ? strtolower( trim( (string) $args['category'] ) ) : '';
		$search   = isset( $args['search'] ) ? mb_strtolower( trim( (string) $args['search'] ) ) : '';
		$limit    = isset( $args['limit'] ) && is_int( $args['limit'] ) ? $args['limit'] : self::DEFAULT_LIMIT;
		$limit    = max( 1, min( self::MAX_LIMIT, $limit ) );

		$list = ( new AbilitiesList() )->execute( [] );
		$rows = [];
		foreach ( (array) ( $list['abilities'] ?? [] ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = (string) ( $row['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$compact = [
				'name'          => $name,
				'mcp_tool_name' => (string) ( $row['mcp_tool_name'] ?? AbilityRegistry::mcp_tool_name( $name ) ),
				'label'         => (string) ( $row['label'] ?? '' ),
				'description'   => mb_substr( (string) ( $row['description'] ?? '' ), 0, self::DESCRIPTION_CHARS ),
				'category'      => (string) ( $row['category'] ?? '' ),
				'enabled'       => (bool) ( $row['enabled'] ?? false ),
			];
			if ( '' !== $category && $category !== strtolower( $compact['category'] ) ) {
				continue;
			}
			if ( '' !== $search ) {
				$haystack = mb_strtolower( $compact['name'] . ' ' . $compact['label'] . ' ' . $compact['description'] . ' ' . $compact['category'] );
				if ( ! str_contains( $haystack, $search ) ) {
					continue;
				}
			}
			$rows[] = $compact;
		}

		$total     = count( $rows );
		$truncated = $total > $limit;
		$sliced    = array_slice( $rows, 0, $limit );

		return [
			'abilities' => $sliced,
			'count'     => count( $sliced ),
			'total'     => $total,
			'truncated' => $truncated,
		];
	}
}
