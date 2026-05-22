<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\DesignTokens;

/**
 * Resolves design-token references in a DesignSpec to concrete values.
 *
 * Token map lives in the validated spec's `tokens` section:
 *
 *   {
 *     "tokens": {
 *       "colors":  { "primary": "#0073aa", "secondary": "#23282d" },
 *       "fonts":   { "body": "Inter, sans-serif", "heading": "Georgia, serif" },
 *       "spacing": { "xs": "8", "sm": "16", "md": "24", "lg": "48" }
 *     }
 *   }
 *
 * References in spec values use the syntax `{token.path}`, e.g. `{colors.primary}`.
 * If a token is not found the original reference string is returned unchanged so
 * callers can surface a diagnostic without crashing.
 */
final class Resolver {

	/** @var array<string, mixed> */
	private array $tokens;

	/**
	 * @param array<string, mixed> $tokens  The `tokens` section from a validated DesignSpec.
	 */
	public function __construct( array $tokens ) {
		$this->tokens = $tokens;
	}

	/**
	 * Build a Resolver from a full validated spec array.
	 *
	 * @param array<string, mixed> $spec
	 */
	public static function from_spec( array $spec ): self {
		$tokens = isset( $spec['tokens'] ) && is_array( $spec['tokens'] ) ? $spec['tokens'] : [];
		return new self( $tokens );
	}

	/**
	 * Resolve a value that may contain a `{category.key}` token reference.
	 *
	 * @param mixed $value  Spec value — string, int, null, etc.
	 * @return mixed        Resolved value (same type if not a token reference).
	 */
	public function resolve( mixed $value ): mixed {
		if ( ! is_string( $value ) ) {
			return $value;
		}

		// Full-string token: "{colors.primary}" → resolved value.
		if ( preg_match( '/^\{([a-zA-Z_][a-zA-Z0-9_.]*)\}$/', $value, $m ) ) {
			$resolved = $this->lookup( $m[1] );
			return $resolved ?? $value;
		}

		// Inline token within a larger string: "padding: {spacing.md}px".
		return (string) preg_replace_callback(
			'/\{([a-zA-Z_][a-zA-Z0-9_.]*)\}/',
			function ( array $match ): string {
				$resolved = $this->lookup( $match[1] );
				return null !== $resolved ? (string) $resolved : $match[0];
			},
			$value
		);
	}

	/**
	 * Resolve a color token. Returns '' if not found.
	 */
	public function color( string $key ): string {
		$val = $this->lookup( 'colors.' . $key );
		return is_string( $val ) ? $val : '';
	}

	/**
	 * Resolve a font token. Returns '' if not found.
	 */
	public function font( string $key ): string {
		$val = $this->lookup( 'fonts.' . $key );
		return is_string( $val ) ? $val : '';
	}

	/**
	 * Resolve a spacing token as an integer pixel value. Returns -1 if not found.
	 */
	public function spacing( string $key ): int {
		$val = $this->lookup( 'spacing.' . $key );
		if ( null === $val ) {
			return -1;
		}
		return (int) $val;
	}

	/**
	 * Dot-path lookup through the tokens tree.
	 */
	private function lookup( string $path ): mixed {
		$parts   = explode( '.', $path );
		$current = $this->tokens;
		foreach ( $parts as $part ) {
			if ( ! is_array( $current ) || ! array_key_exists( $part, $current ) ) {
				return null;
			}
			$current = $current[ $part ];
		}
		return $current;
	}
}
