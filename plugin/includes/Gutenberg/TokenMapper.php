<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Maps DesignSpec token references on a spec node to Gutenberg block attributes
 * and theme.json-compatible style entries.
 *
 * The spec node may carry a `tokens` array of:
 *   { "property": "color"|"fontSize"|"spacing", "token": "<token-id>" }
 *
 * Token IDs follow the Resolver dot-path convention:
 *   "colors.primary", "spacing.md", "fonts.body", etc.
 *
 * Output attributes are merged into existing attributes using:
 *   - color  → textColor slug (text blocks) / backgroundColor slug (container blocks)
 *              OR style.color.text / style.color.background (hex pass-through)
 *   - fontSize → fontSize slug or style.typography.fontSize
 *   - spacing  → style.spacing.padding / style.spacing.blockGap
 *
 * All token resolution is deterministic: same input → same output.
 */
final class TokenMapper {

	/**
	 * Apply tokens from $node to $attrs and return the merged attribute array.
	 *
	 * @param array<string, mixed> $node   Spec node (may contain 'tokens' key).
	 * @param array<string, mixed> $attrs  Existing block attributes to merge into.
	 * @param Resolver             $resolver Token resolver built from the spec.
	 * @param string               $context 'text'|'background' — determines which
	 *                                       color attribute to populate.
	 * @return array<string, mixed>
	 */
	public static function apply( array $node, array $attrs, Resolver $resolver, string $context = 'text' ): array {
		if ( ! isset( $node['tokens'] ) || ! is_array( $node['tokens'] ) ) {
			return $attrs;
		}

		foreach ( $node['tokens'] as $mapping ) {
			if ( ! is_array( $mapping ) ) {
				continue;
			}

			$property = (string) ( $mapping['property'] ?? '' );
			$token    = (string) ( $mapping['token'] ?? '' );

			if ( '' === $property || '' === $token ) {
				continue;
			}

			switch ( $property ) {
				case 'color':
					$value = self::resolve_token( $resolver, $token );
					if ( null !== $value ) {
						$attrs = self::apply_color( $attrs, $value, $context );
					}
					break;

				case 'fontSize':
					$value = self::resolve_token( $resolver, $token );
					if ( null !== $value ) {
						$attrs = self::apply_font_size( $attrs, $value );
					}
					break;

				case 'spacing':
					$value = self::resolve_token( $resolver, $token );
					if ( null !== $value ) {
						$attrs = self::apply_spacing( $attrs, $value );
					}
					break;
			}
		}

		return $attrs;
	}

	/**
	 * Look up a token by dot-path from the resolver.
	 *
	 * Unlike Resolver::resolve(), this supports any characters in path segments
	 * (including hyphens and digits) and returns null when not found.
	 *
	 * @param Resolver $resolver
	 * @param string   $token  Dot-path, e.g. "colors.primary", "fonts.size-lg".
	 * @return string|null  Resolved string value, or null if not found.
	 */
	private static function resolve_token( Resolver $resolver, string $token ): ?string {
		// Use Resolver::resolve() with the token wrapped in braces.
		// The Resolver regex only supports [a-zA-Z0-9_.] in paths, so hyphens
		// in segment names won't match as a full-string reference. In that case,
		// we do a manual dot-split lookup via the public resolve() method on
		// an inline interpolation string — but since that also goes through regex,
		// the cleanest workaround is to reflect through the resolver's resolve()
		// and detect an unresolved result.
		$ref      = '{' . $token . '}';
		$resolved = $resolver->resolve( $ref );

		if ( ! is_string( $resolved ) ) {
			return null;
		}

		// If resolve() could not find the token, it returns the original reference string.
		if ( $resolved === $ref ) {
			return null;
		}

		return '' !== $resolved ? $resolved : null;
	}

	/**
	 * Apply a color value to attributes.
	 *
	 * Hex colors go into style.color.text (or .background for container context).
	 * Named/slug colors use textColor / backgroundColor attribute slugs.
	 *
	 * @param array<string, mixed> $attrs
	 * @return array<string, mixed>
	 */
	private static function apply_color( array $attrs, string $value, string $context ): array {
		if ( str_starts_with( $value, '#' ) ) {
			// Strict hex validation: must be #RGB, #RGBA, #RRGGBB, or #RRGGBBAA (3-8 hex digits).
			if ( ! preg_match( '/^#[0-9a-fA-F]{3,8}$/', $value ) ) {
				return $attrs; // Malformed hex — skip token entirely.
			}
			// Hex: use style.color.text / style.color.background.
			if ( 'background' === $context ) {
				$attrs['style']['color']['background'] = $value;
			} else {
				$attrs['style']['color']['text'] = $value;
			}
		} else {
			// Slug: sanitize to lowercase a-z0-9, hyphens, underscores only.
			$slug = preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) );
			if ( '' === $slug ) {
				return $attrs; // Empty after sanitization — skip token.
			}
			// use textColor / backgroundColor and var:preset|color|<slug>.
			if ( 'background' === $context ) {
				$attrs['backgroundColor']              = $slug;
				$attrs['style']['color']['background'] = 'var:preset|color|' . $slug;
			} else {
				$attrs['textColor']              = $slug;
				$attrs['style']['color']['text'] = 'var:preset|color|' . $slug;
			}
		}
		return $attrs;
	}

	/**
	 * Apply a font-size value to attributes.
	 *
	 * Pixel/em values go into style.typography.fontSize.
	 * Plain slugs go into the fontSize attribute.
	 *
	 * @param array<string, mixed> $attrs
	 * @return array<string, mixed>
	 */
	private static function apply_font_size( array $attrs, string $value ): array {
		if ( preg_match( '/^\d+(\.\d+)?(px|em|rem|%)$/', $value ) ) {
			$attrs['style']['typography']['fontSize'] = $value;
		} else {
			// Slug path: sanitize to lowercase a-z0-9, hyphens, underscores.
			$slug = preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) );
			if ( '' !== $slug ) {
				$attrs['fontSize'] = $slug;
			}
			// Empty slug after sanitization — skip token.
		}
		return $attrs;
	}

	/**
	 * Apply a spacing value to attributes.
	 *
	 * Numeric or pixel values go into style.spacing.padding (all sides).
	 * Values like "8px" or "8" are normalized to "Npx".
	 *
	 * @param array<string, mixed> $attrs
	 * @return array<string, mixed>
	 */
	private static function apply_spacing( array $attrs, string $value ): array {
		// Normalize pure integer strings to "Npx".
		$normalized = ctype_digit( $value ) ? $value . 'px' : $value;

		$attrs['style']['spacing']['padding'] = [
			'top'    => $normalized,
			'right'  => $normalized,
			'bottom' => $normalized,
			'left'   => $normalized,
		];
		return $attrs;
	}
}
