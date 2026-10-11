<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

/**
 * Purges the cached page HTML of one post after its Elementor stylesheet version moved.
 *
 * A page cache that already stored the page keeps the old `?ver=` of the stylesheet link until it
 * is purged. Each purger is used only when its plugin exposes the call (a hook with a listener, a
 * public function, or the plugin's own hooks object), never guessed, and only for this one post.
 * The whole-site purge calls of these plugins are never used. A purger that throws is reported and
 * never stops the others or the write.
 *
 * Sources of the calls (each plugin's public documentation or code):
 * - LiteSpeed Cache: action `litespeed_purge_post( $post_id )`.
 * - WP Rocket: `rocket_clean_post( $post_id, $post = null )`.
 * - W3 Total Cache: `w3tc_flush_post( $post_id, $force = false, $extras = null )`.
 * - WP Super Cache: `wp_cache_post_change( $post_id )`.
 * - WP Fastest Cache: `wpfc_clear_post_cache_by_id( $post_id )`.
 * - SiteGround Optimizer: `sg_cachepress_purge_cache( $url )`. Without a URL, or with the site root,
 *   it purges the whole site, so it only receives a post URL with a path and no query string.
 * - Cloudflare: `purgeCacheByRelevantURLs( $post_ids )` on the plugin's `$cloudflareHooks` object.
 *   The plugin has no per-post action of its own.
 * - WordPress core: `clean_post_cache( $post_id )`, which also reaches plugins that listen to it.
 *
 * A site can change the list with the `stonewright_css_regenerate_purge` filter.
 */
final class PageCachePurger {

	public const FILTER = 'stonewright_css_regenerate_purge';

	public const CORE = 'wordpress_post_cache';

	private const LABELS = [
		'litespeed_cache'      => 'LiteSpeed Cache',
		'wp_rocket'            => 'WP Rocket',
		'w3_total_cache'       => 'W3 Total Cache',
		'wp_super_cache'       => 'WP Super Cache',
		'wp_fastest_cache'     => 'WP Fastest Cache',
		'siteground_optimizer' => 'SiteGround Optimizer',
		'cloudflare'           => 'Cloudflare',
		self::CORE             => 'WordPress post cache',
	];

	/** @var array<string,callable> */
	private array $functions;

	/**
	 * @param array<string,callable> $functions Callables that stand in for plugin functions by name.
	 *                                          Meant for tests; a name that is set counts as present.
	 */
	public function __construct( array $functions = [] ) {
		$this->functions = $functions;
	}

	/**
	 * Purges the post named by `$post_id` with the purgers that are present.
	 *
	 * @return array{ran:list<string>,failed?:list<array{purger:string,error_class:string}>,skipped?:array<string,string>,skipped_reason?:string}
	 */
	public static function purge( int $post_id ): array {
		return ( new self() )->run( $post_id );
	}

	/**
	 * @return array{ran:list<string>,failed?:list<array{purger:string,error_class:string}>,skipped?:array<string,string>,skipped_reason?:string}
	 */
	public function run( int $post_id, ?string $url = null ): array {
		if ( null === $url ) {
			$permalink = function_exists( 'get_permalink' ) ? get_permalink( $post_id ) : false;
			$url       = is_string( $permalink ) ? $permalink : '';
		}

		$failed   = [];
		$skipped  = [];
		$purgers  = $this->detect( $url, $skipped );
		$built_in = $purgers;

		try {
			$filtered = apply_filters( self::FILTER, $purgers, $post_id, $url );
		} catch ( \Throwable $error ) {
			$failed[] = [ 'purger' => 'filter', 'error_class' => get_class( $error ) ];
			$filtered = $purgers;
		}
		if ( ! is_array( $filtered ) ) {
			return $this->finish( [], $failed, $skipped, 'disabled_by_filter' );
		}
		$purgers = [];
		foreach ( $filtered as $id => $purger ) {
			$id = sanitize_key( (string) $id );
			if ( '' !== $id && is_callable( $purger ) ) {
				$purgers[ $id ] = $purger;
			}
		}
		if ( [] === $purgers && [] !== $built_in ) {
			return $this->finish( [], $failed, $skipped, 'disabled_by_filter' );
		}

		$ran = [];
		foreach ( $purgers as $id => $purger ) {
			try {
				$purger( $post_id, $url );
				$ran[] = $id;
			} catch ( \Throwable $error ) {
				$failed[] = [ 'purger' => $id, 'error_class' => get_class( $error ) ];
			}
		}

		return $this->finish( $ran, $failed, $skipped, null );
	}

	/** Display name of a purger id; the id itself for a purger a site added. */
	public static function label( string $id ): string {
		return self::LABELS[ $id ] ?? $id;
	}

	/**
	 * Whether a page cache purger, not only core's object cache purge, ran.
	 *
	 * @param array{ran?:list<string>} $result
	 */
	public static function page_cache_ran( array $result ): bool {
		return [] !== array_diff( $result['ran'] ?? [], [ self::CORE ] );
	}

	/**
	 * @param list<string>                                      $ran
	 * @param list<array{purger:string,error_class:string}>     $failed
	 * @param array<string,string>                              $skipped
	 * @return array{ran:list<string>,failed?:list<array{purger:string,error_class:string}>,skipped?:array<string,string>,skipped_reason?:string}
	 */
	private function finish( array $ran, array $failed, array $skipped, ?string $reason ): array {
		$result = [ 'ran' => $ran ];
		if ( [] !== $failed ) {
			$result['failed'] = $failed;
		}
		if ( [] !== $skipped ) {
			$result['skipped'] = $skipped;
		}
		if ( null === $reason && ! self::page_cache_ran( $result ) && [] === $failed ) {
			$reason = 'no_page_cache_plugin';
		}
		if ( null !== $reason ) {
			$result['skipped_reason'] = $reason;
		}
		return $result;
	}

	/**
	 * The built-in purgers whose plugin is present, in a fixed order, core last.
	 *
	 * @param array<string,string> $skipped Filled with the purgers that are present but not safe to call.
	 * @return array<string,callable>
	 */
	private function detect( string $url, array &$skipped ): array {
		$purgers = [];

		if ( false !== has_action( 'litespeed_purge_post' ) ) {
			$purgers['litespeed_cache'] = static function ( int $post_id ): void {
				do_action( 'litespeed_purge_post', $post_id );
			};
		}

		$by_post_id = [
			'wp_rocket'        => 'rocket_clean_post',
			'w3_total_cache'   => 'w3tc_flush_post',
			'wp_super_cache'   => 'wp_cache_post_change',
			'wp_fastest_cache' => 'wpfc_clear_post_cache_by_id',
		];
		foreach ( $by_post_id as $id => $function ) {
			$callable = $this->function_callable( $function );
			if ( null !== $callable ) {
				$purgers[ $id ] = static function ( int $post_id ) use ( $callable ): void {
					$callable( $post_id );
				};
			}
		}

		$siteground = $this->function_callable( 'sg_cachepress_purge_cache' );
		if ( null !== $siteground ) {
			$unsafe = self::siteground_url_problem( $url );
			if ( null !== $unsafe ) {
				$skipped['siteground_optimizer'] = $unsafe;
			} else {
				$purgers['siteground_optimizer'] = static function ( int $post_id, string $post_url ) use ( $siteground ): void {
					$siteground( $post_url );
				};
			}
		}

		$hooks = $GLOBALS['cloudflareHooks'] ?? null;
		if ( is_object( $hooks ) && is_a( $hooks, 'Cloudflare\APO\WordPress\Hooks' ) && is_callable( [ $hooks, 'purgeCacheByRelevantURLs' ] ) ) {
			$purgers['cloudflare'] = static function ( int $post_id ) use ( $hooks ): void {
				// @phpstan-ignore-next-line Cloudflare plugin runtime API.
				$hooks->purgeCacheByRelevantURLs( $post_id );
			};
		}

		$core = $this->function_callable( 'clean_post_cache' );
		if ( null !== $core ) {
			$purgers[ self::CORE ] = static function ( int $post_id ) use ( $core ): void {
				$core( $post_id );
			};
		}

		return $purgers;
	}

	private function function_callable( string $name ): ?callable {
		if ( isset( $this->functions[ $name ] ) ) {
			return $this->functions[ $name ];
		}
		return function_exists( $name ) ? $name : null;
	}

	/**
	 * Why the SiteGround purge call must not receive this URL, or null when it is safe. The call
	 * purges the site root and everything below it for an empty URL or the root, and a query string
	 * on the root path is ignored by it.
	 */
	private static function siteground_url_problem( string $url ): ?string {
		if ( '' === $url ) {
			return 'no_post_url';
		}
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $home ) ) {
			return 'no_post_url';
		}
		$host      = strtolower( (string) preg_replace( '/^www\./i', '', (string) ( $parts['host'] ?? '' ) ) );
		$home_host = strtolower( (string) preg_replace( '/^www\./i', '', (string) ( $home['host'] ?? '' ) ) );
		if ( '' === $host || $host !== $home_host ) {
			return 'url_not_on_this_site';
		}
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			return 'url_has_query';
		}
		if ( rtrim( (string) ( $parts['path'] ?? '' ), '/' ) === rtrim( (string) ( $home['path'] ?? '' ), '/' ) ) {
			return 'url_is_site_root';
		}
		return null;
	}
}
