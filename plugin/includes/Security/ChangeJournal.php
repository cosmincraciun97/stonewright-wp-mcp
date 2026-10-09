<?php
/**
 * Armed change journal for risky writes.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * Records every risky write before it happens and follows it until the site is known to be healthy.
 *
 * An entry is armed before the write, then settled by a health probe: verified, rolled back,
 * or open as an incident when the rollback failed or a fatal was recorded against it.
 *
 * Two copies are kept in step. The JSON file under uploads/stonewright-state/ (see
 * ChangeJournalFile) holds the compact contract shape that the rescue MU-plugin and WP-CLI read
 * without the full plugin, and where the MU-plugin records a fatal. The database option holds
 * the same entries plus what the file never carries: who made the change, the exact recipe
 * detail, probe evidence and the rollback outcome. Every write goes through one critical section
 * that takes the file lock, merges what another writer put in the file, applies the change, and
 * writes both copies. If the file cannot be used, the journal keeps working from the database.
 *
 * It is a separate store from IncidentStore. An entry exists before anything has failed, it is
 * settled by a probe rather than by a later verified audit event, and a reader must be able to
 * load it without WordPress or the incidents table.
 */
final class ChangeJournal {

	public const FILE_OPTION = 'stonewright_rescue_journal_file';
	public const DB_OPTION   = 'stonewright_change_journal';
	public const OPEN_OPTION = 'stonewright_rescue_open';
	public const SEEN_OPTION = 'stonewright_rescue_journal_seen';
	public const STATE_DIR   = 'stonewright-state';

	/** Seconds from arming until the probe is expected to have finished. */
	public const WINDOW = 120;

	/** MCP name of the ability that rolls an incident back. */
	public const ROLLBACK_TOOL = 'stonewright-rescue-rollback';

	/** States in which an entry is an open incident. */
	public const OPEN_STATES = [ 'incident', 'rollback_failed' ];

	/** Seconds a claim on an entry holds before it is taken to be left over from a rollback that died. */
	public const CLAIM_TTL = 300;

	/** States in which an entry can still be rolled back, and so claimed. */
	private const CLAIMABLE_STATES = [ 'armed', 'incident', 'rollback_failed' ];

	private const SCOPES = [ 'site', 'post', 'light' ];

	private static ?ChangeJournalFile $file = null;
	private static bool $file_checked       = false;

	/**
	 * Record an armed entry before a risky write.
	 *
	 * @param array<string, mixed> $spec ability, resource_type, resource_key, recipe{type,ref}, recipe_detail,
	 *                                   paths, scope, and optionally change_set_id and window (seconds).
	 * @return array<string, mixed>|null The entry, or null when the spec is not usable.
	 */
	public static function arm( array $spec ): ?array {
		$ability = isset( $spec['ability'] ) && is_scalar( $spec['ability'] ) ? strtolower( (string) $spec['ability'] ) : '';
		$type    = isset( $spec['resource_type'] ) && is_scalar( $spec['resource_type'] ) ? (string) $spec['resource_type'] : '';
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,47}\/[a-z0-9][a-z0-9_-]{0,63}$/D', $ability )
			|| ! in_array( $type, ChangeJournalFile::RESOURCE_TYPES, true )
		) {
			return null;
		}
		$id = isset( $spec['change_set_id'] ) && is_scalar( $spec['change_set_id'] ) ? (string) $spec['change_set_id'] : '';
		if ( '' === $id ) {
			$id = 'cs-' . bin2hex( random_bytes( 12 ) );
		}
		$now    = self::now();
		$window = isset( $spec['window'] ) && is_numeric( $spec['window'] ) ? max( 10, min( 900, (int) $spec['window'] ) ) : self::WINDOW;
		$scope  = isset( $spec['scope'] ) && is_scalar( $spec['scope'] ) && in_array( (string) $spec['scope'], self::SCOPES, true ) ? (string) $spec['scope'] : 'site';

		$entry = self::clean_rich(
			[
				'id'             => $id,
				'ability'        => $ability,
				'resource_type'  => $type,
				'resource_key'   => $spec['resource_key'] ?? '',
				'recipe'         => is_array( $spec['recipe'] ?? null ) ? $spec['recipe'] : [ 'type' => 'none', 'ref' => '' ],
				'paths'          => $spec['paths'] ?? [],
				'armed_at'       => $now,
				'probe_deadline' => $now + $window,
				'state'          => 'armed',
				'incident'       => null,
				'scope'          => $scope,
				'actor'          => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'client'         => self::client_hint(),
				'recipe_detail'  => $spec['recipe_detail'] ?? [],
			]
		);
		if ( null === $entry ) {
			return null;
		}

		$result = self::mutate(
			static function ( array &$store ) use ( $entry ): bool {
				// Arming again with the same id replaces the entry and moves it to the end.
				unset( $store['entries'][ $entry['id'] ] );
				$store['entries'][ $entry['id'] ] = $entry;
				return true;
			}
		);
		$entry['persisted'] = $result['persisted'];
		return $entry;
	}

	/**
	 * Attach (or replace) the recipe of an entry once its reference is known.
	 *
	 * @param array{type?:string,ref?:string} $recipe
	 * @param array<string, mixed>            $detail
	 * @return array<string, mixed>|null
	 */
	public static function attach_recipe( string $id, array $recipe, array $detail = [] ): ?array {
		return self::update(
			$id,
			static function ( array $entry ) use ( $recipe, $detail ): array {
				$entry['recipe'] = [
					'type' => $recipe['type'] ?? ( $entry['recipe']['type'] ?? 'none' ),
					'ref'  => $recipe['ref'] ?? ( $entry['recipe']['ref'] ?? '' ),
				];
				if ( [] !== $detail ) {
					$entry['recipe_detail'] = array_merge( is_array( $entry['recipe_detail'] ?? null ) ? $entry['recipe_detail'] : [], $detail );
				}
				return $entry;
			}
		);
	}

	/**
	 * Store the evidence of a probe without changing the state of the entry.
	 *
	 * @param array<string, mixed> $probe
	 * @return array<string, mixed>|null
	 */
	public static function record_probe( string $id, array $probe ): ?array {
		return self::update(
			$id,
			static function ( array $entry ) use ( $probe ): array {
				$entry['probe'] = $probe;
				return $entry;
			}
		);
	}

	/**
	 * Store what the site did before the write, so a silence afterwards can be told from a site
	 * that never answered.
	 *
	 * @param array<string, mixed> $probe
	 * @return array<string, mixed>|null
	 */
	public static function record_baseline( string $id, array $probe ): ?array {
		return self::update(
			$id,
			static function ( array $entry ) use ( $probe ): array {
				$entry['baseline'] = $probe;
				return $entry;
			}
		);
	}

	/**
	 * Move an entry to its outcome.
	 *
	 * @param string               $state verified, rolled_back or rollback_failed.
	 * @param array<string, mixed> $extra probe, rollback, note, residual.
	 * @return array<string, mixed>|null
	 */
	public static function settle( string $id, string $state, array $extra = [] ): ?array {
		if ( ! in_array( $state, [ 'verified', 'rolled_back', 'rollback_failed' ], true ) ) {
			return null;
		}
		$now = self::now();
		return self::update(
			$id,
			static function ( array $entry ) use ( $state, $extra, $now ): array {
				$entry['state']      = $state;
				$entry['settled_at'] = $now;
				$entry['claim_at']   = 0;
				$entry['claim_by']   = '';
				foreach ( [ 'probe', 'rollback', 'note', 'residual' ] as $key ) {
					if ( array_key_exists( $key, $extra ) ) {
						$entry[ $key ] = $extra[ $key ];
					}
				}
				return $entry;
			}
		);
	}

	/**
	 * Claim an entry for a rollback. Taken inside the journal's critical section, so of two callers
	 * (a double click, the page and an agent together) only one gets it and runs the recipe.
	 *
	 * @param string $by Who is rolling back: page, ability, cli or auto.
	 * @return array<string, mixed>|null The entry, or null when it is unknown, cannot be rolled back, or is already claimed.
	 */
	public static function claim( string $id, string $by, ?int $now = null ): ?array {
		$now     = $now ?? self::now();
		$claimed = null;
		self::mutate(
			static function ( array &$store ) use ( $id, $by, $now, &$claimed ): bool {
				$entry = $store['entries'][ $id ] ?? null;
				if ( null === $entry || ! in_array( $entry['state'], self::CLAIMABLE_STATES, true ) || self::claim_is_live( $entry, $now ) ) {
					return false;
				}
				$entry['claim_at']       = $now;
				$entry['claim_by']       = self::short_text( $by, 20 );
				$store['entries'][ $id ] = $entry;
				$claimed                 = $entry;
				return true;
			}
		);
		return $claimed;
	}

	/** Let go of a claim without settling the entry. A no-op when nobody holds one. */
	public static function release_claim( string $id ): void {
		$entry = self::get( $id );
		if ( null === $entry || (int) ( $entry['claim_at'] ?? 0 ) <= 0 ) {
			return;
		}
		self::update(
			$id,
			static function ( array $entry ): array {
				$entry['claim_at'] = 0;
				$entry['claim_by'] = '';
				return $entry;
			}
		);
	}

	/**
	 * Whether a rollback of this entry is running: claimed, and not so long ago that the claim is stale.
	 *
	 * @param array<string, mixed> $entry
	 */
	public static function is_claimed( array $entry, ?int $now = null ): bool {
		return self::claim_is_live( $entry, $now ?? self::now() );
	}

	/**
	 * Changes made to the same item after this one, which a rollback of this one would overwrite.
	 * A change that was rolled back itself leaves the item as this one made it, so it does not count.
	 *
	 * @param array<string, mixed> $entry
	 * @return list<array<string, mixed>> Oldest first.
	 */
	public static function newer_changes( array $entry ): array {
		$newer = [];
		$after = false;
		foreach ( self::load_store()['entries'] as $other ) {
			if ( $other['id'] === $entry['id'] ) {
				$after = true;
				continue;
			}
			// Entries are stored in arming order, so those after this one are newer, even within one second.
			if ( ! $after || 'rolled_back' === $other['state'] || ! self::same_resource( $entry, $other ) ) {
				continue;
			}
			$newer[] = $other;
		}
		return $newer;
	}

	/** @return array<string, mixed>|null */
	public static function get( string $id ): ?array {
		$store = self::load_store();
		return $store['entries'][ $id ] ?? null;
	}

	/**
	 * Newest entries first.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function recent( int $limit = 20 ): array {
		$entries = array_values( self::load_store()['entries'] );
		// Entries armed within the same second keep arming order, newest first.
		$order = array_flip( array_column( $entries, 'id' ) );
		usort(
			$entries,
			static function ( array $a, array $b ) use ( $order ): int {
				$by_time = (int) $b['armed_at'] <=> (int) $a['armed_at'];
				return 0 !== $by_time ? $by_time : $order[ $b['id'] ] <=> $order[ $a['id'] ];
			}
		);
		return array_slice( $entries, 0, max( 1, $limit ) );
	}

	/**
	 * Entries that are open incidents, newest first.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function open_incidents(): array {
		return array_values(
			array_filter(
				self::recent( ChangeJournalFile::MAX_ENTRIES ),
				static fn ( array $entry ): bool => in_array( $entry['state'], self::OPEN_STATES, true )
			)
		);
	}

	/**
	 * Armed entries whose probe never reported back before the deadline.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function unconfirmed( ?int $now = null ): array {
		$now = $now ?? self::now();
		return array_values(
			array_filter(
				self::recent( ChangeJournalFile::MAX_ENTRIES ),
				static fn ( array $entry ): bool => 'armed' === $entry['state'] && (int) $entry['probe_deadline'] < $now
			)
		);
	}

	/**
	 * The compact banner an agent response carries while an incident is open. It reads one
	 * autoloaded option, so it costs nothing when no incident is open.
	 *
	 * @return array{id:string,ability:string,since:string,rollback:string}|null
	 */
	public static function banner(): ?array {
		$open = get_option( self::OPEN_OPTION, '' );
		if ( ! is_array( $open ) || '' === (string) ( $open['id'] ?? '' ) ) {
			return null;
		}
		return [
			'id'       => (string) $open['id'],
			'ability'  => (string) ( $open['ability'] ?? '' ),
			'since'    => gmdate( 'Y-m-d\TH:i:s\Z', max( 0, (int) ( $open['ts'] ?? 0 ) ) ),
			'rollback' => self::ROLLBACK_TOOL,
		];
	}

	public static function has_open_incident(): bool {
		return null !== self::banner();
	}

	/**
	 * Import the fatals another writer put in the file. Safe to call on every admin and REST load:
	 * an unchanged file costs one capped read and a hash, and an incident is imported once.
	 *
	 * The file is input. It can only add an incident to an entry the database already holds; whatever
	 * else it carries (an entry nobody armed, another recipe, a stale incident) is dropped, and the
	 * file is rewritten from the database copy so nothing that reads it sees what was ignored.
	 *
	 * @return int Number of incidents imported by this call.
	 */
	public static function sync_from_file(): int {
		$file = self::existing_file();
		if ( null === $file ) {
			return 0;
		}
		$oversize = $file->is_oversize();
		$raw      = $oversize ? null : $file->read_raw();
		if ( ! $oversize && null === $raw ) {
			return 0;
		}
		$hash = $oversize ? 'oversize' : sha1( (string) $raw );
		$seen = get_option( self::SEEN_OPTION, [] );
		if ( ! $oversize && is_array( $seen ) && ( $seen['hash'] ?? '' ) === $hash ) {
			return 0;
		}
		$imported = 0;
		if ( $oversize || self::file_differs( $file->read(), self::load_store() ) ) {
			$result   = self::mutate( static fn ( array &$store ): bool => false );
			$imported = count( $result['imported'] );
			$raw      = $file->read_raw();
			$hash     = null === $raw ? $hash : sha1( $raw );
		}
		update_option( self::SEEN_OPTION, [ 'hash' => $hash ], true );
		return $imported;
	}

	/**
	 * @return array{mirror:string,directory:string}
	 */
	public static function storage_status(): array {
		$file = self::journal_file();
		return [
			'mirror'    => null === $file ? 'unavailable' : 'ok',
			'directory' => self::STATE_DIR,
		];
	}

	public static function state_directory(): string {
		$base = '';
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir( null, false );
			if ( is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
				$base = (string) $uploads['basedir'];
			}
		}
		if ( '' === $base && defined( 'WP_CONTENT_DIR' ) ) {
			$base = constant( 'WP_CONTENT_DIR' ) . '/uploads';
		}
		return '' === $base ? '' : rtrim( str_replace( '\\', '/', $base ), '/' ) . '/' . self::STATE_DIR;
	}

	/**
	 * A path relative to ABSPATH for a file a write touched, or '' when it lies outside the site.
	 */
	public static function relative_path( string $absolute ): string {
		$path = str_replace( '\\', '/', $absolute );
		$abs  = defined( 'ABSPATH' ) ? rtrim( str_replace( '\\', '/', (string) constant( 'ABSPATH' ) ), '/' ) . '/' : '';
		if ( '' !== $abs && str_starts_with( $path, $abs ) ) {
			return substr( $path, strlen( $abs ) );
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$content = rtrim( str_replace( '\\', '/', (string) constant( 'WP_CONTENT_DIR' ) ), '/' ) . '/';
			if ( str_starts_with( $path, $content ) ) {
				return 'wp-content/' . substr( $path, strlen( $content ) );
			}
		}
		return '';
	}

	/**
	 * Delete the journal file, its lock and any temporary copy, then the folder when nothing else is
	 * in it. For a full data removal: the options and transients go with the plugin's prefix, the
	 * files are out of the database's reach. A file the journal did not write is left alone.
	 *
	 * @return bool Whether no journal file is left.
	 */
	public static function erase_state_files(): bool {
		self::reset_for_tests();
		$dir = self::state_directory();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return true;
		}
		$guards  = [ '.htaccess', 'index.php', 'web.config' ];
		$items   = scandir( $dir );
		$foreign = false;
		$erased  = true;
		foreach ( false === $items ? [] : $items as $item ) {
			if ( '.' === $item || '..' === $item || in_array( $item, $guards, true ) ) {
				continue;
			}
			$path = $dir . '/' . $item;
			$base = (string) preg_replace( '/(\.lock|\.tmp-[a-f0-9]+)$/D', '', $item );
			if ( ! is_file( $path ) || ! ChangeJournalFile::is_valid_file_name( $base ) ) {
				$foreign = true;
				continue;
			}
			if ( ! @unlink( $path ) && file_exists( $path ) ) {
				$erased = false;
			}
		}
		if ( ! $foreign && $erased ) {
			foreach ( $guards as $guard ) {
				@unlink( $dir . '/' . $guard );
			}
			@rmdir( $dir );
		}
		return $erased;
	}

	public static function reset_for_tests(): void {
		self::$file         = null;
		self::$file_checked = false;
	}

	// -----------------------------------------------------------------------
	// Storage.
	// -----------------------------------------------------------------------

	/**
	 * Apply one change to one entry.
	 *
	 * @param callable(array<string,mixed>):array<string,mixed> $change
	 * @return array<string, mixed>|null
	 */
	private static function update( string $id, callable $change ): ?array {
		$found  = null;
		$result = self::mutate(
			static function ( array &$store ) use ( $id, $change, &$found ): bool {
				if ( ! isset( $store['entries'][ $id ] ) ) {
					return false;
				}
				$next = self::clean_rich( $change( $store['entries'][ $id ] ) );
				if ( null === $next ) {
					return false;
				}
				$store['entries'][ $id ] = $next;
				$found                   = $next;
				return true;
			}
		);
		unset( $result );
		return $found;
	}

	/**
	 * Run a change inside the shared critical section.
	 *
	 * The callback receives the merged store by reference and returns whether it changed it.
	 *
	 * @param callable(array{version:int,entries:array<string,array<string,mixed>>}):bool $change
	 * @return array{persisted:bool,imported:list<array<string,mixed>>}
	 */
	private static function mutate( callable $change ): array {
		$imported = [];
		$toggled  = false;
		$ran      = false;
		$run      = static function ( array $document, bool $file_backed, bool $rewrite ) use ( $change, &$imported, &$toggled, &$ran ): ?array {
			$ran = true;
			// Another request (the probe's own, the helper's import) may have written since this one first read
			// the journal or the flag: decide from what is stored now, not from this request's cached copy.
			self::forget_cached_options();
			$store = self::load_store();
			self::merge_file( $store, $document, $imported );
			$changed = $change( $store );
			if ( ! $changed && [] === $imported ) {
				// Nothing to record, but a file that disagrees with the database copy is put right.
				return $file_backed && ( $rewrite || self::file_differs( $document, $store ) )
					? [ 'version' => ChangeJournalFile::VERSION, 'entries' => array_values( $store['entries'] ) ]
					: null;
			}
			$store['entries'] = self::prune_entries( $store['entries'] );
			$toggled          = self::refresh_open_flag( $store );
			update_option( self::DB_OPTION, $store, false );
			return [
				'version' => ChangeJournalFile::VERSION,
				'entries' => array_values( $store['entries'] ),
			];
		};

		$file = self::journal_file();
		if ( null !== $file ) {
			// A file larger than the helper reads is replaced whatever it said.
			$oversize = $file->is_oversize();
			$file->transaction( static fn ( array $document ): ?array => $run( $document, true, $oversize ) );
		}
		if ( ! $ran ) {
			// No usable file, or the lock could not be taken: keep the database copy current.
			$run( ChangeJournalFile::empty_document(), false, false );
		}

		if ( $toggled ) {
			self::bump_tool_surface();
		}
		self::record_imports( $imported );

		// The database copy is written inside the critical section, so it is current whenever the change ran.
		return [
			'persisted' => $ran,
			'imported'  => $imported,
		];
	}

	/**
	 * @return array{version:int,entries:array<string,array<string,mixed>>}
	 */
	private static function load_store(): array {
		$raw     = get_option( self::DB_OPTION, [] );
		$entries = is_array( $raw ) && isset( $raw['entries'] ) && is_array( $raw['entries'] ) ? $raw['entries'] : [];
		$store   = [ 'version' => 1, 'entries' => [] ];
		foreach ( $entries as $entry ) {
			$clean = self::clean_rich( $entry );
			if ( null !== $clean ) {
				$store['entries'][ $clean['id'] ] = $clean;
			}
		}
		return $store;
	}

	/**
	 * Take from the file the one thing only another writer can know: that a fatal followed a change.
	 *
	 * The file is input, not a source of entries. It may add the incident to an entry the database
	 * already holds under the same id, ability and resource. It never creates an entry, and nothing
	 * else it carries (a recipe, paths, another resource) reaches the database copy.
	 *
	 * @param array{version:int,entries:array<string,array<string,mixed>>} $store
	 * @param array{version:int,updated_at:int,entries:list<array<string,mixed>>} $document
	 * @param list<array<string,mixed>> $imported
	 */
	private static function merge_file( array &$store, array $document, array &$imported ): void {
		foreach ( $document['entries'] as $compact ) {
			$id      = $compact['id'];
			$current = $store['entries'][ $id ] ?? null;
			if ( null === $current || ! self::same_subject( $current, $compact ) ) {
				continue;
			}
			if ( 'incident' !== $compact['state'] || ! is_array( $compact['incident'] ) ) {
				continue;
			}
			if ( 'rolled_back' === $current['state'] ) {
				continue;
			}
			$key = self::incident_key( $id, $compact['incident'] );
			if ( $key === ( $current['incident_key'] ?? '' ) ) {
				continue;
			}
			$current['state']        = 'incident';
			$current['incident']     = $compact['incident'];
			$current['incident_key'] = $key;
			$store['entries'][ $id ] = $current;
			$imported[]              = $current;
		}
	}

	/** @param array<string, mixed> $entry */
	private static function claim_is_live( array $entry, int $now ): bool {
		$at = (int) ( $entry['claim_at'] ?? 0 );
		return $at > 0 && $now - $at < self::CLAIM_TTL;
	}

	/**
	 * Whether two entries are about the same item. Option writes overlap when they share an option.
	 *
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	private static function same_resource( array $a, array $b ): bool {
		if ( $a['resource_type'] !== $b['resource_type'] ) {
			return false;
		}
		if ( 'option' === $a['resource_type'] ) {
			return [] !== array_intersect( explode( ',', (string) $a['resource_key'] ), explode( ',', (string) $b['resource_key'] ) );
		}
		return $a['resource_key'] === $b['resource_key'];
	}

	/** Whether a file entry is about the same change as a database entry: id, ability and resource. */
	private static function same_subject( array $rich, array $compact ): bool {
		return $rich['ability'] === $compact['ability']
			&& $rich['resource_type'] === $compact['resource_type']
			&& $rich['resource_key'] === $compact['resource_key'];
	}

	/**
	 * Whether the file says anything the database copy does not: an entry nobody armed, another
	 * recipe or resource, a fatal not imported yet, or a stale one. Order and timestamps do not count.
	 *
	 * @param array{version:int,updated_at:int,entries:list<array<string,mixed>>} $document
	 * @param array{version:int,entries:array<string,array<string,mixed>>}        $store
	 */
	private static function file_differs( array $document, array $store ): bool {
		$expected = [];
		foreach ( $store['entries'] as $entry ) {
			$compact = ChangeJournalFile::normalize_entry( $entry );
			if ( null !== $compact ) {
				$expected[ $compact['id'] ] = $compact;
			}
		}
		$actual = [];
		foreach ( $document['entries'] as $compact ) {
			$actual[ $compact['id'] ] = $compact;
		}
		ksort( $expected );
		ksort( $actual );
		return $expected !== $actual;
	}

	/**
	 * @param array<string,array<string,mixed>> $entries
	 * @return array<string,array<string,mixed>>
	 */
	private static function prune_entries( array $entries ): array {
		if ( count( $entries ) <= ChangeJournalFile::MAX_ENTRIES ) {
			return $entries;
		}
		$kept = [];
		foreach ( ChangeJournalFile::prune( array_values( $entries ), ChangeJournalFile::MAX_ENTRIES ) as $entry ) {
			$kept[ $entry['id'] ] = $entries[ $entry['id'] ];
		}
		return $kept;
	}

	/**
	 * Keep the autoloaded "an incident is open" flag in step with the entries.
	 *
	 * @param array{version:int,entries:array<string,array<string,mixed>>} $store
	 * @return bool Whether an incident opened or closed, which changes the tool surface.
	 */
	private static function refresh_open_flag( array $store ): bool {
		$open = null;
		foreach ( $store['entries'] as $entry ) {
			if ( ! in_array( $entry['state'], self::OPEN_STATES, true ) ) {
				continue;
			}
			$ts = 'incident' === $entry['state'] && is_array( $entry['incident'] ) && (int) $entry['incident']['recorded_at'] > 0
				? (int) $entry['incident']['recorded_at']
				: ( (int) $entry['settled_at'] > 0 ? (int) $entry['settled_at'] : (int) $entry['armed_at'] );
			if ( null === $open || $ts >= $open['ts'] ) {
				$open = [ 'id' => $entry['id'], 'ability' => $entry['ability'], 'ts' => $ts ];
			}
		}
		$before = get_option( self::OPEN_OPTION, '' );
		$was    = is_array( $before ) && '' !== (string) ( $before['id'] ?? '' );
		if ( ( $open ?? '' ) !== $before ) {
			update_option( self::OPEN_OPTION, $open ?? '', true );
		}
		return $was !== ( null !== $open );
	}

	/** Drop this request's cached copy of the journal option and the open flag, so the next read goes to the database. */
	private static function forget_cached_options(): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		wp_cache_delete( self::DB_OPTION, 'options' );
		wp_cache_delete( self::OPEN_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private static function bump_tool_surface(): void {
		if ( class_exists( AbilityRegistry::class ) ) {
			AbilityRegistry::bump_surface_revision();
		}
	}

	/**
	 * One audit row per imported incident, so the Audit Log shows what the file recorded.
	 *
	 * @param list<array<string,mixed>> $imported
	 */
	private static function record_imports( array $imported ): void {
		foreach ( $imported as $entry ) {
			try {
				AuditLog::record(
					'stonewright/rescue-incident',
					[
						'_meta' => [
							'operation_class'     => 'rescue_incident',
							'resource_type'       => $entry['resource_type'],
							'resource_ref'        => $entry['resource_key'],
							'change_set_id'       => $entry['id'],
							'error_code'          => 'stonewright_rescue_fatal_recorded',
							'error_message'       => 'A PHP fatal was recorded after a Stonewright change. Roll it back from Stonewright > Rescue.',
							'verification_status' => 'failed',
							'rollback_status'     => 'pending',
						],
					],
					'error'
				);
			} catch ( \Throwable $failure ) {
				unset( $failure );
			}
		}
	}

	private static function journal_file(): ?ChangeJournalFile {
		if ( self::$file_checked ) {
			return self::$file;
		}
		self::$file_checked = true;
		$dir                = self::state_directory();
		if ( '' === $dir || ! ChangeJournalFile::protect_directory( $dir ) ) {
			return null;
		}
		$name = (string) get_option( self::FILE_OPTION, '' );
		if ( ! ChangeJournalFile::is_valid_file_name( $name ) ) {
			$name = ChangeJournalFile::new_file_name();
			update_option( self::FILE_OPTION, $name, true );
		}
		self::$file = new ChangeJournalFile( $dir . '/' . $name );
		return self::$file;
	}

	/** The file only when it has been created already; reading never creates it. */
	private static function existing_file(): ?ChangeJournalFile {
		$name = (string) get_option( self::FILE_OPTION, '' );
		if ( ! ChangeJournalFile::is_valid_file_name( $name ) ) {
			return null;
		}
		$dir = self::state_directory();
		return '' === $dir ? null : new ChangeJournalFile( $dir . '/' . $name );
	}

	// -----------------------------------------------------------------------
	// Entry shape.
	// -----------------------------------------------------------------------

	/**
	 * The contract fields plus what only the database copy carries.
	 *
	 * @param mixed $entry
	 * @return array<string, mixed>|null
	 */
	private static function clean_rich( mixed $entry ): ?array {
		if ( ! is_array( $entry ) ) {
			return null;
		}
		$base = ChangeJournalFile::normalize_entry( $entry );
		if ( null === $base ) {
			return null;
		}
		$scope = isset( $entry['scope'] ) && is_scalar( $entry['scope'] ) ? (string) $entry['scope'] : 'site';

		return array_merge(
			$base,
			[
				'scope'         => in_array( $scope, self::SCOPES, true ) ? $scope : 'site',
				'actor'         => isset( $entry['actor'] ) && is_numeric( $entry['actor'] ) ? max( 0, (int) $entry['actor'] ) : 0,
				'client'        => self::short_text( $entry['client'] ?? '', 60 ),
				'recipe_detail' => self::flat( $entry['recipe_detail'] ?? [] ),
				'probe'         => self::probe( $entry['probe'] ?? null ),
				'baseline'      => self::probe( $entry['baseline'] ?? null ),
				'rollback'      => self::rollback( $entry['rollback'] ?? null ),
				'settled_at'    => isset( $entry['settled_at'] ) && is_numeric( $entry['settled_at'] ) ? max( 0, (int) $entry['settled_at'] ) : 0,
				'incident_key'  => isset( $entry['incident_key'] ) && is_scalar( $entry['incident_key'] ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', (string) $entry['incident_key'] ) ? (string) $entry['incident_key'] : '',
				'note'          => self::short_text( $entry['note'] ?? '', 200 ),
				'residual'      => true === ( $entry['residual'] ?? false ),
				'claim_at'      => isset( $entry['claim_at'] ) && is_numeric( $entry['claim_at'] ) ? max( 0, (int) $entry['claim_at'] ) : 0,
				'claim_by'      => self::short_text( $entry['claim_by'] ?? '', 20 ),
			]
		);
	}

	/**
	 * @param array<string, mixed> $incident
	 */
	private static function incident_key( string $id, array $incident ): string {
		return hash(
			'sha256',
			implode(
				'|',
				[
					$id,
					(string) ( $incident['recorded_at'] ?? 0 ),
					(string) ( $incident['file'] ?? '' ),
					(string) ( $incident['line'] ?? 0 ),
					(string) ( $incident['type'] ?? 0 ),
					(string) ( $incident['message_sha256'] ?? '' ),
				]
			)
		);
	}

	/**
	 * Probe evidence: statuses, HTTP codes and short reasons. Never a URL or a body.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function probe( mixed $probe ): ?array {
		if ( ! is_array( $probe ) ) {
			return null;
		}
		$legs = [];
		foreach ( is_array( $probe['legs'] ?? null ) ? array_slice( $probe['legs'], 0, 6 ) : [] as $leg ) {
			if ( ! is_array( $leg ) ) {
				continue;
			}
			$legs[] = [
				'leg'    => self::short_text( $leg['leg'] ?? '', 16 ),
				'status' => self::short_text( $leg['status'] ?? '', 16 ),
				'http'   => isset( $leg['http'] ) && is_numeric( $leg['http'] ) ? (int) $leg['http'] : 0,
				'reason' => self::short_text( $leg['reason'] ?? '', 48 ),
				'ms'     => isset( $leg['ms'] ) && is_numeric( $leg['ms'] ) ? max( 0, (int) $leg['ms'] ) : 0,
			];
		}
		$status = isset( $probe['status'] ) && is_scalar( $probe['status'] ) ? (string) $probe['status'] : '';
		return [
			'status'     => in_array( $status, [ 'passed', 'failed', 'unavailable' ], true ) ? $status : 'unavailable',
			'checked_at' => isset( $probe['checked_at'] ) && is_numeric( $probe['checked_at'] ) ? max( 0, (int) $probe['checked_at'] ) : 0,
			'legs'       => $legs,
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function rollback( mixed $rollback ): ?array {
		if ( ! is_array( $rollback ) ) {
			return null;
		}
		return [
			'status' => self::short_text( $rollback['status'] ?? '', 24 ),
			'at'     => isset( $rollback['at'] ) && is_numeric( $rollback['at'] ) ? max( 0, (int) $rollback['at'] ) : 0,
			'by'     => self::short_text( $rollback['by'] ?? '', 24 ),
			'recipe' => self::short_text( $rollback['recipe'] ?? '', 24 ),
			'detail' => self::short_text( $rollback['detail'] ?? '', 200 ),
			'site'   => self::short_text( $rollback['site'] ?? '', 16 ),
		];
	}

	/**
	 * A flat map of short scalar values.
	 *
	 * @return array<string, scalar>
	 */
	private static function flat( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( array_slice( $value, 0, 12, true ) as $key => $item ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z_]{1,32}$/D', $key ) ) {
				continue;
			}
			if ( is_bool( $item ) || is_int( $item ) ) {
				$out[ $key ] = $item;
			} elseif ( is_string( $item ) ) {
				$text = self::short_text( $item, 255 );
				if ( '' !== $text ) {
					$out[ $key ] = $text;
				}
			}
		}
		return $out;
	}

	private static function short_text( mixed $value, int $max ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $value ) );
		if ( '' === $text ) {
			return '';
		}
		if ( SensitiveContent::contains( $text ) ) {
			return '[redacted]';
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	/**
	 * The product token of the caller's User-Agent (for example "claude-code/1.4.2"), or "wp-cli".
	 */
	private static function client_hint(): string {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
		if ( 1 === preg_match( '#^([A-Za-z0-9][A-Za-z0-9._+/-]{0,59})#', trim( $agent ), $match ) ) {
			return $match[1];
		}
		return defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ? 'wp-cli' : '';
	}

	/** The current time, or the time a test or a host has set with the stonewright_rescue_now filter. */
	public static function now(): int {
		$now = function_exists( 'apply_filters' ) ? apply_filters( 'stonewright_rescue_now', time() ) : time();
		return is_numeric( $now ) ? (int) $now : time();
	}
}
