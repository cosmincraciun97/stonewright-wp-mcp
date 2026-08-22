<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Design\Motion\MotionCapabilityResolver;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Read-only motion capability digest for the current site runtime.
 *
 * Reports renderer availability, versions, native motion capabilities, device
 * support, schema fingerprint, fallbacks, approval requirements, warnings, and
 * unsupported reasons. Never writes anything.
 *
 * @stonewright-status stable
 */
final class MotionCapabilities extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-motion-capabilities';
	}

	public function label(): string {
		return __( 'Design motion capabilities', 'stonewright' );
	}

	public function description(): string {
		return __( 'Read-only digest of native motion capabilities per renderer (Gutenberg/FSE, Elementor V3, Elementor V4) from the live runtime, with schema fingerprint, unsupported reasons, and approval requirements.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'mode' => [
					'type'        => 'string',
					'enum'        => [ MotionCapabilityResolver::MODE_SUMMARY, MotionCapabilityResolver::MODE_FULL ],
					'description' => 'summary keeps output bounded; full expands per-capability detail.',
					'default'     => MotionCapabilityResolver::MODE_SUMMARY,
				],
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                    => [ 'type' => 'boolean' ],
				'mode'                  => [ 'type' => 'string' ],
				'read_only'             => [ 'type' => 'boolean' ],
				'versions'              => [ 'type' => 'object' ],
				'renderers'             => [ 'type' => 'object' ],
				'schema_fingerprint'    => [ 'type' => 'string' ],
				'fallbacks'             => [ 'type' => 'object' ],
				'approval_requirements' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
				'warnings'              => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
				'unsupported'           => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
			],
			'required'   => [ 'ok', 'renderers', 'schema_fingerprint' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function execute( array $args ): array {
		$mode = is_string( $args['mode'] ?? null ) ? (string) $args['mode'] : MotionCapabilityResolver::MODE_SUMMARY;

		return ( new MotionCapabilityResolver() )->digest( $mode );
	}
}
