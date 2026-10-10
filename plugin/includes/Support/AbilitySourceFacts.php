<?php
/**
 * Facts about an ability that its source code shows.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

use ReflectionClass;
use Stonewright\WpMcp\Abilities\Ability;
use Stonewright\WpMcp\Abilities\AbilityKernel;

/**
 * Reads the source of an ability class, and of the classes it extends up to the ability kernel,
 * and reports what the ability does: whether it can change state (`write`), whether it has a
 * production-safe confirmation gate (`token`) and whether it reaches hosts outside the site
 * (`external`). Strings and comments are never searched, so text that only names a function does
 * not count.
 *
 * The ability truth matrix (plugin/bin/generate-ability-matrix.php) and the public API contract
 * snapshot use these facts for their read/write column and `kind`, and the matrix generator
 * records them in plugin/data/ability-traits.php, from which AbilityAnnotations derives the MCP
 * tool annotations of every ability.
 */
final class AbilitySourceFacts {

	/**
	 * Calls and names that mark an ability as one that changes state.
	 *
	 * @var list<string>
	 */
	private const WRITE_PATTERNS = [
		'wp_update_post',
		'wp_insert_post',
		'update_post_meta',
		'add_post_meta',
		'update_option',
		'add_option',
		'delete_option',
		'update_metadata',
		'delete_metadata',
		'file_put_contents',
		'rename(',
		'unlink(',
		'copy(',
		'SandboxGuards',
		'snapshot_post',
		'wp_insert_attachment',
		'media_handle_sideload',
		'wpdb->insert(',
		'wpdb->update(',
		'wpdb->delete(',
		'$wpdb->insert',
		'$wpdb->update',
		'$wpdb->delete',
		'Memory::put(',
		'Memory::put_typed(',
		'Memory::delete(',
		'Memory::delete_by_id(',
		'Memory::update_by_id(',
		'->save_skill(',
		'->erase_skill(',
		'->move_to_trash(',
		'->record_evidence(',
		'->withdraw_skill(',
		'SpecToGutenberg()',
		'SpecToElementorV3()',
		'CandidateRepository::create(',
		'CandidateRepository::verify(',
		'CandidateRepository::promote(',
		'CandidateRepository::set_status(',
		'->roll_back_skill(',
		'ExpertiseStore::record_scorecard(',
		'ExpertiseEvaluator::evaluate(',
		'ExpertisePromotion::promote(',
		'ExpertisePromotion::set_terminal_status(',
		'ElementorWriter::write',
		'PostCacheInvalidator::invalidate',
		'CssRegenerator::regenerate',
		'service->save(',
		'service->activate(',
		'service->restore(',
		'QualityReportStore::save(',
		'RuntimeDataPurger::purge(',
		'ChangeRollback::run(',
		'new UploadMedia()',
		'new BuildPageFromSpec()',
		'ConfirmationGuard',
		'eval(',
	];

	/**
	 * Names and calls that mark a production-safe confirmation gate.
	 *
	 * @var list<string>
	 */
	private const TOKEN_MARKERS = [
		'use ConfirmationGuard',
		'ConfirmationToken::verify_or_error',
		'require_confirmation',
		'require_sandbox_confirmation',
		'confirmation_token_error(',
		'production_safe_token_error(',
		'audit_write(',
		'new BuildPageFromSpec()',
		// The rollback engine verifies the token itself, so the ability that calls it must not verify it again.
		'ChangeRollback::run(',
	];

	/**
	 * Calls and helper classes that reach hosts outside the site: HTTP requests, downloads, oEmbed
	 * lookups, sideloads, and the classes that do those for an ability.
	 *
	 * @var list<string>
	 */
	private const EXTERNAL_PATTERNS = [
		'/\bwp_(?:safe_)?remote_(?:get|post|request|head)\s*\(/',
		'/\bdownload_url\s*\(/',
		'/\bwp_oembed_get\s*\(/',
		'/\bmedia_sideload_image\s*\(/',
		'/\b(?:AssetSideloader|AssetReferences|StockImageClient)\b/',
		'/\bnew\s+UploadMedia\b/',
	];

	/**
	 * What the source of an ability class shows.
	 *
	 * @param class-string $class Ability class.
	 * @return array{write: bool, external: bool}
	 */
	public static function detect( string $class ): array {
		$source = self::source_with_parents( $class );

		return [
			'write'    => self::is_write( $source ),
			'external' => self::calls_external( $source ),
		];
	}

	/**
	 * The source of a class joined with the source of every parent class that is not the ability
	 * kernel or the ability interface, which name every wrapper and would flag every ability.
	 *
	 * @param class-string $class Ability class.
	 * @throws \ReflectionException When the class does not exist.
	 */
	public static function source_with_parents( string $class ): string {
		$stop_at = [ AbilityKernel::class, Ability::class ];
		$sources = [];
		$ref     = new ReflectionClass( $class );
		while ( false !== $ref && ! in_array( $ref->getName(), $stop_at, true ) ) {
			$file   = $ref->getFileName();
			$source = false !== $file && is_readable( $file ) ? file_get_contents( $file ) : false;
			if ( is_string( $source ) && '' !== $source ) {
				$sources[] = $source;
			}
			$ref = $ref->getParentClass();
		}

		return implode( "\n", $sources );
	}

	/**
	 * Whether the code can change state: it calls one of the writing functions, it records its
	 * call through the kernel wrapper that declares a write, or it carries a production-safe
	 * confirmation gate. The kernel wrapper that declares no nature says nothing either way.
	 */
	public static function is_write( string $source ): bool {
		$code = self::code_only( $source );

		foreach ( self::WRITE_PATTERNS as $pattern ) {
			if ( str_contains( $code, $pattern ) ) {
				return true;
			}
		}

		return str_contains( $code, '->audit_write(' ) || self::has_token_gate( $code );
	}

	/**
	 * Whether the source carries a production-safe confirmation gate.
	 */
	public static function has_token_gate( string $source ): bool {
		foreach ( self::TOKEN_MARKERS as $marker ) {
			if ( str_contains( $source, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the code can reach hosts outside the site.
	 */
	public static function calls_external( string $source ): bool {
		$code = self::code_only( $source );

		foreach ( self::EXTERNAL_PATTERNS as $pattern ) {
			if ( 1 === preg_match( $pattern, $code ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The source with strings, heredocs and comments removed. Ability classes mention write tools
	 * in guidance text, and that must not mark the ability itself as one that writes.
	 */
	public static function code_only( string $source ): string {
		$tokens = @token_get_all( $source );
		if ( ! is_array( $tokens ) ) {
			return $source;
		}

		$skip_types = [
			T_CONSTANT_ENCAPSED_STRING,
			T_ENCAPSED_AND_WHITESPACE,
			T_COMMENT,
			T_DOC_COMMENT,
		];
		foreach ( [ 'T_START_HEREDOC', 'T_END_HEREDOC', 'T_NOWDOC' ] as $const ) {
			if ( defined( $const ) ) {
				$skip_types[] = constant( $const );
			}
		}

		$clean = '';
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], $skip_types, true ) ) {
					continue;
				}
				$clean .= $token[1];
				continue;
			}
			$clean .= $token;
		}

		return $clean;
	}
}
