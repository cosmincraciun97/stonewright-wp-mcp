<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Patterns;

use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\BlockMarkup;

/**
 * Shared helpers for synced-pattern (wp_block) writes.
 */
final class PatternSupport {

	public const TAXONOMY = 'wp_pattern_category';

	/**
	 * @return object|\WP_Error
	 */
	public static function require_pattern( int $id ) {
		if ( $id <= 0 ) {
			return new \WP_Error(
				'stonewright_not_found',
				__( 'Pattern not found.', 'stonewright' ),
				[ 'status' => 404 ]
			);
		}
		$post = get_post( $id );
		if ( ! $post || 'wp_block' !== (string) $post->post_type ) {
			return new \WP_Error(
				'stonewright_not_found',
				__( 'Pattern not found.', 'stonewright' ),
				[ 'status' => 404 ]
			);
		}
		return $post;
	}

	/**
	 * @return string|\WP_Error
	 */
	public static function sanitize_content( string $content ) {
		return BlockMarkup::sanitize( $content );
	}

	public static function can_write(): bool {
		return Permissions::edit_posts() && Permissions::edit_theme_options();
	}

	/**
	 * @param list<string> $slugs
	 * @return list<string>
	 */
	public static function assign_categories( int $post_id, array $slugs ): array {
		$clean = [];
		foreach ( $slugs as $slug ) {
			$slug = sanitize_title( (string) $slug );
			if ( '' === $slug ) {
				continue;
			}
			$clean[] = $slug;
			if ( function_exists( 'wp_insert_term' ) ) {
				wp_insert_term( $slug, self::TAXONOMY, [ 'slug' => $slug ] );
			}
		}
		$clean = array_values( array_unique( $clean ) );
		wp_set_object_terms( $post_id, $clean, self::TAXONOMY, false );
		return $clean;
	}

	/**
	 * @return list<string>
	 */
	public static function list_categories(): array {
		$found = [];
		if ( function_exists( 'get_block_pattern_categories' ) ) {
			$registered = get_block_pattern_categories();
			if ( is_array( $registered ) ) {
				foreach ( $registered as $slug => $details ) {
					if ( is_string( $slug ) && ! is_numeric( $slug ) && '' !== $slug ) {
						$found[] = sanitize_title( $slug );
						continue;
					}
					if ( is_array( $details ) && isset( $details['name'] ) ) {
						$name = sanitize_title( (string) $details['name'] );
						if ( '' !== $name ) {
							$found[] = $name;
						}
					}
				}
			}
		}
		if ( function_exists( 'get_terms' ) ) {
			$terms = get_terms(
				[
					'taxonomy'   => self::TAXONOMY,
					'hide_empty' => false,
				]
			);
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( is_object( $term ) ) {
						$slug = (string) $term->slug;
						if ( '' !== $slug ) {
							$found[] = $slug;
						}
					} elseif ( is_string( $term ) && '' !== $term ) {
						$found[] = $term;
					}
				}
			}
		}
		return array_values( array_unique( $found ) );
	}
}
