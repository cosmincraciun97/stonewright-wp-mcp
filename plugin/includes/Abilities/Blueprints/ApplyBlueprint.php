<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Blueprints;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Blueprints\BlueprintApplier;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Applies a blueprint DesignSpec to a new or existing page.
 *
 * @stonewright-status stable
 */
final class ApplyBlueprint extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/blueprint-apply';
	}

	public function label(): string {
		return __( 'Apply blueprint', 'stonewright' );
	}

	public function description(): string {
		return __( 'Validates a bundled DesignSpec blueprint, optionally merges a brand kit or palette override, creates or updates a page, snapshots before mutation, and renders via Gutenberg, Elementor, or the Site Editor (fse). A new page is written as a draft and gets its final status after the write; an existing page keeps its status unless mode is passed. The fse engine needs the site editor capabilities.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'blueprint_id'       => [ 'type' => 'string' ],
				'page_title'         => [ 'type' => 'string' ],
				'mode'               => [
					'type'        => 'string',
					'enum'        => [ 'draft', 'publish' ],
					'description' => 'Status of the page after the write. A new page is a draft when this is not passed; an existing page (post_id) keeps its status when this is not passed.',
				],
				'palette_override'   => [
					'type'                 => 'object',
					'additionalProperties' => [ 'type' => 'string' ],
					'description'          => 'Optional color map merged into spec.tokens.colors.',
				],
				'brand_kit'          => [ 'type' => 'string', 'description' => 'Optional brand kit id to merge into the spec tokens.' ],
				'engine'             => [
					'type'        => 'string',
					'enum'        => [ 'auto', 'gutenberg', 'elementor', 'fse' ],
					'default'     => 'auto',
					'description' => 'Render engine. elementor fails loudly when Elementor is inactive (no silent Gutenberg fallback). gutenberg/fse force blocks. auto picks Elementor when active, otherwise Gutenberg.',
				],
				'post_id'            => [ 'type' => 'integer', 'minimum' => 1, 'description' => 'Optional existing page id. The page is replaced; its status is kept unless mode is passed.' ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'blueprint_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'           => [ 'type' => 'boolean' ],
				'page_id'      => [ 'type' => 'integer' ],
				'post_id'      => [ 'type' => 'integer' ],
				'blueprint_id' => [ 'type' => 'string' ],
				'brand_kit'    => [ 'type' => 'string' ],
				'created'      => [ 'type' => 'boolean' ],
				'spec_sha8'    => [ 'type' => 'string' ],
				'engine'           => [ 'type' => 'string' ],
				'engine_requested' => [ 'type' => 'string' ],
				'engine_used'      => [ 'type' => 'string' ],
				'mode'             => [ 'type' => 'string' ],
				'snapshot_id'      => [ 'type' => 'string' ],
				'edit_link'        => [ 'type' => 'string' ],
				'diagnostics'      => [ 'type' => 'array' ],
			],
			'additionalProperties' => true,
			'required'   => [ 'ok', 'page_id', 'blueprint_id' ],
		];
	}

	/**
	 * @stonewright-cap edit_pages (or edit_post for post_id) + the post type's publish cap when mode is publish
	 */
	public function permission_callback( array $args ): bool|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$allowed = $post_id > 0 ? Permissions::edit_post( $post_id ) : Permissions::edit_pages();
		if ( ! $allowed ) {
			return false;
		}

		// The fse engine also writes a template, which is a Site Editor write.
		if ( 'fse' === ( $args['engine'] ?? '' ) && ! Permissions::can_manage_fse() ) {
			return false;
		}

		// Publishing is a separate capability, as in every other content writer.
		if ( 'publish' === ( $args['mode'] ?? '' ) ) {
			$post_type   = $post_id > 0 ? (string) ( get_post( $post_id )->post_type ?? 'page' ) : 'page';
			$publish_cap = Permissions::publish_cap_for_status( $post_type, 'publish' );
			if ( null !== $publish_cap && ! current_user_can( $publish_cap ) ) {
				return new \WP_Error(
					'stonewright_forbidden',
					__( 'Insufficient capability to publish pages.', 'stonewright' ),
					[ 'status' => 403 ]
				);
			}
		}

		return true;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$verify_args = array_filter(
					$args,
					static fn( string $key ): bool => 'confirmation_token' !== $key,
					ARRAY_FILTER_USE_KEY
				);
				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}

				return BlueprintApplier::apply( $args );
			}
		);
	}
}
