<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ThemeBuilder;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\ThemeBuilder\TemplateStore;

/**
 * Set display conditions on an Elementor Theme Builder template.
 *
 * A condition decides where the template renders (e.g. "include / general /
 * site" = everywhere; "exclude / archive / category" = everywhere except
 * category archives). The full rule array replaces whatever was on the
 * template before — callers that want to add/remove a single rule should
 * fetch the template first via GetTemplate.
 *
 * @stonewright-status stable
 */
final class SetConditions extends AbilityKernel {

	public function name(): string {
		return 'stonewright/theme-builder-set-conditions';
	}

	public function label(): string {
		return __( 'Theme Builder: Set conditions', 'stonewright' );
	}

	public function description(): string {
		return __( 'Replaces the display rules on an elementor_library template. Each rule is { type: include|exclude, name: <where>, sub_name?: <which>, sub_id?: <id> }.', 'stonewright' );
	}

	public function category(): string {
		return 'theme-builder';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'template_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'conditions'  => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => [
							'type'     => [ 'type' => 'string', 'enum' => [ 'include', 'exclude' ] ],
							'name'     => [ 'type' => 'string' ],
							'sub_name' => [ 'type' => 'string' ],
							'sub_id'   => [ 'type' => 'integer' ],
						],
						'required' => [ 'type', 'name' ],
					],
				],
			],
			'required' => [ 'template_id', 'conditions' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'template_id' => [ 'type' => 'integer' ],
				'updated'     => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'template_id', 'updated' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$id   = (int) $args['template_id'];
				$post = get_post( $id );
				if ( ! $post || 'elementor_library' !== $post->post_type ) {
					return $this->error(
						'not_a_template',
						__( 'Post is not an elementor_library template.', 'stonewright' )
					);
				}
				$ok = TemplateStore::set_conditions( $id, (array) $args['conditions'] );
				return [
					'template_id' => $id,
					'updated'     => $ok,
				];
			}
		);
	}
}
