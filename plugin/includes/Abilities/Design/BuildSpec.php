<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Validates and normalises a raw design spec payload before rendering.
 *
 * @stonewright-status stable
 */
final class BuildSpec extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-build-spec';
	}

	public function label(): string {
		return __( 'Build design spec', 'stonewright' );
	}

	public function description(): string {
		return __( 'Assembles a Stonewright Design Spec from a list of section descriptors and an optional token bundle.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'page'     => [ 'type' => 'object' ],
				'tokens'   => [ 'type' => 'object' ],
				'sections' => [ 'type' => 'array' ],
				'source'   => [ 'type' => 'object' ],
			],
			'required'             => [ 'page', 'sections' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'version'  => [ 'type' => 'string' ],
				'source'   => [ 'type' => 'object' ],
				'page'     => [ 'type' => 'object' ],
				'tokens'   => [ 'type' => 'object' ],
				'sections' => [ 'type' => 'array' ],
			],
			'required'   => [ 'version', 'page', 'sections' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		// Contract decision: Validator::validate() now returns the normalized
		// spec array directly, or WP_Error(stonewright_spec_invalid).
		$spec = [
			'version'  => '1.0.0',
			'source'   => isset( $args['source'] ) && is_array( $args['source'] ) ? $args['source'] : new \stdClass(),
			'page'     => (array) $args['page'],
			'tokens'   => isset( $args['tokens'] ) && is_array( $args['tokens'] ) ? $args['tokens'] : new \stdClass(),
			'sections' => (array) $args['sections'],
		];

		$result = Validator::validate( $spec );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result;
	}
}
