<?php
/**
 * A read-only import review and deterministic confirmation plan.
 *
 * @package Stonewright
 * @license AGPL-3.0-or-later
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary;

/** Data validation only; permissions and a server-bound review receipt belong to the write boundary. */
final class ImportReview {

	/** @param array<int, string> $tool_names @return array<string, mixed>|\WP_Error */
	public static function examine( string $filename, string $markdown, array $tool_names = [] ): array|\WP_Error {
		if ( ! preg_match( '/^[^\\\\\/:\x00]+\.md\z/i', $filename ) ) {
			return self::invalid( 'Choose one Markdown file, without a directory path.' );
		}
		$decoded = DocumentCodec::read( $markdown, sanitize_title( substr( $filename, 0, -3 ) ) );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		$record = [
			'slug' => $decoded['slug'],
			'title' => $decoded['title'],
			'description' => $decoded['description'],
			'content' => $decoded['content'],
			'source' => 'uploaded',
			'status' => 'draft',
			'enabled' => false,
			'enable_agentic' => false,
			'enable_prompt' => false,
		];
		foreach ( [ 'topic', 'version_constraints' ] as $field ) {
			if ( isset( $decoded['metadata'][ $field ] ) ) {
				$record[ $field ] = $decoded['metadata'][ $field ];
			}
		}
		$record = RecordRules::normalize( $record );
		if ( is_wp_error( $record ) ) {
			return $record;
		}
		$review = RecordRules::review( $record, $tool_names );
		$content_hash = hash( 'sha256', $markdown );
		return [
			'filename' => $filename,
			'content' => $markdown,
			'content_hash' => $content_hash,
			'slug' => $record['slug'],
			'review_hash' => self::binding( $record['slug'], $content_hash ),
			'record' => $record,
			'lint' => [ 'errors' => $review['errors'], 'warnings' => $review['warnings'] ],
			'trust' => $review['trust'],
		];
	}

	/** @param array<string, mixed> $review @param array<int, string> $tool_names @return array<string, mixed>|\WP_Error */
	public static function confirm( array $review, array $tool_names = [] ): array|\WP_Error {
		foreach ( [ 'filename', 'content', 'content_hash', 'slug', 'review_hash' ] as $field ) {
			if ( ! isset( $review[ $field ] ) || ! is_string( $review[ $field ] ) ) {
				return self::invalid( 'Inspect the Markdown file before importing it.' );
			}
		}
		$derived = self::examine( $review['filename'], $review['content'], $tool_names );
		if ( is_wp_error( $derived ) ) {
			return $derived;
		}
		if ( ! hash_equals( $derived['content_hash'], $review['content_hash'] ) ) {
			return self::invalid( 'The file changed after review. Inspect it again.' );
		}
		if ( $derived['slug'] !== $review['slug'] || ! hash_equals( $derived['review_hash'], $review['review_hash'] ) ) {
			return self::invalid( 'The file name changed after review. Inspect it again.' );
		}
		return $derived['record'];
	}

	/** The reviewed identity is derived from the file name, so the review hash covers it with the bytes. */
	private static function binding( string $slug, string $content_hash ): string {
		return hash( 'sha256', $slug . "\n" . $content_hash );
	}

	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'stonewright_skill_import_invalid', $message, [ 'status' => 400 ] );
	}
}
