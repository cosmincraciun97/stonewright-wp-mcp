<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Seo;

/**
 * Multi-plugin SEO meta adapter (Yoast, Rank Math, AIOSEO, SEOPress).
 */
final class SeoAdapter {

	/**
	 * The class that stands in for the AIOSEO post model in tests; null uses the real model.
	 *
	 * @var string|null
	 */
	private static ?string $aioseo_model = null;

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
	 * Names the class that stands in for the AIOSEO post model. For tests; production code never calls it.
	 *
	 * @internal
	 * @param class-string|null $class A class with a static getPost( int ) and an instance save(), or null for the real model.
	 */
	public static function use_aioseo_model_for_tests( ?string $class ): void {
		self::$aioseo_model = $class;
	}

	/**
	 * @return array{plugin:string,title:string,description:string,focus_keyword:string,canonical:string,noindex:bool|null,write_supported:bool}| \WP_Error
	 */
	public static function get_meta( int $post_id ): array|\WP_Error {
		$plugin = self::detect();
		if ( null === $plugin ) {
			return self::plugin_missing_error();
		}
		if ( 'aioseo' === $plugin['id'] ) {
			return self::get_aioseo( $post_id );
		}
		$keys = self::meta_keys( $plugin['id'] );

		return [
			'plugin'          => $plugin['id'],
			'title'           => (string) get_post_meta( $post_id, $keys['title'], true ),
			'description'     => (string) get_post_meta( $post_id, $keys['description'], true ),
			'focus_keyword'   => (string) get_post_meta( $post_id, $keys['focus_keyword'], true ),
			'canonical'       => (string) get_post_meta( $post_id, $keys['canonical'], true ),
			'noindex'         => self::read_noindex( $plugin['id'], $post_id ),
			'write_supported' => true,
		];
	}

	/**
	 * An error when this patch cannot be written through the active plugin, found before anything is written.
	 *
	 * @param array<string, mixed> $patch
	 */
	public static function write_error( int $post_id, array $patch ): ?\WP_Error {
		$plugin = self::detect();
		if ( null === $plugin ) {
			return self::plugin_missing_error();
		}
		if ( 'aioseo' !== $plugin['id'] ) {
			return null;
		}
		if ( null === self::aioseo_post( $post_id ) ) {
			return self::aioseo_unsupported_error( __( 'All in One SEO v4 keeps its data in its own table, and its post model is not available here, so nothing was written. Use the AIOSEO screens to change this post.', 'stonewright' ) );
		}
		if ( array_key_exists( 'focus_keyword', $patch ) ) {
			return self::aioseo_unsupported_error( __( 'All in One SEO stores focus keyphrases as structured data, so focus_keyword cannot be written. Nothing was written.', 'stonewright' ) );
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $patch
	 * @return array{plugin:string,title:string,description:string,focus_keyword:string,canonical:string,noindex:bool|null,write_supported:bool}| \WP_Error
	 */
	public static function update_meta( int $post_id, array $patch ): array|\WP_Error {
		$plugin = self::detect();
		if ( null === $plugin ) {
			return self::plugin_missing_error();
		}
		if ( 'aioseo' === $plugin['id'] ) {
			return self::update_aioseo( $post_id, $patch );
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
			self::write_noindex( $plugin['id'], $post_id, (bool) $patch['noindex'] );
		}
		$meta = self::get_meta( $post_id );
		if ( is_array( $meta ) && array_key_exists( 'noindex', $patch ) && (bool) $patch['noindex'] !== $meta['noindex'] ) {
			return new \WP_Error(
				'stonewright_seo_noindex_not_applied',
				__( 'The noindex setting did not read back as requested, so it was not applied.', 'stonewright' ),
				[ 'status' => 500, 'requested' => (bool) $patch['noindex'], 'stored' => $meta['noindex'] ]
			);
		}
		return $meta;
	}

	// ---------------------------------------------------------------------------------------------
	// The noindex codec of each plugin. A plugin keeps "do not index" in its own shape.
	// ---------------------------------------------------------------------------------------------

	/**
	 * Yoast: '1' is noindex, '2' is index, '0' follows the site default.
	 * Rank Math: an array of robots values that holds 'noindex' or 'index'.
	 * SEOPress: 'yes' is noindex; the key is absent otherwise.
	 */
	private static function read_noindex( string $plugin_id, int $post_id ): bool {
		$key = self::meta_keys( $plugin_id )['noindex'] ?? '';
		if ( '' === $key ) {
			return false;
		}
		$stored = maybe_unserialize( get_post_meta( $post_id, $key, true ) );
		return match ( $plugin_id ) {
			'rankmath' => in_array( 'noindex', self::robots_list( $stored ), true ),
			'seopress' => 'yes' === $stored,
			default    => in_array( strtolower( is_scalar( $stored ) ? (string) $stored : '' ), [ '1', 'true', 'noindex', 'on' ], true )
				|| ( is_array( $stored ) && in_array( 'noindex', $stored, true ) ),
		};
	}

	private static function write_noindex( string $plugin_id, int $post_id, bool $noindex ): void {
		$key = self::meta_keys( $plugin_id )['noindex'] ?? '';
		if ( '' === $key ) {
			return;
		}
		switch ( $plugin_id ) {
			case 'rankmath':
				// Keep the other robots values (nofollow, noarchive, ...) and swap only index and noindex.
				$list   = array_values( array_diff( self::robots_list( maybe_unserialize( get_post_meta( $post_id, $key, true ) ) ), [ 'index', 'noindex' ] ) );
				$list[] = $noindex ? 'noindex' : 'index';
				update_post_meta( $post_id, $key, $list );
				break;
			case 'seopress':
				if ( $noindex ) {
					update_post_meta( $post_id, $key, 'yes' );
				} else {
					delete_post_meta( $post_id, $key );
				}
				break;
			default:
				update_post_meta( $post_id, $key, $noindex ? '1' : '0' );
		}
	}

	/**
	 * @return list<string>
	 */
	private static function robots_list( mixed $stored ): array {
		if ( ! is_array( $stored ) ) {
			return [];
		}
		return array_values( array_filter( array_map( 'strval', $stored ), static fn ( string $value ): bool => '' !== $value ) );
	}

	// ---------------------------------------------------------------------------------------------
	// AIOSEO v4 keeps its data in its own table, so its post meta is not the source of truth. It is read and
	// written through its post model when that is available at runtime, and is reported as unsupported otherwise.
	// ---------------------------------------------------------------------------------------------

	private static function aioseo_post( int $post_id ): ?object {
		$class = self::$aioseo_model ?? '\AIOSEO\Plugin\Common\Models\Post';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'getPost' ) ) {
			return null;
		}
		try {
			$post = $class::getPost( $post_id );
		} catch ( \Throwable $_throwable ) {
			return null;
		}
		return is_object( $post ) && method_exists( $post, 'save' ) ? $post : null;
	}

	/**
	 * @return array{plugin:string,title:string,description:string,focus_keyword:string,canonical:string,noindex:bool|null,write_supported:bool}
	 */
	private static function get_aioseo( int $post_id ): array {
		$post = self::aioseo_post( $post_id );
		if ( null === $post ) {
			// The legacy meta keys are not where AIOSEO v4 keeps its data, so noindex is not reported as a guess.
			$keys = self::meta_keys( 'aioseo' );
			return [
				'plugin'          => 'aioseo',
				'title'           => (string) get_post_meta( $post_id, $keys['title'], true ),
				'description'     => (string) get_post_meta( $post_id, $keys['description'], true ),
				'focus_keyword'   => (string) get_post_meta( $post_id, $keys['focus_keyword'], true ),
				'canonical'       => (string) get_post_meta( $post_id, $keys['canonical'], true ),
				'noindex'         => null,
				'write_supported' => false,
			];
		}
		return [
			'plugin'          => 'aioseo',
			'title'           => (string) ( $post->title ?? '' ),
			'description'     => (string) ( $post->description ?? '' ),
			'focus_keyword'   => '',
			'canonical'       => (string) ( $post->canonical_url ?? '' ),
			// The post-level robots settings apply only when the post does not use the default settings.
			'noindex'         => empty( $post->robots_default ) && ! empty( $post->robots_noindex ),
			'write_supported' => true,
		];
	}

	/**
	 * @param array<string, mixed> $patch
	 * @return array{plugin:string,title:string,description:string,focus_keyword:string,canonical:string,noindex:bool|null,write_supported:bool}| \WP_Error
	 */
	private static function update_aioseo( int $post_id, array $patch ): array|\WP_Error {
		$unsupported = self::write_error( $post_id, $patch );
		$post        = self::aioseo_post( $post_id );
		if ( null !== $unsupported || null === $post ) {
			return $unsupported ?? self::plugin_missing_error();
		}
		if ( array_key_exists( 'title', $patch ) ) {
			$post->title = (string) $patch['title'];
		}
		if ( array_key_exists( 'description', $patch ) ) {
			$post->description = (string) $patch['description'];
		}
		if ( array_key_exists( 'canonical', $patch ) ) {
			$post->canonical_url = (string) $patch['canonical'];
		}
		if ( array_key_exists( 'noindex', $patch ) ) {
			// The post-level robots flags count only when the post does not use the default settings.
			$post->robots_default = false;
			$post->robots_noindex = (bool) $patch['noindex'];
		}
		try {
			$post->save();
		} catch ( \Throwable $_throwable ) {
			return new \WP_Error( 'stonewright_seo_write_failed', __( 'All in One SEO could not save this post.', 'stonewright' ), [ 'status' => 500 ] );
		}
		$meta = self::get_aioseo( $post_id );
		if ( array_key_exists( 'noindex', $patch ) && (bool) $patch['noindex'] !== $meta['noindex'] ) {
			return new \WP_Error(
				'stonewright_seo_noindex_not_applied',
				__( 'The noindex setting did not read back as requested, so it was not applied.', 'stonewright' ),
				[ 'status' => 500, 'requested' => (bool) $patch['noindex'], 'stored' => $meta['noindex'] ]
			);
		}
		return $meta;
	}

	private static function aioseo_unsupported_error( string $message ): \WP_Error {
		return new \WP_Error( 'stonewright_seo_write_unsupported', $message, [ 'status' => 501 ] );
	}

	private static function plugin_missing_error(): \WP_Error {
		return new \WP_Error(
			'stonewright_plugin_missing',
			__( 'No supported SEO plugin is active (Yoast, Rank Math, AIOSEO, SEOPress).', 'stonewright' ),
			[ 'status' => 409 ]
		);
	}
}
