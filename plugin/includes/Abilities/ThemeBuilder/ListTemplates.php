<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ThemeBuilder;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\ThemeBuilder\TemplateStore;

/**
 * List Elementor Theme Builder templates, optionally filtered by type.
 *
 * Returns lightweight identity records — id, title, type — not the full
 * data tree. For the document body and conditions, follow up with
 * GetTemplate.
 *
 * @stonewright-status stable
 */
final class ListTemplates extends AbilityKernel {

	public function name(): string {
		return 'stonewright/theme-builder-list-templates';
	}

	public function label(): string {
		return __( 'Theme Builder: List templates', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists elementor_library templates as { template_id, title, template_type }. Optional template_type filter.', 'stonewright' );
	}

	public function category(): string {
		return 'theme-builder';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'template_type' => [ 'type' => 'string', 'enum' => TemplateStore::ALLOWED_TYPES ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'templates' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'template_id'   => [ 'type' => 'integer' ],
							'title'         => [ 'type' => 'string' ],
							'template_type' => [ 'type' => 'string' ],
						],
						'required' => [ 'template_id', 'title', 'template_type' ],
					],
				],
			],
			'required'   => [ 'templates' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$query_args = [
					'post_type'      => 'elementor_library',
					// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Theme templates are bounded admin metadata, not public pagination.
					'posts_per_page' => 200,
					'post_status'    => 'any',
					'fields'         => 'ids',
				];
				if ( ! empty( $args['template_type'] ) ) {
					$query_args['meta_query'] = [
						[
							'key'   => '_elementor_template_type',
							'value' => (string) $args['template_type'],
						],
					];
				}
				$ids = get_posts( $query_args );

				$templates = [];
				foreach ( (array) $ids as $id ) {
					$templates[] = [
						'template_id'   => (int) $id,
						'title'         => (string) get_the_title( (int) $id ),
						'template_type' => TemplateStore::get_type( (int) $id ),
					];
				}
				return [ 'templates' => $templates ];
			}
		);
	}
}
