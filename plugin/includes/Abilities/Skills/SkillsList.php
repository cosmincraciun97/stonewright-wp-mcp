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
		return __( 'Returns registered site skills. By default, omits full playbook content to reduce token usage; use skills-get for a single full skill.', 'stonewright' );
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
				'include_content' => [
					'type'        => 'boolean',
					'description' => 'When true, include full Markdown content. Defaults to false to reduce token usage.',
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
		$enabled_only    = (bool) ( $args['enabled_only'] ?? false );
		$include_content = (bool) ( $args['include_content'] ?? false );
		$skills          = Skills::list( $enabled_only );

		if ( ! $include_content ) {
			$skills = array_map(
				static function ( array $skill ): array {
					$skill['content_length'] = strlen( (string) ( $skill['content'] ?? '' ) );
					unset( $skill['content'] );
					return $skill;
				},
				$skills
			);
		}

		return [
			'skills' => $skills,
			'count'  => count( $skills ),
		];
	}
}
