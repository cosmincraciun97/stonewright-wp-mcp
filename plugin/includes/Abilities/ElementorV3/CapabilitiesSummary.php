<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
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
				'primary_write_tool' => [ 'type' => 'string' ],
				'batch_write_tool'   => [ 'type' => 'string' ],
				'media_batch_tool'   => [ 'type' => 'string' ],
				'status'             => [ 'type' => 'object' ],
				'native_widgets'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'responsive_controls'=> [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'renderer_limits'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'first_pass_rules'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
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
			'primary_write_tool'  => 'stonewright/elementor-v3-build-page-from-spec',
			'batch_write_tool'    => 'stonewright/elementor-v3-apply-bundle',
			'media_batch_tool'    => 'stonewright/media-upload-batch',
			'status'              => [
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
			'renderer_limits'     => [
				'Validated Stonewright Design Spec only.',
				'No arbitrary PHP, shell, JavaScript, or Elementor custom-code execution.',
				'No Elementor HTML widget by default.',
				'Browser screenshots and Figma extraction stay in external MCPs, not Stonewright.',
			],
			'first_pass_rules'    => [
				'Call stonewright/workflow-preflight before write tasks.',
				'Prefer native widgets; do not use Elementor HTML widgets unless explicitly allowed.',
				'Upload all known remote assets with stonewright/media-upload-batch before building the page.',
				'Use one validated page spec and one build call for normal pages; use apply-bundle only when multiple posts must change together.',
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
