<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg;

use Stonewright\WpMcp\Security\CustomCodeGrant;

/**
 * Native-first Gutenberg gate for raw HTML trees and embedded CSS.
 *
 * Named core/html (and any innerHTML payload) containing {@see <style>} requires
 * allow_raw_html and a consumed custom_code_grant. All-raw-HTML trees are
 * refused without the flag. Content is never silently stripped.
 */
final class RawHtmlGate {

	public const ERROR_APPROVAL = 'stonewright_custom_code_approval_required';
	public const ERROR_RAW_TREE = 'stonewright_raw_html_refused';

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
	 * @param array<string, mixed> $spec
	 * @return list<array{path:string,name:string,content:string}>
	 */
	private static function style_hits( array $spec, string $path ): array {
		$hits = [];
		$name = self::node_name( $spec );
		foreach ( self::payloads( $spec ) as $slot => $content ) {
			if ( ! self::contains_style( $content ) ) {
				continue;
			}
			$hits[] = [
				'path'    => '' === $path ? $slot : $path . '.' . $slot,
				'name'    => $name,
				'content' => $content,
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
	 * @param array<string, mixed> $spec
	 * @return array<string, string>
	 */
	private static function payloads( array $spec ): array {
		$out        = [];
		$attributes = self::attributes( $spec );
		foreach ( [ 'content', 'html' ] as $key ) {
			if ( isset( $attributes[ $key ] ) && is_string( $attributes[ $key ] ) && '' !== $attributes[ $key ] ) {
				$out[ 'attributes.' . $key ] = $attributes[ $key ];
			}
		}
		foreach ( [ 'innerHTML', 'html' ] as $key ) {
			if ( isset( $spec[ $key ] ) && is_string( $spec[ $key ] ) && '' !== $spec[ $key ] ) {
				$out[ $key ] = $spec[ $key ];
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
	 * @param list<array{path:string,name:string,content:string}> $hits
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
			__( 'Raw CSS in a Gutenberg HTML payload requires allow_raw_html:true and a human-issued custom_code_grant. Prefer block supports and theme preset slugs. Do not strip the CSS.', 'stonewright' ),
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
