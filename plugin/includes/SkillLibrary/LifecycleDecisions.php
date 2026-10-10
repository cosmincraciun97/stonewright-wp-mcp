<?php
/**
 * Original logical lifecycle decisions without persistence side effects.
 *
 * @package Stonewright
 * @license GPL-2.0-or-later
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary;

/** The storage adapter maps these logical states only after compatibility evidence. */
final class LifecycleDecisions {
	/** @param array<string, mixed> $record */
	public static function protected_origin( array $record ): bool {
		return in_array( $record['source'] ?? '', [ 'builtin', 'playbook', 'external' ], true ) || 'external' === ( $record['source_kind'] ?? '' );
	}

	/** @param array<string, mixed> $record @return array<string, mixed>|\WP_Error */
	public static function trash( array $record ): array|\WP_Error {
		if ( self::protected_origin( $record ) ) {
			return new \WP_Error( 'stonewright_skill_builtin', 'Product and external skills cannot be removed.' );
		}
		return array_replace( $record, [ 'status' => 'trashed', 'enabled' => false, 'enable_agentic' => false, 'enable_prompt' => false ] );
	}

	/** @param array<string, mixed> $record @return array<string, mixed>|\WP_Error */
	public static function restore( array $record ): array|\WP_Error {
		if ( self::protected_origin( $record ) || 'trashed' !== ( $record['status'] ?? '' ) ) {
			return new \WP_Error( 'stonewright_skill_restore_invalid', 'Only a trashed local skill can be restored.' );
		}
		return array_replace( $record, [ 'status' => 'draft', 'enabled' => false, 'enable_agentic' => false, 'enable_prompt' => false ] );
	}

	/** @param array<string, mixed> $current @param array<string, mixed> $snapshot @return array<string, mixed>|\WP_Error */
	public static function rollback( array $current, array $snapshot ): array|\WP_Error {
		if ( self::protected_origin( $current ) || 'trashed' === ( $current['status'] ?? '' )
			|| ( $snapshot['slug'] ?? null ) !== ( $current['slug'] ?? null ) || ( $snapshot['id'] ?? null ) !== ( $current['id'] ?? null ) ) {
			return new \WP_Error( 'stonewright_skill_rollback_failed', 'The revision cannot replace this logical skill identity.' );
		}
		return $snapshot;
	}
}
