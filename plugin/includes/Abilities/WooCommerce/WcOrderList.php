<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/** @stonewright-status stable */
final class WcOrderList extends AbilityKernel {

	public function name(): string {
		return 'stonewright/wc-order-list';
	}

	public function label(): string {
		return __( 'WooCommerce: Orders', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists WooCommerce orders read-only with HPOS-compatible native queries and no customer contact details.', 'stonewright' );
	}

	public function category(): string {
		return 'woocommerce';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'status'       => [ 'type' => 'string' ],
				'customer_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
				'date_created' => [ 'type' => 'string', 'description' => 'WooCommerce date query such as >=2026-01-01.' ],
				'per_page'     => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
				'page'         => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
				'order'        => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ], 'default' => 'DESC' ],
			],
		];
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
			static function ( array $args ): array|\WP_Error {
				if ( ! WooRuntime::available() || ! function_exists( 'wc_get_orders' ) ) {
					return [ 'supported' => false, 'hint' => 'WooCommerce order APIs are unavailable.' ];
				}
				$query = [
					'limit'    => min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) ),
					'page'     => max( 1, (int) ( $args['page'] ?? 1 ) ),
					'order'    => (string) ( $args['order'] ?? 'DESC' ),
					'orderby'  => 'date',
					'paginate' => true,
				];
				if ( isset( $args['status'] ) && '' !== (string) $args['status'] ) {
					$query['status'] = sanitize_key( (string) $args['status'] );
				}
				if ( isset( $args['customer_id'] ) ) {
					$query['customer_id'] = max( 1, (int) $args['customer_id'] );
				}
				if ( isset( $args['date_created'] ) && '' !== (string) $args['date_created'] ) {
					$date_created = sanitize_text_field( (string) $args['date_created'] );
					if ( 1 !== preg_match( '/^(?:[<>]=?)?\d{4}-\d{2}-\d{2}(?:\.\.\.(?:[<>]=?)?\d{4}-\d{2}-\d{2})?$/', $date_created ) ) {
						return new \WP_Error(
							'stonewright_wc_order_date_invalid',
							'date_created must use a bounded WooCommerce date query.',
							[ 'status' => 400 ]
						);
					}
					$query['date_created'] = $date_created;
				}
				$result = WooRuntime::get_orders( $query );
				if ( $result instanceof \WP_Error ) {
					return $result;
				}
				if ( ! is_array( $result ) && ! is_object( $result ) ) {
					return new \WP_Error( 'stonewright_wc_order_query_failed', 'WooCommerce order query failed.', [ 'status' => 500 ] );
				}
				$orders = is_object( $result ) && isset( $result->orders )
					? (array) $result->orders
					: ( is_array( $result ) ? $result : [] );
				$items  = [];
				foreach ( $orders as $order ) {
					if ( ! is_object( $order ) ) {
						continue;
					}
					$date = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
					$items[] = [
						'id'           => method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0,
						'status'       => method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '',
						'total'        => method_exists( $order, 'get_total' ) ? (string) $order->get_total() : '',
						'currency'     => method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : '',
						'date_created' => is_object( $date ) && method_exists( $date, 'date' ) ? (string) $date->date( DATE_ATOM ) : '',
						'customer_id'  => method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0,
						'item_count'   => method_exists( $order, 'get_item_count' ) ? (int) $order->get_item_count() : 0,
					];
				}
				return [
					'supported'   => true,
					'items'       => $items,
					'count'       => count( $items ),
					'total'       => is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $items ),
					'total_pages' => is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1,
					'page'        => (int) $query['page'],
				];
			}
		);
	}
}
