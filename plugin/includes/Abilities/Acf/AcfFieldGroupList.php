<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Acf;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status stable */
final class AcfFieldGroupList extends AbilityKernel {

	public function name(): string {
		return 'stonewright/acf-field-group-list';
	}

	public function label(): string {
		return __( 'ACF: List field groups', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists ACF field groups as compact DTOs.', 'stonewright' );
	}

	public function category(): string {
		return 'acf';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object', 'additionalProperties' => true ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_acf();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			static function ( array $args ) {
				if ( ! AcfRuntime::is_active() || ! function_exists( 'acf_get_field_groups' ) ) {
					return new \WP_Error(
						'stonewright_plugin_missing',
						__( 'ACF is not active on this site.', 'stonewright' ),
						[ 'status' => 409 ]
					);
				}
				$groups = acf_get_field_groups();
				$items  = [];
				foreach ( (array) $groups as $g ) {
					if ( ! is_array( $g ) ) {
						continue;
					}
					$items[] = [
						'key'              => (string) ( $g['key'] ?? '' ),
						'title'            => (string) ( $g['title'] ?? '' ),
						'active'           => (bool) ( $g['active'] ?? true ),
						'location_summary' => isset( $g['location'] ) ? (string) wp_json_encode( $g['location'] ) : '',
					];
				}
				return [ 'items' => $items, 'total' => count( $items ) ];
			}
		);
	}
}
