<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Finalizer;

/**
 * Offset-aware Gutenberg markup parse and splice.
 *
 * Untouched blocks are copied back from the original source by byte range.
 * They are never passed through PHP `serialize_blocks()`.
 */
final class BlockSource {

	private const TOKEN = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * @param array<string, mixed> $record
	 * @return string|\WP_Error
	 */
	public static function apply( string $content, array $record, string $fragment ) {
		$action = sanitize_key( (string) ( $record['action'] ?? 'insert' ) );
		if ( 'replace' === $action ) {
			return $fragment;
		}

		$path     = isset( $record['path'] ) && is_array( $record['path'] ) ? array_values( array_map( 'intval', $record['path'] ) ) : [];
		$position = array_key_exists( 'position', $record ) && null !== $record['position'] ? (int) $record['position'] : null;
		$spec     = is_array( $record['block_spec'] ?? null ) ? $record['block_spec'] : [];
		$tree     = self::parse( $content );

		if ( 'update' === $action ) {
			$preserve_inner = ! array_key_exists( 'innerBlocks', $spec );
			return self::splice_update( $content, $tree, $path, $fragment, $preserve_inner );
		}

		return self::splice_insert( $content, $tree, $path, $position, $fragment );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function parse( string $document ): array {
		$offset = 0;
		$length = strlen( $document );
		$root   = [];
		$stack  = [];

		while ( true ) {
			$token = self::next_token( $document, $offset );
			if ( null === $token ) {
				if ( [] === $stack ) {
					if ( $offset < $length ) {
						$root[] = self::freeform( $offset, $length );
					}
					break;
				}
				while ( [] !== $stack ) {
					$frame = array_pop( $stack );
					$node  = self::close_frame( $frame, $length, $length );
					if ( [] === $stack ) {
						$root[] = $node;
					} else {
						$stack[ count( $stack ) - 1 ]['children'][] = $node;
					}
				}
				break;
			}

			$start = (int) $token['start'];
			if ( $start > $offset && [] === $stack ) {
				$root[] = self::freeform( $offset, $start );
			}

			if ( 'void' === $token['type'] ) {
				$end  = $start + (int) $token['length'];
				$node = [
					'name'        => (string) $token['name'],
					'start'       => $start,
					'end'         => $end,
					'inner_start' => $end,
					'inner_end'   => $end,
					'children'    => [],
				];
				if ( [] === $stack ) {
					$root[] = $node;
				} else {
					$stack[ count( $stack ) - 1 ]['children'][] = $node;
				}
				$offset = $end;
				continue;
			}

			if ( 'opener' === $token['type'] ) {
				$stack[] = [
					'name'        => (string) $token['name'],
					'start'       => $start,
					'inner_start' => $start + (int) $token['length'],
					'children'    => [],
				];
				$offset = $start + (int) $token['length'];
				continue;
			}

			if ( [] === $stack ) {
				$offset = $start + (int) $token['length'];
				continue;
			}

			$frame = array_pop( $stack );
			$node  = self::close_frame( $frame, $start, $start + (int) $token['length'] );
			if ( [] === $stack ) {
				$root[] = $node;
			} else {
				$stack[ count( $stack ) - 1 ]['children'][] = $node;
			}
			$offset = $start + (int) $token['length'];
		}

		return $root;
	}

	/**
	 * @param list<array<string, mixed>> $tree
	 * @param list<int>                  $path
	 * @return string|\WP_Error
	 */
	private static function splice_update( string $content, array $tree, array $path, string $fragment, bool $preserve_inner ) {
		if ( [] === $path ) {
			return self::path_error();
		}
		$node = self::at_path( $tree, $path );
		if ( null === $node ) {
			return self::path_error();
		}

		$start = (int) $node['start'];
		$end   = (int) $node['end'];
		if ( ! $preserve_inner ) {
			return substr( $content, 0, $start ) . $fragment . substr( $content, $end );
		}

		$fresh = self::first_named( self::parse( $fragment ) );
		if ( null === $fresh ) {
			return substr( $content, 0, $start ) . $fragment . substr( $content, $end );
		}

		$opener = substr( $fragment, (int) $fresh['start'], (int) $fresh['inner_start'] - (int) $fresh['start'] );
		$closer = substr( $fragment, (int) $fresh['inner_end'], (int) $fresh['end'] - (int) $fresh['inner_end'] );
		$inner  = substr( $content, (int) $node['inner_start'], (int) $node['inner_end'] - (int) $node['inner_start'] );

		return substr( $content, 0, $start ) . $opener . $inner . $closer . substr( $content, $end );
	}

	/**
	 * @param list<array<string, mixed>> $tree
	 * @param list<int>                  $parent_path
	 * @return string|\WP_Error
	 */
	private static function splice_insert( string $content, array $tree, array $parent_path, ?int $position, string $fragment ) {
		$siblings = $tree;
		if ( [] !== $parent_path ) {
			$parent = self::at_path( $tree, $parent_path );
			if ( null === $parent ) {
				return self::path_error();
			}
			$siblings = is_array( $parent['children'] ?? null ) ? $parent['children'] : [];
		}

		$count = count( $siblings );
		$index = null === $position ? $count : max( 0, min( $position, $count ) );

		if ( $index < $count ) {
			$insert_at = (int) $siblings[ $index ]['start'];
		} elseif ( [] === $parent_path ) {
			$insert_at = $count > 0 ? (int) $siblings[ $count - 1 ]['end'] : strlen( $content );
		} else {
			$parent    = self::at_path( $tree, $parent_path );
			$insert_at = is_array( $parent ) ? (int) $parent['inner_end'] : strlen( $content );
		}

		$prefix = substr( $content, 0, $insert_at );
		$suffix = substr( $content, $insert_at );
		$sep    = ( '' !== $prefix && '' !== $fragment && ! str_ends_with( $prefix, "\n" ) ) ? "\n" : '';

		return $prefix . $sep . $fragment . $suffix;
	}

	/**
	 * @param list<array<string, mixed>> $nodes
	 * @param list<int>                  $path
	 * @return array<string, mixed>|null
	 */
	private static function at_path( array $nodes, array $path ): ?array {
		$node = null;
		foreach ( $path as $index ) {
			if ( ! isset( $nodes[ $index ] ) || ! is_array( $nodes[ $index ] ) ) {
				return null;
			}
			$node  = $nodes[ $index ];
			$nodes = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : [];
		}
		return $node;
	}

	/**
	 * @param list<array<string, mixed>> $nodes
	 * @return array<string, mixed>|null
	 */
	private static function first_named( array $nodes ): ?array {
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && is_string( $node['name'] ?? null ) && '' !== (string) $node['name'] ) {
				return $node;
			}
		}
		return null;
	}

	/**
	 * @return array{type:string,name:string,start:int,length:int}|null
	 */
	private static function next_token( string $document, int $offset ): ?array {
		if ( $offset >= strlen( $document ) ) {
			return null;
		}

		$matches   = [];
		$has_match = preg_match( self::TOKEN, $document, $matches, PREG_OFFSET_CAPTURE, $offset );
		if ( 1 !== $has_match ) {
			return null;
		}

		$start     = (int) $matches[0][1];
		$length    = strlen( (string) $matches[0][0] );
		$is_closer = isset( $matches['closer'] ) && -1 !== (int) $matches['closer'][1] && '' !== (string) $matches['closer'][0];
		$is_void   = isset( $matches['void'] ) && -1 !== (int) $matches['void'][1] && '' !== (string) $matches['void'][0];
		$namespace = ( isset( $matches['namespace'] ) && -1 !== (int) $matches['namespace'][1] && '' !== (string) $matches['namespace'][0] )
			? (string) $matches['namespace'][0]
			: 'core/';
		$name      = $namespace . (string) $matches['name'][0];

		if ( $is_void ) {
			return [ 'type' => 'void', 'name' => $name, 'start' => $start, 'length' => $length ];
		}
		if ( $is_closer ) {
			return [ 'type' => 'closer', 'name' => $name, 'start' => $start, 'length' => $length ];
		}
		return [ 'type' => 'opener', 'name' => $name, 'start' => $start, 'length' => $length ];
	}

	/**
	 * @param array<string, mixed> $frame
	 * @return array<string, mixed>
	 */
	private static function close_frame( array $frame, int $inner_end, int $end ): array {
		return [
			'name'        => (string) $frame['name'],
			'start'       => (int) $frame['start'],
			'end'         => $end,
			'inner_start' => (int) $frame['inner_start'],
			'inner_end'   => $inner_end,
			'children'    => is_array( $frame['children'] ?? null ) ? $frame['children'] : [],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function freeform( int $start, int $end ): array {
		return [
			'name'        => null,
			'start'       => $start,
			'end'         => $end,
			'inner_start' => $start,
			'inner_end'   => $end,
			'children'    => [],
		];
	}

	private static function path_error(): \WP_Error {
		return new \WP_Error(
			'stonewright_invalid_path',
			__( 'Block path not found.', 'stonewright' ),
			[ 'status' => 400 ]
		);
	}
}
