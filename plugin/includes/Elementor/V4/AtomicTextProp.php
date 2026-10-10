<?php
/**
 * The text prop of an Elementor Atomic widget: its live type, the envelope of each type and the readback check.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

/**
 * The text of `e-heading`, `e-paragraph` and `e-button` is a typed prop whose type differs between Elementor
 * versions: a plain string (`html`, `escaped-html`) or an object (`html-v2`, `html-v3`). Elementor renders a
 * stored value only when its `$$type` is the one the widget declares, so a value of another type is stored and
 * read back correctly but renders empty.
 *
 * This class reads the declared type from Elementor's own props schema of the live widget. Only the types it
 * knows an envelope for are used; anything else is left alone and the bundled map applies.
 */
final class AtomicTextProp {

	/** @var list<string> Text prop types with a known envelope, besides the plain `string`. */
	public const TYPES = [ 'html-v3', 'html-v2', 'escaped-html', 'html' ];

	/** @var list<string> Types of a union that stand in for the text and are not the text type. */
	private const STAND_INS = [ 'dynamic', 'overridable' ];

	public static function is_text_type( string $type ): bool {
		return in_array( $type, self::TYPES, true );
	}

	/**
	 * The text type the live widget declares for a prop, or null when no live widget is available, the prop is not
	 * a text prop, or its type is not one this class knows an envelope for.
	 */
	public static function live_type( string $atomic_type, string $key ): ?string {
		$prop = self::live_prop( $atomic_type, $key );
		if ( null === $prop ) {
			return null;
		}
		$descriptor = self::descriptor( $prop );
		if ( [] === $descriptor ) {
			return null;
		}
		$declared = [];
		if ( is_array( $descriptor['prop_types'] ?? null ) ) {
			$declared = array_values( array_filter( array_map( 'strval', array_keys( $descriptor['prop_types'] ) ), static fn( string $type ): bool => ! in_array( $type, self::STAND_INS, true ) ) );
		} elseif ( is_string( $descriptor['key'] ?? null ) ) {
			$declared = [ $descriptor['key'] ];
		}
		$text = array_values( array_filter( $declared, [ self::class, 'is_text_type' ] ) );
		if ( [] === $text ) {
			return null;
		}
		$default = is_array( $descriptor['default'] ?? null ) && is_string( $descriptor['default']['$$type'] ?? null ) ? $descriptor['default']['$$type'] : '';

		return in_array( $default, $text, true ) ? $default : $text[0];
	}

	/**
	 * The envelope that stores a text under a text type, or null for a type this class has no envelope for.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function envelope( string $type, string $text ): ?array {
		return match ( $type ) {
			'html-v3'            => [ '$$type' => 'html-v3', 'value' => [ 'content' => [ '$$type' => 'string', 'value' => $text ], 'children' => [] ] ],
			'html-v2'            => [ '$$type' => 'html-v2', 'value' => [ 'content' => $text, 'children' => [] ] ],
			'escaped-html', 'html' => [ '$$type' => $type, 'value' => $text ],
			default              => null,
		};
	}

	/**
	 * The text an envelope holds, read by the envelope's own type; null when its value does not have the shape of its
	 * type (such a value renders nothing) or when it is not a text envelope.
	 */
	public static function text_of( mixed $envelope ): ?string {
		if ( ! is_array( $envelope ) || ! is_string( $envelope['$$type'] ?? null ) || ! array_key_exists( 'value', $envelope ) ) {
			return null;
		}
		$value = $envelope['value'];

		return match ( $envelope['$$type'] ) {
			'escaped-html', 'html', 'string' => is_string( $value ) ? $value : null,
			'html-v2'                        => is_array( $value ) && is_string( $value['content'] ?? null ) && is_array( $value['children'] ?? null ) ? $value['content'] : null,
			'html-v3'                        => is_array( $value ) && is_array( $value['content'] ?? null ) && 'string' === ( $value['content']['$$type'] ?? null ) && is_string( $value['content']['value'] ?? null ) && is_array( $value['children'] ?? [] ) ? $value['content']['value'] : null,
			default                          => null,
		};
	}

	/**
	 * Whether an envelope of a text type has the shape that type stores.
	 */
	public static function shape_valid( mixed $envelope ): bool {
		return null !== self::text_of( $envelope );
	}

	/**
	 * The text props of an Atomic type whose type was read from the live widget, as settings key => type.
	 *
	 * @return array<string, string>
	 */
	public static function live_props( string $atomic_type ): array {
		$schema = AtomicSchemaRepository::for_atomic_type( $atomic_type );
		$props  = is_array( $schema['props'] ?? null ) ? $schema['props'] : [];
		$out    = [];
		foreach ( $props as $name => $prop ) {
			if ( is_array( $prop ) && 'live_runtime' === ( $prop['type_source'] ?? '' ) && is_string( $prop['type'] ?? null ) && self::is_text_type( $prop['type'] ) ) {
				$out[ (string) ( $prop['key'] ?? $name ) ] = $prop['type'];
			}
		}

		return $out;
	}

	/**
	 * The text of the given elements that Elementor would render empty: an envelope of a text type other than the
	 * one the live widget declares, or one whose value does not have the shape of its own type. An envelope of any
	 * other type (a dynamic tag, for one) is not a text envelope and is left alone. A text identical to the one in
	 * `$before` is not reported: it is not what the write changed.
	 *
	 * @param array<int, mixed> $tree    The document tree after the write.
	 * @param list<string>      $ids     The elements the write touched.
	 * @param array<int, mixed> $before  The document tree before it, when there was one.
	 * @return list<array{code:string,id:string,path:string,key:string,expected_type:string,actual_type:string}>
	 */
	public static function problems( array $tree, array $ids, array $before = [] ): array {
		$wanted   = array_fill_keys( $ids, true );
		$previous = [];
		self::index( $before, $previous );
		$found = [];
		self::index( $tree, $found );
		$problems = [];
		foreach ( $found as $id => $node ) {
			if ( ! isset( $wanted[ $id ] ) || 'widget' !== ( $node['elType'] ?? '' ) || ! is_string( $node['widgetType'] ?? null ) || ! is_array( $node['settings'] ?? null ) ) {
				continue;
			}
			$live = self::live_props( $node['widgetType'] );
			foreach ( self::bundled_text_keys( $node['widgetType'] ) as $key ) {
				$envelope = $node['settings'][ $key ] ?? null;
				if ( ! is_array( $envelope ) || ! is_string( $envelope['$$type'] ?? null ) ) {
					continue;
				}
				$actual = $envelope['$$type'];
				if ( ! self::is_text_type( $actual ) && 'string' !== $actual ) {
					continue;
				}
				$expected = $live[ $key ] ?? '';
				$wrong    = '' !== $expected && $actual !== $expected;
				if ( ! $wrong && self::shape_valid( $envelope ) ) {
					continue;
				}
				if ( isset( $previous[ $id ]['settings'][ $key ] ) && $previous[ $id ]['settings'][ $key ] === $envelope ) {
					continue;
				}
				$problems[] = [ 'code' => $wrong ? 'text_type_not_declared' : 'text_shape_invalid', 'id' => (string) $id, 'path' => '/' . $id . '/settings/' . $key, 'key' => $key, 'expected_type' => '' !== $expected ? $expected : $actual, 'actual_type' => $actual ];
			}
		}

		return $problems;
	}

	/**
	 * The refusal for text a write would leave unrendered.
	 *
	 * @param non-empty-list<array{code:string,id:string,path:string,key:string,expected_type:string,actual_type:string}> $problems
	 */
	public static function error( array $problems ): \WP_Error {
		$first = $problems[0];

		return new \WP_Error(
			'stonewright_atomic_text_not_renderable',
			sprintf(
				/* translators: 1: element id, 2: settings key, 3: the type stored, 4: " (and N more)" or empty, 5: the type the widget declares */
				'Element %1$s stores its "%2$s" text as %3$s, which this site\'s Elementor renders empty%4$s. Write the text with $$type "%5$s" (for a copied element, with update_node in the same batch). No page data was written.',
				$first['id'],
				$first['key'],
				$first['actual_type'],
				count( $problems ) > 1 ? ' (and ' . ( count( $problems ) - 1 ) . ' more)' : '',
				$first['expected_type']
			),
			[
				'status'              => 409,
				'execution_status'    => 'blocked',
				'verification_status' => 'failed',
				'problems'            => array_slice( $problems, 0, 10 ),
				'problems_count'      => count( $problems ),
				'write_blocked'       => true,
				'retryable'           => true,
			]
		);
	}

	/** @return list<string> Settings keys of the text props of an Atomic widget, in the schema repository. */
	private static function bundled_text_keys( string $atomic_type ): array {
		$schema = AtomicSchemaRepository::for_atomic_type( $atomic_type );
		$keys   = [];
		foreach ( is_array( $schema['props'] ?? null ) ? $schema['props'] : [] as $name => $prop ) {
			if ( is_array( $prop ) && is_string( $prop['type'] ?? null ) && self::is_text_type( $prop['type'] ) ) {
				$keys[] = (string) ( $prop['key'] ?? $name );
			}
		}

		return $keys;
	}

	/**
	 * @param array<int, mixed>                  $nodes
	 * @param array<string, array<string, mixed>> $index
	 */
	private static function index( array $nodes, array &$index, int $depth = 0 ): void {
		if ( $depth > AtomicReadbackVerifier::MAX_DEPTH ) {
			return;
		}
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( isset( $node['id'] ) && is_scalar( $node['id'] ) && '' !== (string) $node['id'] && ! isset( $index[ (string) $node['id'] ] ) ) {
				$index[ (string) $node['id'] ] = $node;
			}
			if ( is_array( $node['elements'] ?? null ) ) {
				self::index( $node['elements'], $index, $depth + 1 );
			}
		}
	}

	private static function live_prop( string $atomic_type, string $key ): ?object {
		if ( ! class_exists( '\\Elementor\\Plugin' ) || ! isset( \Elementor\Plugin::$instance ) ) {
			return null;
		}
		$manager = \Elementor\Plugin::$instance->widgets_manager ?? null;
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_widget_types' ) ) {
			return null;
		}
		try {
			$widget = $manager->get_widget_types( $atomic_type );
			if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_props_schema' ) ) {
				return null;
			}
			$schema = call_user_func( [ $widget, 'get_props_schema' ] );
		} catch ( \Throwable ) {
			return null;
		}
		$prop = is_array( $schema ) ? ( $schema[ $key ] ?? null ) : null;

		return is_object( $prop ) ? $prop : null;
	}

	/** @return array<string, mixed> */
	private static function descriptor( object $prop ): array {
		try {
			$raw = $prop instanceof \JsonSerializable ? $prop->jsonSerialize() : ( method_exists( $prop, 'to_json_schema' ) ? $prop->to_json_schema() : null );
		} catch ( \Throwable ) {
			return [];
		}

		return is_array( $raw ) ? $raw : [];
	}
}
