<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Trims an ability response down to the parts a caller asked for.
 *
 * Reads dominate an agent's token spend, and most of a read is payload it will
 * never look at — a full Elementor structure to check one hash, a full site
 * profile to read one URL. Projection lets the caller name the branches it needs
 * and pay for those.
 *
 * Two things are deliberate. Projection is a hint, not a contract: an
 * unrecognised or malformed path is dropped in silence rather than raised as an
 * error, because a caller guessing at field names should get a smaller payload,
 * not a failed call. And the class is pure and stateless — everything comes from
 * the arguments of one call. Ability instances are reused across requests in the
 * registry, so a projection remembered anywhere on an instance would leak one
 * caller's request into the next caller's response.
 */
final class ResponseProjection {

	/**
	 * Input parameter that carries the projection.
	 *
	 * Namespaced rather than a bare `fields` because abilities own that name
	 * already — `stonewright/content-model-loop-grid-flow` takes a required
	 * `fields` input describing ACF field definitions, and a collision there
	 * would have dropped the caller's field list before execute() saw it.
	 */
	public const PARAM = 'stonewright_fields';

	/**
	 * Keys a projection can never remove.
	 *
	 * The envelope is how a caller tells a successful call from a failed one. A
	 * response projected down to nothing still has to say that it succeeded.
	 *
	 * @var list<string>
	 */
	private const ALWAYS_KEPT = [ 'ok' ];

	/** Most paths honoured in one call. */
	private const MAX_PATHS = 64;

	/** Deepest path honoured, in segments. */
	private const MAX_DEPTH = 12;

	/** Characters a path segment may contain. */
	private const SEGMENT_PATTERN = '~^[A-Za-z0-9_-]+$~';

	/**
	 * Schema fragment advertising the parameter on a strict input schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema_property(): array {
		return [
			'type'        => [ 'string', 'array' ],
			'items'       => [ 'type' => 'string' ],
			'description' => 'Optional. Dot-separated response paths to return, as a list or a comma-separated string (for example "meta.title, elements.id"). Unknown paths are ignored. Omit for the full response.',
		];
	}

	/**
	 * Project a successful response.
	 *
	 * @param array<string, mixed> $result Ability response.
	 * @param mixed                $fields Raw parameter value as it came off the wire.
	 * @param list<string>         $required_keys Top-level keys required by the declared output schema.
	 * @return array<string, mixed>
	 */
	public static function apply( array $result, mixed $fields, array $required_keys = [] ): array {
		$paths = self::normalize( $fields );
		if ( [] === $paths ) {
			return $result;
		}

		$projected = [];
		foreach ( array_values( array_unique( [ ...self::ALWAYS_KEPT, ...$required_keys ] ) ) as $key ) {
			if ( array_key_exists( $key, $result ) ) {
				$projected[ $key ] = $result[ $key ];
			}
		}

		$list_nodes = [];
		foreach ( $paths as $path ) {
			$projected = self::merge( $projected, self::pick( $result, $path, [], $list_nodes ) );
		}

		return self::close_gaps( $projected, [], $list_nodes );
	}

	/**
	 * Turn a raw parameter value into validated segment lists.
	 *
	 * @return list<list<string>>
	 */
	private static function normalize( mixed $fields ): array {
		if ( is_string( $fields ) ) {
			$fields = explode( ',', $fields );
		}

		if ( ! is_array( $fields ) ) {
			return [];
		}

		$paths = [];
		foreach ( $fields as $field ) {
			if ( ! is_string( $field ) ) {
				continue;
			}

			$segments = explode( '.', trim( $field ) );
			if ( count( $segments ) > self::MAX_DEPTH ) {
				continue;
			}

			$valid = true;
			foreach ( $segments as $segment ) {
				if ( 1 !== preg_match( self::SEGMENT_PATTERN, $segment ) ) {
					$valid = false;
					break;
				}
			}

			if ( ! $valid ) {
				continue;
			}

			$paths[] = array_values( $segments );
			if ( count( $paths ) >= self::MAX_PATHS ) {
				break;
			}
		}

		return $paths;
	}

	/**
	 * Extract one path from a node, as a partial array shaped like the source.
	 *
	 * @param array<array-key, mixed> $node
	 * @param list<string>            $segments
	 * @param list<string>            $trail      Keys already walked, for node identity.
	 * @param array<string, true>     $list_nodes Nodes picked member-wise from a list, by trail.
	 * @return array<array-key, mixed> Empty when the path does not resolve.
	 */
	private static function pick( array $node, array $segments, array $trail, array &$list_nodes ): array {
		$key = array_shift( $segments );
		if ( null === $key || ! array_key_exists( $key, $node ) ) {
			return [];
		}

		$value = $node[ $key ];
		if ( [] === $segments ) {
			return [ $key => $value ];
		}

		if ( ! is_array( $value ) ) {
			// The path asks for a branch below a leaf. Nothing to return.
			return [];
		}

		$trail[] = $key;

		if ( self::is_list_of_arrays( $value ) ) {
			$items = [];
			foreach ( $value as $index => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$picked = self::pick( $item, $segments, [], $list_nodes );
				if ( [] === $picked ) {
					continue;
				}

				// Source indexes are kept so two paths through the same list
				// merge member-for-member instead of appending. Gaps are closed
				// by close_gaps() once every path has been merged in.
				$items[ $index ] = $picked;
			}

			if ( [] === $items ) {
				return [];
			}

			// Only nodes recorded here are re-indexed later. An integer-keyed map
			// that the caller asked for wholesale keeps its keys, because losing
			// them would change what the response means.
			$list_nodes[ self::node_id( $trail ) ] = true;

			return [ $key => $items ];
		}

		$picked = self::pick( $value, $segments, $trail, $list_nodes );

		return [] === $picked ? [] : [ $key => $picked ];
	}

	/**
	 * Identity of a node inside the projected response.
	 *
	 * Members of a picked list are re-indexed as a group, so the trail records the
	 * key path down to the list itself and stops there.
	 *
	 * @param list<string> $trail
	 */
	private static function node_id( array $trail ): string {
		return implode( "\0", $trail );
	}

	/**
	 * @param array<array-key, mixed> $value
	 */
	private static function is_list_of_arrays( array $value ): bool {
		if ( [] === $value || ! array_is_list( $value ) ) {
			return false;
		}

		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<array-key, mixed> $base
	 * @param array<array-key, mixed> $addition
	 * @return array<array-key, mixed>
	 */
	private static function merge( array $base, array $addition ): array {
		foreach ( $addition as $key => $value ) {
			if ( ! array_key_exists( $key, $base ) ) {
				$base[ $key ] = $value;
				continue;
			}

			if ( is_array( $base[ $key ] ) && is_array( $value ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
				continue;
			}

			$base[ $key ] = $value;
		}

		return $base;
	}

	/**
	 * Close the index gaps left by list members that did not match a path.
	 *
	 * Only the nodes that pick() built from a list are touched, and they are sorted
	 * by source index first: two paths can match different members, and the order
	 * they were merged in is not the order the caller read them in.
	 *
	 * @param array<array-key, mixed> $value
	 * @param list<string>            $trail
	 * @param array<string, true>     $list_nodes
	 * @return array<array-key, mixed>
	 */
	private static function close_gaps( array $value, array $trail, array $list_nodes ): array {
		$out = [];
		foreach ( $value as $key => $child ) {
			if ( ! is_array( $child ) ) {
				$out[ $key ] = $child;
				continue;
			}

			$child_trail = $trail;
			if ( is_string( $key ) ) {
				$child_trail[] = $key;
			}

			if ( isset( $list_nodes[ self::node_id( $child_trail ) ] ) ) {
				ksort( $child, SORT_NUMERIC );

				$members = [];
				foreach ( $child as $member ) {
					// Members were picked with a fresh trail, so their own nested
					// lists are recorded relative to the member.
					$members[] = is_array( $member ) ? self::close_gaps( $member, [], $list_nodes ) : $member;
				}

				$out[ $key ] = $members;
				continue;
			}

			$out[ $key ] = self::close_gaps( $child, $child_trail, $list_nodes );
		}

		return $out;
	}
}
