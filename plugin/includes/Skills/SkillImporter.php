<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Skills;

use Stonewright\WpMcp\Support\Logger;

/**
 * Two-step import for uploaded skill Markdown.
 *
 * Step one is inspect(): it parses the file, lints it, scans it for the kinds
 * of instructions a hostile author would plant, and reports whether the slug
 * already belongs to something on this site. Nothing is written.
 *
 * Step two is import(): it takes the report back, checks the reviewed body
 * still hashes to what was reviewed, and then re-derives lint, trust, and
 * collision from the content itself. A report that arrives with
 * `ready_to_import` set by hand does not get to skip anything — the flag is
 * information for the reviewer, never the thing the write is gated on.
 *
 * Imported skills always land disabled, as drafts, sourced `uploaded`. They
 * only ever start doing something once a human turns them on.
 *
 * @stonewright-status stable
 */
final class SkillImporter {

	/** Largest file the importer will read, in bytes. */
	public const MAX_BYTES = 1048576;

	/**
	 * Parse and review an uploaded file without writing anything.
	 *
	 * @param string $filename Original upload filename, used for the slug.
	 * @param string $content  Raw file contents.
	 * @return array<string, mixed>|\WP_Error Report on success, error on refusal.
	 */
	public static function inspect( string $filename, string $content ) {
		if ( ! str_ends_with( strtolower( trim( $filename ) ), '.md' ) ) {
			return new \WP_Error(
				'stonewright_skill_import_invalid',
				__( 'Skills import from Markdown only. Upload a .md file.', 'stonewright' )
			);
		}

		if ( strlen( $content ) > self::MAX_BYTES ) {
			return new \WP_Error(
				'stonewright_skill_import_too_large',
				__( 'That file is larger than 1 MiB. A skill should be a short playbook, not a manual.', 'stonewright' )
			);
		}

		if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
			return new \WP_Error(
				'stonewright_skill_import_encoding',
				__( 'That file is not valid UTF-8, so its text cannot be read reliably.', 'stonewright' )
			);
		}

		$parsed = self::parse( $content );
		if ( null === $parsed ) {
			return new \WP_Error(
				'stonewright_skill_import_front_matter',
				__( 'A skill file needs YAML front matter with a name and a description.', 'stonewright' )
			);
		}

		$slug = sanitize_title( self::basename_without_extension( $filename ) );
		if ( '' === $slug ) {
			return new \WP_Error(
				'stonewright_skill_import_invalid',
				__( 'That filename does not produce a usable slug. Rename the .md file and try again.', 'stonewright' )
			);
		}

		$existing  = Skills::get( $slug );
		$lint      = self::lint( $parsed['description'], $parsed['body'] );
		$trust     = SkillImportSanitizer::scan( self::scan_target( $parsed['description'], $parsed['body'] ) );
		$collision = [
			'exists' => null !== $existing,
			'source' => (string) ( $existing['source'] ?? '' ),
		];

		return [
			'slug'            => $slug,
			'title'           => $parsed['name'],
			'description'     => $parsed['description'],
			'content'         => $parsed['body'],
			'bytes'           => strlen( $content ),
			'content_hash'    => hash( 'sha256', $content ),
			'body_hash'       => hash( 'sha256', $parsed['body'] ),
			'lint'            => $lint,
			'trust'           => $trust,
			'collision'       => $collision,
			'ready_to_import' => [] === $lint['errors'] && ! $trust['blocked'] && ! $collision['exists'],
		];
	}

	/**
	 * Store a reviewed skill as a disabled draft.
	 *
	 * @param array<string, mixed> $inspection Report returned by inspect().
	 * @param int                  $actor_id   User confirming the import.
	 * @return int|\WP_Error New skill id, or an error explaining the refusal.
	 */
	public static function import( array $inspection, int $actor_id ) {
		$slug = sanitize_title( (string) ( $inspection['slug'] ?? '' ) );
		if ( '' === $slug ) {
			return new \WP_Error(
				'stonewright_skill_import_invalid',
				__( 'The confirmed import has no slug.', 'stonewright' )
			);
		}

		$content     = (string) ( $inspection['content'] ?? '' );
		$description = (string) ( $inspection['description'] ?? '' );
		$reviewed    = (string) ( $inspection['body_hash'] ?? '' );

		if ( '' === $reviewed || ! hash_equals( $reviewed, hash( 'sha256', $content ) ) ) {
			return new \WP_Error(
				'stonewright_skill_import_hash_mismatch',
				__( 'The content changed after it was reviewed. Inspect the file again before importing it.', 'stonewright' )
			);
		}

		// Readiness is recomputed here. The flag in the report is a hint for the
		// reviewer, not something the write trusts.
		$lint  = self::lint( $description, $content );
		$trust = SkillImportSanitizer::scan( self::scan_target( $description, $content ) );

		if ( [] !== $lint['errors'] || $trust['blocked'] ) {
			return new \WP_Error(
				'stonewright_skill_import_not_ready',
				__( 'This skill still has problems that have to be fixed before it can be imported.', 'stonewright' ),
				[
					'lint'  => $lint,
					'trust' => $trust,
				]
			);
		}

		if ( null !== Skills::get( $slug ) ) {
			return new \WP_Error(
				'stonewright_skill_import_collision',
				__( 'A skill with that slug already exists here. Import never overwrites; rename the file or edit the existing skill.', 'stonewright' )
			);
		}

		$id = Skills::save(
			[
				'slug'           => $slug,
				'title'          => (string) ( $inspection['title'] ?? $slug ),
				'description'    => $description,
				'content'        => $content,
				'source'         => 'uploaded',
				'status'         => 'draft',
				'enabled'        => 0,
				'enable_agentic' => 0,
				'enable_prompt'  => 0,
			]
		);

		if ( $id <= 0 ) {
			return new \WP_Error(
				'stonewright_skill_import_failed',
				__( 'The skill could not be stored.', 'stonewright' )
			);
		}

		Logger::debug(
			'skill.imported',
			[
				'slug'         => $slug,
				'skill_id'     => $id,
				'actor_id'     => $actor_id,
				'content_hash' => (string) ( $inspection['content_hash'] ?? '' ),
				'warnings'     => $trust['findings'],
			]
		);

		return $id;
	}

	/**
	 * Front matter plus body, or null when the file has no usable front matter.
	 *
	 * @return array{name: string, description: string, body: string}|null
	 */
	private static function parse( string $content ): ?array {
		$content = preg_replace( '/^\xEF\xBB\xBF/', '', $content ) ?? $content;

		$matches = [];
		if ( ! preg_match( '/^---\r?\n(.*?)\r?\n---[ \t]*\r?\n?(.*)$/s', $content, $matches ) ) {
			return null;
		}

		$fields = [];
		foreach ( preg_split( '/\r?\n/', $matches[1] ) ?: [] as $line ) {
			if ( ! str_contains( $line, ':' ) || str_starts_with( ltrim( $line ), '#' ) ) {
				continue;
			}
			[ $key, $value ] = explode( ':', $line, 2 );

			$fields[ strtolower( trim( $key ) ) ] = trim( trim( $value ), "\"'" );
		}

		$name        = (string) ( $fields['name'] ?? '' );
		$description = (string) ( $fields['description'] ?? '' );

		if ( '' === $name || '' === $description ) {
			return null;
		}

		return [
			'name'        => $name,
			'description' => $description,
			'body'        => trim( (string) $matches[2] ),
		];
	}

	/**
	 * Lint an import the same way the editor lints a saved skill.
	 *
	 * @return array{errors: list<string>, warnings: list<string>, word_count: int}
	 */
	private static function lint( string $description, string $body ): array {
		return Skills::lint(
			[
				'description' => $description,
				'content'     => $body,
				'status'      => 'draft',
			]
		);
	}

	/**
	 * Everything an agent would eventually read, joined for one scan.
	 *
	 * The description ships in the routing index, so it is as much an
	 * instruction surface as the body and gets scanned with it.
	 */
	private static function scan_target( string $description, string $body ): string {
		return $description . "\n\n" . $body;
	}

	private static function basename_without_extension( string $filename ): string {
		$base = basename( str_replace( '\\', '/', trim( $filename ) ) );
		$dot  = strrpos( $base, '.' );

		return false === $dot ? $base : substr( $base, 0, $dot );
	}
}
