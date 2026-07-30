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
final class CreateTemplatePart extends AbilityKernel {

	public function name(): string {
		return 'stonewright/fse-create-template-part';
	}

	public function label(): string {
		return __( 'Create template part', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates a wp_template_part (header, footer, sidebar, uncategorized).', 'stonewright' );
	}

	public function category(): string {
		return 'fse';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'slug'    => [ 'type' => 'string', 'maxLength' => 200 ],
				'title'   => [ 'type' => 'string', 'maxLength' => 255 ],
				'content' => [ 'type' => 'string' ],
				'area'    => [ 'type' => 'string', 'enum' => [ 'header', 'footer', 'sidebar', 'uncategorized' ], 'default' => 'uncategorized' ],
			],
			'required'             => [ 'slug', 'title', 'content' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'      => [ 'type' => 'string' ],
				'post_id' => [ 'type' => 'integer' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_theme_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$slug = sanitize_title( (string) $args['slug'] );
				$area = isset( $args['area'] ) ? (string) $args['area'] : 'uncategorized';

				$post_id = wp_insert_post(
					[
						'post_type'    => 'wp_template_part',
						'post_status'  => 'publish',
						'post_name'    => $slug,
						'post_title'   => sanitize_text_field( (string) $args['title'] ),
						'post_content' => (string) $args['content'],
						'tax_input'    => [
							'wp_theme'              => [ get_stylesheet() ],
							'wp_template_part_area' => [ $area ],
						],
					],
					true
				);

				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}

				wp_set_object_terms( (int) $post_id, get_stylesheet(), 'wp_theme', false );
				wp_set_object_terms( (int) $post_id, $area, 'wp_template_part_area', false );

				return [
					'id'      => get_stylesheet() . '//' . $slug,
					'post_id' => (int) $post_id,
				];
			}
		);
	}
}
