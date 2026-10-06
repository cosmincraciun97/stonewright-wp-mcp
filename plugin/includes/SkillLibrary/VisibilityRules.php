<?php
/**
 * Runtime exposure policy for logical skill records.
 *
 * @package Stonewright
 * @license GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary;

use Stonewright\WpMcp\Elementor\Schema\RuntimeFingerprint;

/** Version interpretation belongs to the existing runtime compatibility contract. */
final class VisibilityRules {

	/** Lists plugin slugs separated by "|"; any one present plugin satisfies the constraint. */
	public const ANY_OF = 'any_of';

	/** Plugin slug form: lowercase letters, digits, and hyphens, starting with a letter or digit; underscores stay accepted. */
	private const COMPONENT = '[a-z0-9][a-z0-9_-]*';

	/**
	 * Accepted shapes: an empty map for no constraint, a component mapped to "required" or a
	 * version expression, and any_of mapped to alternatives such as "slug-a|slug-b".
	 *
	 * @param array<mixed> $constraints
	 */
	public static function well_formed( array $constraints ): bool {
		foreach ( $constraints as $component => $expression ) {
			if ( ! is_string( $component ) || ! preg_match( '/^' . self::COMPONENT . '\z/', $component ) || ! is_string( $expression ) || '' === trim( $expression ) ) {
				return false;
			}
			if ( self::ANY_OF === $component && ! preg_match( '/^' . self::COMPONENT . '(?:\|' . self::COMPONENT . ')*\z/', $expression ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Unavailable requirements: component names, plus each unmet any_of list as written.
	 *
	 * @param array<string, mixed> $record @param callable(array<string, mixed>): bool|null $compatible @return array<int, string>
	 */
	public static function missing( array $record, ?callable $compatible = null ): array {
		$compatible ??= [ RuntimeFingerprint::class, 'matches_constraints' ];
		$constraints = $record['version_constraints'] ?? [];
		if ( ! is_array( $constraints ) ) {
			return [ 'invalid_constraints' ];
		}
		$missing = [];
		foreach ( $constraints as $component => $expression ) {
			if ( self::ANY_OF === $component ) {
				if ( ! self::any_present( $expression, $compatible ) ) {
					$missing[] = is_string( $expression ) ? $expression : self::ANY_OF;
				}
				continue;
			}
			if ( ! $compatible( [ (string) $component => $expression ] ) ) {
				$missing[] = (string) $component;
			}
		}
		return $missing;
	}

	/** Each alternative is asked as a required plugin, stopping at the first present one. */
	private static function any_present( mixed $alternatives, callable $compatible ): bool {
		if ( ! is_string( $alternatives ) ) {
			return false;
		}
		foreach ( explode( '|', $alternatives ) as $slug ) {
			$slug = trim( $slug );
			if ( '' !== $slug && $compatible( [ $slug => 'required' ] ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string, mixed> $record @param callable(array<string, mixed>): bool|null $compatible */
	public static function eligible( array $record, string $mode, ?callable $compatible = null ): bool {
		if ( empty( $record['enabled'] )
			|| 'active' !== ( $record['status'] ?? 'active' ) || [] !== self::missing( $record, $compatible ) ) {
			return false;
		}
		if ( 'agentic' === $mode ) {
			return ! empty( $record['enable_agentic'] );
		}
		if ( 'prompt' === $mode ) {
			return ! empty( $record['enable_prompt'] );
		}
		return in_array( $mode, [ 'all', 'discover' ], true );
	}
}
