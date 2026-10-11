<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ContentModel;

/**
 * The names a custom post type or taxonomy may not take, and the length limits core enforces.
 *
 * Core registers a post type with a name longer than 20 characters as an error, a taxonomy with a name longer
 * than 32, and a name that is also a public query variable breaks front-end routing. A name that another
 * plugin or the theme already registered is refused too, unless it is an entry Stonewright made earlier.
 */
final class ReservedNames {

	public const POST_TYPE_MAX_LENGTH = 20;

	public const TAXONOMY_MAX_LENGTH = 32;

	/** Registered by core, or used by core as a query variable or an internal type. */
	private const SHARED = [
		'action',
		'attachment',
		'attachment_id',
		'author',
		'author_name',
		'calendar',
		'cat',
		'category',
		'category__and',
		'category__in',
		'category__not_in',
		'category_name',
		'comments_per_page',
		'comments_popup',
		'custom',
		'custom_css',
		'customize_changeset',
		'customize_messenger_channel',
		'customized',
		'cpage',
		'day',
		'debug',
		'embed',
		'error',
		'exact',
		'feed',
		'fields',
		'hour',
		'link_category',
		'm',
		'minute',
		'monthnum',
		'more',
		'name',
		'nav_menu',
		'nav_menu_item',
		'nonce',
		'nopaging',
		'offset',
		'oembed_cache',
		'order',
		'orderby',
		'p',
		'page',
		'page_id',
		'paged',
		'pagename',
		'pb',
		'perm',
		'post',
		'post__in',
		'post__not_in',
		'post_format',
		'post_mime_type',
		'post_status',
		'post_tag',
		'post_type',
		'posts',
		'posts_per_archive_page',
		'posts_per_page',
		'preview',
		'revision',
		'robots',
		's',
		'search',
		'second',
		'sentence',
		'showposts',
		'static',
		'status',
		'subpost',
		'subpost_id',
		'tag',
		'tag__and',
		'tag__in',
		'tag__not_in',
		'tag_id',
		'tag_slug__and',
		'tag_slug__in',
		'taxonomy',
		'tb',
		'term',
		'terms',
		'theme',
		'themes',
		'title',
		'type',
		'types',
		'user_request',
		'w',
		'withcomments',
		'withoutcomments',
		'year',
	];

	/**
	 * An error for a post type slug that cannot be registered, or null when it can.
	 *
	 * @param bool $ours True when the slug already has a Stonewright (CPT UI-compatible) entry, so a registration updates it.
	 */
	public static function post_type_error( string $slug, bool $ours = false ): ?\WP_Error {
		$problem = self::common_problem( $slug, self::POST_TYPE_MAX_LENGTH );
		if ( null === $problem && ! $ours && null !== get_post_type_object( $slug ) ) {
			$problem = __( 'A post type with this name is already registered by WordPress, a plugin or the theme. Choose another slug.', 'stonewright' );
		}
		return null === $problem ? null : new \WP_Error( 'stonewright_cpt_slug_invalid', $problem, [ 'status' => 400, 'slug' => $slug ] );
	}

	/**
	 * An error for a taxonomy slug that cannot be registered, or null when it can.
	 *
	 * @param bool $ours True when the slug already has a Stonewright (CPT UI-compatible) entry, so a registration updates it.
	 */
	public static function taxonomy_error( string $slug, bool $ours = false ): ?\WP_Error {
		$problem = self::common_problem( $slug, self::TAXONOMY_MAX_LENGTH );
		if ( null === $problem && ! $ours && taxonomy_exists( $slug ) ) {
			$problem = __( 'A taxonomy with this name is already registered by WordPress, a plugin or the theme. Choose another slug.', 'stonewright' );
		}
		return null === $problem ? null : new \WP_Error( 'stonewright_taxonomy_invalid', $problem, [ 'status' => 400, 'slug' => $slug ] );
	}

	private static function common_problem( string $slug, int $max ): ?string {
		if ( '' === $slug ) {
			return __( 'slug is invalid: use lowercase letters, numbers, underscores and dashes.', 'stonewright' );
		}
		if ( strlen( $slug ) > $max ) {
			/* translators: %d: the longest slug core accepts. */
			return sprintf( __( 'slug is too long: core accepts at most %d characters.', 'stonewright' ), $max );
		}
		if ( in_array( $slug, self::SHARED, true ) || str_starts_with( $slug, 'wp_' ) ) {
			return __( 'This slug is reserved by WordPress. Choose another slug.', 'stonewright' );
		}
		return null;
	}
}
