<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\DesignSpec;

/**
 * DesignSpec version migrations.
 *
 * Version 1 remains fully supported. Callers that need v2 keys (native policy,
 * content facts, design system) should migrate before validating against
 * the v2 schema.
 */
final class Migrator {

	/**
	 * Lift a DesignSpec 1.x payload to 2.0.0 without changing renderable structure.
	 *
	 * @param array<string, mixed> $spec
	 * @return array<string, mixed>
	 */
	public static function v1_to_v2( array $spec ): array {
		$out = $spec;

		$out['version'] = '2.0.0';

		// Preserve page / sections / tokens / assets / responsive as-is.
		if ( ! isset( $out['page'] ) || ! is_array( $out['page'] ) ) {
			$out['page'] = [ 'title' => '' ];
		}
		if ( ! isset( $out['sections'] ) || ! is_array( $out['sections'] ) ) {
			$out['sections'] = [];
		}

		// Promote v1 tokens into design_system when present.
		// Only include non-empty maps so JSON encoding never emits [] for object fields.
		if ( ! isset( $out['design_system'] ) || ! is_array( $out['design_system'] ) ) {
			$tokens = isset( $out['tokens'] ) && is_array( $out['tokens'] ) ? $out['tokens'] : [];
			$system = [];
			$map    = [
				'colors'     => $tokens['colors'] ?? null,
				'typography' => $tokens['typography'] ?? null,
				'spacing'    => $tokens['spacing'] ?? null,
				'radii'      => $tokens['radius'] ?? null,
				'shadows'    => $tokens['shadow'] ?? null,
			];
			foreach ( $map as $key => $value ) {
				if ( is_array( $value ) && [] !== $value ) {
					$system[ $key ] = $value;
				}
			}
			$out['design_system'] = $system;
		}

		if ( ! isset( $out['content_facts'] ) || ! is_array( $out['content_facts'] ) ) {
			$out['content_facts'] = [
				'required_facts'     => [],
				'blocking_questions' => [],
				'facts'              => [],
			];
		}

		if ( ! isset( $out['page_intent'] ) || ! is_array( $out['page_intent'] ) ) {
			$out['page_intent'] = [
				'page_type'        => 'landing',
				'conversion_goal'  => '',
				'audience'         => '',
			];
		}

		if ( ! isset( $out['native_policy'] ) || ! is_array( $out['native_policy'] ) ) {
			$out['native_policy'] = [
				'strict'                            => false,
				'block_html_widgets'                => true,
				'require_native_gap_for_custom_css' => true,
				'enforce_heading_hierarchy'         => true,
			];
		}

		if ( ! isset( $out['verification_policy'] ) || ! is_array( $out['verification_policy'] ) ) {
			$out['verification_policy'] = [
				'structural_readback'   => true,
				'responsive_breakpoints'=> [ 'desktop', 'tablet', 'mobile' ],
				'contrast_check'        => false,
				'rollback_on_failure'   => true,
			];
		}

		// Ensure every block has a stable id for v2 node mapping.
		foreach ( $out['sections'] as $si => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$blocks = isset( $section['blocks'] ) && is_array( $section['blocks'] ) ? $section['blocks'] : [];
			foreach ( $blocks as $bi => $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				if ( ! isset( $block['id'] ) || '' === (string) $block['id'] ) {
					$block['id'] = sprintf( 's%d_b%d', (int) $si, (int) $bi );
				}
				$blocks[ $bi ] = $block;
			}
			$section['blocks']     = $blocks;
			$out['sections'][ $si ] = $section;
		}

		return $out;
	}
}
