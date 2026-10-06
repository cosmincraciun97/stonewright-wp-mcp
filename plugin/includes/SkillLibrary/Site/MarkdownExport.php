<?php
/**
 * Markdown export of a stored skill with provenance and a content hash.
 *
 * @package Stonewright
 * @license AGPL-3.0-or-later
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary\Site;

/**
 * Front matter starts with `name, description, slug, source, status, revision,
 * origin, exported_at, content_sha256`, then the topic, exposure preferences,
 * and version constraints. Text values are written as JSON strings so any
 * character survives; the import reader accepts them. The hash covers the
 * body exactly as an import reads it back.
 */
final class MarkdownExport {

	/** @param array<string, mixed> $record */
	public static function filename( array $record ): string {
		return (string) ( $record['slug'] ?? 'skill' ) . '.md';
	}

	/** @param array<string, mixed> $record A logical record. */
	public static function document( array $record, ?string $exported_at = null ): string {
		$body = ltrim( str_replace( [ "\r\n", "\r" ], "\n", (string) ( $record['content'] ?? '' ) ), "\n" );
		if ( '' !== $body && ! str_ends_with( $body, "\n" ) ) {
			$body .= "\n";
		}

		$lines = [
			'---',
			'name: ' . self::quoted( (string) ( $record['title'] ?? '' ) ),
			'description: ' . self::quoted( (string) ( $record['description'] ?? '' ) ),
			'slug: ' . self::quoted( (string) ( $record['slug'] ?? '' ) ),
			'source: ' . self::quoted( (string) ( $record['source'] ?? 'user' ) ),
			'status: ' . self::quoted( (string) ( $record['status'] ?? 'active' ) ),
			'revision: ' . max( 1, (int) ( $record['revision'] ?? 1 ) ),
			'origin: ' . self::quoted( home_url( '/' ) ),
			'exported_at: ' . self::quoted( $exported_at ?? gmdate( 'Y-m-d\TH:i:s\Z' ) ),
			'content_sha256: ' . hash( 'sha256', $body ),
		];
		$topic = (string) ( $record['topic'] ?? '' );
		if ( '' !== $topic ) {
			$lines[] = 'topic: ' . self::quoted( $topic );
		}
		$lines[] = 'enable_agentic: ' . ( RowFormat::flag( $record['enable_agentic'] ?? true ) ? 'true' : 'false' );
		$lines[] = 'enable_prompt: ' . ( RowFormat::flag( $record['enable_prompt'] ?? true ) ? 'true' : 'false' );
		$constraints = is_array( $record['version_constraints'] ?? null ) ? $record['version_constraints'] : [];
		if ( [] !== $constraints ) {
			$lines[] = 'version_constraints: ' . RowFormat::encode_map( $constraints );
		}
		$lines[] = '---';

		return implode( "\n", $lines ) . "\n\n" . $body;
	}

	private static function quoted( string $value ): string {
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		return is_string( $encoded ) ? $encoded : '""';
	}
}
