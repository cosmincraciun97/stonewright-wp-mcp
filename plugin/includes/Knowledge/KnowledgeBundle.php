<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Knowledge;

use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Memory\MemoryScreen;
use Stonewright\WpMcp\SkillLibrary\Site\SkillLibraryService;

/**
 * Imports and exports the persistent guidance that agents see:
 * custom instructions, memory entries, and site skills.
 */
final class KnowledgeBundle {

	public const FORMAT  = 'stonewright-knowledge-bundle';
	public const VERSION = 1;

	/** Most skipped skill identities an import reports. */
	public const SKIPPED_LIMIT = 50;

	/**
	 * @return array<string, mixed>
	 */
	public static function export(): array {
		return [
			'format'       => self::FORMAT,
			'version'      => self::VERSION,
			'exported_at'  => current_time( 'mysql', true ),
			'instructions' => [
				'enabled' => (bool) get_option( 'stonewright_custom_instructions_enabled', true ),
				'text'    => (string) get_option( 'stonewright_custom_instructions', '' ),
			],
			'memory'       => [
				'enabled' => (bool) get_option( 'stonewright_memory_enabled', true ),
				'entries' => Memory::list_all( 10000, 0 ),
			],
			'skills'       => [
				'entries' => SkillLibraryService::open()->records( false ),
			],
		];
	}

	/**
	 * Skills arrive as disabled drafts and never replace a skill: an identity that
	 * is stored in any state, the trash included, or reserved for a built-in skill
	 * is skipped, and so is an entry the library refuses.
	 *
	 * Memory entries go through the same instruction screen as a memory save. An entry whose text tells an agent to
	 * override the plugin rules is stored with the status needs_review, whatever status the bundle gives it.
	 *
	 * @param array<string, mixed> $bundle
	 * @return array{instructions_imported: int, instructions_rejected: int, memory_imported: int, memory_needs_review: int, skills_imported: int, skills_skipped: array<int, string>}
	 */
	public static function import( array $bundle ): array {
		self::assert_supported_bundle( $bundle );

		$instructions_imported = 0;
		$instructions_rejected = 0;
		$memory_imported       = 0;
		$memory_needs_review   = 0;
		$skills_imported       = 0;
		$skills_skipped        = [];

		$instructions = $bundle['instructions'] ?? null;
		if ( is_array( $instructions ) ) {
			if ( array_key_exists( 'text', $instructions ) ) {
				$text = mb_substr( (string) $instructions['text'], 0, 4000 );
				// Text that breaks the screen is left out of the import, as a memory save would not take it as active.
				if ( MemoryScreen::instructions_refusal( $text ) instanceof \WP_Error ) {
					++$instructions_rejected;
				} else {
					update_option( 'stonewright_custom_instructions', $text );
					++$instructions_imported;
				}
			}
			if ( array_key_exists( 'enabled', $instructions ) ) {
				update_option( 'stonewright_custom_instructions_enabled', (bool) $instructions['enabled'] );
			}
		}

		$memory = $bundle['memory'] ?? null;
		if ( is_array( $memory ) ) {
			if ( array_key_exists( 'enabled', $memory ) ) {
				update_option( 'stonewright_memory_enabled', (bool) $memory['enabled'] );
			}

			$entries = $memory['entries'] ?? [];
			if ( is_array( $entries ) ) {
				foreach ( $entries as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}
					$key = (string) ( $entry['memory_key'] ?? $entry['key'] ?? '' );
					if ( '' === $key ) {
						continue;
					}
					$name  = (string) ( $entry['name'] ?? $key );
					$topic = (string) ( $entry['topic'] ?? $entry['name'] ?? $key );
					// The bundle is text from elsewhere: the screen of a memory save reads the name, the topic and the value.
					$flagged = [] !== MemoryScreen::blocking_rules( $name . "\n" . $topic, $entry['value'] ?? null );
					$id = Memory::put_typed(
						(string) ( $entry['type'] ?? 'generic' ),
						(string) ( $entry['scope'] ?? 'default' ),
						$key,
						$name,
						$entry['value'] ?? null,
						(float) ( $entry['confidence'] ?? 1.0 ),
						[
							'topic'                 => $topic,
							'status'                => $flagged ? MemoryScreen::NEEDS_REVIEW : (string) ( $entry['status'] ?? 'active' ),
							'precedence'            => (int) ( $entry['precedence'] ?? 0 ),
							'version_fingerprint'   => (string) ( $entry['version_fingerprint'] ?? '' ),
							'expires_at'            => $entry['expires_at'] ?? null,
						]
					);
					if ( $id > 0 ) {
						++$memory_imported;
						if ( $flagged ) {
							++$memory_needs_review;
						}
					}
				}
			}
		}

		$skills = $bundle['skills'] ?? null;
		if ( is_array( $skills ) ) {
			$entries = $skills['entries'] ?? [];
			if ( is_array( $entries ) ) {
				$library = SkillLibraryService::open();
				foreach ( $entries as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}
					$slug = sanitize_title( (string) ( $entry['slug'] ?? '' ) );
					if ( '' === $slug || '' === (string) ( $entry['content'] ?? '' ) ) {
						continue;
					}

					// A bundle never sets provenance, exposure, or status; its skills wait as local drafts for review.
					$id = $library->import_bundle_skill(
						[
							'slug'        => $slug,
							'title'       => (string) ( $entry['title'] ?? $slug ),
							'description' => (string) ( $entry['description'] ?? '' ),
							'content'     => (string) $entry['content'],
						]
					);
					if ( is_int( $id ) && $id > 0 ) {
						++$skills_imported;
					} elseif ( count( $skills_skipped ) < self::SKIPPED_LIMIT ) {
						$skills_skipped[] = $slug;
					}
				}
			}
		}

		return [
			'instructions_imported' => $instructions_imported,
			'instructions_rejected' => $instructions_rejected,
			'memory_imported'       => $memory_imported,
			'memory_needs_review'   => $memory_needs_review,
			'skills_imported'       => $skills_imported,
			'skills_skipped'        => $skills_skipped,
		];
	}

	/**
	 * @param array<string, mixed> $bundle
	 * @throws \InvalidArgumentException When the bundle format or version is unsupported.
	 */
	private static function assert_supported_bundle( array $bundle ): void {
		if ( self::FORMAT !== (string) ( $bundle['format'] ?? '' ) ) {
			throw new \InvalidArgumentException( 'Invalid Stonewright knowledge bundle format.' );
		}

		if ( self::VERSION !== (int) ( $bundle['version'] ?? 0 ) ) {
			throw new \InvalidArgumentException( 'Unsupported Stonewright knowledge bundle version.' );
		}
	}
}
