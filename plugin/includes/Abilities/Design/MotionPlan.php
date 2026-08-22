<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Design\Motion\MotionPlanCompiler;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Read-only motion plan compilation (dry-run tier of the pipeline).
 *
 * Validates the spec, lowers semantic motion to renderer operations, binds a
 * plan hash over every input fingerprint. Writes stay in the renderer-specific
 * apply abilities.
 *
 * @stonewright-status stable
 */
final class MotionPlan extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-motion-plan';
	}

	public function label(): string {
		return __( 'Design motion plan', 'stonewright' );
	}

	public function description(): string {
		return __( 'Read-only deterministic lowering of a validated DesignSpec motion contract into renderer-specific operations with a bound plan hash; no writes.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'spec' ],
			'properties'           => [
				'spec'              => [ 'type' => 'object', 'description' => 'Raw DesignSpec payload; validated before any lowering.' ],
				'renderer'          => [
					'type'    => 'string',
					'enum'    => [ 'gutenberg-fse', 'elementor-v3', 'elementor-v4' ],
					'default' => 'gutenberg-fse',
				],
				'capability_digest' => [ 'type' => 'object', 'description' => 'Output of stonewright/design-motion-capabilities.' ],
				'direction'         => [
					'type'       => 'object',
					'properties' => [
						'id'                 => [ 'type' => 'string' ],
						'version'            => [ 'type' => 'string' ],
						'hash'               => [ 'type' => 'string' ],
						'entrance_animation' => [ 'type' => 'string', 'enum' => [ 'blocked', 'hero_only', 'allowed' ] ],
					],
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'          => [ 'type' => 'boolean' ],
				'read_only'   => [ 'type' => 'boolean' ],
				'mode'        => [ 'type' => 'string' ],
				'renderer'    => [ 'type' => 'string' ],
				'plan_hash'   => [ 'type' => 'string' ],
				'bindings'    => [ 'type' => 'object' ],
				'operations'  => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
				'unsupported' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
				'warnings'    => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
				'summary'     => [ 'type' => 'object' ],
			],
			'required'   => [ 'ok', 'plan_hash', 'operations' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $args ) {
		return MotionPlanCompiler::compile(
			is_array( $args['spec'] ?? null ) ? $args['spec'] : [],
			[
				'renderer'          => is_string( $args['renderer'] ?? null ) ? $args['renderer'] : 'gutenberg-fse',
				'capability_digest' => is_array( $args['capability_digest'] ?? null ) ? $args['capability_digest'] : null,
				'direction'         => is_array( $args['direction'] ?? null ) ? $args['direction'] : null,
			]
		);
	}
}
