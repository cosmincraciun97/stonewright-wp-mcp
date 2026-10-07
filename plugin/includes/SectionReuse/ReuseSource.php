<?php
/**
 * Where a reused section came from, as a change set records it.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

/**
 * The `reuse_source` extension of ChangeSetV1: for each section an insert copied, the source post and the
 * section locator, so the audit trail can say which page a section was taken from. A source is only recorded
 * when the current user may read and edit it, so a payload cannot make the audit trail name a post the user has
 * no access to.
 */
final class ReuseSource {

	/** Most sources one change set records. */
	public const MAX = 20;

	/**
	 * The source of a validated payload, or null when the payload carries none.
	 *
	 * @param array<string, mixed> $payload
	 * @return array{post_id:int,builder:string,locator:array<string,mixed>}|null
	 */
	public static function of_payload( array $payload ): ?array {
		$source = $payload['source'] ?? null;
		if ( ! is_array( $source ) || ! isset( $source['post_id'], $source['locator'] ) ) {
			return null;
		}

		return [ 'post_id' => (int) $source['post_id'], 'builder' => (string) ( $payload['builder'] ?? '' ), 'locator' => (array) $source['locator'] ];
	}

	/**
	 * An error when the user may not read and edit the source post, or it does not exist.
	 *
	 * @param array{post_id:int,builder:string,locator:array<string,mixed>} $source
	 */
	public static function check( array $source ): ?\WP_Error {
		$post_id = (int) $source['post_id'];
		$post    = get_post( $post_id );
		if ( ! is_object( $post ) || 'trash' === SectionSource::field( $post, 'post_status' ) ) {
			return new \WP_Error( 'stonewright_section_source_not_found', 'The post this section was taken from does not exist.', [ 'status' => 404, 'source_post_id' => $post_id ] );
		}
		if ( ! SourceScanner::may_use( $post_id ) ) {
			return new \WP_Error( 'stonewright_section_source_not_permitted', 'You may not read and edit the post this section was taken from.', [ 'status' => 403, 'source_post_id' => $post_id ] );
		}

		return null;
	}

	/**
	 * The sources in the form a change set stores: a list of small maps with the locator path as text.
	 *
	 * @param list<array{post_id:int,builder:string,locator:array<string,mixed>}> $sources
	 * @return list<array<string, mixed>>
	 */
	public static function for_change_set( array $sources ): array {
		$out  = [];
		$seen = [];
		foreach ( $sources as $source ) {
			$locator = [ 'kind' => (string) ( $source['locator']['kind'] ?? '' ) ];
			if ( isset( $source['locator']['id'] ) && is_string( $source['locator']['id'] ) ) {
				$locator['id'] = $source['locator']['id'];
			}
			if ( isset( $source['locator']['path'] ) && is_array( $source['locator']['path'] ) ) {
				$locator['path'] = implode( '.', array_map( 'intval', $source['locator']['path'] ) );
			}
			if ( isset( $source['locator']['anchor'] ) && is_string( $source['locator']['anchor'] ) ) {
				$locator['anchor'] = $source['locator']['anchor'];
			}
			$entry = [ 'post_id' => (int) $source['post_id'], 'builder' => (string) $source['builder'], 'locator' => $locator ];
			$key   = (string) wp_json_encode( $entry );
			if ( isset( $seen[ $key ] ) || count( $out ) >= self::MAX ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $entry;
		}

		return $out;
	}
}
