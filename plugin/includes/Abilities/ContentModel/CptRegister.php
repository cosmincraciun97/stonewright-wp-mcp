<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ContentModel;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Persists a CPT UI-compatible post type registration.
 *
 * @stonewright-status stable
 */
final class CptRegister extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/cpt-register';
	}

	public function label(): string {
		return __( 'Content Model: Register CPT', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates or updates a persistent custom post type (CPT UI-compatible option + runtime registration).', 'stonewright' );
	}

	public function category(): string {
		return 'content-model';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'slug', 'singular', 'plural' ],
			'properties'           => [
				'slug'               => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 32 ],
				'singular'           => [ 'type' => 'string', 'minLength' => 1 ],
				'plural'             => [ 'type' => 'string', 'minLength' => 1 ],
				'supports'           => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'taxonomies'         => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'has_archive'        => [ 'type' => 'boolean', 'default' => true ],
				'public'             => [ 'type' => 'boolean', 'default' => true ],
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

				$slug     = sanitize_key( (string) $args['slug'] );
				$singular = (string) $args['singular'];
				$plural   = (string) $args['plural'];
				if ( '' === $slug ) {
					return new \WP_Error( 'stonewright_cpt_slug_invalid', 'slug is invalid.' );
				}

				$supports   = array_values( array_map( 'strval', (array) ( $args['supports'] ?? [ 'title', 'editor' ] ) ) );
				$taxonomies = array_values( array_map( 'strval', (array) ( $args['taxonomies'] ?? [] ) ) );
				$public     = (bool) ( $args['public'] ?? true );
				$archive    = (bool) ( $args['has_archive'] ?? true );

				$payload = [
					'name'         => $slug,
					'label'        => $plural,
					'singular_label' => $singular,
					'public'       => $public,
					'has_archive'  => $archive,
					'supports'     => $supports,
					'taxonomies'   => $taxonomies,
					'show_in_rest' => true,
				];

				$config = get_option( 'cptui_post_types', [] );
				$config = is_array( $config ) ? $config : [];
				$config[ $slug ] = $payload;
				update_option( 'cptui_post_types', $config, false );

				if ( function_exists( 'register_post_type' ) ) {
					register_post_type(
						$slug,
						[
							'labels'       => [
								'name'          => $plural,
								'singular_name' => $singular,
							],
							'public'       => $public,
							'has_archive'  => $archive,
							'supports'     => $supports,
							'taxonomies'   => $taxonomies,
							'show_in_rest' => true,
						]
					);
				}

				return [
					'ok'      => true,
					'slug'    => $slug,
					'source'  => 'stonewright',
					'updated' => true,
				];
			}
		);
	}
}
