<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Acf;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status stable */
final class AcfFieldGroupGet extends AbilityKernel {

	public function name(): string {
		return 'stonewright/acf-field-group-get';
	}

	public function label(): string {
		return __( 'ACF: Get field group', 'stonewright' );
	}

	public function description(): string {
		return __( 'Gets one ACF field group and its fields as compact DTOs.', 'stonewright' );
	}

	public function category(): string {
		return 'acf';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'key' ],
			'properties'           => [
				'key' => [ 'type' => 'string', 'minLength' => 1 ],
			],
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
				if ( ! AcfRuntime::is_active() || ! function_exists( 'acf_get_field_group' ) ) {
					return new \WP_Error(
						'stonewright_plugin_missing',
						__( 'ACF is not active on this site.', 'stonewright' ),
						[ 'status' => 409 ]
					);
				}
				$key   = (string) $args['key'];
				$group = acf_get_field_group( $key );
				if ( ! is_array( $group ) ) {
					return new \WP_Error( 'stonewright_acf_group_not_found', 'Field group not found.' );
				}
				$fields  = function_exists( 'acf_get_fields' ) ? acf_get_fields( $key ) : [];
				$compact = [];
				foreach ( (array) $fields as $f ) {
					if ( ! is_array( $f ) ) {
						continue;
					}
					$compact[] = [
						'key'      => (string) ( $f['key'] ?? '' ),
						'name'     => (string) ( $f['name'] ?? '' ),
						'label'    => (string) ( $f['label'] ?? '' ),
						'type'     => (string) ( $f['type'] ?? '' ),
						'required' => (bool) ( $f['required'] ?? false ),
					];
				}
				return [
					'group'  => [
						'key'    => (string) ( $group['key'] ?? $key ),
						'title'  => (string) ( $group['title'] ?? '' ),
						'active' => (bool) ( $group['active'] ?? true ),
					],
					'fields' => $compact,
				];
			}
		);
	}
}
