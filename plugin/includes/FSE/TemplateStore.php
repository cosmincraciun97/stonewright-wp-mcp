<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\FSE;

/**
 * Storage conventions for user-edited templates and template parts.
 *
 * WordPress keeps a customized template as a `wp_template` /
 * `wp_template_part` post whose `post_name` is the bare slug and whose
 * `wp_theme` term names the theme the template belongs to (template parts also
 * carry a `wp_template_part_area` term). `get_block_template()` resolves a
 * "theme//slug" id from exactly that shape, so every Stonewright template
 * write and lookup goes through this class.
 */
final class TemplateStore {

	public const TEMPLATE      = 'wp_template';
	public const TEMPLATE_PART = 'wp_template_part';

	private const STATUSES = [ 'publish', 'auto-draft', 'draft' ];

	/**
	 * Find the stored post for a template.
	 *
	 * Resolves the "theme//slug" id through core. Posts that an earlier version
	 * wrote under a composite post_name and without a `wp_theme` term are found
	 * as well, so the next write can repair them.
	 */
	public static function find( string $slug, string $theme, string $post_type ): ?object {
		$post = self::find_through_core( $slug, $theme, $post_type );
		return $post ?? self::find_unregistered( $slug, $theme, $post_type );
	}

	/**
	 * Insert a template the way core stores it.
	 *
	 * @param array<string, mixed> $fields post_title, post_content and post_excerpt.
	 * @return int|\WP_Error
	 */
	public static function insert( string $post_type, string $slug, string $theme, array $fields, string $area = '' ): int|\WP_Error {
		$tax_input = [ 'wp_theme' => [ $theme ] ];
		if ( '' !== $area ) {
			$tax_input['wp_template_part_area'] = [ $area ];
		}

		$post_id = wp_insert_post(
			wp_slash(
				array_merge(
					[
						'post_type'   => $post_type,
						'post_name'   => $slug,
						'post_title'  => $slug,
						'post_status' => 'publish',
						'tax_input'   => $tax_input,
					],
					$fields
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		self::assign_terms( (int) $post_id, $theme, $area );
		return (int) $post_id;
	}

	/**
	 * Make an existing post match the core storage shape: slug as post_name,
	 * theme (and area) as terms.
	 */
	public static function normalize( object $post, string $slug, string $theme, string $area = '' ): void {
		if ( (string) ( $post->post_name ?? '' ) !== $slug ) {
			wp_update_post( wp_slash( [ 'ID' => (int) $post->ID, 'post_name' => $slug ] ) );
		}
		self::assign_terms( (int) $post->ID, $theme, $area );
	}

	public static function assign_terms( int $post_id, string $theme, string $area = '' ): void {
		wp_set_object_terms( $post_id, $theme, 'wp_theme', false );
		if ( '' !== $area ) {
			wp_set_object_terms( $post_id, $area, 'wp_template_part_area', false );
		}
	}

	private static function find_through_core( string $slug, string $theme, string $post_type ): ?object {
		if ( ! function_exists( 'get_block_template' ) ) {
			return null;
		}
		$template = get_block_template( $theme . '//' . $slug, $post_type );
		$wp_id    = is_object( $template ) ? (int) ( $template->wp_id ?? 0 ) : 0;
		if ( $wp_id <= 0 ) {
			return null;
		}
		$post = get_post( $wp_id );
		return $post && $post_type === $post->post_type ? $post : null;
	}

	/**
	 * A post written under the composite name "theme//slug" (WordPress turns
	 * the slashes into a hyphen or drops them) that has no `wp_theme` term.
	 */
	private static function find_unregistered( string $slug, string $theme, string $post_type ): ?object {
		$candidates = array_values( array_unique( array_filter( [ sanitize_title( $theme . '//' . $slug ), $theme . '-' . $slug ] ) ) );
		foreach ( $candidates as $name ) {
			$posts = get_posts(
				[
					'post_type'      => $post_type,
					'name'           => $name,
					'posts_per_page' => 1,
					'post_status'    => self::STATUSES,
				]
			);
			if ( empty( $posts ) || ! is_object( $posts[0] ) ) {
				continue;
			}
			$terms = get_the_terms( $posts[0], 'wp_theme' );
			if ( false === $terms || is_wp_error( $terms ) || [] === $terms ) {
				return $posts[0];
			}
		}
		return null;
	}
}
