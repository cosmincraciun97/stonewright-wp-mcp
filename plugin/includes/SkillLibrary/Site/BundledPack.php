<?php
/**
 * The skill pack bundled with the plugin and its refresh into the site tables.
 *
 * @package Stonewright
 * @license GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary\Site;

use Stonewright\WpMcp\SkillLibrary\PackRefresh;
use Stonewright\WpMcp\SkillLibrary\RecordRules;
use Stonewright\WpMcp\SkillLibrary\Repository;

/**
 * A skill directory `<name>/SKILL.md` is stored as `stonewright-<name>` with
 * source `builtin`; `playbooks/<name>.md` is stored as `playbook-<name>` with
 * source `playbook`.
 *
 * A refresh inserts entries that are missing, updates the text and constraints
 * of changed entries while keeping the site's enable choices, takes a bundled
 * identity back from a row of another source after that row is kept as a
 * revision, and retires shipped entries that no longer ship. Rows of other
 * sources are otherwise left alone, and nothing is deleted.
 */
final class BundledPack {

	private const SKILL_PREFIX = 'stonewright-';

	private const PLAYBOOK_PREFIX = 'playbook-';

	/** Release archives bundle the pack inside the plugin; repository checkouts keep it beside the plugin. */
	public static function root(): string {
		$plugin = rtrim( str_replace( '\\', '/', (string) STONEWRIGHT_DIR ), '/' );
		return is_dir( $plugin . '/skills' ) ? $plugin . '/skills' : dirname( $plugin ) . '/skills';
	}

	public static function identity( string $pack_key ): string {
		return str_starts_with( $pack_key, 'playbooks/' )
			? self::PLAYBOOK_PREFIX . substr( $pack_key, strlen( 'playbooks/' ) )
			: self::SKILL_PREFIX . $pack_key;
	}

	/**
	 * Stored identity for each pack entry, keyed by its pack key.
	 *
	 * @param array<string, mixed> $inventory From PackInventory::scan().
	 * @return array<string, string>
	 */
	public static function identities( array $inventory ): array {
		$identities = [];
		foreach ( is_array( $inventory['entries'] ?? null ) ? $inventory['entries'] : [] as $entry ) {
			$key                = (string) ( $entry['pack_key'] ?? '' );
			$identities[ $key ] = self::identity( $key );
		}
		return $identities;
	}

	/**
	 * Bundled identities from the directory listing alone, without reading the
	 * files. They stay reserved for the pack even before they are seeded.
	 *
	 * @return array<int, string>
	 */
	public static function slugs( ?string $root = null ): array {
		static $listed = [];
		$root = $root ?? self::root();
		if ( isset( $listed[ $root ] ) ) {
			return $listed[ $root ];
		}
		$slugs = [];
		if ( is_dir( $root ) ) {
			foreach ( new \DirectoryIterator( $root ) as $item ) {
				if ( $item->isDot() || $item->isLink() || ! $item->isDir() ) {
					continue;
				}
				if ( 'playbooks' === $item->getFilename() ) {
					foreach ( new \DirectoryIterator( $item->getPathname() ) as $playbook ) {
						if ( $playbook->isFile() && str_ends_with( $playbook->getFilename(), '.md' ) ) {
							$slugs[] = self::identity( 'playbooks/' . substr( $playbook->getFilename(), 0, -3 ) );
						}
					}
				} elseif ( is_file( $item->getPathname() . '/SKILL.md' ) ) {
					$slugs[] = self::identity( $item->getFilename() );
				}
			}
		}
		sort( $slugs );
		$listed[ $root ] = $slugs;
		return $slugs;
	}

	/**
	 * @param array<string, mixed> $inventory From PackInventory::scan().
	 * @return array{inserted: int, updated: int, reclaimed: int, retired: int, unchanged: int, failed: int}|\WP_Error
	 */
	public static function refresh( array $inventory, Repository $repository, SystemWrites $writes ): array|\WP_Error {
		$identities = self::identities( $inventory );
		$existing   = $repository->all_records();
		$plan       = PackRefresh::plan( $inventory, $existing, $identities );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$stored = [];
		foreach ( $existing as $record ) {
			$stored[ RecordRules::identity( (string) $record['slug'] ) ] = $record;
		}
		$counts = [
			'inserted'  => 0,
			'updated'   => 0,
			'reclaimed' => 0,
			'retired'   => 0,
			'unchanged' => 0,
			'failed'    => 0,
		];

		foreach ( $plan['upserts'] as $record ) {
			$previous = $stored[ $record['slug'] ] ?? null;
			if ( null === $previous ) {
				$outcome = is_int( $writes->insert( 'seed', $record ) ) ? 'inserted' : 'failed';
				++$counts[ $outcome ];
				continue;
			}
			// A retired entry that ships again returns to service.
			if ( 'retired' === $previous['status'] ) {
				$record['status'] = 'active';
			}
			if ( RowFormat::stored( $previous ) === RowFormat::stored( $record ) ) {
				++$counts['unchanged'];
				continue;
			}
			$outcome = true === $writes->replace( 'seed', $previous, $record ) ? 'updated' : 'failed';
			++$counts[ $outcome ];
		}

		foreach ( $plan['conflicts'] as $conflict ) {
			$slug     = (string) ( $conflict['slug'] ?? '' );
			$previous = $stored[ $slug ] ?? null;
			$entry    = self::entry_for( $inventory, $identities, $slug );
			$outcome  = null !== $previous && null !== $entry && true === $writes->replace( 'seed', $previous, self::fresh_record( $entry, $slug ) ) ? 'reclaimed' : 'failed';
			++$counts[ $outcome ];
		}

		$shipped = array_flip( array_values( $identities ) );
		foreach ( $existing as $record ) {
			if ( ! in_array( $record['source'], [ 'builtin', 'playbook' ], true ) || isset( $shipped[ $record['slug'] ] ) || 'retired' === $record['status'] ) {
				continue;
			}
			$outcome = true === $writes->replace( 'seed', $record, array_replace( $record, [ 'status' => 'retired' ] ), false ) ? 'retired' : 'failed';
			++$counts[ $outcome ];
		}

		return $counts;
	}

	/**
	 * @param array<string, mixed>  $inventory
	 * @param array<string, string> $identities
	 * @return array<string, mixed>|null
	 */
	private static function entry_for( array $inventory, array $identities, string $slug ): ?array {
		foreach ( $inventory['entries'] as $entry ) {
			if ( ( $identities[ (string) $entry['pack_key'] ] ?? '' ) === $slug ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * The record a fresh install would hold for a pack entry.
	 *
	 * @param array<string, mixed> $entry
	 * @return array<string, mixed>
	 */
	private static function fresh_record( array $entry, string $slug ): array {
		$document = $entry['record'];
		$metadata = is_array( $document['metadata'] ?? null ) ? $document['metadata'] : [];
		return [
			'slug'                 => $slug,
			'title'                => $document['title'],
			'description'          => $document['description'],
			'content'              => $document['content'],
			'source'               => $entry['kind'],
			'status'               => 'active',
			'enabled'              => true,
			'enable_agentic'       => (bool) ( $metadata['enable_agentic'] ?? true ),
			'enable_prompt'        => (bool) ( $metadata['enable_prompt'] ?? true ),
			'topic'                => is_string( $metadata['topic'] ?? null ) ? $metadata['topic'] : '',
			'version_constraints'  => is_array( $metadata['version_constraints'] ?? null ) ? $metadata['version_constraints'] : [],
			'semantic_fingerprint' => '',
			'verification_count'   => 0,
			'conflicts'            => [],
		];
	}
}
