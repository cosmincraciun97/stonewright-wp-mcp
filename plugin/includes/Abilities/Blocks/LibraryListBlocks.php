<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Blocks;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Lists registered blocks for one supported Gutenberg library.
 *
 * @stonewright-status stable
 */
final class LibraryListBlocks extends AbilityKernel {

	private const PREFIXES = [
		'generateblocks' => 'generateblocks/',
		'kadence'        => 'kadence/',
		'spectra'        => 'uagb/',
	];

	public function name(): string {
		return 'stonewright/blocks-library-list-blocks';
	}

	public function label(): string {
		return __( 'List library blocks', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists registered block names for GenerateBlocks, Kadence Blocks, or Spectra when that library is active.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'library' ],
			'properties'           => [
				'library' => [
					'type' => 'string',
					'enum' => [ 'generateblocks', 'kadence', 'spectra' ],
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'library' => [ 'type' => 'string' ],
				'blocks'  => [ 'type' => 'array' ],
				'count'   => [ 'type' => 'integer' ],
			],
			'required'   => [ 'library', 'blocks', 'count' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$library = (string) ( $args['library'] ?? '' );
		$prefix  = self::PREFIXES[ $library ] ?? '';
		if ( '' === $prefix ) {
			return $this->error( 'invalid_library', __( 'Unknown block library.', 'stonewright' ) );
		}

		$names = [];
		if ( class_exists( \WP_Block_Type_Registry::class ) ) {
			foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
				if ( str_starts_with( (string) $name, $prefix ) ) {
					$names[] = (string) $name;
				}
			}
		}
		sort( $names );

		return [
			'library' => $library,
			'blocks'  => $names,
			'count'   => count( $names ),
		];
	}
}
