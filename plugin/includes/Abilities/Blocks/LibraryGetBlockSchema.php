<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Blocks;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Gutenberg\GetBlockSchema;
use Stonewright\WpMcp\Elementor\Schema\PlainLlmSchemaConverter;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Returns a registered block schema for a supported library prefix.
 *
 * @stonewright-status stable
 */
final class LibraryGetBlockSchema extends AbilityKernel {

	private const PREFIXES = [
		'generateblocks' => 'generateblocks/',
		'kadence'        => 'kadence/',
		'spectra'        => 'uagb/',
	];

	public function name(): string {
		return 'stonewright/blocks-library-get-schema';
	}

	public function label(): string {
		return __( 'Get library block schema', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns the registered block.json schema for one GenerateBlocks, Kadence, or Spectra block.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'library', 'name' ],
			'properties'           => [
				'library' => [
					'type' => 'string',
					'enum' => [ 'generateblocks', 'kadence', 'spectra' ],
				],
				'name'    => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$library = (string) ( $args['library'] ?? '' );
		$name    = (string) ( $args['name'] ?? '' );
		$prefix  = self::PREFIXES[ $library ] ?? '';
		if ( '' === $prefix || ! str_starts_with( $name, $prefix ) ) {
			return $this->error( 'invalid_block', __( 'Block name is not in the requested library prefix.', 'stonewright' ) );
		}

		$result = ( new GetBlockSchema() )->execute( [ 'name' => $name ] );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		if ( isset( $result['attributes'] ) && is_array( $result['attributes'] ) ) {
			$result['attributes'] = PlainLlmSchemaConverter::convert( $result['attributes'] );
		}

		return $result;
	}
}
