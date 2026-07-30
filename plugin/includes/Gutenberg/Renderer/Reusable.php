<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Renderer;

/**
 * Renders a DesignSpec reusable node as a core/block (reusable pattern reference).
 *
 * Spec node shape:
 * {
 *   type: 'reusable',
 *   ref: <integer post_id>
 * }
 *
 * If ref is not a positive integer, returns null and appends a diagnostic.
 */
final class Reusable {

	/**
	 * @param array<string, mixed>             $node
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @return array<string, mixed>|null
	 */
	public static function render( array $node, string $path, array &$diagnostics ): ?array {
		$ref = isset( $node['ref'] ) ? (int) $node['ref'] : 0;

		if ( $ref <= 0 ) {
			$diagnostics[] = [
				'code'     => 'invalid_reusable_ref',
				'type'     => 'reusable',
				'path'     => $path,
				'renderer' => 'gutenberg',
				'message'  => 'Reusable block ref must be a positive integer post_id.',
			];
			return null;
		}

		$html = '';

		return [
			'blockName'    => 'core/block',
			'attrs'        => [ 'ref' => $ref ],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
			'innerBlocks'  => [],
		];
	}
}
