<?php
/**
 * Runtime exposure policy for logical skill records.
 *
 * @package Stonewright
 * @license AGPL-3.0-or-later
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary;

use Stonewright\WpMcp\Elementor\Schema\RuntimeFingerprint;

/** Version interpretation belongs to the existing runtime compatibility contract. */
final class VisibilityRules {

	/** @param array<string, mixed> $record @param callable(array<string, mixed>): bool|null $compatible @return array<int, string> */
	public static function missing( array $record, ?callable $compatible = null ): array {
		$compatible ??= [ RuntimeFingerprint::class, 'matches_constraints' ];
		$constraints = $record['version_constraints'] ?? [];
		if ( ! is_array( $constraints ) ) {
			return [ 'invalid_constraints' ];
		}
		$missing = [];
		foreach ( $constraints as $component => $expression ) {
			if ( ! $compatible( [ (string) $component => $expression ] ) ) {
				$missing[] = (string) $component;
			}
		}
		return $missing;
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
