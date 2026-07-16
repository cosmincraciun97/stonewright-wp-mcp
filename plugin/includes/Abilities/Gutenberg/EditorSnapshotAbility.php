<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Gutenberg;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Gutenberg\EditorSnapshot;
use Stonewright\WpMcp\Security\Permissions;

/**
 * One-call Gutenberg/FSE editor snapshot.
 *
 * @stonewright-status stable
 */
final class EditorSnapshotAbility extends AbilityKernel {

	public function name(): string {
		return 'stonewright/gutenberg-editor-snapshot';
	}

	public function label(): string {
		return __( 'Gutenberg editor snapshot', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns a compact Gutenberg/FSE editor snapshot: theme type, templates summary, global styles presence, and registered block count.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => new \stdClass(),
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'             => [ 'type' => 'boolean' ],
				'theme'          => [ 'type' => 'object' ],
				'templates'      => [ 'type' => 'object' ],
				'template_parts' => [ 'type' => 'object' ],
				'global_styles'  => [ 'type' => 'object' ],
				'blocks'         => [ 'type' => 'object' ],
				'capabilities'   => [ 'type' => 'object' ],
			],
			'required'   => [ 'ok', 'theme', 'blocks' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return EditorSnapshot::capture();
	}
}
