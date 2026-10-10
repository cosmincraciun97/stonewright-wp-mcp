<?php
/**
 * Storage-independent resolution using explicit provenance identities.
 *
 * @package Stonewright
 * @license GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary;

/** The normalized source input is a domain contract, not the WordPress filter wire format. */
final class SourceDirectory {

	/** @param array<int, array<string, mixed>> $packaged @param array<int, array<string, mixed>> $local @param array<int, array<string, mixed>> $external @return array<string, mixed> */
	public static function combine( array $packaged, array $local, array $external ): array {
		$skills = [];
		$conflicts = [];
		$reserved = [];
		$sources = [];
		foreach ( [ 'builtin' => $packaged, 'local' => $local ] as $kind => $records ) {
			foreach ( $records as $record ) {
				$slug = is_string( $record['slug'] ?? null ) ? $record['slug'] : '';
				if ( '' === $slug || isset( $reserved[ $slug ] ) ) {
					$conflicts[] = [ 'slug' => $slug, 'source_kind' => $kind, 'reason' => 'identity_collision' ];
					continue;
				}
				$reserved[ $slug ] = true;
				$record['source_kind'] = $kind;
				$record['identity'] = [ 'source_id' => '', 'slug' => $slug ];
				$skills[] = $record;
			}
		}
		$seen = [];
		foreach ( $external as $entry ) {
			$source = is_string( $entry['source_id'] ?? null ) ? $entry['source_id'] : '';
			$record = is_array( $entry['record'] ?? null ) ? $entry['record'] : [];
			$slug = is_string( $record['slug'] ?? null ) ? $record['slug'] : '';
			if ( ! preg_match( '/^[a-z0-9]+(?:[._-][a-z0-9]+)*\z/', $source ) || '' === $slug ) {
				$conflicts[] = [ 'source_id' => $source, 'slug' => $slug, 'reason' => 'invalid_source_record' ];
				continue;
			}
			$identity = [ 'source_id' => $source, 'slug' => $slug ];
			$key = wp_json_encode( $identity );
			if ( isset( $reserved[ $slug ] ) || isset( $seen[ $key ] ) ) {
				$conflicts[] = [ 'source_id' => $source, 'slug' => $slug, 'reason' => 'identity_collision' ];
				continue;
			}
			$seen[ $key ] = true;
			$sources[ $source ] = [ 'source_id' => $source ];
			foreach ( [ 'id', 'trust', 'trusted', 'verified', 'history', 'revision', 'semantic_fingerprint' ] as $site_claim ) {
				unset( $record[ $site_claim ] );
			}
			$record['source'] = 'external';
			$record['verification_count'] = 0;
			$record['source_kind'] = 'external';
			$record['source_id'] = $source;
			$record['identity'] = $identity;
			$skills[] = $record;
		}
		return [ 'skills' => $skills, 'conflicts' => $conflicts, 'sources' => array_values( $sources ) ];
	}
}
