<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\WooCommerce\Catalog;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/** @stonewright-status stable */
final class WcCatalogAudit extends AbilityKernel {

	public function name(): string {
		return 'stonewright/wc-catalog-audit';
	}

	public function label(): string {
		return __( 'WooCommerce: Catalog audit', 'stonewright' );
	}

	public function description(): string {
		return __( 'Runs a bounded, read-only catalog quality audit for SKU, price, image, category, stock, and variation gaps.', 'stonewright' );
	}

	public function category(): string {
		return 'woocommerce';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'limit'       => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 250, 'default' => 100 ],
				'page'        => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
				'status'      => [ 'type' => 'string', 'enum' => [ 'any', 'draft', 'pending', 'private', 'publish' ], 'default' => 'publish' ],
				'issue_limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 250, 'default' => 100 ],
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
				if ( ! WooRuntime::available() ) {
					return [ 'supported' => false, 'hint' => 'WooCommerce is not active on this site.' ];
				}
				$limit    = min( 250, max( 1, (int) ( $args['limit'] ?? 100 ) ) );
				$products = WooRuntime::get_products(
					[
						'limit'  => $limit,
						'page'   => max( 1, (int) ( $args['page'] ?? 1 ) ),
						'status' => (string) ( $args['status'] ?? 'publish' ),
						'return' => 'objects',
					]
				);
				if ( $products instanceof \WP_Error ) {
					return $products;
				}
				if ( ! is_array( $products ) ) {
					return new \WP_Error( 'stonewright_wc_catalog_audit_failed', 'WooCommerce catalog query failed.' );
				}

				$issue_limit = min( 250, max( 1, (int) ( $args['issue_limit'] ?? 100 ) ) );
				$issues      = [];
				$sku_ids     = [];
				$by_type     = [];
				$by_stock    = [];
				foreach ( $products as $product ) {
					if ( ! is_object( $product ) ) {
						continue;
					}
					$row          = Catalog::product_summary( $product );
					$id           = (int) $row['id'];
					$type         = (string) $row['type'];
					$stock_status = (string) $row['stock_status'];
					$sku          = trim( (string) $row['sku'] );
					$by_type[ $type ]       = ( $by_type[ $type ] ?? 0 ) + 1;
					$by_stock[ $stock_status ] = ( $by_stock[ $stock_status ] ?? 0 ) + 1;

					if ( '' === $sku ) {
						self::add_issue( $issues, $issue_limit, $id, 'missing_sku' );
					} else {
						$sku_ids[ strtolower( $sku ) ][] = $id;
					}
					if ( '' === (string) $row['regular_price'] && ! in_array( $type, [ 'grouped', 'variable' ], true ) ) {
						self::add_issue( $issues, $issue_limit, $id, 'missing_regular_price' );
					}
					if ( 0 === (int) $row['image_id'] ) {
						self::add_issue( $issues, $issue_limit, $id, 'missing_image' );
					}
					if ( [] === $row['category_ids'] ) {
						self::add_issue( $issues, $issue_limit, $id, 'missing_category' );
					}
					$children = method_exists( $product, 'get_children' )
						? Catalog::int_list( $product->get_children() )
						: [];
					if ( 'variable' === $type && [] === $children ) {
						self::add_issue( $issues, $issue_limit, $id, 'variable_without_variations' );
					}
				}
				foreach ( $sku_ids as $ids ) {
					if ( count( $ids ) < 2 ) {
						continue;
					}
					foreach ( $ids as $id ) {
						self::add_issue( $issues, $issue_limit, (int) $id, 'duplicate_sku' );
					}
				}

				ksort( $by_type );
				ksort( $by_stock );
				return [
					'supported'      => true,
					'scanned'        => count( $products ),
					'count_by_type'  => $by_type,
					'count_by_stock' => $by_stock,
					'issues'         => $issues,
					'issues_count'   => count( $issues ),
					'issues_capped'  => count( $issues ) >= $issue_limit,
					'next_page'      => count( $products ) === $limit ? max( 1, (int) ( $args['page'] ?? 1 ) ) + 1 : null,
				];
			}
		);
	}

	/** @param list<array{id:int,code:string}> $issues */
	private static function add_issue( array &$issues, int $limit, int $product_id, string $code ): void {
		if ( count( $issues ) >= $limit ) {
			return;
		}
		$issues[] = [ 'id' => $product_id, 'code' => $code ];
	}
}
