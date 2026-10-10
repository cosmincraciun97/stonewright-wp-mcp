<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg;

use Stonewright\WpMcp\Security\CustomCodeGrant;

/**
 * Native-first Gutenberg gate for raw HTML trees and embedded custom code.
 *
 * A payload string that carries custom code (a style, script or iframe element, an inline event
 * handler, or a javascript: URL; see {@see self::custom_code_kinds()}) requires allow_raw_html
 * and a consumed custom_code_grant, wherever it sits in a spec: block attributes of any key and
 * depth, innerHTML, or a named core/html block. All-raw-HTML trees are refused without the flag.
 * Content is never silently stripped.
 *
 * The same detector judges the markup a browser serializes for a queued change, so the code that
 * was approved with the spec is the only code the stored result may carry.
 */
final class RawHtmlGate {

	public const ERROR_APPROVAL = 'stonewright_custom_code_approval_required';
	public const ERROR_RAW_TREE = 'stonewright_raw_html_refused';

	/**
	 * Kinds of custom code, in the order they are reported. `unscannable` marks markup the detector
	 * could not process within its regex limits: it is never assumed to be clean.
	 *
	 * @var list<string>
	 */
	public const CODE_KINDS = [ 'style', 'script', 'iframe', 'event_handler', 'javascript_url', 'unscannable' ];

	/** An element: its name, then the attribute text with quoted values kept whole. */
	private const TAG_PATTERN = '/<([a-z][a-z0-9:-]*)((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>/i';

	/**
	 * An element start read without regard to quoting, up to the next "<" or ">". Markup inside a
	 * quoted value can still become an element when a browser parses it in another context (a
	 * quoted value can hold the end tag of a raw-text element), so it is scanned as well.
	 */
	private const LOOSE_TAG_PATTERN = '/<[a-z][a-z0-9:-]*([^<>]*)/i';

	/** One attribute: name, then an optional double-quoted, single-quoted or bare value. */
	private const ATTRIBUTE_PATTERN = '/([^\s"\'<>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'<>=`]+)))?/';

	/** @var list<string> */
	private const RAW_BLOCKS = [ 'core/html', 'core/freeform' ];

	public static function grant_path( int $post_id ): string {
		return 'gutenberg/html/' . max( 0, $post_id );
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	public static function assert_spec( array $spec, bool $allow_raw_html, string $grant, int $post_id, bool $consume = true ): ?\WP_Error {
		return self::assert_specs( [ $spec ], $allow_raw_html, $grant, $post_id, $consume );
	}

	/**
	 * @param list<array<string, mixed>> $specs
	 */
	public static function assert_specs( array $specs, bool $allow_raw_html, string $grant, int $post_id, bool $consume = true ): ?\WP_Error {
		$style_hits = [];
		foreach ( $specs as $index => $spec ) {
			if ( ! is_array( $spec ) ) {
				continue;
			}
			$style_hits = array_merge( $style_hits, self::style_hits( $spec, (string) $index ) );
		}
		if ( [] !== $style_hits ) {
			return self::style_error( $style_hits, $allow_raw_html, $grant, $post_id, $consume );
		}

		foreach ( $specs as $index => $spec ) {
			if ( ! is_array( $spec ) ) {
				continue;
			}
			$leaves = self::leaves( $spec, (string) $index );
			if ( [] === $leaves ) {
				continue;
			}
			$raw = array_values( array_filter( $leaves, [ self::class, 'is_raw_leaf' ] ) );
			if ( count( $raw ) === count( $leaves ) && ! $allow_raw_html ) {
				return self::raw_tree_error( $raw );
			}
		}

		return null;
	}

	/**
	 * @param list<array<string, mixed>> $operations
	 */
	public static function assert_operations( array $operations, bool $allow_raw_html, string $grant, int $post_id, bool $consume = true ): ?\WP_Error {
		$specs = [];
		foreach ( $operations as $index => $operation ) {
			if ( ! is_array( $operation ) ) {
				continue;
			}
			$spec = self::spec_from_operation( $operation, (string) $index );
			if ( null !== $spec ) {
				$specs[] = $spec;
			}
		}
		return self::assert_specs( $specs, $allow_raw_html, $grant, $post_id, $consume );
	}

	/**
	 * @param array<string, mixed> $operation
	 * @return array<string, mixed>|null
	 */
	private static function spec_from_operation( array $operation, string $index ): ?array {
		$block = isset( $operation['block'] ) && is_array( $operation['block'] ) ? $operation['block'] : null;
		if ( is_array( $block ) ) {
			if ( isset( $operation['innerHTML'] ) && is_string( $operation['innerHTML'] ) && ! isset( $block['innerHTML'] ) ) {
				$block['innerHTML'] = $operation['innerHTML'];
			}
			return $block;
		}
		if ( isset( $operation['innerHTML'] ) && is_string( $operation['innerHTML'] ) ) {
			return [
				'name'      => sanitize_text_field( (string) ( $operation['blockName'] ?? $operation['name'] ?? '' ) ),
				'innerHTML' => $operation['innerHTML'],
				'attributes' => isset( $operation['attrs'] ) && is_array( $operation['attrs'] ) ? $operation['attrs'] : [],
			];
		}
		if ( isset( $operation['block_spec'] ) && is_array( $operation['block_spec'] ) ) {
			return $operation['block_spec'];
		}
		unset( $index );
		return null;
	}

	/**
	 * Kinds of custom code a spec carries, across the same payload strings the gate inspects.
	 *
	 * @param array<string, mixed> $spec
	 * @return list<string> Values of {@see self::CODE_KINDS}, in that order.
	 */
	public static function spec_custom_code_kinds( array $spec ): array {
		$found = [];
		foreach ( self::style_hits( $spec, '' ) as $hit ) {
			foreach ( $hit['kinds'] as $kind ) {
				$found[ $kind ] = true;
			}
		}
		return self::ordered_kinds( $found );
	}

	/**
	 * Kinds of custom code present in a markup string: `style`, `script` and `iframe` elements,
	 * `event_handler` for an inline on* attribute, `javascript_url` for an attribute value that
	 * navigates to a javascript: URL (entity-encoded, spaced or mixed-case forms included), and
	 * `unscannable` when the markup could not be scanned. Text that merely mentions code is not
	 * code: escaped markup, prose such as "online=1", and quoted attribute text are ignored.
	 *
	 * @return list<string> Values of {@see self::CODE_KINDS}, in that order.
	 */
	public static function custom_code_kinds( string $markup ): array {
		$found  = [];
		$named  = self::scan( '/<(style|script|iframe)\b/i', $markup, 1 );
		// A tag left open at the end of a fragment borrows the next ">" of the document it lands in.
		$tagged = self::scan( self::TAG_PATTERN, $markup . '>', 2 );
		$loose  = self::scan( self::LOOSE_TAG_PATTERN, $markup, 1 );
		if ( null === $named || null === $tagged || null === $loose ) {
			$found['unscannable'] = true;
		}
		foreach ( $named ?? [] as $element ) {
			$found[ strtolower( $element ) ] = true;
		}
		foreach ( array_merge( $tagged ?? [], $loose ?? [] ) as $body ) {
			$pairs = self::attribute_pairs( $body );
			if ( null === $pairs ) {
				$found['unscannable'] = true;
				continue;
			}
			foreach ( $pairs as [ $name, $value ] ) {
				if ( 1 === preg_match( '/^on[a-z0-9_:.-]+$/i', $name ) ) {
					$found['event_handler'] = true;
				}
				if ( self::is_script_url( $value ) ) {
					$found['javascript_url'] = true;
				}
			}
		}
		return self::ordered_kinds( $found );
	}

	/**
	 * Every match of one capture group, or null when the scan fails (pattern limits reached), which
	 * the caller must not read as "nothing found".
	 *
	 * @return list<string>|null
	 */
	private static function scan( string $pattern, string $subject, int $group ): ?array {
		if ( false === preg_match_all( $pattern, $subject, $found ) ) {
			return null;
		}
		return array_values( array_map( 'strval', $found[ $group ] ) );
	}

	/**
	 * @return list<array{0:string,1:string}>|null Attribute name and decoded-as-written value; null when the scan fails.
	 */
	private static function attribute_pairs( string $attribute_text ): ?array {
		if ( false === preg_match_all( self::ATTRIBUTE_PATTERN, $attribute_text, $found, PREG_SET_ORDER ) ) {
			return null;
		}
		$pairs = [];
		foreach ( $found as $set ) {
			$value = '';
			foreach ( [ 2, 3, 4 ] as $group ) {
				if ( isset( $set[ $group ] ) && '' !== $set[ $group ] ) {
					$value = $set[ $group ];
					break;
				}
			}
			$pairs[] = [ $set[1], $value ];
		}
		return $pairs;
	}

	/**
	 * Whether an attribute value navigates to a javascript: URL once a browser has decoded its
	 * entities and dropped the whitespace and control characters it ignores in a scheme.
	 */
	private static function is_script_url( string $value ): bool {
		if ( '' === $value ) {
			return false;
		}
		$value   = (string) preg_replace( '/&#(x[0-9a-f]+|[0-9]+);?/i', '&#$1;', $value );
		$decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$compact = (string) preg_replace( '/[\x00-\x20]+/', '', $decoded );
		return 0 === stripos( $compact, 'javascript:' );
	}

	/**
	 * @param array<string, true> $found
	 * @return list<string>
	 */
	private static function ordered_kinds( array $found ): array {
		return array_values( array_filter( self::CODE_KINDS, static fn( string $kind ): bool => isset( $found[ $kind ] ) ) );
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return list<array{path:string,name:string,content:string,kinds:list<string>}>
	 */
	private static function style_hits( array $spec, string $path ): array {
		$hits = [];
		$name = self::node_name( $spec );
		foreach ( self::payloads( $spec ) as $slot => $content ) {
			$kinds = self::custom_code_kinds( $content );
			if ( [] === $kinds ) {
				continue;
			}
			$hits[] = [
				'path'    => '' === $path ? $slot : $path . '.' . $slot,
				'name'    => $name,
				'content' => $content,
				'kinds'   => $kinds,
			];
		}
		$children = self::children( $spec );
		foreach ( $children as $index => $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$child_path = '' === $path ? 'innerBlocks.' . $index : $path . '.innerBlocks.' . $index;
			$hits       = array_merge( $hits, self::style_hits( $child, $child_path ) );
		}
		return $hits;
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return list<array{path:string,name:string}>
	 */
	private static function leaves( array $spec, string $path ): array {
		$children = self::children( $spec );
		if ( [] === $children ) {
			return [
				[
					'path' => $path,
					'name' => self::node_name( $spec ),
				],
			];
		}
		$out = [];
		foreach ( $children as $index => $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$child_path = '' === $path ? 'innerBlocks.' . $index : $path . '.innerBlocks.' . $index;
			$out        = array_merge( $out, self::leaves( $child, $child_path ) );
		}
		return $out;
	}

	/** @param array{path:string,name:string} $leaf */
	private static function is_raw_leaf( array $leaf ): bool {
		return in_array( (string) ( $leaf['name'] ?? '' ), self::RAW_BLOCKS, true );
	}

	/**
	 * Every string a block could render as markup: attribute strings of any key at any depth
	 * (rich-text attributes such as content, text, caption and values all end up in the saved
	 * markup), plus the spec's own innerHTML and html.
	 *
	 * @param array<string, mixed> $spec
	 * @return array<string, string>
	 */
	private static function payloads( array $spec ): array {
		$out = self::attribute_strings( self::attributes( $spec ), 'attributes' );
		foreach ( [ 'innerHTML', 'html' ] as $key ) {
			if ( isset( $spec[ $key ] ) && is_string( $spec[ $key ] ) && '' !== $spec[ $key ] ) {
				$out[ $key ] = $spec[ $key ];
			}
		}
		return $out;
	}

	/**
	 * @param array<array-key, mixed> $values
	 * @return array<string, string> Non-empty string leaves keyed by their dotted path.
	 */
	private static function attribute_strings( array $values, string $prefix ): array {
		$out = [];
		foreach ( $values as $key => $value ) {
			$slot = $prefix . '.' . $key;
			if ( is_string( $value ) ) {
				if ( '' !== $value ) {
					$out[ $slot ] = $value;
				}
			} elseif ( is_array( $value ) ) {
				$out = array_merge( $out, self::attribute_strings( $value, $slot ) );
			}
		}
		return $out;
	}

	/** @param array<string, mixed> $spec */
	private static function node_name( array $spec ): string {
		return sanitize_text_field( (string) ( $spec['name'] ?? $spec['blockName'] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return array<string, mixed>
	 */
	private static function attributes( array $spec ): array {
		if ( isset( $spec['attributes'] ) && is_array( $spec['attributes'] ) ) {
			return $spec['attributes'];
		}
		if ( isset( $spec['attrs'] ) && is_array( $spec['attrs'] ) ) {
			return $spec['attrs'];
		}
		return [];
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return list<mixed>
	 */
	private static function children( array $spec ): array {
		$inner = $spec['innerBlocks'] ?? null;
		return is_array( $inner ) ? array_values( $inner ) : [];
	}

	public static function contains_style( string $html ): bool {
		return (bool) preg_match( '/<style\b/i', $html );
	}

	/**
	 * @param list<array{path:string,name:string,content:string,kinds:list<string>}> $hits
	 */
	private static function style_error( array $hits, bool $allow_raw_html, string $grant, int $post_id, bool $consume ): ?\WP_Error {
		$paths = array_values( array_unique( array_map( static fn( array $hit ): string => (string) $hit['path'], $hits ) ) );
		$map   = [];
		foreach ( $hits as $hit ) {
			$map[ (string) $hit['path'] ] = (string) $hit['content'];
		}
		ksort( $map );
		$candidate = 1 === count( $map ) ? (string) reset( $map ) : (string) wp_json_encode( $map );
		$hash      = hash( 'sha256', $candidate );
		$path      = self::grant_path( $post_id );
		$first     = $hits[0];

		if ( $allow_raw_html && '' !== $grant ) {
			if ( ! $consume ) {
				return null;
			}
			$ok = CustomCodeGrant::verify_and_consume( $grant, $path, $hash, 'html', strlen( $candidate ) );
			if ( $ok instanceof \WP_Error ) {
				return $ok;
			}
			return null;
		}

		$proposal = CustomCodeGrant::missing_grant_proposal(
			[
				'path'                       => $path,
				'language'                   => 'html',
				'after_sha256'               => $hash,
				'changed_bytes'              => strlen( $candidate ),
				'resource_type'              => 'gutenberg_html',
				'resource_ref'               => $path,
				'execution_status'           => 'blocked',
				'verification_status'        => 'blocked',
				'allow_raw_html_required'    => true,
				'custom_code_grant_required' => true,
			]
		);

		return new \WP_Error(
			self::ERROR_APPROVAL,
			__( 'Custom code in a Gutenberg HTML payload (CSS, script, frames, inline event handlers, or javascript: URLs) requires allow_raw_html:true and a human-issued custom_code_grant. Prefer block supports and theme preset slugs. Do not strip the code.', 'stonewright' ),
			array_merge(
				[
					'status'                     => 400,
					'retryable'                  => false,
					'offending_path'             => (string) $first['path'],
					'offending_paths'            => $paths,
					'block_name'                 => (string) $first['name'],
					'native_alternative'         => __( 'Use attrs.style, textColor/backgroundColor preset slugs, or typed block supports. Site-wide CSS uses stonewright-theme-custom-css after dry_run. Elementor custom_css keys use the same custom_code_grant pipeline.', 'stonewright' ),
					'path'                       => $path,
					'language'                   => 'html',
					'after_sha256'               => $hash,
					'allow_raw_html_required'    => true,
					'custom_code_grant_required' => true,
					'allow_raw_html'             => $allow_raw_html,
					'dry_run_tool'               => 'stonewright-theme-custom-css',
				],
				$proposal
			)
		);
	}

	/**
	 * @param list<array{path:string,name:string}> $leaves
	 */
	private static function raw_tree_error( array $leaves ): \WP_Error {
		return new \WP_Error(
			self::ERROR_RAW_TREE,
			__( 'An all-raw-HTML block tree is refused unless allow_raw_html is true. Queue named core blocks with attributes instead of core/html or Classic (freeform) leaves.', 'stonewright' ),
			[
				'status'     => 400,
				'raw_leaves' => array_values(
					array_map(
						static fn( array $leaf ): array => [
							'path' => (string) $leaf['path'],
							'name' => (string) $leaf['name'],
						],
						$leaves
					)
				),
			]
		);
	}
}
