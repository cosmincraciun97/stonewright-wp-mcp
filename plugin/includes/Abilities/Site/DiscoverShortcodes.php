<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Site;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Lists registered WordPress shortcodes without executing them.
 *
 * @stonewright-status stable
 */
final class DiscoverShortcodes extends AbilityKernel {

	public function name(): string {
		return 'stonewright/site-shortcodes-discover';
	}

	public function label(): string {
		return __( 'Discover shortcodes', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists registered shortcode tags and optional safe callback summaries without executing shortcode handlers.', 'stonewright' );
	}

	public function category(): string {
		return 'site';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'search'            => [
					'type'        => 'string',
					'description' => 'Optional case-insensitive substring filter for shortcode tags.',
				],
				'include_callbacks' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'When true, include callback type and a non-executed callback name summary.',
				],
				'max'               => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 500,
					'default'     => 100,
					'description' => 'Maximum shortcode rows to return.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'         => [ 'type' => 'boolean' ],
				'count'      => [ 'type' => 'integer' ],
				'total'      => [ 'type' => 'integer' ],
				'truncated'  => [ 'type' => 'boolean' ],
				'shortcodes' => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
			],
			'required'   => [ 'ok', 'count', 'total', 'truncated', 'shortcodes' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$registered = isset( $GLOBALS['shortcode_tags'] ) && is_array( $GLOBALS['shortcode_tags'] )
			? $GLOBALS['shortcode_tags']
			: [];
		$search = strtolower( sanitize_text_field( (string) ( $args['search'] ?? '' ) ) );
		$max    = (int) ( $args['max'] ?? 100 );
		if ( $max < 1 ) {
			return $this->error( 'invalid_max', __( 'max must be at least 1.', 'stonewright' ), [ 'status' => 400 ] );
		}
		$max    = min( 500, $max );
		$rows   = [];

		foreach ( $registered as $tag => $callback ) {
			$tag = (string) $tag;
			if ( '' !== $search && ! str_contains( strtolower( $tag ), $search ) ) {
				continue;
			}

			$row = [ 'tag' => $tag ];
			if ( ! empty( $args['include_callbacks'] ) ) {
				$row = array_merge( $row, self::callback_summary( $callback ) );
			}
			$rows[] = $row;
		}

		usort(
			$rows,
			static fn( array $a, array $b ): int => strcmp( (string) $a['tag'], (string) $b['tag'] )
		);

		$total = count( $rows );
		$rows  = array_slice( $rows, 0, $max );

		return [
			'ok'         => true,
			'count'      => count( $rows ),
			'total'      => $total,
			'truncated'  => $total > count( $rows ),
			'shortcodes' => $rows,
		];
	}

	/**
	 * @return array{callback_type:string,callback_name:string}
	 */
	private static function callback_summary( mixed $callback ): array {
		if ( is_string( $callback ) ) {
			return [
				'callback_type' => 'function',
				'callback_name' => $callback,
			];
		}
		if ( is_array( $callback ) && 2 === count( $callback ) ) {
			$class_or_object = $callback[0];
			$method          = is_string( $callback[1] ) ? $callback[1] : '';
			if ( is_string( $class_or_object ) ) {
				return [
					'callback_type' => 'class_method',
					'callback_name' => $class_or_object . '::' . $method,
				];
			}
			if ( is_object( $class_or_object ) ) {
				return [
					'callback_type' => 'object_method',
					'callback_name' => get_class( $class_or_object ) . '->' . $method,
				];
			}
		}
		if ( $callback instanceof \Closure ) {
			return [
				'callback_type' => 'closure',
				'callback_name' => 'Closure',
			];
		}

		return [
			'callback_type' => 'unknown',
			'callback_name' => '',
		];
	}
}
