<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
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
		return __( 'Returns compact global-style, section-batch, native-widget, and verification rules for fast design implementation.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'version'             => [ 'type' => 'string' ],
				'sequence'            => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'global_styles_first' => [ 'type' => 'object', 'additionalProperties' => true ],
				'section_batch'       => [ 'type' => 'object', 'additionalProperties' => true ],
				'native_widget_map'   => [ 'type' => 'object', 'additionalProperties' => true ],
				'token_efficiency'    => [ 'type' => 'object', 'additionalProperties' => true ],
				'hard_failures'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			],
			'required'   => [
				'version',
				'sequence',
				'global_styles_first',
				'section_batch',
				'native_widget_map',
				'token_efficiency',
				'hard_failures',
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		return self::contract();
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function contract(): array {
		return [
			'version'             => '1.0.0',
			'sequence'            => [
				'global_styles_first',
				'section_reference_tokens',
				'native_widget_map',
				'one_section_build',
				'screenshot_delta',
				'next_section_or_surgical_batch',
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
				'invented_border_radius_shadow_filter',
				'html_widget_without_explicit_allow_html_widget',
				'no_global_style_plan_before_elementor_write',
				'no_same_viewport_screenshot_after_section_batch',
				'fixed_canvas_width_causing_horizontal_scroll',
				'no_full_page_screenshot_backgrounds',
			],
		];
	}
}
