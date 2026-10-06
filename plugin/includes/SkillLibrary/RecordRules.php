<?php
/**
 * Logical validation independent of site storage.
 *
 * @package Stonewright
 * @license GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary;

use Stonewright\WpMcp\Security\SensitiveContent;

/** Validates proposed records without losing omitted private metadata. */
final class RecordRules {

	/** Findings that judge authored text rather than the record's lifecycle. */
	private const AUTHORED_CONTENT_FINDINGS = [ 'missing_trigger', 'missing_version_constraints' ];

	/** The single identity normalization shared by saves, imports, and pack refreshes. */
	public static function identity( string $slug ): string {
		return sanitize_title( $slug );
	}

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
		$record['slug'] = self::identity( $record['slug'] );
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
			if ( ! VisibilityRules::well_formed( $record['version_constraints'] ) ) {
				return self::invalid( 'Version constraints map components to "required" or a version expression, or list any_of alternatives.' );
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
			|| ( '' !== $record['semantic_fingerprint'] && ! preg_match( '/^[a-f0-9]{64}\z/', $record['semantic_fingerprint'] ) ) ) ) {
			return self::invalid( 'The semantic fingerprint must be empty or a lowercase SHA-256.' );
		}
		if ( ! self::plain( $record ) || ! is_string( wp_json_encode( $record ) ) ) {
			return self::invalid( 'Skill metadata must be serializable plain data.' );
		}
		if ( self::carries_credentials( $record ) ) {
			return new \WP_Error( 'stonewright_skill_sensitive_content', 'Remove credential material before storing a skill.', [ 'status' => 400 ] );
		}
		return $record;
	}

	/**
	 * Shipped product guidance is trusted text: its authored-content findings stay advisory,
	 * while lifecycle and conflict findings still block activation.
	 *
	 * @param array<string, mixed> $record @param array<int, string> $errors @return array<int, string>
	 */
	public static function blocking( array $record, array $errors ): array {
		if ( ! in_array( $record['source'] ?? '', [ 'builtin', 'playbook' ], true ) || 'external' === ( $record['source_kind'] ?? '' ) ) {
			return $errors;
		}
		return array_values( array_filter( $errors, static fn( string $error ): bool => ! in_array( $error, self::AUTHORED_CONTENT_FINDINGS, true ) && ! str_starts_with( $error, 'unavailable_tool:' ) ) );
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

	/** Arrays of scalars and null only; objects could hide text from the credential scan. */
	private static function plain( mixed $value ): bool {
		if ( ! is_array( $value ) ) {
			return null === $value || is_scalar( $value );
		}
		foreach ( $value as $item ) {
			if ( ! self::plain( $item ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Every text key and leaf is scanned as raw text, because encoded forms escape characters the
	 * detector relies on. Each leaf is also read after its nearest key, as a labelled value would be written.
	 */
	private static function carries_credentials( mixed $value, string $label = '' ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( ( is_string( $key ) && SensitiveContent::contains( $key ) ) || self::carries_credentials( $item, is_string( $key ) ? $key : $label ) ) {
					return true;
				}
			}
			return false;
		}
		if ( null === $value || ! is_scalar( $value ) ) {
			return false;
		}
		$text = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
		return ( is_string( $value ) && SensitiveContent::contains( $text ) ) || ( '' !== $label && SensitiveContent::contains( $label . ': ' . $text ) );
	}

	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'stonewright_skill_record_invalid', $message, [ 'status' => 400 ] );
	}
}
