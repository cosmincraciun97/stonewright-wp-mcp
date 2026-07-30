<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ContentModel;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status stable */
final class TaxonomyRegister extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/taxonomy-register';
	}

	public function label(): string {
		return __( 'Content Model: Register taxonomy', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates or updates a persistent custom taxonomy (CPT UI-compatible option + runtime registration).', 'stonewright' );
	}

	public function category(): string {
		return 'content-model';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'slug', 'object_types', 'singular', 'plural' ],
			'properties'           => [
				'slug'               => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 32 ],
				'object_types'       => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
					'minItems' => 1,
				],
				'singular'           => [ 'type' => 'string', 'minLength' => 1 ],
				'plural'             => [ 'type' => 'string', 'minLength' => 1 ],
				'hierarchical'       => [ 'type' => 'boolean', 'default' => false ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object', 'additionalProperties' => true ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$verify = $args;
				unset( $verify['confirmation_token'] );
				$err = $this->confirmation_token_error( $args, $verify );
				if ( null !== $err ) {
					return $err;
				}

				$slug         = sanitize_key( (string) $args['slug'] );
				$object_types = array_values( array_map( 'strval', (array) $args['object_types'] ) );
				$singular     = (string) $args['singular'];
				$plural       = (string) $args['plural'];
				$hierarchical = (bool) ( $args['hierarchical'] ?? false );
				if ( '' === $slug || [] === $object_types ) {
					return new \WP_Error( 'stonewright_taxonomy_invalid', 'slug and object_types are required.' );
				}

				$payload = [
					'name'          => $slug,
					'label'         => $plural,
					'singular_label'=> $singular,
					'object_types'  => $object_types,
					'hierarchical'  => $hierarchical,
					'show_in_rest'  => true,
				];

				$config = get_option( 'cptui_taxonomies', [] );
				$config = is_array( $config ) ? $config : [];
				$config[ $slug ] = $payload;
				update_option( 'cptui_taxonomies', $config, false );

				if ( function_exists( 'register_taxonomy' ) ) {
					register_taxonomy(
						$slug,
						$object_types,
						[
							'labels'       => [
								'name'          => $plural,
								'singular_name' => $singular,
							],
							'hierarchical' => $hierarchical,
							'public'       => true,
							'show_in_rest' => true,
						]
					);
				}

				return [
					'ok'     => true,
					'slug'   => $slug,
					'source' => 'stonewright',
				];
			}
		);
	}
}
