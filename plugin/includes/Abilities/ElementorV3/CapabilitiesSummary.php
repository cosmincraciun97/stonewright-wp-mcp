<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Design\ImplementationContract;
use Stonewright\WpMcp\Elementor\WidgetRegistry\EditorTabKnowledge;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Compact Elementor capability map for first-pass planning.
 *
 * @stonewright-status stable
 */
final class CapabilitiesSummary extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-capabilities-summary';
	}

	public function label(): string {
		return __( 'Elementor V3 capabilities summary', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns a compact Elementor V3 capability summary so agents can plan native-widget builds in one round trip.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'primary_write_tool'             => [ 'type' => 'string' ],
				'batch_write_tool'               => [ 'type' => 'string' ],
				'mutation_batch_tool'            => [ 'type' => 'string' ],
				'container_schema_tool'          => [ 'type' => 'string' ],
				'media_batch_tool'               => [ 'type' => 'string' ],
				'content_batch_tool'             => [ 'type' => 'string' ],
				'wp_cli_batch_tool'              => [ 'type' => 'string' ],
				'global_style_read_tool'         => [ 'type' => 'string' ],
				'default_response_mode'          => [ 'type' => 'string' ],
				'status'                         => [ 'type' => 'object' ],
				'design_implementation_contract' => [ 'type' => 'object' ],
				'native_widgets'                 => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'responsive_controls'            => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'advanced_controls'              => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'renderer_limits'                => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'first_pass_rules'               => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			],
			'required'   => [ 'primary_write_tool', 'renderer_limits', 'first_pass_rules' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$status = ( new Status() )->execute( [] );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		return [
			'primary_write_tool'             => 'stonewright/elementor-v3-build-page-from-spec',
			'batch_write_tool'               => 'stonewright/elementor-v3-apply-bundle',
			'mutation_batch_tool'            => 'stonewright/elementor-v3-batch-mutate',
			'container_schema_tool'          => 'stonewright/elementor-v3-container-schema',
			'media_batch_tool'               => 'stonewright/media-upload-batch',
			'content_batch_tool'             => 'stonewright/content-bulk-upsert-posts',
			'wp_cli_batch_tool'              => 'stonewright-wp-cli-batch-run',
			'global_style_read_tool'         => 'stonewright/elementor-v3-get-kit-globals',
			'default_response_mode'          => 'summary',
			'design_implementation_contract' => ImplementationContract::contract(),
			'status'                         => [
				'installed'                => (bool) ( $status['installed'] ?? false ),
				'active'                   => (bool) ( $status['active'] ?? false ),
				'version'                  => (string) ( $status['version'] ?? '' ),
				'has_pro'                  => (bool) ( $status['has_pro'] ?? false ),
				'pro_elements_active'      => (bool) ( $status['pro_elements_active'] ?? false ),
				'v4_atomic_support_status' => (string) ( $status['v4_atomic_support_status'] ?? 'unknown' ),
			],
			'native_widgets'      => self::native_widgets(),
			'responsive_controls' => [
				'width',
				'height',
				'min_height',
				'padding',
				'margin',
				'gap',
				'font_size',
				'line_height',
				'visibility',
			],
			'advanced_controls'   => EditorTabKnowledge::advanced_control_keys(),
			'renderer_limits'     => [
				'Validated Stonewright Design Spec only.',
				'No arbitrary PHP, shell, JavaScript, or Elementor custom-code execution.',
				'No Elementor HTML widget by default.',
				'Browser screenshots and Figma extraction stay in external MCPs, not Stonewright.',
			],
			'first_pass_rules'    => [
				'Call stonewright/workflow-preflight before write tasks.',
				'For visual work, verify external Playwright/browser MCP before the first write.',
				'Read active Elementor kit globals with stonewright/elementor-v3-get-kit-globals before updating kit colors or typography.',
				'Prefer native widgets; do not use Elementor HTML widgets unless explicitly allowed.',
				'Upload all known remote assets with stonewright/media-upload-batch before building the page.',
				'For Loop Grid or repeated dynamic cards backed by CPT/meta, write rows with stonewright/content-bulk-upsert-posts before the Elementor tree write.',
				'For repeated CPT UI, ACF, option, term, plugin, and cache commands, use stonewright-wp-cli-batch-run with responseMode=summary.',
				'For design-derived pages, implement at most two sections per write-and-verify batch; prefer one dense section per batch.',
				'Auto-continue to the next section batch only after desktop, tablet, and mobile checks pass.',
				'Use one validated section-batch spec per visual pass; use apply-bundle only when multiple posts must change together.',
				'For repeated cards or grids, use a validated spec first pass; use stonewright/elementor-v3-batch-mutate for surgical add/update/move/remove edits on an existing page.',
				'Use build-page-from-spec dry_run before writes when the agent needs element_count, diagnostics, or a no-write preview.',
				'For custom container layout settings, call stonewright/elementor-v3-container-schema before writing flex, spacing, or responsive container controls.',
				'Set style_policy=strict for design-derived visual specs and include style_source or style._source before applying borders, radius, shadows, or filters.',
				'For every widget used, call stonewright/elementor-v3-get-widget-schema in summary mode and inspect Content, Style, and Advanced controls before writing settings; request responseMode=full only when defaults are required.',
				'For existing pages, call stonewright/elementor-v3-get-page-structure in summary mode before surgical edits; request responseMode=full only when raw settings are required.',
				'Name major parent containers semantically; do not over-name every inner utility container.',
				'Plan desktop, tablet, and mobile values before first write.',
				'Use max-width, percentage width, gap, and responsive padding instead of fixed viewport-wide canvases.',
				'Review renderer diagnostics before screenshot iteration.',
			],
		];
	}

	/**
	 * @return list<string>
	 */
	private static function native_widgets(): array {
		return [
			'container',
			'heading',
			'text-editor',
			'image',
			'image-gallery',
			'button',
			'icon',
			'icon-box',
			'icon-list',
			'divider',
			'spacer',
			'nav-menu',
			'form',
			'slides',
			'social-icons',
			'tabs',
			'accordion',
			'toggle',
			'counter',
			'progress-bar',
			'video',
		];
	}
}
