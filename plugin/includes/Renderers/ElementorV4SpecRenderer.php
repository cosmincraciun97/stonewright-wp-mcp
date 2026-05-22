<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Renderers;

use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * EXPERIMENTAL — INCOMPLETE.
 *
 * Renders a Stonewright Design Spec into an Elementor V4 atomic element tree.
 *
 * The V4 atomic model uses `elType: 'e-flexbox'` containers and atomic widget
 * instances rather than the classic V3 section/column/widget nesting. A full
 * mapping from every Stonewright block type to a first-class atomic widget is
 * deferred to a later phase once the Elementor V4 atomic API stabilises.
 *
 * For now, each spec section is emitted as an `e-flexbox` container placeholder.
 * Child blocks are reported through diagnostics because V4 atomic widget
 * mappings are not implemented yet.
 *
 * TODO (Phase 10+): map block types to real atomic widget instances; bind
 *      kit variables and classes; handle nested containers.
 */
final class ElementorV4SpecRenderer {

	/**
	 * Convert a Stonewright Design Spec to an Elementor V4 atomic element tree.
	 *
	 * @param array<string, mixed> $spec
	 * @param array<int, array<string, mixed>>|null $diagnostics Unsupported-node diagnostics are appended here.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function render( array $spec, ?array &$diagnostics = null ): array|\WP_Error {
		$validated = Validator::validate( $spec );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$diagnostics ??= [];
		$spec      = $validated;
		$sections = isset( $spec['sections'] ) && is_array( $spec['sections'] ) ? $spec['sections'] : [];
		$out      = [];
		foreach ( $sections as $section_index => $section ) {
			$out[] = self::render_section( (array) $section, (int) $section_index, $diagnostics );
		}
		return $out;
	}

	/**
	 * Map a single spec section to an e-flexbox placeholder container.
	 *
	 * The `elements` array is intentionally empty in this stub; real child
	 * widget rendering will be added when atomic widget constructors are stable.
	 *
	 * @param array<string, mixed> $section
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @return array<string, mixed>
	 */
	private static function render_section( array $section, int $section_index, array &$diagnostics ): array {
		foreach ( (array) ( $section['blocks'] ?? [] ) as $block_index => $block ) {
			$block = (array) $block;
			$type  = (string) ( $block['type'] ?? '' );
			$diagnostics[] = [
				'code'     => 'unsupported_node',
				'type'     => '' !== $type ? $type : 'unknown',
				'path'     => [ 'sections', $section_index, 'blocks', (int) $block_index ],
				'renderer' => 'elementor_v4',
				'message'  => __( 'Elementor V4 atomic renderer does not support child node rendering yet.', 'stonewright' ),
			];
		}

		return [
			'id'       => ElementorData::generate_id(),
			'elType'   => 'e-flexbox',
			'settings' => [
				'classes'   => [],
				'variables' => [],
				'label'     => isset( $section['label'] ) ? (string) $section['label'] : '',
			],
			'elements' => [],
		];
	}
}
