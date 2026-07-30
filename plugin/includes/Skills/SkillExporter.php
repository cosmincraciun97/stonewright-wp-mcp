<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Skills;

/**
 * Writes a stored skill back out as Markdown.
 *
 * The exported file is the same shape the importer reads, and it carries the
 * things a reviewer on the other end needs: which site it came from, when it
 * left, and a hash of the body so a changed file is obvious.
 *
 * @stonewright-status stable
 */
final class SkillExporter {

	/**
	 * Render a skill as a portable Markdown file.
	 *
	 * @param int $skill_id Skill row id.
	 * @return string|\WP_Error Markdown on success.
	 */
	public static function markdown( int $skill_id ) {
		$skill = Skills::get_by_id( $skill_id );

		if ( null === $skill ) {
			return new \WP_Error(
				'stonewright_skill_not_found',
				__( 'That skill does not exist.', 'stonewright' )
			);
		}

		$body = self::normalize_body( (string) ( $skill['content'] ?? '' ) );

		$front = [
			'name'           => (string) ( $skill['title'] ?? '' ),
			'description'    => (string) ( $skill['description'] ?? '' ),
			'slug'           => (string) ( $skill['slug'] ?? '' ),
			'source'         => (string) ( $skill['source'] ?? 'user' ),
			'status'         => (string) ( $skill['status'] ?? 'draft' ),
			'revision'       => (string) (int) ( $skill['revision'] ?? 1 ),
			'origin'         => home_url( '/' ),
			'exported_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'content_sha256' => hash( 'sha256', $body ),
		];

		$lines = [ '---' ];
		foreach ( $front as $key => $value ) {
			$lines[] = $key . ': ' . self::scalar( $value );
		}
		$lines[] = '---';
		$lines[] = '';
		$lines[] = $body;
		$lines[] = '';

		return implode( "\n", $lines );
	}

	/**
	 * One line, no stray carriage returns, no trailing blank space.
	 */
	private static function normalize_body( string $content ): string {
		$content = (string) preg_replace( '/\r\n?/', "\n", $content );
		$content = (string) preg_replace( '/[ \t]+$/m', '', $content );

		return trim( $content );
	}

	/**
	 * Flatten a front-matter value so it cannot break out of its own line.
	 */
	private static function scalar( string $value ): string {
		$value = (string) preg_replace( '/\s+/', ' ', $value );

		return trim( $value );
	}
}
