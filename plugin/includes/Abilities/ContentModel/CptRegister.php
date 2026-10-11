<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ContentModel;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Registers a custom post type and stores a CPT UI-compatible entry for it.
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
		return __( 'Registers a custom post type for this request and stores a CPT UI-compatible entry in the cptui_post_types option, merging it into an existing entry. The type is registered again on later requests only when the CPT UI plugin is active and reads that option; without CPT UI it exists for the current request only, and the result says so. The slug is at most 20 characters, and names that WordPress reserves or another plugin already registered are refused.', 'stonewright' );
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
				'slug'               => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => ReservedNames::POST_TYPE_MAX_LENGTH ],
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

				$existing = CptUiStore::existing( CptUiStore::POST_TYPES, $slug );
				$invalid  = ReservedNames::post_type_error( $slug, null !== $existing );
				if ( null !== $invalid ) {
					return $invalid;
				}

				// A new entry gets the defaults; an existing one keeps what it holds unless the call names the setting.
				$payload = [
					'name'           => $slug,
					'label'          => $plural,
					'singular_label' => $singular,
					'show_in_rest'   => true,
				];
				$defaults = [
					'public'      => true,
					'has_archive' => true,
					'supports'    => [ 'title', 'editor' ],
					'taxonomies'  => [],
				];
				foreach ( $defaults as $key => $default ) {
					if ( array_key_exists( $key, $args ) || null === $existing ) {
						$payload[ $key ] = match ( $key ) {
							'public', 'has_archive' => (bool) ( $args[ $key ] ?? $default ),
							default                 => array_values( array_map( 'strval', (array) ( $args[ $key ] ?? $default ) ) ),
						};
					}
				}
				$entry = array_replace( (array) $existing, $payload );

				// Register first: core refuses a name or an argument it cannot use, and nothing is stored in that case.
				$registered = register_post_type(
					$slug,
					[
						'labels'       => [
							'name'          => $plural,
							'singular_name' => $singular,
						],
						'public'       => CptUiStore::to_bool( $entry['public'] ?? null, true ),
						'has_archive'  => CptUiStore::to_bool( $entry['has_archive'] ?? null, true ),
						'supports'     => array_values( array_map( 'strval', (array) ( $entry['supports'] ?? [ 'title', 'editor' ] ) ) ),
						'taxonomies'   => array_values( array_map( 'strval', (array) ( $entry['taxonomies'] ?? [] ) ) ),
						'show_in_rest' => true,
					]
				);
				if ( is_wp_error( $registered ) ) {
					return $registered;
				}

				if ( ! CptUiStore::save( CptUiStore::POST_TYPES, $slug, $payload ) ) {
					return new \WP_Error( 'stonewright_cpt_store_failed', __( 'The post type was registered for this request, but its entry could not be stored.', 'stonewright' ), [ 'status' => 500, 'slug' => $slug ] );
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
