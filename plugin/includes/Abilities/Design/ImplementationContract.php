<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\DesignSpec\Validator as SpecValidator;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Compact design-to-WordPress contract for fast, high-fidelity MCP agents.
 *
 * @stonewright-status stable
 */
final class ImplementationContract extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-implementation-contract';
	}

	public function label(): string {
		return __( 'Design implementation contract', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns compact global-style, section-batch, native-widget, and verification rules for fast design implementation. Optional action=validate rejects custom_css without a native_gap justification.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'action'      => [
					'type'        => 'string',
					'enum'        => [ 'contract', 'validate' ],
					'default'     => 'contract',
					'description' => 'contract returns rules; validate enforces CSS-only-with-native_gap on a DesignSpec.',
				],
				'spec'        => [
					'type'        => 'object',
					'description' => 'DesignSpec to validate when action=validate.',
				],
				'native_plan' => [
					'type'        => 'object',
					'description' => 'Optional plan from design-native-plan; CSS is allowed only where native_gap is recorded.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'version'             => [ 'type' => 'string' ],
				'ok'                  => [ 'type' => 'boolean' ],
				'sequence'            => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'global_styles_first' => [ 'type' => 'object', 'additionalProperties' => true ],
				'section_batch'       => [ 'type' => 'object', 'additionalProperties' => true ],
				'native_widget_map'   => [ 'type' => 'object', 'additionalProperties' => true ],
				'design_evidence'     => [ 'type' => 'object', 'additionalProperties' => true ],
				'native_first'        => [ 'type' => 'object', 'additionalProperties' => true ],
				'custom_code_phase'   => [ 'type' => 'object', 'additionalProperties' => true ],
				'token_efficiency'    => [ 'type' => 'object', 'additionalProperties' => true ],
				'hard_failures'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'verification'        => [ 'type' => 'object', 'additionalProperties' => true ],
			],
			'required'   => [
				'version',
				'sequence',
				'global_styles_first',
				'section_batch',
				'native_widget_map',
				'design_evidence',
				'native_first',
				'custom_code_phase',
				'token_efficiency',
				'hard_failures',
			],
			'additionalProperties' => true,
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$action = (string) ( $args['action'] ?? 'contract' );
		if ( 'validate' === $action ) {
			$spec = isset( $args['spec'] ) && is_array( $args['spec'] ) ? $args['spec'] : [];
			$plan = isset( $args['native_plan'] ) && is_array( $args['native_plan'] ) ? $args['native_plan'] : [];
			return self::validate_build_spec( $spec, $plan );
		}
		return self::contract();
	}

	/**
	 * Enforce: custom CSS only where a justified native_gap exists.
	 *
	 * @param array<string, mixed> $spec
	 * @param array<string, mixed> $native_plan Optional plan from design-native-plan.
	 * @return array{ok: bool, version: string, errors: list<array<string, mixed>>}|\WP_Error
	 */
	public static function validate_build_spec( array $spec, array $native_plan = [] ) {
		// Force native_gap requirement even if the caller omitted native_policy.
		if ( ! isset( $spec['native_policy'] ) || ! is_array( $spec['native_policy'] ) ) {
			$spec['native_policy'] = [];
		}
		$spec['native_policy']['require_native_gap_for_custom_css'] = true;
		$spec['native_policy']['block_html_widgets']                = $spec['native_policy']['block_html_widgets'] ?? true;

		// Promote plan-level native_gap onto matching block ids so CSS can cite the plan.
		$plan_gaps = [];
		foreach ( (array) ( $native_plan['native_phase']['operations'] ?? [] ) as $op ) {
			if ( ! is_array( $op ) || null === ( $op['native_gap'] ?? null ) ) {
				continue;
			}
			$nid = (string) ( $op['node_id'] ?? '' );
			if ( '' !== $nid && is_array( $op['native_gap'] ) ) {
				$plan_gaps[ $nid ] = $op['native_gap'];
			}
		}
		if ( [] !== $plan_gaps && isset( $spec['sections'] ) && is_array( $spec['sections'] ) ) {
			foreach ( $spec['sections'] as $si => $section ) {
				if ( ! is_array( $section ) || ! isset( $section['blocks'] ) || ! is_array( $section['blocks'] ) ) {
					continue;
				}
				foreach ( $section['blocks'] as $bi => $block ) {
					if ( ! is_array( $block ) ) {
						continue;
					}
					$block_id = (string) ( $block['id'] ?? '' );
					$has_reason = is_array( $block['native_gap'] ?? null )
						&& '' !== trim( (string) ( $block['native_gap']['reason'] ?? '' ) );
					if ( ! $has_reason && '' !== $block_id && isset( $plan_gaps[ $block_id ] ) ) {
						$spec['sections'][ $si ]['blocks'][ $bi ]['native_gap'] = $plan_gaps[ $block_id ];
					}
				}
			}
		}

		$errors = SpecValidator::native_policy_checks( $spec );

		// Explicit hard-fail keyword for the contract (in addition to native_policy_custom_css).
		foreach ( $errors as $i => $row ) {
			if ( is_array( $row ) && ( $row['keyword'] ?? '' ) === 'native_policy_custom_css' ) {
				$errors[ $i ]['keyword'] = 'custom_css_without_native_gap';
			}
		}

		if ( [] !== $errors ) {
			return new \WP_Error(
				'stonewright_spec_invalid',
				__( 'Design build violates the implementation contract (custom CSS without native_gap or other native policy failures).', 'stonewright' ),
				[
					'status' => 400,
					'errors' => $errors,
				]
			);
		}

		return [
			'ok'         => true,
			'version'    => self::contract()['version'],
			'errors'     => [],
			'css_policy' => [
				'allowed_only_with_native_gap' => true,
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function contract(): array {
		return [
			'version'             => '2.0.0',
			'sequence'            => [
				'design_evidence',
				'semantic_validation',
				'global_styles_first',
				'native_plan',
				'native_dry_run',
				'native_write_and_readback',
				'customization_proposal_if_needed',
			],
			'design_evidence'     => [
				'tool'  => 'stonewright/design-native-plan',
				'rules' => [
					'vendor_neutral',
					'per_style_provenance',
					'resolved_actions',
					'no_raw_figma_tree_after_normalization',
					'visual_build_gate',
					'figma_token_table_or_spacing_typography_color_tokens',
					'measured_targets_per_breakpoint',
					'layout_intent_flex_grid_alignment',
				],
			],
			'native_first'        => [
				'order' => [ 'builder_controls', 'global_classes_variables', 'native_widget_composition', 'wordpress_content_model' ],
				'raw_settings_from_ai' => false,
				'engines' => [ 'elementor', 'gutenberg', 'fse' ],
				'per_element' => 'native_mapping OR justified native_gap',
			],
			'custom_code_phase'   => [
				'auto_apply' => false,
				'approval_required' => true,
				'order' => [ 'css', 'css_js', 'custom_php' ],
				'required' => [ 'remaining_delta', 'reason', 'diff', 'risk', 'rollback', 'tests' ],
				'css_only_with_native_gap' => true,
				'gate' => 'stonewright/design-implementation-contract action=validate → stonewright_spec_invalid without native_gap.reason',
			],
			'verification'        => [
				'owner' => 'agent_playwright_or_browser_mcp',
				'tolerances' => [
					'spacing_px'   => 2,
					'color_hex'    => 'exact_after_token_resolution',
					'font_size_px' => 0,
					'line_height'  => 0.05,
				],
				'loop' => 'render → screenshot each breakpoint frame → compare measured values to DesignEvidence measured_targets → iterate',
			],
			'global_styles_first' => [
				'status' => 'required_before_first_elementor_write',
				'tools'  => [
					'stonewright/elementor-v3-get-kit-globals',
					'stonewright/elementor-v3-update-kit-colors',
					'stonewright/elementor-v3-update-kit-typography',
				],
				'rules'  => [
					'read_active_elementor_kit_colors_and_typography',
					'map_reusable_design_colors_and_typography_to_elementor_kit',
					'keep_one_off_values_local_unless_user_approved_site_wide_changes',
					'reuse_global_tokens_in_specs_after_kit_update',
				],
			],
			'section_batch'       => [
				'default_sections_per_pass'             => 1,
				'max_sections_per_pass'                 => 2,
				'primary_write_tool'                    => 'stonewright/elementor-v3-build-page-from-spec',
				'surgical_fix_tool'                     => 'stonewright/elementor-v3-batch-mutate',
				'required_evidence_before_first_write'  => [
					'figma_or_reference_section_bounds',
					'desktop_tablet_mobile_token_table',
					'asset_crop_and_media_reuse_audit',
					'native_widget_mapping',
				],
				'required_evidence_before_next_batch'   => [
					'desktop_screenshot_same_viewport',
					'tablet_screenshot_same_viewport',
					'mobile_screenshot_same_viewport',
					'overflow_check',
					'visible_delta_list',
				],
				'dry_run_first'                         => true,
				'style_policy'                          => 'strict',
			],
			'native_widget_map'   => [
				'layout'        => 'container',
				'heading'       => 'heading',
				'body_text'     => 'text-editor',
				'image'         => 'image',
				'gallery'       => 'image-gallery',
				'cta'           => 'button',
				'countdown'     => 'countdown',
				'navigation'    => 'nav-menu',
				'form'          => 'form',
				'social_links'  => 'social-icons',
				'icon_list'     => 'icon-list',
				'dynamic_cards' => 'loop-grid',
			],
			'token_efficiency'    => [
				'wp_cli_response_mode' => 'summary',
				'batch_tools'          => [
					'stonewright/media-upload-batch',
					'stonewright/content-bulk-upsert-posts',
					'stonewright/wp-cli-batch-run',
					'stonewright/elementor-v3-batch-mutate',
					'stonewright/elementor-v3-apply-bundle',
				],
				'rules'                => [
					'use_summary_outputs_for_repeated_cli_work',
					'prefer_targeted_element_reads_after_first_structure_snapshot',
					'compare_section_screenshots_instead_of_full_page_until_final_gate',
				],
			],
			'hard_failures'       => [
				'unresolved_action',
				'unproven_non_neutral_style',
				'ai_generated_raw_elementor_settings',
				'custom_code_without_explicit_approval',
				'custom_css_without_native_gap',
				'invented_border_radius_shadow_filter',
				'html_widget_without_explicit_allow_html_widget',
				'no_global_style_plan_before_elementor_write',
				'no_same_viewport_screenshot_after_section_batch',
				'fixed_canvas_width_causing_horizontal_scroll',
				'no_full_page_screenshot_backgrounds',
				'raw_figma_tree_after_normalization',
			],
		];
	}
}
