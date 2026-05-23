<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Site;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Promote a published page to the site front page.
 *
 * Sets `show_on_front = page` and `page_on_front = $page_id` atomically.
 * Safe to call repeatedly — idempotent when called with the same page_id.
 *
 * @stonewright-status stable
 */
final class SetFrontPage extends AbilityKernel {

	public function name(): string {
		return 'stonewright/site-set-front-page';
	}

	public function label(): string {
		return __( 'Set front page', 'stonewright' );
	}

	public function description(): string {
		return __( 'Promote a published page to the WordPress front page. Equivalent to Settings → Reading → Front page displays → A static page.', 'stonewright' );
	}

	public function category(): string {
		return 'site';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'page_id' ],
			'properties'           => [
				'page_id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'ID of the published page to promote as the static front page.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'ok', 'page_id', 'previous_page_id' ],
			'properties' => [
				'ok'               => [ 'type' => 'boolean' ],
				'page_id'          => [ 'type' => 'integer' ],
				'previous_page_id' => [ 'type' => [ 'integer', 'null' ] ],
				'show_on_front'    => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit( $args, function ( array $args ): array|\WP_Error {
			$page_id = isset( $args['page_id'] ) ? (int) $args['page_id'] : 0;
			if ( $page_id < 1 ) {
				return $this->error( 'invalid_page_id', __( 'page_id must be a positive integer.', 'stonewright' ), [ 'status' => 400 ] );
			}

			$post = get_post( $page_id );
			if ( ! $post || 'page' !== $post->post_type ) {
				return $this->error(
					'not_a_page',
					/* translators: %d: rejected post ID */
					sprintf( __( 'Post %d is not a page.', 'stonewright' ), $page_id ),
					[ 'status' => 404 ]
				);
			}
			if ( 'publish' !== $post->post_status ) {
				return $this->error(
					'page_not_published',
					/* translators: %d: post ID */
					sprintf( __( 'Page %d is not published.', 'stonewright' ), $page_id ),
					[ 'status' => 422 ]
				);
			}

			$previous = (int) get_option( 'page_on_front', 0 );
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $page_id );

			return $this->ok( [
				'page_id'          => $page_id,
				'previous_page_id' => $previous > 0 ? $previous : null,
				'show_on_front'    => 'page',
			] );
		} );
	}
}
