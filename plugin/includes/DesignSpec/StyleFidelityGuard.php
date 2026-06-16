<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\DesignSpec;

/**
 * Guards strict visual specs against decorative style hallucinations.
 */
final class StyleFidelityGuard {

	/**
	 * @param array<string, mixed> $spec
	 * @return list<array<string, mixed>>
	 */
	public static function validate( array $spec ): array {
		if ( ! self::strict_enabled( $spec ) ) {
			return [];
		}

		$errors   = [];
		$sections = isset( $spec['sections'] ) && is_array( $spec['sections'] ) ? $spec['sections'] : [];
		foreach ( $sections as $section_index => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$blocks = isset( $section['blocks'] ) && is_array( $section['blocks'] ) ? $section['blocks'] : [];
			self::validate_blocks( $blocks, [ 'sections', $section_index, 'blocks' ], $errors );
		}

		return $errors;
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private static function strict_enabled( array $spec ): bool {
		$policy = $spec['style_policy'] ?? null;
		if ( ! is_string( $policy ) && isset( $spec['meta'] ) && is_array( $spec['meta'] ) ) {
			$policy = $spec['meta']['style_policy'] ?? null;
		}

		return is_string( $policy ) && in_array( strtolower( trim( $policy ) ), [ 'strict', 'pixel', 'pixel-perfect' ], true );
	}

	/**
	 * @param array<int, mixed>               $blocks
	 * @param list<int|string>               $path
	 * @param list<array<string, mixed>>     $errors
	 */
	private static function validate_blocks( array $blocks, array $path, array &$errors ): void {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$block_path = array_merge( $path, [ $index ] );
			foreach ( [ 'style', 'styles', 'field_style', 'button_style' ] as $style_key ) {
				if ( isset( $block[ $style_key ] ) && is_array( $block[ $style_key ] ) ) {
					self::validate_style_map( $block[ $style_key ], array_merge( $block_path, [ $style_key ] ), $block, $errors );
				}
			}

			if ( isset( $block['blocks'] ) && is_array( $block['blocks'] ) ) {
				self::validate_blocks( $block['blocks'], array_merge( $block_path, [ 'blocks' ] ), $errors );
			}
		}
	}

	/**
	 * @param array<string, mixed>            $style
	 * @param list<int|string>               $path
	 * @param array<string, mixed>           $node
	 * @param list<array<string, mixed>>     $errors
	 */
	private static function validate_style_map( array $style, array $path, array $node, array &$errors ): void {
		$source = self::style_source( $style, $node );
		foreach ( $style as $key => $value ) {
			if ( self::is_provenance_key( (string) $key ) ) {
				continue;
			}

			if ( ! self::is_decorative_key( (string) $key ) || self::is_neutral_value( $value ) || self::is_trusted_source( $source ) ) {
				continue;
			}

			$errors[] = [
				'keyword' => 'style_fidelity',
				'message' => 'Strict style policy requires measured design provenance before applying decorative border, radius, shadow, or filter styles.',
				'path'    => array_merge( $path, [ $key ] ),
			];
		}
	}

	/**
	 * @param array<string, mixed>  $style
	 * @param array<string, mixed>  $node
	 */
	private static function style_source( array $style, array $node ): string {
		foreach ( [ '_source', 'source', 'provenance' ] as $key ) {
			if ( isset( $style[ $key ] ) && is_string( $style[ $key ] ) ) {
				return strtolower( trim( $style[ $key ] ) );
			}
		}

		foreach ( [ 'style_source', 'design_source' ] as $key ) {
			if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) ) {
				return strtolower( trim( $node[ $key ] ) );
			}
		}

		return '';
	}

	private static function is_decorative_key( string $key ): bool {
		$normalised = str_replace( [ '-', '_' ], '', strtolower( $key ) );
		if ( str_contains( $normalised, 'border' ) || str_contains( $normalised, 'radius' ) || str_contains( $normalised, 'shadow' ) ) {
			return true;
		}

		return in_array( $normalised, [ 'filter', 'cssfilter', 'backdropfilter' ], true );
	}

	private static function is_provenance_key( string $key ): bool {
		return in_array( $key, [ '_source', 'source', 'provenance' ], true );
	}

	private static function is_trusted_source( string $source ): bool {
		return in_array( $source, [ 'design', 'explicit', 'figma', 'measured', 'reference', 'screenshot', 'user' ], true );
	}

	private static function is_neutral_value( mixed $value ): bool {
		if ( null === $value || false === $value || 0 === $value || '0' === $value ) {
			return true;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), [ '', '0px', 'false', 'no', 'none', 'transparent' ], true );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				if ( ! self::is_neutral_value( $child ) ) {
					return false;
				}
			}
			return true;
		}

		return false;
	}
}
