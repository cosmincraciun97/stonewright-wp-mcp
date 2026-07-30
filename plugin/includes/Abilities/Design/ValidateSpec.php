<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class ValidateSpec extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-validate-spec';
	}

	public function label(): string {
		return __( 'Validate Stonewright design spec', 'stonewright' );
	}

	public function description(): string {
		return __( 'Validates a design spec against the Stonewright JSON Schema and returns errors plus a normalized spec.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'spec' => [ 'type' => 'object' ],
			],
			'required'             => [ 'spec' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'valid'      => [ 'type' => 'boolean' ],
				'errors'     => [ 'type' => 'array' ],
				'normalized' => [ 'type' => 'object' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$result = Validator::validate( (array) $args['spec'] );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			return [
				'valid'      => false,
				'errors'     => is_array( $data ) && isset( $data['errors'] ) ? $data['errors'] : [],
				'normalized' => [],
			];
		}
		return [
			'valid'      => true,
			'errors'     => [],
			'normalized' => $result,
		];
	}
}
