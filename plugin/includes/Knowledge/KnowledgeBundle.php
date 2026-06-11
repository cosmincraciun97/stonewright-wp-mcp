<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Knowledge;

use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Skills\Skills;

/**
 * Imports and exports the persistent guidance that agents see:
 * custom instructions, memory entries, and site skills.
 */
final class KnowledgeBundle {

	public const FORMAT  = 'stonewright-knowledge-bundle';
	public const VERSION = 1;

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
				'entries' => Skills::list( false ),
			],
		];
	}

	/**
	 * @param array<string, mixed> $bundle
	 * @return array<string, int>
	 */
	public static function import( array $bundle ): array {
		self::assert_supported_bundle( $bundle );

		$instructions_imported = 0;
		$memory_imported       = 0;
		$skills_imported       = 0;

		$instructions = $bundle['instructions'] ?? null;
		if ( is_array( $instructions ) ) {
			if ( array_key_exists( 'text', $instructions ) ) {
				update_option( 'stonewright_custom_instructions', mb_substr( (string) $instructions['text'], 0, 4000 ) );
				++$instructions_imported;
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
					$id = Memory::put_typed(
						(string) ( $entry['type'] ?? 'generic' ),
						(string) ( $entry['scope'] ?? 'default' ),
						$key,
						(string) ( $entry['name'] ?? $key ),
						$entry['value'] ?? null,
						(float) ( $entry['confidence'] ?? 1.0 )
					);
					if ( $id > 0 ) {
						++$memory_imported;
					}
				}
			}
		}

		$skills = $bundle['skills'] ?? null;
		if ( is_array( $skills ) ) {
			$entries = $skills['entries'] ?? [];
			if ( is_array( $entries ) ) {
				foreach ( $entries as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}
					$slug = sanitize_title( (string) ( $entry['slug'] ?? '' ) );
					if ( '' === $slug || '' === (string) ( $entry['content'] ?? '' ) ) {
						continue;
					}

					$source = (string) ( $entry['source'] ?? 'uploaded' );
					if ( 'builtin' === $source ) {
						$source = 'uploaded';
					}

					$id = Skills::save(
						[
							'slug'           => $slug,
							'title'          => (string) ( $entry['title'] ?? $slug ),
							'description'    => (string) ( $entry['description'] ?? '' ),
							'content'        => (string) $entry['content'],
							'enabled'        => (bool) ( $entry['enabled'] ?? true ),
							'enable_agentic' => (bool) ( $entry['enable_agentic'] ?? ( $entry['enabled'] ?? true ) ),
							'enable_prompt'  => (bool) ( $entry['enable_prompt'] ?? ( $entry['enabled'] ?? true ) ),
							'source'         => in_array( $source, [ 'user', 'uploaded' ], true ) ? $source : 'uploaded',
						]
					);
					if ( $id > 0 ) {
						++$skills_imported;
					}
				}
			}
		}

		return [
			'instructions_imported' => $instructions_imported,
			'memory_imported'       => $memory_imported,
			'skills_imported'       => $skills_imported,
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
