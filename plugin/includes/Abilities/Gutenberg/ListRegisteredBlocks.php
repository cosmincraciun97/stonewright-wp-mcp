<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Gutenberg;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class ListRegisteredBlocks extends AbilityKernel {

	private const SUMMARY_MAX = 80;
	private const COMPACT_MAX = 150;
	private const FULL_MAX    = 500;

	public function name(): string {
		return 'stonewright/blocks-list-registered';
	}

	public function label(): string {
		return __( 'List registered blocks', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists block types registered with the WP_Block_Type_Registry. Defaults to name and title only; use responseMode=full for inserter metadata.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'namespace'    => [ 'type' => 'string' ],
				'search'       => [ 'type' => 'string' ],
				'responseMode' => [
					'type'        => 'string',
					'enum'        => [ 'summary', 'compact', 'full' ],
					'default'     => 'summary',
					'description' => 'summary returns name and title; compact adds category; full restores the previous inserter metadata dump.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'response_mode'  => [ 'type' => 'string' ],
				'blocks'         => [ 'type' => 'array' ],
				'total'          => [ 'type' => 'integer' ],
				'returned'       => [ 'type' => 'integer' ],
				'truncated'      => [ 'type' => 'boolean' ],
				'full_mode_hint' => [ 'type' => 'string' ],
			],
			'required'   => [ 'blocks' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$ns     = isset( $args['namespace'] ) ? (string) $args['namespace'] : '';
		$search = isset( $args['search'] ) ? mb_strtolower( (string) $args['search'] ) : '';
		$mode   = strtolower( trim( (string) ( $args['responseMode'] ?? 'summary' ) ) );
		if ( ! in_array( $mode, [ 'summary', 'compact', 'full' ], true ) ) {
			$mode = 'summary';
		}

		$registry = \WP_Block_Type_Registry::get_instance();
		$blocks   = [];

		foreach ( $registry->get_all_registered() as $name => $type ) {
			if ( '' !== $ns && 0 !== strpos( $name, $ns . '/' ) ) {
				continue;
			}
			$title = isset( $type->title ) ? (string) $type->title : '';
			if ( '' !== $search && false === stripos( $name . ' ' . $title, $search ) ) {
				continue;
			}

			$full = [
				'name'        => $name,
				'title'       => $title,
				'category'    => isset( $type->category ) ? (string) $type->category : '',
				'description' => isset( $type->description ) ? (string) $type->description : '',
				'icon'        => isset( $type->icon ) ? (string) $type->icon : '',
				'keywords'    => isset( $type->keywords ) ? (array) $type->keywords : [],
				'supports'    => isset( $type->supports ) ? (array) $type->supports : [],
				'example'     => isset( $type->example ) ? (array) $type->example : [],
				'is_dynamic'  => is_callable( $type->render_callback ?? null ),
			];
			if ( 'summary' === $mode ) {
				$blocks[] = [
					'name'  => $full['name'],
					'title' => $full['title'],
				];
			} elseif ( 'compact' === $mode ) {
				$blocks[] = [
					'name'       => $full['name'],
					'title'      => $full['title'],
					'category'   => $full['category'],
					'is_dynamic' => $full['is_dynamic'],
				];
			} else {
				$blocks[] = $full;
			}
		}

		$cap       = match ( $mode ) {
			'summary' => self::SUMMARY_MAX,
			'compact' => self::COMPACT_MAX,
			default   => self::FULL_MAX,
		};
		$total     = count( $blocks );
		$truncated = $total > $cap;
		$sliced    = array_slice( $blocks, 0, $cap );

		return [
			'response_mode'  => $mode,
			'blocks'         => $sliced,
			'total'          => $total,
			'returned'       => count( $sliced ),
			'truncated'      => $truncated,
			'full_mode_hint' => 'Call with responseMode=full only when inserter metadata is required for the next write.',
		];
	}
}
