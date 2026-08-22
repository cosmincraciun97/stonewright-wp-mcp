<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Blocks;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Expertise\IntegrationCatalog;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Presence check for a Gutenberg block library.
 *
 * @stonewright-status stable
 */
final class LibraryCheckSetup extends AbilityKernel {

	public const LIBRARIES = [
		'generateblocks' => 'generateblocks',
		'kadence'        => 'kadence-blocks',
		'spectra'        => 'spectra',
		'blocksy'        => 'blocksy',
	];

	public function name(): string {
		return 'stonewright/blocks-library-check-setup';
	}

	public function label(): string {
		return __( 'Check block library setup', 'stonewright' );
	}

	public function description(): string {
		return __( 'Reports whether GenerateBlocks, Kadence Blocks, Spectra, or Blocksy is active and which version was detected.', 'stonewright' );
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
					'enum' => [ 'generateblocks', 'kadence', 'spectra', 'blocksy' ],
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'library' => [ 'type' => 'string' ],
				'active'  => [ 'type' => 'boolean' ],
				'version' => [ 'type' => 'string' ],
				'status'  => [ 'type' => 'string' ],
			],
			'required'   => [ 'library', 'active', 'version', 'status' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$library = (string) ( $args['library'] ?? '' );
		$catalog_id = self::LIBRARIES[ $library ] ?? '';
		if ( '' === $catalog_id ) {
			return $this->error( 'invalid_library', __( 'Unknown block library.', 'stonewright' ) );
		}

		foreach ( IntegrationCatalog::inspect() as $row ) {
			if ( $catalog_id !== (string) ( $row['id'] ?? '' ) ) {
				continue;
			}
			$version = (string) ( $row['version'] ?? '' );
			return [
				'library' => $library,
				'active'  => '' !== $version,
				'version' => $version,
				'status'  => (string) ( $row['status'] ?? 'unavailable' ),
			];
		}

		return [
			'library' => $library,
			'active'  => false,
			'version' => '',
			'status'  => 'unavailable',
		];
	}
}
