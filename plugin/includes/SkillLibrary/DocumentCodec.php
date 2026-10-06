<?php
/**
 * Bounded Markdown exchange for plain-data skill documents.
 *
 * @package Stonewright
 * @license AGPL-3.0-or-later
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary;

/** Reads a deliberately small scalar front-matter language, never YAML objects. */
final class DocumentCodec {

	public const MAX_BYTES = 1048576;

	/** @return array<string, mixed>|\WP_Error */
	public static function read( string $markdown, string $fallback_slug = '' ): array|\WP_Error {
		if ( strlen( $markdown ) > self::MAX_BYTES || 1 !== preg_match( '//u', $markdown )
			|| preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $markdown ) ) {
			return self::invalid( 'The skill must be UTF-8 text no larger than 1 MiB.' );
		}
		$markdown = str_replace( [ "\r\n", "\r" ], "\n", $markdown );
		if ( str_starts_with( $markdown, "\xEF\xBB\xBF" ) ) {
			$markdown = substr( $markdown, 3 );
		}
		if ( ! str_starts_with( $markdown, "---\n" ) ) {
			return self::invalid( 'The skill needs name and description front matter.' );
		}
		$closing = strpos( $markdown, "\n---\n", 3 );
		if ( false === $closing && str_ends_with( $markdown, "\n---" ) ) {
			$closing = strlen( $markdown ) - 4;
		}
		if ( false === $closing ) {
			return self::invalid( 'The front matter has no closing delimiter.' );
		}
		$lines = explode( "\n", substr( $markdown, 4, $closing - 4 ) );
		$line_count = count( $lines );
		if ( $line_count > 256 ) {
			return self::invalid( 'The front matter contains too many lines.' );
		}
		$metadata = [];
		for ( $index = 0; $index < $line_count; ++$index ) {
			$line = $lines[ $index ];
			if ( '' === trim( $line ) || str_starts_with( ltrim( $line ), '#' ) ) {
				continue;
			}
			if ( ! preg_match( '/^([a-z][a-z0-9_-]*):[ \t]*(.*)\z/', $line, $match ) ) {
				return self::invalid( 'Front matter supports flat scalar fields only.' );
			}
			$key = $match[1];
			if ( array_key_exists( $key, $metadata ) ) {
				return self::invalid( 'A front-matter field is repeated.' );
			}
			$value = trim( $match[2] );
			if ( in_array( $value, [ '>', '|', '>-', '|-' ], true ) ) {
				$parts = [];
				while ( isset( $lines[ $index + 1 ] ) && ( '' === trim( $lines[ $index + 1 ] ) || preg_match( '/^ {2,}\S/', $lines[ $index + 1 ] ) ) ) {
					++$index;
					$parts[] = preg_replace( '/^ {2}/', '', $lines[ $index ] );
				}
				$value = trim( implode( "\n", $parts ) );
				if ( str_starts_with( $match[2], '>' ) ) {
					$value = (string) preg_replace( '/(?<=\S)\n(?=\S)/', ' ', $value );
				}
			} elseif ( 'version_constraints' !== $key ) {
				$value = self::scalar( $value );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
			}
			if ( in_array( $key, [ 'enable_agentic', 'enable_prompt' ], true ) ) {
				if ( ! in_array( $value, [ 'true', 'false' ], true ) ) {
					return self::invalid( 'Exposure flags must be true or false.' );
				}
				$value = 'true' === $value;
			}
			if ( 'version_constraints' === $key ) {
				$value = self::constraints( $value );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
			}
			$metadata[ $key ] = $value;
		}
		foreach ( [ 'name', 'description' ] as $required ) {
			if ( ! isset( $metadata[ $required ] ) || ! is_string( $metadata[ $required ] ) || '' === trim( $metadata[ $required ] ) ) {
				return self::invalid( 'The skill needs nonempty name and description fields.' );
			}
		}
		$slug_input = '' !== $fallback_slug ? $fallback_slug : (string) ( $metadata['slug'] ?? $metadata['name'] );
		$slug = sanitize_title( $slug_input );
		if ( '' === $slug ) {
			return self::invalid( 'The skill needs a usable identifier.' );
		}
		return [
			'slug' => $slug,
			'title' => $metadata['name'],
			'description' => $metadata['description'],
			'content' => ltrim( substr( $markdown, $closing + 5 ), "\n" ),
			'metadata' => $metadata,
		];
	}

	/** @param array<string, mixed> $record */
	public static function write( array $record ): string {
		// The hash covers exactly the canonical body that read() returns, which never starts with a newline.
		$content = ltrim( str_replace( [ "\r\n", "\r" ], "\n", (string) ( $record['content'] ?? '' ) ), "\n" );
		if ( '' !== $content && ! str_ends_with( $content, "\n" ) ) {
			$content .= "\n";
		}
		$fields = [
			'name' => (string) ( $record['title'] ?? '' ),
			'description' => (string) ( $record['description'] ?? '' ),
			'slug' => (string) ( $record['slug'] ?? '' ),
			'provenance' => (string) ( $record['source'] ?? 'user' ),
			'content_sha256' => hash( 'sha256', $content ),
		];
		foreach ( [ 'topic', 'source_id' ] as $optional ) {
			if ( isset( $record[ $optional ] ) && is_string( $record[ $optional ] ) ) {
				$fields[ $optional ] = $record[ $optional ];
			}
		}
		$lines = [ '---' ];
		foreach ( $fields as $key => $value ) {
			$lines[] = $key . ': ' . wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		foreach ( [ 'enable_agentic', 'enable_prompt' ] as $flag ) {
			if ( isset( $record[ $flag ] ) ) {
				$lines[] = $flag . ': ' . ( $record[ $flag ] ? 'true' : 'false' );
			}
		}
		if ( isset( $record['version_constraints'] ) && is_array( $record['version_constraints'] ) ) {
			$lines[] = 'version_constraints: ' . wp_json_encode( (object) $record['version_constraints'], JSON_UNESCAPED_SLASHES );
		}
		$lines[] = '---';
		return implode( "\n", $lines ) . "\n\n" . $content;
	}

	private static function scalar( string $value ): string|\WP_Error {
		if ( '' === $value ) {
			return self::invalid( 'Empty or nested scalar fields are unsupported.' );
		}
		if ( '"' === $value[0] ) {
			$decoded = json_decode( $value, true );
			return is_string( $decoded ) ? $decoded : self::invalid( 'A quoted field is malformed.' );
		}
		if ( "'" === $value[0] ) {
			if ( strlen( $value ) < 2 || ! str_ends_with( $value, "'" ) ) {
				return self::invalid( 'A quoted field is malformed.' );
			}
			$inner = substr( $value, 1, -1 );
			if ( str_contains( str_replace( "''", '', $inner ), "'" ) ) {
				return self::invalid( 'A quoted field is malformed.' );
			}
			return str_replace( "''", "'", $inner );
		}
		if ( preg_match( '/^[&*!\[\]{}]|:\s/', $value ) ) {
			return self::invalid( 'YAML tags, aliases, collections, and nested mappings are unsupported.' );
		}
		return $value;
	}

	/** @return array<string, string>|\WP_Error */
	private static function constraints( string $value ): array|\WP_Error {
		$decoded = json_decode( $value, true );
		// An empty list is how stored rows record "no constraint"; any other list is not a component map.
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded )
			|| ! ( str_starts_with( $value, '{' ) || ( str_starts_with( $value, '[' ) && [] === $decoded ) ) ) {
			return self::invalid( 'Version constraints must be a JSON object, or [] for none.' );
		}
		if ( ! VisibilityRules::well_formed( $decoded ) ) {
			return self::invalid( 'Version constraints map components to "required" or a version expression, or list any_of alternatives.' );
		}
		// Decoding keeps only the last of repeated names, so every written member must survive it.
		// In a flat object of strings, only a member name is a JSON string followed by a colon.
		if ( preg_match_all( '/"(?:[^"\\\\]|\\\\.)*"\s*:/', $value ) !== count( $decoded ) ) {
			return self::invalid( 'A version constraint component is repeated.' );
		}
		return $decoded;
	}

	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'stonewright_skill_document_invalid', $message, [ 'status' => 400 ] );
	}
}
