<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Skills;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Skills\Skills;

/**
 * Lists all registered skills with their enabled state.
 *
 * @stonewright-status stable
 */
final class SkillsList extends AbilityKernel {

	public function name(): string {
		return 'stonewright/skills-list';
	}

	public function label(): string {
		return __( 'List skills', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns all registered site skills with their slug, title, description, enabled state, and source.', 'stonewright' );
	}

	public function category(): string {
		return 'system';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'enabled_only' => [
					'type'        => 'boolean',
					'description' => 'When true, only return enabled skills.',
					'default'     => false,
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'skills' => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'count'  => [ 'type' => 'integer' ],
			],
			'required'   => [ 'skills', 'count' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		$enabled_only = (bool) ( $args['enabled_only'] ?? false );
		$skills       = Skills::list( $enabled_only );

		return [
			'skills' => $skills,
			'count'  => count( $skills ),
		];
	}
}
