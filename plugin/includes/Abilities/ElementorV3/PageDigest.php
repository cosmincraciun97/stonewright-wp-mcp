<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Compact Elementor page digest for token-efficient edit sessions.
 *
 * @stonewright-status stable
 */
final class PageDigest extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-page-digest';
	}

	public function label(): string {
		return __( 'Elementor page digest', 'stonewright' );
	}

	public function description(): string {
		return __( 'One-call compact Elementor page outline: tree of elType/widgetType/id/index-path/heading text, counts, and kit flags. Target under ~800 tokens for a medium page.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id' ],
			'properties'           => [
				'post_id'       => [ 'type' => 'integer', 'minimum' => 1 ],
				'max_nodes'     => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 400,
					'default'     => 120,
					'description' => 'Cap on outline nodes returned.',
				],
				'heading_chars' => [
					'type'    => 'integer',
					'minimum' => 10,
					'maximum' => 120,
					'default' => 40,
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'post_id'       => [ 'type' => 'integer' ],
				'active'        => [ 'type' => 'boolean' ],
				'counts'        => [ 'type' => 'object' ],
				'outline'       => [ 'type' => 'array' ],
				'truncated'    => [ 'type' => 'boolean' ],
				'estimated_tokens' => [ 'type' => 'integer' ],
			],
			'required'             => [ 'post_id', 'active', 'counts', 'outline' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	public function execute( array $args ): array|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( ! get_post( $post_id ) ) {
			return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
		}

		$max_nodes     = max( 1, min( 400, (int) ( $args['max_nodes'] ?? 120 ) ) );
		$heading_chars = max( 10, min( 120, (int) ( $args['heading_chars'] ?? 40 ) ) );
		$tree          = ElementorData::read( $post_id );
		$outline       = [];
		$counts        = [
			'sections'   => 0,
			'containers' => 0,
			'columns'    => 0,
			'widgets'    => 0,
			'other'      => 0,
			'total'      => 0,
		];
		$truncated   = false;

		$this->walk( $tree, [], $outline, $counts, $max_nodes, $heading_chars, $truncated );

		$payload = [
			'post_id'  => $post_id,
			'active'   => ElementorData::is_active( $post_id ),
			'counts'   => $counts,
			'outline'  => $outline,
			'truncated' => $truncated,
		];
		$json    = (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$payload['estimated_tokens'] = (int) ceil( strlen( $json ) / 4 );

		return $payload;
	}

	/**
	 * @param array<int, array<string, mixed>> $nodes
	 * @param list<int> $path
	 * @param list<array<string, mixed>> $outline
	 * @param array<string, int> $counts
	 */
	private function walk( array $nodes, array $path, array &$outline, array &$counts, int $max_nodes, int $heading_chars, bool &$truncated ): void {
		foreach ( $nodes as $index => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( count( $outline ) >= $max_nodes ) {
				$truncated = true;
				return;
			}

			$current_path = array_merge( $path, [ (int) $index ] );
			$el_type      = (string) ( $node['elType'] ?? '' );
			$widget_type  = (string) ( $node['widgetType'] ?? '' );
			$id           = (string) ( $node['id'] ?? '' );
			$settings     = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];

			if ( 'section' === $el_type ) {
				++$counts['sections'];
			} elseif ( 'container' === $el_type ) {
				++$counts['containers'];
			} elseif ( 'column' === $el_type ) {
				++$counts['columns'];
			} elseif ( 'widget' === $el_type ) {
				++$counts['widgets'];
			} else {
				++$counts['other'];
			}
			++$counts['total'];

			$heading = '';
			foreach ( [ 'title', 'editor', 'text', 'heading_title' ] as $key ) {
				if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
					$heading = mb_substr( wp_strip_all_tags( $settings[ $key ] ), 0, $heading_chars );
					break;
				}
			}

			$outline[] = [
				'id'          => $id,
				'elType'      => $el_type,
				'widgetType'  => $widget_type,
				'index_path'  => $current_path,
				'heading'     => $heading,
				'setting_keys'=> array_slice( array_keys( $settings ), 0, 12 ),
			];

			$children = isset( $node['elements'] ) && is_array( $node['elements'] ) ? $node['elements'] : [];
			if ( [] !== $children ) {
				$this->walk( $children, $current_path, $outline, $counts, $max_nodes, $heading_chars, $truncated );
				if ( $truncated ) {
					return;
				}
			}
		}
	}
}
