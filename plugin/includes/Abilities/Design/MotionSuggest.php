<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Design\Motion\MotionSuggestEngine;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Read-only motion suggestions for a page intent.
 *
 * Maximum three proposals, exactly one recommended, "no motion" always valid.
 *
 * @stonewright-status stable
 */
final class MotionSuggest extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-motion-suggest';
	}

	public function label(): string {
		return __( 'Design motion suggest', 'stonewright' );
	}

	public function description(): string {
		return __( 'Read-only deterministic motion proposals (max three, one recommended, no-motion always valid) derived from page intent, section roles, and design direction.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'renderer'  => [
					'type'    => 'string',
					'enum'    => [ 'gutenberg-fse', 'elementor-v3', 'elementor-v4' ],
					'default' => 'gutenberg-fse',
				],
				'sections'  => [
					'type'        => 'array',
					'maxItems'    => 40,
					'description' => 'Page intent summary: id, role, and block list per section.',
					'items'       => [ 'type' => 'object' ],
				],
				'direction' => [
					'type'        => 'object',
					'description' => 'Active design direction excerpt; entrance_animation controls the politics.',
					'properties'  => [
						'id'                 => [ 'type' => 'string' ],
						'version'            => [ 'type' => 'string' ],
						'hash'               => [ 'type' => 'string' ],
						'entrance_animation' => [ 'type' => 'string', 'enum' => [ 'blocked', 'hero_only', 'allowed' ] ],
					],
				],
				'preferences'=> [
					'type'       => 'object',
					'properties' => [
						'level' => [ 'type' => 'string', 'enum' => [ 'none', 'subtle', 'expressive' ] ],
					],
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'              => [ 'type' => 'boolean' ],
				'read_only'       => [ 'type' => 'boolean' ],
				'renderer'        => [ 'type' => 'string' ],
				'proposal_count'  => [ 'type' => 'integer' ],
				'recommended_id'  => [ 'type' => 'string' ],
				'no_motion_valid' => [ 'type' => 'boolean' ],
				'proposals'       => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
			],
			'required'   => [ 'ok', 'proposals', 'recommended_id' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public function execute( array $args ): array {
		return MotionSuggestEngine::suggest( $args );
	}
}
