<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/** @stonewright-status stable */
final class WcSalesReport extends AbilityKernel {

	public function name(): string {
		return 'stonewright/wc-sales-report';
	}

	public function label(): string {
		return __( 'WooCommerce: Sales report', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns a bounded, HPOS-compatible gross sales summary without customer data.', 'stonewright' );
	}

	public function category(): string {
		return 'woocommerce';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'period'     => [ 'type' => 'string', 'enum' => [ 'last_7_days', 'last_30_days', 'year_to_date', 'custom' ], 'default' => 'last_30_days' ],
				'date_from'  => [ 'type' => 'string', 'format' => 'date' ],
				'date_to'    => [ 'type' => 'string', 'format' => 'date' ],
				'statuses'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'default' => [ 'completed', 'processing' ] ],
				'max_orders' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 500 ],
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
				$range = self::date_range( $args );
				if ( $range instanceof \WP_Error ) {
					return $range;
				}
				$max_orders = min( 1000, max( 1, (int) ( $args['max_orders'] ?? 500 ) ) );
				$query      = [
					'limit'        => $max_orders,
					'page'         => 1,
					'paginate'     => true,
					'status'       => array_values( array_map( 'sanitize_key', (array) ( $args['statuses'] ?? [ 'completed', 'processing' ] ) ) ),
					'date_created' => $range['from'] . '...' . $range['to'],
					'orderby'      => 'date',
					'order'        => 'ASC',
				];
				$result = WooRuntime::get_orders( $query );
				if ( $result instanceof \WP_Error ) {
					return $result;
				}
				if ( ! is_array( $result ) && ! is_object( $result ) ) {
					return new \WP_Error( 'stonewright_wc_sales_query_failed', 'WooCommerce sales query failed.', [ 'status' => 500 ] );
				}
				$orders = is_object( $result ) && isset( $result->orders )
					? (array) $result->orders
					: ( is_array( $result ) ? $result : [] );
				$gross       = 0.0;
				$refunds     = 0.0;
				$currency    = '';
				$status_count = [];
				foreach ( $orders as $order ) {
					if ( ! is_object( $order ) ) {
						continue;
					}
					$gross += method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0.0;
					$refunds += method_exists( $order, 'get_total_refunded' ) ? (float) $order->get_total_refunded() : 0.0;
					if ( '' === $currency && method_exists( $order, 'get_currency' ) ) {
						$currency = (string) $order->get_currency();
					}
					$status = method_exists( $order, 'get_status' ) ? (string) $order->get_status() : 'unknown';
					$status_count[ $status ] = ( $status_count[ $status ] ?? 0 ) + 1;
				}
				$total = is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $orders );
				ksort( $status_count );
				return [
					'supported'       => true,
					'period'          => (string) ( $args['period'] ?? 'last_30_days' ),
					'date_from'       => $range['from'],
					'date_to'         => $range['to'],
					'order_count'     => count( $orders ),
					'total_matching'  => $total,
					'complete'        => $total <= $max_orders,
					'gross_total'     => $gross,
					'refunded_total'  => $refunds,
					'net_total'       => $gross - $refunds,
					'currency'        => $currency,
					'count_by_status' => $status_count,
				];
			}
		);
	}

	/** @param array<string, mixed> $args @return array{from:string,to:string}|\WP_Error */
	private static function date_range( array $args ): array|\WP_Error {
		$now    = current_datetime();
		$period = (string) ( $args['period'] ?? 'last_30_days' );
		if ( 'custom' === $period ) {
			$from = (string) ( $args['date_from'] ?? '' );
			$to   = (string) ( $args['date_to'] ?? '' );
			if ( ! self::valid_date( $from ) || ! self::valid_date( $to ) || $from > $to ) {
				return new \WP_Error(
					'stonewright_wc_sales_date_invalid',
					'Custom sales reports require valid date_from and date_to values.',
					[ 'status' => 400 ]
				);
			}
			return [ 'from' => $from, 'to' => $to ];
		}
		$from = match ( $period ) {
			'last_7_days' => $now->modify( '-6 days' ),
			'year_to_date' => $now->setDate( (int) $now->format( 'Y' ), 1, 1 ),
			default => $now->modify( '-29 days' ),
		};
		return [ 'from' => $from->format( 'Y-m-d' ), 'to' => $now->format( 'Y-m-d' ) ];
	}

	private static function valid_date( string $value ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return false !== $date && $date->format( 'Y-m-d' ) === $value;
	}
}
