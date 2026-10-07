<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

/**
 * Recursive nested comparison of what a V4 write claims against the tree read back afterwards.
 *
 * A node that is missing, retyped, re-parented, reordered or short of an expected setting is
 * a problem; a dropped child is never a success. Every expected node must carry an id. Extra
 * settings and extra sibling nodes written by the runtime are tolerated unless `exact_children`
 * is set.
 */
final class AtomicReadbackVerifier {

	public const MAX_REPORTED = 20;
	public const MAX_NODES    = 2000;
	public const MAX_DEPTH    = 32;

	/**
	 * @param array<int, mixed>   $expected Subtrees the write is expected to have produced.
	 * @param array<int, mixed>   $actual   Tree read back from storage; the subtrees may sit anywhere in it.
	 * @param array{exact_children?: bool, root_parent?: string} $options Pin the top-level nodes to a parent id (an empty string for the document root).
	 * @return array{ok: bool, checked: int, problems: list<array<string, string>>, problems_count: int, problems_truncated: bool}
	 */
	public static function verify( array $expected, array $actual, array $options = [] ): array {
		$index = [];
		self::index( $actual, '', $index, 0 );
		$state = [ 'checked' => 0, 'problems' => [], 'count' => 0, 'exact' => ! empty( $options['exact_children'] ) ];
		foreach ( $expected as $node ) {
			self::compare( is_array( $node ) ? $node : [], isset( $options['root_parent'] ) ? (string) $options['root_parent'] : null, '', $index, $state, 0 );
		}
		return [
			'ok'                 => 0 === $state['count'],
			'checked'            => $state['checked'],
			'problems'           => $state['problems'],
			'problems_count'     => $state['count'],
			'problems_truncated' => $state['count'] > count( $state['problems'] ),
		];
	}

	/**
	 * The nested structure (id, atomic type, children) a composition's resolved XML describes.
	 *
	 * @return list<array<string, mixed>>|\WP_Error
	 */
	public static function structure_from_xml( string $xml ): array|\WP_Error {
		$xml = trim( $xml );
		if ( '' === $xml || strlen( $xml ) > 1048576 || false !== stripos( $xml, '<!DOCTYPE' ) || false !== stripos( $xml, '<!ENTITY' ) ) {
			return new \WP_Error( 'stonewright_atomic_structure_unreadable', 'The resolved composition XML is empty, too large, or declares entities.', [ 'status' => 502 ] );
		}
		$previous = libxml_use_internal_errors( true );
		$dom      = new \DOMDocument();
		$loaded   = $dom->loadXML( '<stonewright-root>' . $xml . '</stonewright-root>', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || ! $dom->documentElement instanceof \DOMElement ) {
			return new \WP_Error( 'stonewright_atomic_structure_unreadable', 'The resolved composition XML is not well formed.', [ 'status' => 502 ] );
		}
		$count = 0;
		$nodes = self::nodes_from_dom( $dom->documentElement, $count, 0 );
		return null === $nodes
			? new \WP_Error( 'stonewright_atomic_structure_unreadable', 'The resolved composition XML is nested too deeply or has too many elements.', [ 'status' => 502 ] )
			: $nodes;
	}

	/** @param array{problems: list<array<string, string>>, problems_count: int, checked: int} $report */
	public static function error( array $report, string $context ): \WP_Error {
		return new \WP_Error(
			'stonewright_atomic_readback_mismatch',
			'The readback does not match what the write reported: a node is missing, changed, moved, or reordered.',
			[
				'status'              => 409,
				'execution_status'    => 'failed',
				'verification_status' => 'failed',
				'readback_context'    => $context,
				'checked'             => (int) $report['checked'],
				'problems_count'      => (int) $report['problems_count'],
				'problems'            => array_slice( $report['problems'], 0, self::MAX_REPORTED ),
			]
		);
	}

	/**
	 * @param \DOMElement $parent
	 * @return list<array<string, mixed>>|null
	 */
	private static function nodes_from_dom( \DOMElement $parent, int &$count, int $depth ): ?array {
		if ( $depth > self::MAX_DEPTH ) {
			return null;
		}
		$out = [];
		foreach ( $parent->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			if ( ++$count > self::MAX_NODES ) {
				return null;
			}
			$children = self::nodes_from_dom( $child, $count, $depth + 1 );
			if ( null === $children ) {
				return null;
			}
			$out[] = [ 'id' => $child->getAttribute( 'id' ), 'type' => $child->tagName, 'elements' => $children ];
		}
		return $out;
	}

	/**
	 * @param array<int, mixed>                                                 $nodes
	 * @param array<string, array{node: array<string, mixed>, parent: string}> $index
	 */
	private static function index( array $nodes, string $parent, array &$index, int $depth ): void {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$id = isset( $node['id'] ) && is_scalar( $node['id'] ) ? (string) $node['id'] : '';
			if ( '' !== $id && ! isset( $index[ $id ] ) ) {
				$index[ $id ] = [ 'node' => $node, 'parent' => $parent ];
			}
			self::index( is_array( $node['elements'] ?? null ) ? $node['elements'] : [], '' !== $id ? $id : $parent, $index, $depth + 1 );
		}
	}

	/**
	 * @param array<string, mixed>                                              $expected
	 * @param array<string, array{node: array<string, mixed>, parent: string}> $index
	 * @param array{checked: int, problems: list<array<string, string>>, count: int, exact: bool} $state
	 */
	private static function compare( array $expected, ?string $parent, string $path, array $index, array &$state, int $depth ): void {
		$id = isset( $expected['id'] ) && is_scalar( $expected['id'] ) ? (string) $expected['id'] : '';
		if ( '' === $id ) {
			self::problem( $state, 'expected_node_without_id', '', $path );
			return;
		}
		++$state['checked'];
		$path    .= '/' . $id;
		$children = is_array( $expected['elements'] ?? null ) ? $expected['elements'] : [];
		if ( $depth > self::MAX_DEPTH ) {
			self::problem( $state, 'depth_exceeded', $id, $path );
			return;
		}
		if ( ! isset( $index[ $id ] ) ) {
			self::problem( $state, 'element_missing', $id, $path );
			foreach ( $children as $child ) {
				self::compare( is_array( $child ) ? $child : [], $id, $path, [], $state, $depth + 1 );
			}
			return;
		}
		$actual = $index[ $id ]['node'];
		if ( null !== $parent && $index[ $id ]['parent'] !== $parent ) {
			self::problem( $state, 'wrong_parent', $id, $path );
		}
		$wanted = self::type_of( $expected );
		if ( '' !== $wanted && $wanted !== self::type_of( $actual ) ) {
			self::problem( $state, 'type_mismatch', $id, $path );
		}
		if ( is_array( $expected['settings'] ?? null ) && ! self::contains( $expected['settings'], is_array( $actual['settings'] ?? null ) ? $actual['settings'] : [] ) ) {
			self::problem( $state, 'settings_mismatch', $id, $path );
		}
		self::compare_children( $id, $children, is_array( $actual['elements'] ?? null ) ? $actual['elements'] : [], $path, $state );
		foreach ( $children as $child ) {
			self::compare( is_array( $child ) ? $child : [], $id, $path, $index, $state, $depth + 1 );
		}
	}

	/**
	 * @param array<int, mixed>                                                 $expected
	 * @param array<int, mixed>                                                 $actual
	 * @param array{checked: int, problems: list<array<string, string>>, count: int, exact: bool} $state
	 */
	private static function compare_children( string $parent, array $expected, array $actual, string $path, array &$state ): void {
		$actual_ids = [];
		foreach ( $actual as $child ) {
			if ( is_array( $child ) && isset( $child['id'] ) && is_scalar( $child['id'] ) ) {
				$actual_ids[] = (string) $child['id'];
			}
		}
		$expected_ids = [];
		foreach ( $expected as $child ) {
			if ( is_array( $child ) && isset( $child['id'] ) && is_scalar( $child['id'] ) && '' !== (string) $child['id'] ) {
				$expected_ids[] = (string) $child['id'];
			}
		}
		$present = array_values( array_filter( $expected_ids, static fn( string $id ): bool => in_array( $id, $actual_ids, true ) ) );
		$order   = array_values( array_filter( $actual_ids, static fn( string $id ): bool => in_array( $id, $present, true ) ) );
		if ( $present !== $order ) {
			self::problem( $state, 'child_order_mismatch', $parent, $path );
		}
		if ( $state['exact'] ) {
			foreach ( array_diff( $actual_ids, $expected_ids ) as $extra ) {
				self::problem( $state, 'unexpected_child', (string) $extra, $path . '/' . $extra );
			}
		}
	}

	/** @param array<string, mixed> $node */
	private static function type_of( array $node ): string {
		if ( isset( $node['type'] ) && is_string( $node['type'] ) ) {
			return $node['type'];
		}
		$el_type = (string) ( $node['elType'] ?? '' );
		return 'widget' === $el_type ? (string) ( $node['widgetType'] ?? '' ) : $el_type;
	}

	/**
	 * Every expected key must exist with an equal value at every depth. Lists must have the same length and each
	 * entry must contain its expected entry, so a dropped nested item fails while members the runtime added do not.
	 */
	public static function contains( mixed $expected, mixed $actual ): bool {
		if ( ! is_array( $expected ) ) {
			return $expected === $actual;
		}
		if ( ! is_array( $actual ) ) {
			return false;
		}
		if ( [] === $expected ) {
			return true;
		}
		if ( array_is_list( $expected ) ) {
			if ( ! array_is_list( $actual ) || count( $actual ) !== count( $expected ) ) {
				return false;
			}
			foreach ( $expected as $index => $value ) {
				if ( ! self::contains( $value, $actual[ $index ] ) ) {
					return false;
				}
			}
			return true;
		}
		foreach ( $expected as $key => $value ) {
			if ( ! array_key_exists( $key, $actual ) || ! self::contains( $value, $actual[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}
	/** @param array{problems: list<array<string, string>>, count: int} $state */
	private static function problem( array &$state, string $code, string $id, string $path ): void {
		++$state['count'];
		if ( count( $state['problems'] ) < self::MAX_REPORTED ) {
			$state['problems'][] = [ 'code' => $code, 'id' => $id, 'path' => $path ];
		}
	}
}
