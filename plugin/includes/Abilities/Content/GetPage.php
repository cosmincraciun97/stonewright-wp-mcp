<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Content;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class GetPage extends AbilityKernel {

	public function name(): string {
		return 'stonewright/content-get-page';
	}

	public function label(): string {
		return __( 'Get page', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns a page or post including raw content, status, parent, and template.', 'stonewright' );
	}

	public function category(): string {
		return 'content';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'id' => [ 'type' => 'integer', 'minimum' => 1 ],
			],
			'required'             => [ 'id' ],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	public function execute( array $args ): array|\WP_Error {
		$id   = (int) $args['id'];
		$post = get_post( $id );
		if ( ! $post ) {
			return $this->error( 'not_found', __( 'Page not found.', 'stonewright' ) );
		}

		return [
			'id'           => $post->ID,
			'title'        => $post->post_title,
			'status'       => $post->post_status,
			'type'         => $post->post_type,
			'parent'       => (int) $post->post_parent,
			'slug'         => $post->post_name,
			'content'      => $post->post_content,
			'excerpt'      => $post->post_excerpt,
			'template'     => (string) get_post_meta( $id, '_wp_page_template', true ),
			'has_elementor'=> (string) get_post_meta( $id, '_elementor_edit_mode', true ) === 'builder',
			'edit_url'     => get_edit_post_link( $id, 'raw' ) ?: '',
		];
	}
}
