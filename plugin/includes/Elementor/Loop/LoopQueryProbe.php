<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Loop;

/**
 * Performs a bounded, read-only preflight for loop query intent.
 */
final class LoopQueryProbe {
	private const ALLOWED_KEYS = [
		'posts_per_page',
		'post__in',
		'post__not_in',
		'tax_query',
		'meta_query',
		'orderby',
		'order',
		'offset',
	];

	/**
	 * @param array<string, mixed> $query
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function probe( string $post_type, array $query, bool $require_results ): array|\WP_Error {
		$post_type = sanitize_key( $post_type );
		if ( '' === $post_type || ! get_post_type_object( $post_type ) ) {
			return new \WP_Error(
				'stonewright_loop_post_type_invalid',
				__( 'The requested loop post type is not registered.', 'stonewright' ),
				[ 'status' => 400, 'post_type' => $post_type ]
			);
		}

		$unknown = array_values( array_diff( array_keys( $query ), self::ALLOWED_KEYS ) );
		if ( [] !== $unknown ) {
			return new \WP_Error(
				'stonewright_loop_query_key_invalid',
				__( 'The loop query contains unsupported keys.', 'stonewright' ),
				[ 'status' => 400, 'unsupported_keys' => array_map( 'sanitize_key', $unknown ) ]
			);
		}

		$args = self::sanitize_query( $post_type, $query );
		$result = new \WP_Query( $args );
		$posts  = $result->posts;
		$found  = (int) $result->found_posts;
		if ( 0 === $found && $require_results ) {
			return new \WP_Error(
				'stonewright_loop_query_empty',
				__( 'The validated loop query returned no content.', 'stonewright' ),
				[
					'status'     => 409,
					'query_hash' => hash( 'sha256', (string) wp_json_encode( $args ) ),
				]
			);
		}

		$ids = array_map(
			self::post_id( ... ),
			$posts
		);

		return [
			'found'       => $found,
			'sampled_ids' => array_values( array_filter( array_slice( $ids, 0, 20 ) ) ),
			'warnings'    => 0 === $found ? [ 'query_returned_no_results' ] : [],
			'query_hash'  => hash( 'sha256', (string) wp_json_encode( $args ) ),
		];
	}

	/**
	 * @param array<string, mixed> $query
	 * @return array<string, mixed>
	 */
	private static function sanitize_query( string $post_type, array $query ): array {
		$args = [
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, min( 20, (int) ( $query['posts_per_page'] ?? 6 ) ) ),
			'fields'         => 'ids',
			'no_found_rows'  => false,
		];
		foreach ( [ 'post__in', 'post__not_in' ] as $key ) {
			if ( isset( $query[ $key ] ) && is_array( $query[ $key ] ) ) {
				$args[ $key ] = array_values( array_unique( array_filter( array_map( 'absint', $query[ $key ] ) ) ) );
			}
		}
		if ( isset( $query['offset'] ) ) {
			$args['offset'] = max( 0, (int) $query['offset'] );
		}
		if ( isset( $query['order'] ) ) {
			$args['order'] = 'ASC' === strtoupper( (string) $query['order'] ) ? 'ASC' : 'DESC';
		}
		if ( isset( $query['orderby'] ) ) {
			$args['orderby'] = sanitize_key( (string) $query['orderby'] );
		}
		if ( isset( $query['tax_query'] ) && is_array( $query['tax_query'] ) ) {
			$args['tax_query'] = self::sanitize_tax_query( $query['tax_query'] );
		}
		if ( isset( $query['meta_query'] ) && is_array( $query['meta_query'] ) ) {
			$args['meta_query'] = self::sanitize_meta_query( $query['meta_query'] );
		}

		return $args;
	}

	/** @return array<int|string, mixed> */
	private static function sanitize_tax_query( array $rows ): array {
		$clean = [];
		foreach ( $rows as $key => $row ) {
			if ( 'relation' === $key ) {
				$clean['relation'] = 'OR' === strtoupper( (string) $row ) ? 'OR' : 'AND';
				continue;
			}
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean[] = [
				'taxonomy' => sanitize_key( (string) ( $row['taxonomy'] ?? '' ) ),
				'field'    => sanitize_key( (string) ( $row['field'] ?? 'term_id' ) ),
				'terms'    => array_values( array_map( 'sanitize_text_field', (array) ( $row['terms'] ?? [] ) ) ),
				'operator' => self::allowed_value(
					strtoupper( trim( (string) ( $row['operator'] ?? 'IN' ) ) ),
					[ 'IN', 'NOT IN', 'AND', 'EXISTS', 'NOT EXISTS' ],
					'IN'
				),
			];
		}
		return $clean;
	}

	/** @return array<int|string, mixed> */
	private static function sanitize_meta_query( array $rows ): array {
		$clean = [];
		foreach ( $rows as $key => $row ) {
			if ( 'relation' === $key ) {
				$clean['relation'] = 'OR' === strtoupper( (string) $row ) ? 'OR' : 'AND';
				continue;
			}
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean[] = [
				'key'     => sanitize_key( (string) ( $row['key'] ?? '' ) ),
				'value'   => is_array( $row['value'] ?? null )
					? array_map( 'sanitize_text_field', $row['value'] )
					: sanitize_text_field( (string) ( $row['value'] ?? '' ) ),
				'compare' => self::allowed_value(
					strtoupper( trim( (string) ( $row['compare'] ?? '=' ) ) ),
					[ '=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'EXISTS', 'NOT EXISTS', 'REGEXP', 'NOT REGEXP', 'RLIKE' ],
					'='
				),
				'type'    => self::allowed_value(
					strtoupper( trim( (string) ( $row['type'] ?? 'CHAR' ) ) ),
					[ 'NUMERIC', 'BINARY', 'CHAR', 'DATE', 'DATETIME', 'DECIMAL', 'SIGNED', 'TIME', 'UNSIGNED' ],
					'CHAR'
				),
			];
		}
		return $clean;
	}

	/** @param list<string> $allowed */
	private static function allowed_value( string $value, array $allowed, string $fallback ): string {
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	private static function post_id( mixed $post ): int {
		if ( $post instanceof \WP_Post ) {
			return $post->ID;
		}
		if ( is_object( $post ) ) {
			$properties = get_object_vars( $post );
			return isset( $properties['ID'] ) ? (int) $properties['ID'] : 0;
		}
		return (int) $post;
	}
}
