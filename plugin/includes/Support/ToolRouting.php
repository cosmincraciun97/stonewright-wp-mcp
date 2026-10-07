<?php
/**
 * Typed-tool hints for common php-execute patterns and task phrases.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

use Stonewright\WpMcp\Abilities\Settings\SettingsGet;

/**
 * Names the typed ability for a common php-execute pattern: post meta, options,
 * Elementor data, and menus.
 *
 * The same table serves two callers. php-execute asks about the snippet it just
 * ran; task-start asks about the task text, before any snippet exists. A match
 * is advice only. Nothing here blocks php-execute, and a hint holds fixed tool
 * names, never text taken from the snippet or the task.
 *
 * A pattern matches only when the typed tool can do the job: an option call needs
 * a literal option name that the settings abilities accept, and a post meta call
 * needs a literal public meta key, because the typed content abilities skip
 * protected keys. A tool the operator disabled is never named.
 */
final class ToolRouting {

	/** Most patterns in one hint. */
	public const MAX_PATTERNS = 4;

	/** Most tools named for one pattern. */
	public const MAX_TOOLS_PER_PATTERN = 3;

	/** Longest snippet or task text examined, in bytes. */
	private const MAX_SCAN_BYTES = 200000;

	private const NOTE = 'Typed tools add validation, backups, and audit; php-execute was not blocked. If a tool is not in your list, call stonewright-tool-profile.';

	/**
	 * MCP tool names per pattern, in the order a hint lists them.
	 *
	 * @var array<string, list<string>>
	 */
	private const TOOLS = [
		'post_meta'      => [ 'stonewright-content-update-post', 'stonewright-content-bulk-upsert-posts' ],
		'options'        => [ 'stonewright-settings-get', 'stonewright-settings-update' ],
		'elementor_data' => [ 'stonewright-elementor-v3-get-page-structure', 'stonewright-elementor-v3-batch-mutate' ],
		'menus'          => [
			'stonewright-menu-list',
			'stonewright-menu-create',
			'stonewright-menu-add-item',
			'stonewright-menu-assign-location',
			'stonewright-menu-delete',
		],
	];

	/**
	 * Task phrases per pattern, with the tools to name when one matches.
	 *
	 * @var array<string, array{0:string,1:list<string>}>
	 */
	private const TASK_RULES = [
		'post_meta'      => [
			'/\b(?:post ?meta|meta (?:keys?|values?|fields?)|custom fields?)\b/',
			[ 'stonewright-content-update-post', 'stonewright-content-bulk-upsert-posts' ],
		],
		'options'        => [
			'/\b(?:site (?:title|name|tagline)|tagline|blog ?(?:name|description)|time ?zone|date format|time format|posts per page|front page|homepage setting)\b/',
			[ 'stonewright-settings-get', 'stonewright-settings-update' ],
		],
		'elementor_data' => [
			'/\b(?:elementor (?:data|json|meta)|_elementor_data)\b/',
			[ 'stonewright-elementor-v3-get-page-structure', 'stonewright-elementor-v3-batch-mutate' ],
		],
		'menus'          => [
			'/\b(?:(?:nav|navigation|main|primary|footer|header|mobile) menus?|menu (?:items?|locations?))\b/',
			[ 'stonewright-menu-list', 'stonewright-menu-add-item', 'stonewright-menu-assign-location' ],
		],
	];

	/**
	 * Menu functions and the typed tool that replaces each.
	 *
	 * @var array<string, string>
	 */
	private const MENU_CALLS = [
		'/\bwp_get_nav_menus\s*\(/i'                                  => 'stonewright-menu-list',
		'/\bwp_create_nav_menu\s*\(/i'                                => 'stonewright-menu-create',
		'/\bwp_update_nav_menu_item\s*\(/i'                           => 'stonewright-menu-add-item',
		'/\bset_theme_mod\s*\(\s*[\'"]nav_menu_locations[\'"]/i'      => 'stonewright-menu-assign-location',
		'/\bwp_delete_nav_menu\s*\(/i'                                => 'stonewright-menu-delete',
	];

	/**
	 * Pattern ids, in the order a hint lists them.
	 *
	 * @return list<string>
	 */
	public static function patterns(): array {
		return array_keys( self::TOOLS );
	}

	/**
	 * Every tool a hint can name.
	 *
	 * @return list<string>
	 */
	public static function tools(): array {
		return array_values( array_unique( array_merge( ...array_values( self::TOOLS ) ) ) );
	}

	/**
	 * Typed tools for the patterns a PHP snippet uses.
	 *
	 * @return array<string, list<string>> Pattern id => MCP tool names.
	 */
	public static function for_snippet( string $code ): array {
		$code = self::bounded( $code );
		if ( '' === trim( $code ) ) {
			return [];
		}

		$selected = [];

		// The typed content abilities write public meta keys only.
		if (
			1 === preg_match( '/\b(?:update|add)_post_meta\s*\(\s*[^,]+,\s*([\'"])(?!_)[^\'"]+\1/i', $code )
			|| 1 === preg_match( '/\b(?:update|add)_metadata\s*\(\s*[\'"]post[\'"]\s*,\s*[^,]+,\s*([\'"])(?!_)[^\'"]+\1/i', $code )
		) {
			$selected['post_meta'] = self::TOOLS['post_meta'];
		}

		// The settings abilities accept a fixed list of site settings.
		$options = [];
		$calls   = [];
		preg_match_all( '/\b(get|update|add)_option\s*\(\s*([\'"])([A-Za-z0-9_\-]+)\2/i', $code, $calls, PREG_SET_ORDER );
		foreach ( $calls as $call ) {
			if ( in_array( $call[3], SettingsGet::ALLOWLIST, true ) ) {
				$options[] = 'get' === strtolower( $call[1] ) ? 'stonewright-settings-get' : 'stonewright-settings-update';
			}
		}
		if ( [] !== $options ) {
			$selected['options'] = $options;
		}

		if ( 1 === preg_match( '/_elementor_data/i', $code ) ) {
			$selected['elementor_data'] = self::TOOLS['elementor_data'];
		}

		$menus = [];
		foreach ( self::MENU_CALLS as $pattern => $tool ) {
			if ( 1 === preg_match( $pattern, $code ) ) {
				$menus[] = $tool;
			}
		}
		if ( [] !== $menus ) {
			$selected['menus'] = $menus;
		}

		return self::finish( $selected );
	}

	/**
	 * Typed tools for the patterns a task description mentions.
	 *
	 * @return array<string, list<string>> Pattern id => MCP tool names.
	 */
	public static function for_task( string $task ): array {
		$task = strtolower( self::bounded( $task ) );
		if ( '' === trim( $task ) ) {
			return [];
		}

		$selected = [];
		foreach ( self::TASK_RULES as $pattern => [ $phrases, $tools ] ) {
			if ( 1 === preg_match( $phrases, $task ) ) {
				$selected[ $pattern ] = $tools;
			}
		}

		return self::finish( $selected );
	}

	/**
	 * Response hint for a set of matches; empty when nothing matched.
	 *
	 * @param array<string, list<string>> $matches Result of for_snippet() or for_task().
	 * @param bool                        $note    Add the short advisory sentence.
	 * @return array{prefer?:array<string, list<string>>, note?:string}
	 */
	public static function hint( array $matches, bool $note ): array {
		if ( [] === $matches ) {
			return [];
		}

		$hint = [ 'prefer' => $matches ];
		if ( $note ) {
			$hint['note'] = self::NOTE;
		}

		return $hint;
	}

	/**
	 * Orders the selection canonically, drops disabled tools, and applies the caps.
	 *
	 * @param array<string, list<string>> $selected Pattern id => tools in any order.
	 * @return array<string, list<string>>
	 */
	private static function finish( array $selected ): array {
		$disabled = self::disabled_tools();
		$out      = [];
		foreach ( self::TOOLS as $pattern => $canonical ) {
			if ( ! isset( $selected[ $pattern ] ) ) {
				continue;
			}
			$tools = [];
			foreach ( $canonical as $tool ) {
				if ( in_array( $tool, $selected[ $pattern ], true ) && ! isset( $disabled[ $tool ] ) ) {
					$tools[] = $tool;
				}
			}
			$tools = array_slice( $tools, 0, self::MAX_TOOLS_PER_PATTERN );
			if ( [] !== $tools ) {
				$out[ $pattern ] = $tools;
			}
			if ( count( $out ) >= self::MAX_PATTERNS ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * MCP names of the abilities the operator disabled.
	 *
	 * @return array<string, true>
	 */
	private static function disabled_tools(): array {
		$disabled = [];
		foreach ( (array) get_option( 'stonewright_disabled_abilities', [] ) as $name ) {
			if ( is_string( $name ) && str_starts_with( $name, 'stonewright/' ) ) {
				$disabled[ 'stonewright-' . substr( $name, strlen( 'stonewright/' ) ) ] = true;
			}
		}

		return $disabled;
	}

	private static function bounded( string $text ): string {
		return strlen( $text ) > self::MAX_SCAN_BYTES ? substr( $text, 0, self::MAX_SCAN_BYTES ) : $text;
	}
}
