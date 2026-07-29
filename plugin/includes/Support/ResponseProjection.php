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
	 * @return array<string, mixed>
	 */
	public static function apply( array $result, mixed $fields ): array {
		$paths = self::normalize( $fields );
		if ( [] === $paths ) {
			return $result;
		}

		$projected = [];
		foreach ( self::ALWAYS_KEPT as $key ) {
			if ( array_key_exists( $key, $result ) ) {
				$projected[ $key ] = $result[ $key ];
			}
		}

		foreach ( $paths as $path ) {
			$projected = self::merge( $projected, self::pick( $result, $path ) );
		}

		return self::reindex_lists( $projected );
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
	 * @return array<array-key, mixed> Empty when the path does not resolve.
	 */
	private static function pick( array $node, array $segments ): array {
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

		if ( self::is_list_of_arrays( $value ) ) {
			$items = [];
			foreach ( $value as $index => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$picked = self::pick( $item, $segments );
				if ( [] === $picked ) {
					continue;
				}

				// Source indexes are kept so two paths through the same list
				// merge member-for-member instead of appending. Gaps are closed
				// by reindex_lists() once every path has been merged in.
				$items[ $index ] = $picked;
			}

			return [] === $items ? [] : [ $key => $items ];
		}

		$picked = self::pick( $value, $segments );

		return [] === $picked ? [] : [ $key => $picked ];
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
	 * Close the index gaps left by members that did not match a path.
	 *
	 * @param array<array-key, mixed> $value
	 * @return array<array-key, mixed>
	 */
	private static function reindex_lists( array $value ): array {
		$out         = [];
		$integer_key = true;
		foreach ( $value as $key => $child ) {
			$out[ $key ] = is_array( $child ) ? self::reindex_lists( $child ) : $child;
			if ( ! is_int( $key ) ) {
				$integer_key = false;
			}
		}

		return $integer_key && [] !== $out ? array_values( $out ) : $out;
	}
}
