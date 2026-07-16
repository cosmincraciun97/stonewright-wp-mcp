<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Seo;

/**
 * Multi-plugin SEO meta adapter (Yoast, Rank Math, AIOSEO, SEOPress).
 */
final class SeoAdapter {

	/**
	 * @return array{id:string,label:string,sitemap:string}|null
	 */
	public static function detect(): ?array {
		// Unit/integration tests may force a plugin id or null without defining constants.
		if ( array_key_exists( 'stonewright_test_seo_plugin', $GLOBALS ) ) {
			$forced = $GLOBALS['stonewright_test_seo_plugin'];
			if ( null === $forced || false === $forced || '' === $forced ) {
				return null;
			}
			$id = (string) $forced;
			return match ( $id ) {
				'rankmath' => [ 'id' => 'rankmath', 'label' => 'Rank Math', 'sitemap' => '/sitemap_index.xml' ],
				'aioseo' => [ 'id' => 'aioseo', 'label' => 'All in One SEO', 'sitemap' => '/sitemap.xml' ],
				'seopress' => [ 'id' => 'seopress', 'label' => 'SEOPress', 'sitemap' => '/sitemaps.xml' ],
				default => [ 'id' => 'yoast', 'label' => 'Yoast SEO', 'sitemap' => '/sitemap_index.xml' ],
			};
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return [ 'id' => 'yoast', 'label' => 'Yoast SEO', 'sitemap' => '/sitemap_index.xml' ];
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return [ 'id' => 'rankmath', 'label' => 'Rank Math', 'sitemap' => '/sitemap_index.xml' ];
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return [ 'id' => 'aioseo', 'label' => 'All in One SEO', 'sitemap' => '/sitemap.xml' ];
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return [ 'id' => 'seopress', 'label' => 'SEOPress', 'sitemap' => '/sitemaps.xml' ];
		}
		return null;
	}

	/**
	 * @return array<string, string>
	 */
	public static function meta_keys( string $plugin_id ): array {
		return match ( $plugin_id ) {
			'yoast' => [
				'title'         => '_yoast_wpseo_title',
				'description'   => '_yoast_wpseo_metadesc',
				'focus_keyword' => '_yoast_wpseo_focuskw',
				'canonical'     => '_yoast_wpseo_canonical',
				'noindex'       => '_yoast_wpseo_meta-robots-noindex',
			],
			'rankmath' => [
				'title'         => 'rank_math_title',
				'description'   => 'rank_math_description',
				'focus_keyword' => 'rank_math_focus_keyword',
				'canonical'     => 'rank_math_canonical_url',
				'noindex'       => 'rank_math_robots',
			],
			'aioseo' => [
				'title'         => '_aioseo_title',
				'description'   => '_aioseo_description',
				'focus_keyword' => '_aioseo_keywords',
				'canonical'     => '_aioseo_canonical_url',
				'noindex'       => '_aioseo_noindex',
			],
			'seopress' => [
				'title'         => '_seopress_titles_title',
				'description'   => '_seopress_titles_desc',
				'focus_keyword' => '_seopress_analysis_target_kw',
				'canonical'     => '_seopress_robots_canonical',
				'noindex'       => '_seopress_robots_index',
			],
			default => [],
		};
	}

	/**
	 * @return array{plugin:string,title:string,description:string,focus_keyword:string,canonical:string,noindex:bool}| \WP_Error
	 */
	public static function get_meta( int $post_id ): array|\WP_Error {
		$plugin = self::detect();
		if ( null === $plugin ) {
			return new \WP_Error(
				'stonewright_plugin_missing',
				__( 'No supported SEO plugin is active (Yoast, Rank Math, AIOSEO, SEOPress).', 'stonewright' ),
				[ 'status' => 409 ]
			);
		}
		$keys = self::meta_keys( $plugin['id'] );
		$noindex_raw = (string) get_post_meta( $post_id, $keys['noindex'], true );
		$noindex     = in_array( strtolower( $noindex_raw ), [ '1', 'true', 'noindex', 'on' ], true )
			|| ( is_array( maybe_unserialize( $noindex_raw ) ) && in_array( 'noindex', (array) maybe_unserialize( $noindex_raw ), true ) );

		return [
			'plugin'        => $plugin['id'],
			'title'         => (string) get_post_meta( $post_id, $keys['title'], true ),
			'description'   => (string) get_post_meta( $post_id, $keys['description'], true ),
			'focus_keyword' => (string) get_post_meta( $post_id, $keys['focus_keyword'], true ),
			'canonical'     => (string) get_post_meta( $post_id, $keys['canonical'], true ),
			'noindex'       => $noindex,
		];
	}

	/**
	 * @param array<string, mixed> $patch
	 * @return array{plugin:string,title:string,description:string,focus_keyword:string,canonical:string,noindex:bool}| \WP_Error
	 */
	public static function update_meta( int $post_id, array $patch ): array|\WP_Error {
		$plugin = self::detect();
		if ( null === $plugin ) {
			return new \WP_Error(
				'stonewright_plugin_missing',
				__( 'No supported SEO plugin is active (Yoast, Rank Math, AIOSEO, SEOPress).', 'stonewright' ),
				[ 'status' => 409 ]
			);
		}
		$keys = self::meta_keys( $plugin['id'] );
		$map  = [
			'title'         => $keys['title'],
			'description'   => $keys['description'],
			'focus_keyword' => $keys['focus_keyword'],
			'canonical'     => $keys['canonical'],
		];
		foreach ( $map as $field => $meta_key ) {
			if ( array_key_exists( $field, $patch ) ) {
				update_post_meta( $post_id, $meta_key, (string) $patch[ $field ] );
			}
		}
		if ( array_key_exists( 'noindex', $patch ) ) {
			$val = (bool) $patch['noindex'] ? '1' : '0';
			update_post_meta( $post_id, $keys['noindex'], $val );
		}
		return self::get_meta( $post_id );
	}
}
