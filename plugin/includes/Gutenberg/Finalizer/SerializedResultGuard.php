<?php
/**
 * Holds browser-serialized block markup to the change that was queued for it.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Finalizer;

use Stonewright\WpMcp\Gutenberg\RawHtmlGate;

/**
 * A queued change is a {name, attributes, innerBlocks} spec and the browser answers with the markup
 * its native editor serialized for it. That markup ends up in the post, so it is held to the spec
 * twice: before the queue accepts it from the browser, and again before the finalize ability writes
 * it. Three rules apply, checked in this order.
 *
 * Custom code. The markup may carry only the kinds of custom code that RawHtmlGate found in the
 * spec when the change was queued (the record's `custom_code`). The gate refuses such a spec unless
 * it comes with allow_raw_html and a consumed, human-issued custom_code_grant, so those kinds are
 * exactly what the grant covered. A record with no recorded approval may carry none: a script,
 * style or iframe element, an inline event handler, or a javascript: URL is refused.
 *
 * Structure. The markup must parse, strictly (see BlockSource::outline()), as exactly one top-level
 * block. Its name, and the names, order and count of its inner blocks, must equal the spec's; an
 * absent innerBlocks means no inner blocks. Whitespace may surround the block, nothing else may.
 *
 * Attributes. Where a block comment and the spec both carry an attribute their values must be equal
 * as JSON values (key order and 50 versus 50.0 do not matter). The editor leaves an attribute out of
 * the comment when it equals the block's default or when the block keeps it in its markup, so a
 * spec attribute missing from the comment is accepted. The other direction is held tighter: a
 * comment attribute the spec never set is accepted only when it equals the default declared by the
 * block's server-side registration (the editor adding a default). A block registered on the server
 * that declares no such default gets no extras. A block the server does not know is client-only,
 * so PHP never renders its comment attributes and they are not compared.
 *
 * Markup-sourced attributes (a paragraph's text, for instance) live in the saved markup and cannot
 * be recomputed on the server; the custom-code rule is what bounds that markup.
 *
 * A top-level classic (core/freeform) block is the one exception to the structure rule: the editor
 * serializes it as its bare HTML, without delimiters. It must then carry no delimiter at all, so it
 * cannot introduce blocks the spec does not name, and its spec may hold no inner blocks.
 */
final class SerializedResultGuard {

	public const MARKUP_REFUSED     = 'serialized_markup_refused';
	public const STRUCTURE_MISMATCH = 'serialized_structure_mismatch';

	/**
	 * @param array<string, mixed> $record Queue record: block_spec and custom_code are read.
	 * @return array{code:string,message:string}|null Null when the markup may be accepted.
	 */
	public static function refusal( array $record, string $html ): ?array {
		$approved   = [];
		foreach ( (array) ( $record['custom_code'] ?? [] ) as $kind ) {
			if ( is_string( $kind ) ) {
				$approved[] = $kind;
			}
		}
		$unapproved = array_values( array_diff( RawHtmlGate::custom_code_kinds( $html ), $approved ) );
		if ( [] !== $unapproved ) {
			return self::refuse(
				self::MARKUP_REFUSED,
				sprintf(
					__( 'The serialized markup carries custom code this change was not approved for (%1$s). Queue custom code in a core/html block with allow_raw_html and a human-issued custom_code_grant.', 'stonewright' ),
					implode( ', ', $unapproved )
				)
			);
		}

		$spec = is_array( $record['block_spec'] ?? null ) ? $record['block_spec'] : [];
		if ( 'core/freeform' === self::qualified( (string) ( $spec['name'] ?? $spec['blockName'] ?? '' ) ) ) {
			$difference = self::freeform_difference( $spec, $html );
			return null === $difference ? null : self::refuse( self::STRUCTURE_MISMATCH, $difference );
		}

		$outline = BlockSource::outline( $html, 2 * BlockQueue::MAX_TREE_NODES );
		if ( null === $outline ) {
			return self::refuse( self::STRUCTURE_MISMATCH, __( 'The serialized markup is not well-formed block markup.', 'stonewright' ) );
		}
		if ( 1 !== count( $outline ) ) {
			return self::refuse(
				self::STRUCTURE_MISMATCH,
				sprintf( __( 'Expected exactly one top-level block but found %1$d.', 'stonewright' ), count( $outline ) )
			);
		}

		$difference = self::difference( $spec, $outline[0], 'root' );
		return null === $difference ? null : self::refuse( self::STRUCTURE_MISMATCH, $difference );
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private static function freeform_difference( array $spec, string $html ): ?string {
		if ( isset( $spec['innerBlocks'] ) && is_array( $spec['innerBlocks'] ) && [] !== $spec['innerBlocks'] ) {
			return __( 'A classic block cannot hold inner blocks.', 'stonewright' );
		}
		// preg_match() also answers false when it gives up; that counts as a delimiter being present.
		if ( 0 !== preg_match( '/<!--\s+\/?wp:/', $html ) ) {
			return __( 'The serialized classic block carries block delimiters.', 'stonewright' );
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $spec
	 * @param array{name:string,attrs:array<string,mixed>,children:list<mixed>} $node
	 */
	private static function difference( array $spec, array $node, string $path ): ?string {
		$want = self::qualified( (string) ( $spec['name'] ?? $spec['blockName'] ?? '' ) );
		if ( '' === $want ) {
			return __( 'The queued change names no block.', 'stonewright' );
		}
		if ( $want !== $node['name'] ) {
			return sprintf( __( 'Expected %1$s at %2$s but found %3$s.', 'stonewright' ), $want, $path, $node['name'] );
		}

		$wanted = self::spec_attributes( $spec );
		$key    = self::attribute_difference( $want, $wanted, $node['attrs'] );
		if ( null !== $key ) {
			return sprintf( __( 'Attribute "%1$s" of %2$s differs from the queued change.', 'stonewright' ), $key, $path );
		}

		$inner    = isset( $spec['innerBlocks'] ) && is_array( $spec['innerBlocks'] ) ? array_values( $spec['innerBlocks'] ) : [];
		$children = array_values( $node['children'] );
		if ( count( $inner ) !== count( $children ) ) {
			return sprintf( __( 'Expected %1$d inner blocks in %2$s but found %3$d.', 'stonewright' ), count( $inner ), $path, count( $children ) );
		}
		foreach ( $inner as $index => $child_spec ) {
			$child = $children[ $index ];
			if ( ! is_array( $child_spec ) || ! is_array( $child ) ) {
				return sprintf( __( 'Inner block %1$d of %2$s cannot be compared.', 'stonewright' ), $index, $path );
			}
			$detail = self::difference( $child_spec, $child, $path . '.' . $index );
			if ( null !== $detail ) {
				return $detail;
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $wanted Spec attributes.
	 * @param array<string, mixed> $found  Attributes read from the block comment.
	 * @return string|null The first offending attribute name, made safe to show; null when they agree.
	 */
	private static function attribute_difference( string $block, array $wanted, array $found ): ?string {
		$defaults = null;
		foreach ( $found as $key => $value ) {
			$key = (string) $key;
			if ( array_key_exists( $key, $wanted ) ) {
				if ( self::canonical( $wanted[ $key ] ) !== self::canonical( $value ) ) {
					return self::display_key( $key );
				}
				continue;
			}
			$defaults ??= self::declared_defaults( $block );
			if ( null === $defaults ) {
				continue;
			}
			if ( ! array_key_exists( $key, $defaults ) || self::canonical( $defaults[ $key ] ) !== self::canonical( $value ) ) {
				return self::display_key( $key );
			}
		}
		return null;
	}

	/**
	 * Defaults a block declares through its server-side registration.
	 *
	 * @return array<string, mixed>|null Null when the server does not know the block.
	 */
	private static function declared_defaults( string $block ): ?array {
		if ( ! class_exists( '\WP_Block_Type_Registry' ) || ! method_exists( '\WP_Block_Type_Registry', 'get_instance' ) ) {
			return null;
		}
		try {
			$registered = \WP_Block_Type_Registry::get_instance()->get_registered( $block );
		} catch ( \Throwable $_throwable ) {
			// An unreadable registry grants no extras.
			return [];
		}
		if ( ! is_object( $registered ) ) {
			return null;
		}
		$schema   = isset( $registered->attributes ) && is_array( $registered->attributes ) ? $registered->attributes : [];
		$defaults = [];
		foreach ( $schema as $name => $definition ) {
			if ( is_array( $definition ) && array_key_exists( 'default', $definition ) ) {
				$defaults[ (string) $name ] = $definition['default'];
			}
		}
		return $defaults;
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return array<string, mixed>
	 */
	private static function spec_attributes( array $spec ): array {
		foreach ( [ 'attributes', 'attrs' ] as $key ) {
			if ( isset( $spec[ $key ] ) && is_array( $spec[ $key ] ) ) {
				return $spec[ $key ];
			}
		}
		return [];
	}

	private static function qualified( string $name ): string {
		$name = trim( $name );
		if ( '' === $name ) {
			return '';
		}
		return str_contains( $name, '/' ) ? $name : 'core/' . $name;
	}

	/** JSON form with object keys sorted and whole floats as integers, so equal values compare equal. */
	private static function canonical( mixed $value ): string {
		return (string) wp_json_encode( self::normalized( $value ) );
	}

	private static function normalized( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			if ( ! array_is_list( $value ) ) {
				ksort( $value );
			}
			return array_map( [ self::class, 'normalized' ], $value );
		}
		if ( is_float( $value ) && floor( $value ) === $value && abs( $value ) < 1e15 ) {
			return (int) $value;
		}
		return $value;
	}

	private static function display_key( string $key ): string {
		$safe = mb_substr( sanitize_key( $key ), 0, 40 );
		return '' === $safe ? '(unnamed)' : $safe;
	}

	/** @return array{code:string,message:string} */
	private static function refuse( string $code, string $message ): array {
		return [
			'code'    => $code,
			'message' => mb_substr( $message, 0, 500 ),
		];
	}
}
