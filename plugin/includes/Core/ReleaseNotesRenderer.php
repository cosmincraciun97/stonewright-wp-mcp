<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * Deterministic Markdown subset renderer for WordPress View details.
 *
 * GitHub release bodies are untrusted. Escape raw HTML before interpretation,
 * then sanitize the generated markup with an explicit wp_kses allowlist.
 */
final class ReleaseNotesRenderer {

	private const MAX_BYTES = 65536;
	private const CODE_PLACEHOLDER_PREFIX = "\x1eCODE";
	private const TAG_PLACEHOLDER_PREFIX  = "\x1eTAG";
	private const PLACEHOLDER_SUFFIX      = "\x1e";

	/**
	 * @var array<string, array<string, bool>>
	 */
	private const ALLOWED_HTML = [
		'h1'     => [],
		'h2'     => [],
		'h3'     => [],
		'h4'     => [],
		'p'      => [],
		'ul'     => [],
		'ol'     => [],
		'li'     => [],
		'pre'    => [],
		'code'   => [],
		'strong' => [],
		'em'     => [],
		'a'      => [
			'href' => true,
			'rel'  => true,
		],
	];

	public static function render( string $markdown ): string {
		if ( '' === trim( $markdown ) ) {
			return self::sanitize( self::fallback_html() );
		}

		if ( strlen( $markdown ) > self::MAX_BYTES ) {
			$markdown = substr( $markdown, 0, self::MAX_BYTES );
		}

		$markdown = str_replace( [ "\r\n", "\r" ], "\n", $markdown );
		$escaped  = htmlspecialchars( $markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$html     = self::parse_blocks( $escaped );

		return self::sanitize( '' === trim( $html ) ? self::fallback_html() : $html );
	}

	private static function fallback_text(): string {
		return __( 'Release notes are not available for this version.', 'stonewright' );
	}

	private static function fallback_html(): string {
		return '<p>' . esc_html( self::fallback_text() ) . '</p>';
	}

	private static function sanitize( string $html ): string {
		return wp_kses( $html, self::ALLOWED_HTML, [ 'https' ] );
	}

	private static function parse_blocks( string $text ): string {
		$lines  = explode( "\n", $text );
		$html   = '';
		$n      = count( $lines );
		$i      = 0;

		while ( $i < $n ) {
			$line = $lines[ $i ];
			if ( '' === trim( $line ) ) {
				++$i;
				continue;
			}

			if ( str_starts_with( $line, '```' ) ) {
				$code_lines = [];
				++$i;
				while ( $i < $n && ! str_starts_with( $lines[ $i ], '```' ) ) {
					$code_lines[] = $lines[ $i ];
					++$i;
				}
				if ( $i < $n ) {
					++$i;
				}
				$html .= '<pre><code>' . implode( "\n", $code_lines ) . '</code></pre>';
				continue;
			}

			if ( 1 === preg_match( '/^(#{1,4})[ \t]+(.+)$/', $line, $heading ) ) {
				$level = (string) strlen( $heading[1] );
				$html .= '<h' . $level . '>' . self::inline( rtrim( $heading[2], " \t#" ) ) . '</h' . $level . '>';
				++$i;
				continue;
			}

			if ( 1 === preg_match( '/^[-*+][ \t]+/', $line ) ) {
				$items = [];
				while ( $i < $n && 1 === preg_match( '/^[-*+][ \t]+(.*)$/', $lines[ $i ], $item ) ) {
					$items[] = '<li>' . self::inline( $item[1] ) . '</li>';
					++$i;
				}
				$html .= '<ul>' . implode( '', $items ) . '</ul>';
				continue;
			}

			if ( 1 === preg_match( '/^\d{1,9}\.[ \t]+/', $line ) ) {
				$items = [];
				while ( $i < $n && 1 === preg_match( '/^\d{1,9}\.[ \t]+(.*)$/', $lines[ $i ], $item ) ) {
					$items[] = '<li>' . self::inline( $item[1] ) . '</li>';
					++$i;
				}
				$html .= '<ol>' . implode( '', $items ) . '</ol>';
				continue;
			}

			$paragraph = [];
			while ( $i < $n && '' !== trim( $lines[ $i ] ) && ! self::is_block_start( $lines[ $i ] ) ) {
				$paragraph[] = $lines[ $i ];
				++$i;
			}
			$html .= '<p>' . self::inline( implode( ' ', $paragraph ) ) . '</p>';
		}

		return $html;
	}

	private static function is_block_start( string $line ): bool {
		return str_starts_with( $line, '```' )
			|| 1 === preg_match( '/^(#{1,4}[ \t]|[-*+][ \t]|\d{1,9}\.[ \t])/', $line );
	}

	private static function inline( string $text, bool $allow_links = true ): string {
		$codes = [];
		$text  = preg_replace_callback(
			'/`([^`]+)`/',
			static function ( array $matches ) use ( &$codes ): string {
				$token           = self::CODE_PLACEHOLDER_PREFIX . (string) count( $codes ) . self::PLACEHOLDER_SUFFIX;
				$codes[ $token ] = '<code>' . $matches[1] . '</code>';
				return $token;
			},
			$text
		) ?? $text;

		if ( $allow_links ) {
			$text = preg_replace_callback(
				'/\[([^\]]+)\]\(([^)]+)\)/',
				static function ( array $matches ): string {
					$label = self::inline( $matches[1], false );
					$url   = self::safe_https_url( $matches[2] );
					if ( null === $url ) {
						return $label;
					}
					$href = esc_url( $url, [ 'https' ] );
					if ( '' === $href ) {
						return $label;
					}
					return '<a href="' . esc_attr( $href ) . '" rel="noopener noreferrer">' . $label . '</a>';
				},
				$text
			) ?? $text;
		}

		$tags = [];
		$text = preg_replace_callback(
			'/<[^>]+>/',
			static function ( array $matches ) use ( &$tags ): string {
				$token          = self::TAG_PLACEHOLDER_PREFIX . (string) count( $tags ) . self::PLACEHOLDER_SUFFIX;
				$tags[ $token ] = $matches[0];
				return $token;
			},
			$text
		) ?? $text;

		$text = preg_replace_callback(
			'/\*\*(.+?)\*\*/',
			static fn( array $matches ): string => '<strong>' . $matches[1] . '</strong>',
			$text
		) ?? $text;
		$text = preg_replace_callback(
			'/__(.+?)__/',
			static fn( array $matches ): string => '<strong>' . $matches[1] . '</strong>',
			$text
		) ?? $text;
		$text = preg_replace_callback(
			'/\*(.+?)\*/',
			static fn( array $matches ): string => '<em>' . $matches[1] . '</em>',
			$text
		) ?? $text;
		$text = preg_replace_callback(
			'/(?<![A-Za-z0-9])_([^_]+)_(?![A-Za-z0-9])/',
			static fn( array $matches ): string => '<em>' . $matches[1] . '</em>',
			$text
		) ?? $text;

		return strtr( $text, $codes + $tags );
	}

	private static function safe_https_url( string $raw ): ?string {
		$url = $raw;
		for ( $i = 0; $i < 5; $i++ ) {
			$decoded = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $decoded === $url ) {
				break;
			}
			$url = $decoded;
		}

		$url = trim( $url );
		if ( '' === $url || 1 === preg_match( '/[\s\x00-\x1f\x7f]/', $url ) ) {
			return null;
		}
		if ( str_starts_with( $url, '//' ) || false !== strpbrk( $url, "<>\"'" ) ) {
			return null;
		}

		$parts = parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'https' !== $scheme ) {
			return null;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		if ( '' === (string) ( $parts['host'] ?? '' ) ) {
			return null;
		}
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return null;
		}

		return $url;
	}
}
