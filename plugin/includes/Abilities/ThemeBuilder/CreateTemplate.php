<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ThemeBuilder;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\ThemeBuilder\TemplateStore;

/**
 * Create an empty Elementor Theme Builder template.
 *
 * This is the door that lets a model build a real header / footer / archive /
 * 404 / loop-item — as a first-class `elementor_library` post with the right
 * `_elementor_template_type` meta — instead of stuffing menu/header markup
 * into a regular page. Display conditions (where the template shows) are
 * set separately via the SetConditions ability.
 *
 * @stonewright-status stable
 */
final class CreateTemplate extends AbilityKernel {

	public function name(): string {
		return 'stonewright/theme-builder-create-template';
	}

	public function label(): string {
		return __( 'Theme Builder: Create template', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates a real Elementor Theme Builder template (header / footer / single / single-post / single-page / archive / search-results / error-404 / loop-item) as an elementor_library post. Display conditions are set separately with theme-builder-set-conditions.', 'stonewright' );
	}

	public function category(): string {
		return 'theme-builder';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'title'         => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ],
				'template_type' => [ 'type' => 'string', 'enum' => TemplateStore::ALLOWED_TYPES ],
			],
			'required' => [ 'title', 'template_type' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'template_id'   => [ 'type' => 'integer' ],
				'template_type' => [ 'type' => 'string' ],
			],
			'required' => [ 'template_id', 'template_type' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$id = TemplateStore::create(
					(string) $args['title'],
					(string) $args['template_type']
				);
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				return [
					'template_id'   => $id,
					'template_type' => (string) $args['template_type'],
				];
			}
		);
	}
}
