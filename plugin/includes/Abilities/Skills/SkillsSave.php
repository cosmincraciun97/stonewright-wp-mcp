<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Skills;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Skills\Skills;

/**
 * Creates or updates a site skill (upsert by slug).
 *
 * @stonewright-status stable
 */
final class SkillsSave extends AbilityKernel {

	public function name(): string {
		return 'stonewright/skills-save';
	}

	public function label(): string {
		return __( 'Save skill', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates or updates a site skill playbook. Use this to teach the AI site-specific workflows. Pass a unique slug to upsert.', 'stonewright' );
	}

	public function category(): string {
		return 'system';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'slug', 'title', 'content' ],
			'properties'           => [
				'slug'        => [
					'type'        => 'string',
					'description' => 'Unique identifier for the skill (e.g. "my-landing-page-workflow"). Alphanumeric and hyphens only.',
				],
				'title'       => [
					'type'        => 'string',
					'description' => 'Human-readable title shown in the Skills admin page.',
				],
				'description' => [
					'type'        => 'string',
					'description' => 'One-line description of when this skill applies. Shown in the admin card.',
					'default'     => '',
				],
				'content'     => [
					'type'        => 'string',
					'description' => 'Markdown playbook content. This is injected into the MCP server instructions when the skill is enabled.',
				],
				'enabled'     => [
					'type'        => 'boolean',
					'description' => 'Whether the skill is active. Defaults to true.',
					'default'     => true,
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'      => [ 'type' => 'integer' ],
				'slug'    => [ 'type' => 'string' ],
				'updated' => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'id', 'slug', 'updated' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		$slug = sanitize_title( (string) ( $args['slug'] ?? '' ) );

		if ( '' === $slug ) {
			return $this->error( 'stonewright_skills_invalid_slug', __( 'slug is required and must be non-empty.', 'stonewright' ) );
		}

		$existing = Skills::get( $slug );

		$id = Skills::save( [
			'slug'        => $slug,
			'title'       => (string) ( $args['title'] ?? '' ),
			'description' => (string) ( $args['description'] ?? '' ),
			'content'     => (string) ( $args['content'] ?? '' ),
			'enabled'     => $args['enabled'] ?? true,
			'source'      => 'user',
		] );

		if ( 0 === $id ) {
			return $this->error( 'stonewright_skills_save_failed', __( 'Failed to save skill. The table may not exist yet.', 'stonewright' ) );
		}

		return [
			'id'      => $id,
			'slug'    => $slug,
			'updated' => null !== $existing,
		];
	}
}
