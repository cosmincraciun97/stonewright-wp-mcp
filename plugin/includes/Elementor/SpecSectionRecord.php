<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

/**
 * Which top-level container of a page was built from which Design Spec section.
 *
 * The renderer derives element ids from the position of a node in the spec, so an element id says nothing about
 * the section it came from. A build that names its sections stores the section id next to the id of the container
 * it produced, and a later build that targets one section looks the container up here. The record lives in post
 * meta, outside the Elementor document, so it adds nothing to the element tree.
 *
 * An id in the record only counts while a top-level container with that id is still in the document; a record
 * that no longer matches the document finds nothing.
 */
final class SpecSectionRecord {

	public const META_KEY = '_stonewright_spec_sections';

	/**
	 * @return array<string, list<string>> Section id => ids of the containers built from it.
	 */
	public static function read( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}
		$record = [];
		foreach ( $decoded as $section_id => $element_ids ) {
			if ( ! is_array( $element_ids ) ) {
				continue;
			}
			$ids = array_values( array_filter( $element_ids, static fn( mixed $id ): bool => is_string( $id ) && '' !== $id ) );
			if ( [] !== $ids ) {
				$record[ (string) $section_id ] = $ids;
			}
		}
		return $record;
	}

	/**
	 * @param array<string, list<string>> $record Section id => container ids; an empty record removes the meta.
	 */
	public static function store( int $post_id, array $record ): bool {
		if ( [] === $record ) {
			if ( [] !== self::read( $post_id ) ) {
				delete_post_meta( $post_id, self::META_KEY );
			}
			return true;
		}
		$encoded = wp_json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) ) {
			return false;
		}
		// update_post_meta() unslashes what it receives; false also means "unchanged", which is not a failure.
		update_post_meta( $post_id, self::META_KEY, wp_slash( $encoded ) );
		return self::read( $post_id ) === $record;
	}

	/**
	 * Top-level container ids of $tree that the record lists for $section_id, in document order.
	 *
	 * @param array<string, list<string>>      $record
	 * @param array<int, array<string, mixed>> $tree
	 * @return array<int, string> Index in $tree => container id.
	 */
	public static function containers( array $record, array $tree, string $section_id ): array {
		$wanted = array_flip( $record[ $section_id ] ?? [] );
		$found  = [];
		foreach ( $tree as $index => $element ) {
			$id = is_array( $element ) && isset( $element['id'] ) ? (string) $element['id'] : '';
			if ( '' !== $id && isset( $wanted[ $id ] ) && 'container' === (string) ( $element['elType'] ?? '' ) ) {
				$found[ (int) $index ] = $id;
			}
		}
		return $found;
	}

	/**
	 * The record without the containers that are no longer top-level containers of $tree.
	 *
	 * @param array<string, list<string>>      $record
	 * @param array<int, array<string, mixed>> $tree
	 * @return array<string, list<string>>
	 */
	public static function pruned( array $record, array $tree ): array {
		$pruned = [];
		foreach ( array_keys( $record ) as $section_id ) {
			$present = array_values( self::containers( $record, $tree, (string) $section_id ) );
			if ( [] !== $present ) {
				$pruned[ (string) $section_id ] = $present;
			}
		}
		return $pruned;
	}
}
