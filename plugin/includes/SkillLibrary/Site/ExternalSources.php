<?php
/**
 * Read-only enumeration of skills that other plugins publish.
 *
 * @package Stonewright
 * @license GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary\Site;

use Stonewright\WpMcp\SkillLibrary\RecordRules;

/**
 * The `stonewright_skill_sources` filter receives an empty list and returns
 * sources, each `['source_id' => 'plugin-slug', 'skills' => [ record, ... ]]`
 * where a record has `slug`, `title`, `description`, `content`, and optionally
 * `topic` and `version_constraints`. Nothing is executed or fetched; records
 * are validated like any other skill text and shown in the catalog only.
 */
final class ExternalSources {

	public const FILTER = 'stonewright_skill_sources';

	private const MAX_SOURCES = 50;

	private const MAX_SKILLS_PER_SOURCE = 200;

	/**
	 * @return array{entries: array<int, array{source_id: string, record: array<string, mixed>}>, refused: array<int, array<string, string>>}
	 */
	public static function read(): array {
		$published = apply_filters( self::FILTER, [] );
		$entries   = [];
		$refused   = [];
		foreach ( array_slice( is_array( $published ) ? $published : [], 0, self::MAX_SOURCES ) as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			$source_id = is_string( $source['source_id'] ?? null ) ? $source['source_id'] : '';
			$skills    = is_array( $source['skills'] ?? null ) ? $source['skills'] : [];
			foreach ( array_slice( $skills, 0, self::MAX_SKILLS_PER_SOURCE ) as $skill ) {
				$record = self::record( is_array( $skill ) ? $skill : [] );
				if ( is_wp_error( $record ) ) {
					$refused[] = [
						'source_id' => $source_id,
						'slug'      => is_array( $skill ) && is_string( $skill['slug'] ?? null ) ? $skill['slug'] : '',
						'reason'    => 'invalid_source_record',
					];
					continue;
				}
				$entries[] = [
					'source_id' => $source_id,
					'record'    => $record,
				];
			}
		}
		return [
			'entries' => $entries,
			'refused' => $refused,
		];
	}

	/** @param array<string, mixed> $skill @return array<string, mixed>|\WP_Error */
	private static function record( array $skill ): array|\WP_Error {
		$fields = array_intersect_key( $skill, array_flip( [ 'slug', 'title', 'description', 'content', 'topic', 'version_constraints' ] ) );
		return RecordRules::normalize(
			$fields + [
				'source'         => 'external',
				'status'         => 'active',
				'enabled'        => false,
				'enable_agentic' => false,
				'enable_prompt'  => false,
			]
		);
	}
}
