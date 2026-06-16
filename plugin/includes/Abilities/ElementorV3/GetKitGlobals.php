<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Compact read of active Elementor kit colors and typography.
 *
 * @stonewright-status stable
 */
final class GetKitGlobals extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-get-kit-globals';
	}

	public function label(): string {
		return __( 'Get Elementor kit globals', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns a compact active Elementor kit color and typography snapshot for global-style-first design implementation.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'max_items' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 200,
					'description' => 'Maximum colors and typography entries returned per collection.',
					'default'     => 80,
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'kit_id'             => [ 'type' => 'integer' ],
				'kit_title'          => [ 'type' => 'string' ],
				'settings_keys'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'colors'             => [ 'type' => 'object' ],
				'typography'         => [ 'type' => 'object' ],
				'token_plan'         => [ 'type' => 'object' ],
				'next_actions'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			],
			'required'   => [ 'kit_id', 'colors', 'typography', 'next_actions' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$kit_id = (int) get_option( 'elementor_active_kit', 0 );
		if ( 0 === $kit_id ) {
			return $this->error( 'no_kit', __( 'No active Elementor kit.', 'stonewright' ) );
		}

		$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		$max_items = isset( $args['max_items'] ) ? max( 1, min( 200, (int) $args['max_items'] ) ) : 80;
		$post      = get_post( $kit_id );

		$colors = [
			'system' => self::limit_items( self::normalize_colors( $settings['system_colors'] ?? [] ), $max_items ),
			'custom' => self::limit_items( self::normalize_colors( $settings['custom_colors'] ?? [] ), $max_items ),
			'global' => self::limit_items( self::normalize_colors( $settings['global_colors'] ?? [] ), $max_items ),
		];
		$typography = [
			'system' => self::limit_items( self::normalize_typography( $settings['system_typography'] ?? [] ), $max_items ),
			'custom' => self::limit_items( self::normalize_typography( $settings['custom_typography'] ?? [] ), $max_items ),
			'global' => self::limit_items( self::normalize_typography( $settings['global_typography'] ?? [] ), $max_items ),
		];

		return [
			'kit_id'        => $kit_id,
			'kit_title'     => is_object( $post ) ? (string) $post->post_title : '',
			'settings_keys' => array_values( array_map( 'strval', array_keys( $settings ) ) ),
			'colors'        => [
				'counts' => self::counts( $colors ),
				'items'  => $colors,
			],
			'typography'    => [
				'counts' => self::counts( $typography ),
				'items'  => $typography,
			],
			'token_plan'    => [
				'read_tool'             => 'stonewright/elementor-v3-get-kit-globals',
				'color_write_tool'      => 'stonewright/elementor-v3-update-kit-colors',
				'typography_write_tool' => 'stonewright/elementor-v3-update-kit-typography',
				'page_write_tool'       => 'stonewright/elementor-v3-build-page-from-spec',
				'principle'             => 'Update reusable site-wide tokens first; keep one-off Figma values local to the page spec.',
			],
			'next_actions'  => [
				'Compare extracted design colors and typography with this kit snapshot before page writes.',
				'Only update Elementor kit globals when reusable values are approved for site-wide use.',
				'Run build-page-from-spec after globals are settled so section specs do not repeat raw values unnecessarily.',
			],
		];
	}

	/**
	 * @param mixed $items
	 * @return list<array<string, string>>
	 */
	private static function normalize_colors( mixed $items ): array {
		if ( ! is_array( $items ) ) {
			return [];
		}

		$out = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$out[] = [
				'id'    => (string) ( $item['_id'] ?? $item['id'] ?? '' ),
				'title' => (string) ( $item['title'] ?? $item['name'] ?? '' ),
				'color' => (string) ( $item['color'] ?? $item['value'] ?? '' ),
			];
		}
		return $out;
	}

	/**
	 * @param mixed $items
	 * @return list<array<string, mixed>>
	 */
	private static function normalize_typography( mixed $items ): array {
		if ( ! is_array( $items ) ) {
			return [];
		}

		$out = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$out[] = [
				'id'             => (string) ( $item['_id'] ?? $item['id'] ?? '' ),
				'title'          => (string) ( $item['title'] ?? $item['name'] ?? '' ),
				'font_family'    => (string) ( $item['typography_font_family'] ?? $item['font_family'] ?? '' ),
				'font_weight'    => (string) ( $item['typography_font_weight'] ?? $item['font_weight'] ?? '' ),
				'font_size'      => $item['typography_font_size'] ?? $item['font_size'] ?? null,
				'line_height'    => $item['typography_line_height'] ?? $item['line_height'] ?? null,
				'letter_spacing' => $item['typography_letter_spacing'] ?? $item['letter_spacing'] ?? null,
			];
		}
		return $out;
	}

	/**
	 * @template T
	 * @param list<T> $items
	 * @return list<T>
	 */
	private static function limit_items( array $items, int $max_items ): array {
		return array_slice( $items, 0, $max_items );
	}

	/**
	 * @param array<string, list<mixed>> $groups
	 * @return array<string, int>
	 */
	private static function counts( array $groups ): array {
		return array_map( 'count', $groups );
	}
}
