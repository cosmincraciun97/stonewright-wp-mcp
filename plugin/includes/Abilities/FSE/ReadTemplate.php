<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\FSE;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Ability: stonewright/fse.read_template
 *
 * Reads a wp_template post by slug + theme.
 * Permission: can_view_design().
 *
 * @stonewright-status stable
 */
final class ReadTemplate extends AbilityKernel {

	public function name(): string {
		return 'stonewright/fse-read-template';
	}

	public function label(): string {
		return __( 'Read FSE template', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns the block markup content of a wp_template post for a given slug and theme.', 'stonewright' );
	}

	public function category(): string {
		return 'fse';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'template_slug' => [ 'type' => 'string', 'description' => 'Template slug.' ],
				'theme'         => [ 'type' => 'string', 'description' => 'Theme stylesheet slug.' ],
			],
			'required' => [ 'template_slug', 'theme' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'exists'   => [ 'type' => 'boolean' ],
				'content'  => [ 'type' => [ 'string', 'null' ] ],
				'post_id'  => [ 'type' => [ 'integer', 'null' ] ],
			],
			'required' => [ 'exists', 'content', 'post_id' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_view_design();
	}

	public function execute( array $args ): array|\WP_Error {
		$slug  = (string) ( $args['template_slug'] ?? '' );
		$theme = (string) ( $args['theme'] ?? '' );

		if ( '' === $slug || '' === $theme ) {
			return $this->error( 'invalid_args', __( 'template_slug and theme are required.', 'stonewright' ) );
		}

		$post = $this->find_template_post( $slug, $theme, 'wp_template' );
		if ( null === $post ) {
			return [
				'exists'  => false,
				'content' => null,
				'post_id' => null,
			];
		}

		return [
			'exists'  => true,
			'content' => (string) $post->post_content,
			'post_id' => (int) $post->ID,
		];
	}

	/**
	 * @return object|\WP_Post|null
	 */
	protected function find_template_post( string $slug, string $theme, string $post_type ): ?object {
		$posts = get_posts(
			[
				'post_type'      => $post_type,
				'name'           => $slug,
				'posts_per_page' => 1,
				'post_status'    => [ 'publish', 'auto-draft', 'draft' ],
				'meta_query'     => [
					[
						'key'   => 'theme',
						'value' => $theme,
					],
				],
				// theme is stored as a post_name prefix in WP core: slug = "theme//template"
				// We accept either naming style.
			]
		);

		// WP core stores templates as "theme//slug" composite slugs.
		if ( empty( $posts ) ) {
			$posts = get_posts(
				[
					'post_type'      => $post_type,
					'name'           => $theme . '//' . $slug,
					'posts_per_page' => 1,
					'post_status'    => [ 'publish', 'auto-draft', 'draft' ],
				]
			);
		}

		return ! empty( $posts ) ? $posts[0] : null;
	}
}
