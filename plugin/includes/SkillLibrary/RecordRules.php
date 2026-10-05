<?php
/**
 * Logical validation independent of site storage.
 *
 * @package Stonewright
 * @license AGPL-3.0-or-later
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary;

use Stonewright\WpMcp\Security\SensitiveContent;

/** Validates proposed records without losing omitted private metadata. */
final class RecordRules {

	/** @param array<string, mixed> $input @param array<string, mixed>|null $previous @return array<string, mixed>|\WP_Error */
	public static function normalize( array $input, ?array $previous = null ): array|\WP_Error {
		$record = array_replace( $previous ?? [], $input );
		foreach ( [ 'description', 'enabled', 'enable_agentic', 'enable_prompt', 'source', 'status', 'topic', 'version_constraints', 'verification_count', 'conflicts', 'semantic_fingerprint' ] as $known ) {
			if ( array_key_exists( $known, $record ) && null === $record[ $known ] ) {
				return self::invalid( 'Known skill metadata cannot be null; omit fields that are unchanged.' );
			}
		}
		foreach ( [ 'slug', 'title', 'content' ] as $required ) {
			if ( ! isset( $record[ $required ] ) || ! is_string( $record[ $required ] ) || '' === trim( $record[ $required ] ) ) {
				return self::invalid( 'The skill needs a slug, title, and Markdown content.' );
			}
		}
		$record['slug'] = sanitize_title( $record['slug'] );
		if ( '' === $record['slug'] ) {
			return self::invalid( 'The skill identifier is empty after normalization.' );
		}
		$record['description'] ??= '';
		if ( ! is_string( $record['description'] ) || strlen( $record['content'] ) > DocumentCodec::MAX_BYTES ) {
			return self::invalid( 'The description must be text and the body must fit within 1 MiB.' );
		}
		foreach ( [ 'title', 'description', 'content' ] as $text ) {
			if ( 1 !== preg_match( '//u', $record[ $text ] ) || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $record[ $text ] ) ) {
				return self::invalid( 'Skill text must be valid UTF-8 without binary bytes.' );
			}
		}
		$record['enabled'] ??= true;
		foreach ( [ 'enabled', 'enable_agentic', 'enable_prompt' ] as $flag ) {
			$record[ $flag ] ??= $record['enabled'];
			if ( ! is_bool( $record[ $flag ] ) && ! in_array( $record[ $flag ], [ 0, 1, '0', '1' ], true ) ) {
				return self::invalid( 'Exposure flags must be boolean values.' );
			}
			$record[ $flag ] = (bool) $record[ $flag ];
		}
		$record['status'] ??= $record['enabled'] ? 'active' : 'draft';
		$record['source'] ??= 'user';
		if ( ! is_string( $record['source'] ) || ! is_string( $record['status'] )
			|| ! in_array( $record['status'], [ 'active', 'draft', 'stale', 'retired', 'trashed' ], true ) ) {
			return self::invalid( 'The skill source and lifecycle state are invalid.' );
		}
		if ( isset( $record['topic'] ) && ! is_string( $record['topic'] ) ) {
			return self::invalid( 'The topic must be text.' );
		}
		if ( isset( $record['version_constraints'] ) ) {
			if ( ! is_array( $record['version_constraints'] ) ) {
				return self::invalid( 'Version constraints must be a component map.' );
			}
			foreach ( $record['version_constraints'] as $component => $expression ) {
				if ( ! is_string( $component ) || ! preg_match( '/^[a-z][a-z0-9_-]*$/', $component ) || ! is_string( $expression ) || '' === trim( $expression ) ) {
					return self::invalid( 'Version constraints require component names and nonempty version expressions.' );
				}
			}
		}
		if ( isset( $record['verification_count'] ) && ( ! is_int( $record['verification_count'] ) || $record['verification_count'] < 0 ) ) {
			return self::invalid( 'The verification count must be a nonnegative integer.' );
		}
		if ( isset( $record['conflicts'] ) ) {
			if ( ! is_array( $record['conflicts'] ) ) {
				return self::invalid( 'Conflicts must be a list of text findings.' );
			}
			foreach ( $record['conflicts'] as $conflict ) {
				if ( ! is_string( $conflict ) ) {
					return self::invalid( 'Conflicts must be a list of text findings.' );
				}
			}
		}
		if ( isset( $record['semantic_fingerprint'] ) && ( ! is_string( $record['semantic_fingerprint'] )
			|| ( '' !== $record['semantic_fingerprint'] && ! preg_match( '/^[a-f0-9]{64}$/', $record['semantic_fingerprint'] ) ) ) ) {
			return self::invalid( 'The semantic fingerprint must be empty or a lowercase SHA-256.' );
		}
		$encoded = wp_json_encode( $record );
		if ( ! is_string( $encoded ) ) {
			return self::invalid( 'Skill metadata must be serializable plain data.' );
		}
		if ( SensitiveContent::contains( $encoded ) ) {
			return new \WP_Error( 'stonewright_skill_sensitive_content', 'Remove credential material before storing a skill.', [ 'status' => 400 ] );
		}
		return $record;
	}

	/** @param array<string, mixed> $record @param array<int, string> $tool_names @param callable(string): bool|null $trigger_policy @return array{errors: array<int, string>, warnings: array<int, string>, trust: array<int, string>} */
	public static function review( array $record, array $tool_names = [], ?callable $trigger_policy = null ): array {
		$errors = [];
		$warnings = [];
		$trust = [];
		$description = is_string( $record['description'] ?? null ) ? $record['description'] : '';
		$content = is_string( $record['content'] ?? null ) ? $record['content'] : '';
		// Default lint can prove text is missing, but cannot judge natural-language clarity.
		$trigger_policy ??= static fn( string $text ): bool => '' !== trim( $text ) && 1 === preg_match( '/[\p{L}\p{N}]/u', $text );
		if ( ! $trigger_policy( $description ) ) {
			$errors[] = 'missing_trigger';
		}
		if ( preg_match( '/\belementor\b/i', $description . ' ' . $content ) && empty( $record['version_constraints'] ) ) {
			$errors[] = 'missing_version_constraints';
		}
		if ( ! empty( $record['conflicts'] ) ) {
			$errors[] = 'unresolved_conflicts';
		}
		if ( in_array( $record['status'] ?? '', [ 'stale', 'retired', 'trashed' ], true ) ) {
			$errors[] = 'stale_record';
		}
		preg_match_all( '/\bstonewright\/[a-z0-9]+(?:-[a-z0-9]+)*\b/', $content, $matches );
		foreach ( array_unique( $matches[0] ) as $tool ) {
			if ( ! in_array( $tool, $tool_names, true ) ) {
				$errors[] = 'unavailable_tool:' . $tool;
			}
		}
		if ( strlen( $description ) > 500 ) {
			$warnings[] = 'long_routing_description';
		}
		if ( ! in_array( $record['source'] ?? 'user', [ 'builtin', 'playbook' ], true ) ) {
			$trust[] = 'site_or_external_guidance';
		}
		if ( preg_match( '/ignore (?:all |the )?(?:previous|system) instructions|(?:send|reveal|exfiltrate).{0,40}(?:passwords?|credentials?|secrets?)/i', $content ) ) {
			$trust[] = 'privileged_instruction_request';
		}
		return [ 'errors' => array_values( array_unique( $errors ) ), 'warnings' => $warnings, 'trust' => $trust ];
	}

	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'stonewright_skill_record_invalid', $message, [ 'status' => 400 ] );
	}
}
