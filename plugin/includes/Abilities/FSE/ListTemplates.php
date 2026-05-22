<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\FSE;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class ListTemplates extends AbilityKernel {

	public function name(): string {
		return 'stonewright/fse-list-templates';
	}

	public function label(): string {
		return __( 'List templates', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists block templates and template parts (FSE).', 'stonewright' );
	}

	public function category(): string {
		return 'fse';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'type' => [ 'type' => 'string', 'enum' => [ 'wp_template', 'wp_template_part', 'both' ], 'default' => 'both' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'templates'      => [ 'type' => 'array' ],
				'template_parts' => [ 'type' => 'array' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_theme_options();
	}

	public function execute( array $args ): array|\WP_Error {
		$type = isset( $args['type'] ) ? (string) $args['type'] : 'both';

		$out = [
			'templates'      => [],
			'template_parts' => [],
		];

		if ( 'wp_template' === $type || 'both' === $type ) {
			$out['templates'] = $this->collect( 'wp_template' );
		}
		if ( 'wp_template_part' === $type || 'both' === $type ) {
			$out['template_parts'] = $this->collect( 'wp_template_part' );
		}

		return $out;
	}

	private function collect( string $cpt ): array {
		if ( ! function_exists( 'get_block_templates' ) ) {
			return [];
		}
		$results = get_block_templates( [], $cpt );
		$list    = [];
		foreach ( $results as $tpl ) {
			$list[] = [
				'id'          => $tpl->id ?? '',
				'slug'        => $tpl->slug ?? '',
				'theme'       => $tpl->theme ?? '',
				'title'       => is_object( $tpl->title ?? null ) ? ( $tpl->title->rendered ?? '' ) : ( $tpl->title ?? '' ),
				'description' => $tpl->description ?? '',
				'source'      => $tpl->source ?? '',
				'origin'      => $tpl->origin ?? '',
				'area'        => $tpl->area ?? '',
				'type'        => $tpl->type ?? $cpt,
			];
		}
		return $list;
	}
}
