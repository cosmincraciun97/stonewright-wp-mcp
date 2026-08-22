<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Skills;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Skills\Skills;

/**
 * Returns a single skill by slug.
 *
 * @stonewright-status stable
 */
final class SkillsGet extends AbilityKernel {

	public function name(): string {
		return 'stonewright/skills-get';
	}

	public function label(): string {
		return __( 'Get skill', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns the full content and metadata of a single site skill by its slug.', 'stonewright' );
	}

	public function category(): string {
		return 'system';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'slug' ],
			'properties'           => [
				'slug' => [
					'type'        => 'string',
					'description' => 'The unique slug of the skill to retrieve.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'skill' => [
					'oneOf' => [
						[ 'type' => 'object' ],
						[ 'type' => 'null' ],
					],
				],
				'found' => [ 'type' => 'boolean' ],
			],
			'required' => [ 'skill', 'found' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		$slug  = sanitize_title( (string) ( $args['slug'] ?? '' ) );
		$skill = Skills::get( $slug );

		if ( null !== $skill && ! Skills::runtime_visible( $skill ) ) {
			$components  = Skills::unavailable_components( $skill );
			$component   = (string) ( $components[0] ?? 'unknown' );
			$constraints = (array) ( $skill['version_constraints'] ?? [] );

			return new \WP_Error(
				'stonewright_skill_unavailable',
				sprintf(
					/* translators: %s is a required runtime component slug. */
					__( 'This skill is unavailable because the required component "%s" is missing or incompatible.', 'stonewright' ),
					$component
				),
				[
					'status'             => 409,
					'missing_component'  => $component,
					'version_constraint' => (string) ( $constraints[ $component ] ?? '' ),
				]
			);
		}

		return [
			'skill' => $skill,
			'found' => null !== $skill,
		];
	}
}
