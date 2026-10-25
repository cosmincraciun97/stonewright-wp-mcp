<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

use Stonewright\WpMcp\Support\AgentNotices;

/**
 * Tells an agent when the site's page cache purges a lot on every save, so it batches its writes.
 *
 * Stonewright does not change another plugin's purge rules and does not suppress its hooks: the site owner
 * configures the cache. This class only reads the rules and reports them. Today it knows LiteSpeed Cache, whose
 * Auto Purge Rules purge every page on each post or page save and the whole cache when a plugin or theme is
 * updated. It runs only when LiteSpeed Cache is loaded (the constant LSCWP_V).
 *
 * The three option names are the ones LiteSpeed Cache itself stores: its configuration class names an option
 * `litespeed.<class>.<id>`, so the Conf class and the ids `purge-post_all`, `purge-post_p` and `purge-upgrade` give
 * `litespeed.conf.purge-post_all`, `litespeed.conf.purge-post_p` and `litespeed.conf.purge-upgrade` (LiteSpeed Cache
 * 7.x, `src/base.cls.php` constants O_PURGE_POST_ALL, O_PURGE_POST_PAGES and O_PURGE_ON_UPGRADE). The list lives in
 * LITESPEED_RULES and nowhere else.
 */
final class CachePurgeAdvisor {

	public const NOTICE_KEY = 'cache_purge';

	/** The option prefix of LiteSpeed Cache's configuration. */
	public const LITESPEED_PREFIX = 'litespeed.conf.';

	/**
	 * LiteSpeed Cache rule to the effect it has and the name its settings screen gives it.
	 *
	 * @var array<string, array{effect: string, label: string}>
	 */
	public const LITESPEED_RULES = [
		'purge-post_all' => [ 'effect' => 'post_save', 'label' => 'Auto Purge Rules: All pages' ],
		'purge-post_p'   => [ 'effect' => 'post_save', 'label' => 'Auto Purge Rules: Pages' ],
		'purge-upgrade'  => [ 'effect' => 'upgrade', 'label' => 'Purge All On Upgrade' ],
	];

	private static ?bool $litespeed_override = null;

	/**
	 * What the page cache of this site purges on its own, as short entries an agent can show.
	 *
	 * @return list<array{plugin: string, setting: string, effect: string, hint: string}>
	 */
	public static function advisories(): array {
		$active     = self::active_rules();
		$advisories = [];
		if ( isset( $active['post_save'] ) ) {
			$advisories[] = [
				'plugin'  => 'LiteSpeed Cache',
				'setting' => implode( '; ', $active['post_save'] ),
				'effect'  => __( 'Every page is purged on each post or page save.', 'stonewright' ),
				'hint'    => self::post_save_hint(),
			];
		}
		if ( isset( $active['upgrade'] ) ) {
			$advisories[] = [
				'plugin'  => 'LiteSpeed Cache',
				'setting' => implode( '; ', $active['upgrade'] ),
				'effect'  => __( 'The whole cache is purged when a plugin or theme is updated.', 'stonewright' ),
				'hint'    => __( 'This site purges its whole page cache when a plugin or theme is updated (LiteSpeed Cache › Cache › Purge › Purge All On Upgrade). Update plugins in one batch, outside traffic peaks.', 'stonewright' ),
			];
		}

		return $advisories;
	}

	/**
	 * What a plugin or sandbox activation or deactivation answers with, or null when the cache rules say nothing.
	 *
	 * @return array{advisories: list<array{plugin: string, setting: string, effect: string, hint: string}>, hint: string}|null
	 */
	public static function for_plugin_change(): ?array {
		$advisories = self::advisories();
		if ( [] === $advisories ) {
			return null;
		}

		return [
			'advisories' => $advisories,
			'hint'       => __( "Activating or deactivating a plugin clears Elementor's generated CSS and, with this site's cache rules, the whole page cache.", 'stonewright' ),
		];
	}

	/**
	 * Adds `cache_purge` to the answer of a plugin or sandbox activation or deactivation when the cache rules have
	 * something to say. An answer without advisories is returned as it was.
	 *
	 * @param array<string, mixed> $result The answer of the ability.
	 * @return array<string, mixed>
	 */
	public static function attach_to_plugin_change( array $result ): array {
		$advisory = self::for_plugin_change();
		if ( null !== $advisory && ! array_key_exists( 'cache_purge', $result ) ) {
			$result['cache_purge'] = $advisory;
		}

		return $result;
	}

	/**
	 * Leaves the post-save advisory as a notice on agent responses for an hour. Called after a write that saves a
	 * page; once the notice is there, later writes leave it alone, so it is pushed once per hour.
	 */
	public static function note_write( ?int $now = null ): void {
		if ( ! isset( self::active_rules()['post_save'] ) || AgentNotices::has( self::NOTICE_KEY, $now ) ) {
			return;
		}
		AgentNotices::push( self::NOTICE_KEY, self::post_save_hint(), HOUR_IN_SECONDS, $now );
	}

	private static function post_save_hint(): string {
		return __( 'This site purges its whole page cache on every page save (LiteSpeed Cache › Cache › Purge › Auto Purge Rules). Batch your changes: one apply per post, and avoid saves during traffic peaks.', 'stonewright' );
	}

	/**
	 * The rules that are on, by what they do: `post_save` or `upgrade`, each with the names of its settings.
	 *
	 * @return array<string, list<string>>
	 */
	private static function active_rules(): array {
		if ( ! self::litespeed_loaded() ) {
			return [];
		}
		$active = [];
		foreach ( self::LITESPEED_RULES as $id => $rule ) {
			if ( self::is_on( get_option( self::LITESPEED_PREFIX . $id, 0 ) ) ) {
				$active[ $rule['effect'] ][] = $rule['label'];
			}
		}

		return $active;
	}

	/** Whether LiteSpeed Cache is loaded: its version constant is defined. */
	private static function litespeed_loaded(): bool {
		return self::$litespeed_override ?? defined( 'LSCWP_V' );
	}

	/**
	 * Pretends LiteSpeed Cache is loaded (true) or absent (false), for unit tests; null reads the constant again.
	 */
	public static function use_litespeed( ?bool $loaded ): void {
		self::$litespeed_override = $loaded;
	}

	private static function is_on( mixed $value ): bool {
		return in_array( $value, [ 1, '1', true, 'on', 'yes' ], true );
	}
}
