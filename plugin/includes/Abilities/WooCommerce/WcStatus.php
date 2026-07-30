<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Expertise\IntegrationCatalog;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/** @stonewright-status stable */
final class WcStatus extends AbilityKernel {

	public function name(): string {
		return 'stonewright/wc-status';
	}

	public function label(): string {
		return __( 'WooCommerce: Status', 'stonewright' );
	}

	public function description(): string {
		return __( 'Reports WooCommerce availability, versions, storage mode, registered product types, Woo blocks, and integration support levels.', 'stonewright' );
	}

	public function category(): string {
		return 'woocommerce';
	}

	public function output_schema(): array {
		return [ 'type' => 'object', 'additionalProperties' => true ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_woocommerce();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			static function (): array {
				if ( ! WooRuntime::available() ) {
					return [
						'supported' => false,
						'hint'      => 'WooCommerce is not active on this site.',
					];
				}

				$product_types = function_exists( 'wc_get_product_types' )
					? array_keys( (array) wc_get_product_types() )
					: [ 'simple', 'grouped', 'external', 'variable' ];
				$hpos_callback = [ '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ];
				$hpos_enabled  = is_callable( $hpos_callback ) ? (bool) call_user_func( $hpos_callback ) : false;
				$woo_blocks = [];
				if ( class_exists( '\WP_Block_Type_Registry' ) ) {
					$registry   = \WP_Block_Type_Registry::get_instance();
					$registered = method_exists( $registry, 'get_all_registered' )
						? array_keys( (array) $registry->get_all_registered() )
						: [];
					$woo_blocks = array_values(
						array_filter(
							$registered,
							static fn( string $name ): bool => str_starts_with( $name, 'woocommerce/' )
						)
					);
				}

				return [
					'supported'     => true,
					'version'       => WooRuntime::version(),
					'hpos_enabled'  => $hpos_enabled,
					'product_types' => array_values( array_map( 'sanitize_key', $product_types ) ),
					'woo_blocks'    => $woo_blocks,
					'integrations'  => IntegrationCatalog::inspect(),
				];
			}
		);
	}
}
