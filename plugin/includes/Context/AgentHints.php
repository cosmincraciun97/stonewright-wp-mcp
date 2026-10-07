<?php
/**
 * Agent hints shared by the MCP server instructions and task-start.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Context;

use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Direction\DirectionSummary;

/**
 * Compact agent-facing hints shared by the MCP server instructions and
 * stonewright-task-start.
 *
 * Each hint is built once here, so the connect-time text and the task-start
 * payload read the same source:
 *
 * - the active Design Direction pointer, from the same record task-start reports
 *   as `context.design_direction_ref`;
 * - agent preferences, a small object of scalar settings that providers add
 *   through the `stonewright_agent_preferences` filter. A provider's entry
 *   appears next to the Design Direction pointer in task-start and in the
 *   connect-time instructions with no further wiring. No provider ships with the
 *   plugin, so the object is absent until one registers.
 */
final class AgentHints {

	/**
	 * Filter that supplies agent preferences as `key => scalar`.
	 *
	 * @param array<string, bool|int|string> $preferences Preferences collected so far.
	 */
	public const PREFERENCES_FILTER = 'stonewright_agent_preferences';

	/** Most preferences returned, so the object stays compact. */
	public const MAX_PREFERENCES = 8;

	/** Longest text value kept, in characters. */
	public const MAX_PREFERENCE_VALUE_CHARS = 48;

	private const MAX_DIRECTION_NAME_CHARS = 40;
	private const DIRECTION_BRIEF_TOOL     = 'stonewright-design-direction-brief';
	private const HASH_PREFIX_CHARS        = 12;

	/**
	 * Compact pointer to the active Design Direction, or null when none is active.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function design_direction_ref(): ?array {
		$record = ( new DesignDirectionService() )->active();
		if ( ! is_array( $record ) ) {
			return null;
		}

		$id          = (int) ( $record['id'] ?? 0 );
		$ref         = DirectionSummary::row( $record, $id );
		$ref['tool'] = self::DIRECTION_BRIEF_TOOL;

		return $ref;
	}

	/**
	 * Agent preferences contributed by providers; an empty array when none.
	 *
	 * Keys are lower snake case, values are booleans, integers, or short single
	 * line text. Anything else is dropped, and the object never exceeds
	 * MAX_PREFERENCES entries.
	 *
	 * @return array<string, bool|int|string>
	 */
	public static function agent_preferences(): array {
		try {
			$raw = apply_filters( self::PREFERENCES_FILTER, [] );
		} catch ( \Throwable $error ) {
			return [];
		}
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$preferences = [];
		foreach ( $raw as $key => $value ) {
			if ( count( $preferences ) >= self::MAX_PREFERENCES ) {
				break;
			}
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,31}$/D', $key ) ) {
				continue;
			}
			if ( is_bool( $value ) || is_int( $value ) ) {
				$preferences[ $key ] = $value;
				continue;
			}
			if ( is_string( $value ) ) {
				$text = self::plain( $value, self::MAX_PREFERENCE_VALUE_CHARS );
				if ( '' !== $text ) {
					$preferences[ $key ] = $text;
				}
			}
		}

		return $preferences;
	}

	/**
	 * Lines for the MCP server instructions that every client reads on connect.
	 *
	 * Never throws: server registration must not depend on this text.
	 *
	 * @return list<string>
	 */
	public static function connect_lines(): array {
		try {
			$lines = [];

			$ref = self::design_direction_ref();
			if ( is_array( $ref ) ) {
				$lines[] = sprintf(
					'- Active Design Direction: %1$s (slug %2$s, id %3$d, contract %4$s). Before any visual work, read it with %5$s.',
					self::plain( (string) ( $ref['name'] ?? '' ), self::MAX_DIRECTION_NAME_CHARS ),
					self::plain( (string) ( $ref['slug'] ?? '' ), self::MAX_DIRECTION_NAME_CHARS ),
					(int) ( $ref['id'] ?? 0 ),
					substr( self::plain( (string) ( $ref['contract_hash'] ?? '' ), 64 ), 0, self::HASH_PREFIX_CHARS ),
					self::DIRECTION_BRIEF_TOOL
				);
			}

			$pairs = [];
			foreach ( self::agent_preferences() as $key => $value ) {
				$pairs[] = $key . '=' . ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value );
			}
			if ( [] !== $pairs ) {
				$lines[] = '- Agent preferences: ' . implode( ', ', $pairs ) . '.';
			}

			return $lines;
		} catch ( \Throwable $error ) {
			return [];
		}
	}

	/**
	 * Single-line text without control characters, cut to a character limit.
	 */
	private static function plain( string $text, int $max_chars ): string {
		$text = trim( (string) preg_replace( '/[\x00-\x1F\x7F\s]+/u', ' ', $text ) );

		return mb_substr( $text, 0, $max_chars );
	}
}
