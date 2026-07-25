<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Skills;

/**
 * Decides which copy of a skill wins when several places offer the same slug.
 *
 * Resolution order is built-in, then this site's database, then registered
 * external sources in registration order. An external source has to qualify
 * every slug with its own id, so it can add skills but can never quietly
 * stand in for one Stonewright ships or one the site wrote.
 *
 * Enumeration is read-only. Nothing here opens a socket, reads a file, or
 * hands source-provided text to anything that would run it.
 *
 * @stonewright-status stable
 */
final class SkillSourceRegistry {

	/** Filter for third-party sources: array<int, SkillSource> in, same out. */
	public const FILTER = 'stonewright_skill_sources';

	/** Source ids Stonewright keeps for itself. */
	private const RESERVED_IDS = [ 'builtin', 'database', 'stonewright' ];

	/** Slug prefixes only Stonewright's own seeds may use. */
	private const RESERVED_PREFIXES = [ 'stonewright-', 'playbook-' ];

	/** Row sources that belong to the built-in catalog rather than the site. */
	private const BUILTIN_ROW_SOURCES = [ 'builtin', 'playbook' ];

	/** @var array<string, SkillSource> */
	private static array $registered = [];

	/**
	 * Add an external source. Reserved and unusable ids are ignored.
	 */
	public static function register( SkillSource $source ): void {
		$external = $source->as_external();
		$id       = $external->id();

		if ( '' === $id || in_array( $id, self::RESERVED_IDS, true ) ) {
			return;
		}

		self::$registered[ $id ] = $external;
	}

	/**
	 * Drop every registered source. For tests and uninstall paths.
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$registered = [];
	}

	/** @return list<string> */
	public static function reserved_prefixes(): array {
		return self::RESERVED_PREFIXES;
	}

	/**
	 * All sources in resolution order.
	 *
	 * @return list<SkillSource>
	 */
	public static function sources(): array {
		$rows     = Skills::list();
		$builtin  = [];
		$database = [];

		foreach ( $rows as $row ) {
			if ( in_array( (string) ( $row['source'] ?? '' ), self::BUILTIN_ROW_SOURCES, true ) ) {
				$builtin[] = $row;
				continue;
			}
			$database[] = $row;
		}

		$own = [
			new SkillSource( 'builtin', __( 'Built-in', 'stonewright' ), SkillSource::KIND_BUILTIN, $builtin ),
			new SkillSource( 'database', __( 'This site', 'stonewright' ), SkillSource::KIND_DATABASE, $database ),
		];

		$sources = $own;
		foreach ( self::$registered as $source ) {
			$sources[] = $source;
		}

		/**
		 * Filters the skill sources Stonewright reads from.
		 *
		 * Entries that are not SkillSource objects are dropped, and every
		 * added source is treated as external regardless of the kind it
		 * declares.
		 *
		 * @param list<SkillSource> $sources Sources in resolution order.
		 */
		$filtered = apply_filters( self::FILTER, $sources );

		return self::normalize( is_array( $filtered ) ? $filtered : $sources, $own );
	}

	/**
	 * Merge every source into one catalog and report what was dropped.
	 *
	 * @return array{skills: list<array<string, mixed>>, conflicts: list<array<string, string>>}
	 */
	public static function resolve(): array {
		$skills    = [];
		$conflicts = [];
		$owners    = [];

		foreach ( self::sources() as $source ) {
			$prefix = $source->slug_prefix();

			foreach ( $source->skills() as $skill ) {
				$slug = (string) ( $skill['slug'] ?? '' );

				if ( '' !== $prefix && ! str_starts_with( $slug, $prefix ) ) {
					$conflicts[] = [
						'slug'    => $slug,
						'kept'    => $owners[ $slug ] ?? '',
						'dropped' => $source->id(),
						'reason'  => 'external_slug_not_source_qualified',
					];
					continue;
				}

				if ( isset( $owners[ $slug ] ) ) {
					$conflicts[] = [
						'slug'    => $slug,
						'kept'    => $owners[ $slug ],
						'dropped' => $source->id(),
						'reason'  => 'duplicate_slug',
					];
					continue;
				}

				$owners[ $slug ]      = $source->id();
				$skill['source_id']   = $source->id();
				$skill['source_kind'] = $source->kind();
				$skills[]             = $skill;
			}
		}

		return [
			'skills'    => $skills,
			'conflicts' => $conflicts,
		];
	}

	/**
	 * Keep real sources, force added ones to external, and drop reserved ids.
	 *
	 * @param array<int|string, mixed> $sources
	 * @param list<SkillSource>        $own     Sources Stonewright built in this call.
	 * @return list<SkillSource>
	 */
	private static function normalize( array $sources, array $own ): array {
		$normalized = [];
		$seen       = [];

		foreach ( $sources as $source ) {
			if ( ! $source instanceof SkillSource ) {
				continue;
			}

			// Only the objects Stonewright constructed above keep a non-external kind.
			$is_own = in_array( $source, $own, true );
			$source = $is_own ? $source : $source->as_external();
			$id     = $source->id();

			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			if ( ! $is_own && in_array( $id, self::RESERVED_IDS, true ) ) {
				continue;
			}

			$seen[ $id ]  = true;
			$normalized[] = $source;
		}

		return $normalized;
	}
}
