<?php
/**
 * Preserving seed plans without storage or identity guesses.
 *
 * @package Stonewright
 * @license AGPL-3.0-or-later
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary;

/** Product changes and private site preferences are merged only in a proposed plan. */
final class PackRefresh {

	/** @param array<string, mixed> $inventory @param array<int, array<string, mixed>> $existing @param array<string, string> $identities @return array<string, mixed>|\WP_Error */
	public static function plan( array $inventory, array $existing, array $identities ): array|\WP_Error {
		if ( ! isset( $inventory['entries'], $inventory['diagnostics'] ) || ! is_array( $inventory['entries'] ) || [] !== $inventory['diagnostics'] ) {
			return new \WP_Error( 'stonewright_skill_pack_invalid', 'Resolve packaged skill diagnostics before planning a refresh.' );
		}
		$by_slug = [];
		foreach ( $existing as $record ) {
			$slug = (string) ( $record['slug'] ?? '' );
			if ( '' === $slug || isset( $by_slug[ $slug ] ) ) {
				return new \WP_Error( 'stonewright_skill_identity_ambiguous', 'Existing skill identities are ambiguous. No refresh plan was created.' );
			}
			$by_slug[ $slug ] = $record;
		}
		$mapped = [];
		foreach ( $inventory['entries'] as $entry ) {
			$key = (string) ( $entry['pack_key'] ?? '' );
			$slug = $identities[ $key ] ?? '';
			if ( '' === $slug ) {
				return new \WP_Error( 'stonewright_skill_identity_unverified', 'Supply verified persistent identities for every packaged entry.' );
			}
			if ( isset( $mapped[ $slug ] ) ) {
				return new \WP_Error( 'stonewright_skill_identity_ambiguous', 'Two packaged entries share one persistent identity. No refresh plan was created.' );
			}
			$mapped[ $slug ] = true;
		}
		$upserts = [];
		$conflicts = [];
		foreach ( $inventory['entries'] as $entry ) {
			$key = (string) ( $entry['pack_key'] ?? '' );
			$slug = $identities[ $key ];
			$previous = $by_slug[ $slug ] ?? null;
			if ( null !== $previous && ! in_array( $previous['source'] ?? '', [ 'builtin', 'playbook' ], true ) ) {
				$conflicts[] = [ 'slug' => $slug, 'reason' => 'local_identity_reserved' ];
				continue;
			}
			$document = $entry['record'];
			$changes = [ 'slug' => $slug, 'title' => $document['title'], 'description' => $document['description'], 'content' => $document['content'], 'source' => $entry['kind'] ];
			foreach ( [ 'topic', 'version_constraints' ] as $metadata ) {
				if ( array_key_exists( $metadata, $document['metadata'] ) ) {
					$changes[ $metadata ] = $document['metadata'][ $metadata ];
				}
			}
			if ( null === $previous ) {
				foreach ( [ 'enable_agentic', 'enable_prompt' ] as $flag ) {
					$changes[ $flag ] = (bool) ( $document['metadata'][ $flag ] ?? true );
				}
			}
			$record = RecordRules::normalize( $changes, $previous );
			if ( is_wp_error( $record ) ) {
				return $record;
			}
			$upserts[] = $record;
		}
		return [ 'upserts' => $upserts, 'conflicts' => $conflicts ];
	}
}
