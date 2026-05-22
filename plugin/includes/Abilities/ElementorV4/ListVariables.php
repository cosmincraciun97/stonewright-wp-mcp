<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Lists design variables stored in the active Elementor kit's settings.
 * Variables are stored under the 'e_atomic_variables' key in kit page meta.
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status experimental
 */
final class ListVariables extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v4-list-variables';
	}

	public function label(): string {
		return __( 'List Elementor V4 variables', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns all design variables (color, font, size, string) registered in the active Elementor kit.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function meta(): array {
		return [ 'experimental' => true ];
	}

	public function output_schema(): array {
		return [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'properties' => [
					'id'           => [ 'type' => 'string' ],
					'name'         => [ 'type' => 'string' ],
					'type'         => [ 'type' => 'string', 'enum' => [ 'color', 'font', 'size', 'string' ] ],
					'value'        => [ 'type' => 'string' ],
					'default_mode' => [ 'type' => 'string' ],
				],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		if ( ! get_option( 'stonewright_elementor_v4_atomic', false ) ) {
			return new \WP_Error( 'feature_disabled', __( 'Elementor V4 atomic features are disabled.', 'stonewright' ) );
		}
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$kit_id = $this->get_active_kit_id();
				if ( 0 === $kit_id ) {
					return $this->error( 'no_kit', __( 'No active Elementor kit found.', 'stonewright' ) );
				}

				$variables = $this->read_kit_variables( $kit_id );
				return $variables;
			}
		);
	}

	private function get_active_kit_id(): int {
		if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\\Elementor\\Plugin' ) ) {
			return 0;
		}
		try {
			$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
			return $kit ? (int) $kit->get_id() : 0;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function read_kit_variables( int $kit_id ): array {
		$raw = get_post_meta( $kit_id, '_elementor_page_settings', true );
		if ( ! is_array( $raw ) ) {
			$raw = is_string( $raw ) ? json_decode( $raw, true ) : [];
		}
		$raw = is_array( $raw ) ? $raw : [];

		$collection = $raw['e_atomic_variables'] ?? [];
		if ( ! is_array( $collection ) ) {
			return [];
		}

		$out = [];
		foreach ( $collection as $var ) {
			if ( ! is_array( $var ) ) {
				continue;
			}
			$out[] = [
				'id'           => (string) ( $var['id'] ?? '' ),
				'name'         => (string) ( $var['name'] ?? '' ),
				'type'         => (string) ( $var['type'] ?? 'string' ),
				'value'        => (string) ( $var['value'] ?? '' ),
				'default_mode' => (string) ( $var['default_mode'] ?? 'light' ),
			];
		}
		return $out;
	}
}
