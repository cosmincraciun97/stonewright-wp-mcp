<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Gutenberg;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\BlockSerializer;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class SerializeBlocks extends AbilityKernel {

	public function name(): string {
		return 'stonewright/blocks-serialize';
	}

	public function label(): string {
		return __( 'Serialize blocks', 'stonewright' );
	}

	public function description(): string {
		return __( 'Serializes a block tree into post-content HTML.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'blocks' => [ 'type' => 'array' ],
			],
			'required'             => [ 'blocks' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'html' => [ 'type' => 'string' ],
			],
			'required'   => [ 'html' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$blocks = (array) ( $args['blocks'] ?? [] );
		return [ 'html' => BlockSerializer::serialize( $blocks ) ];
	}
}
