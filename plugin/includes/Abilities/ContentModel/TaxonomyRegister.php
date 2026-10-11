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
		return __( 'Registers a custom taxonomy for this request and stores a CPT UI-compatible entry in the cptui_taxonomies option, merging it into an existing entry. The taxonomy is registered again on later requests only when the CPT UI plugin is active and reads that option; without CPT UI it exists for the current request only, and the result says so. The slug is at most 32 characters, and names that WordPress reserves or another plugin already registered are refused.', 'stonewright' );
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
				'slug'               => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => ReservedNames::TAXONOMY_MAX_LENGTH ],
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
				if ( [] === $object_types ) {
					return new \WP_Error( 'stonewright_taxonomy_invalid', 'slug and object_types are required.', [ 'status' => 400 ] );
				}

				$existing = CptUiStore::existing( CptUiStore::TAXONOMIES, $slug );
				$invalid  = ReservedNames::taxonomy_error( $slug, null !== $existing );
				if ( null !== $invalid ) {
					return $invalid;
				}

				$payload = [
					'name'           => $slug,
					'label'          => $plural,
					'singular_label' => $singular,
					'object_types'   => $object_types,
					'show_in_rest'   => true,
				];
				// A new entry gets the default; an existing one keeps what it holds unless the call names the setting.
				if ( array_key_exists( 'hierarchical', $args ) || null === $existing ) {
					$payload['hierarchical'] = (bool) ( $args['hierarchical'] ?? false );
				}
				$entry = array_replace( (array) $existing, $payload );

				// Register first: core refuses a name or an argument it cannot use, and nothing is stored in that case.
				$registered = register_taxonomy(
					$slug,
					$object_types,
					[
						'labels'       => [
							'name'          => $plural,
							'singular_name' => $singular,
						],
						'hierarchical' => CptUiStore::to_bool( $entry['hierarchical'] ?? null, false ),
						'public'       => true,
						'show_in_rest' => true,
					]
				);
				if ( is_wp_error( $registered ) ) {
					return $registered;
				}

				if ( ! CptUiStore::save( CptUiStore::TAXONOMIES, $slug, $payload ) ) {
					return new \WP_Error( 'stonewright_taxonomy_store_failed', __( 'The taxonomy was registered for this request, but its entry could not be stored.', 'stonewright' ), [ 'status' => 500, 'slug' => $slug ] );
				}

				return [
					'ok'          => true,
					'slug'        => $slug,
					'source'      => 'stonewright',
					'created'     => null === $existing,
					'updated'     => null !== $existing,
					'persistent'  => CptUiStore::is_active(),
					'persistence' => CptUiStore::persistence_note(),
				];
			}
		);
	}
}
