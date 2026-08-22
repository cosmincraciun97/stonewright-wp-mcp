<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Motion;

use Stonewright\WpMcp\Support\BlockTree;

/**
 * Resolves a compiled Gutenberg/FSE motion plan against a parsed block tree
 * and produces consolidated blocks-batch-mutate update operations.
 *
 * Target identity rules (DesignSpec motion contract §5.1):
 * - targets are spec IDs resolved through explicit block paths supplied by
 *   the caller after reading the page structure — never CSS selectors;
 * - zero or ambiguous resolution fails the dry run;
 * - unknown existing classes are preserved; only allowlisted preset and
 *   marker classes are appended.
 */
final class GutenbergMotionApplier {

	/**
	 * @param array<int, array<string, mixed>> $parsed_blocks     Parsed post content.
	 * @param list<array{target_id:string, path:array<int,int>}> $requested_targets
	 * @param array<string, mixed>             $plan              Compiled plan (renderer gutenberg-fse).
	 * @return array{operations: list<array<string, mixed>>, runtime_needed: bool, resolved: list<string>}|\WP_Error
	 */
	public static function build_operations( array $parsed_blocks, array $requested_targets, array $plan ) {
		if ( (string) ( $plan['renderer'] ?? '' ) !== 'gutenberg-fse' ) {
			return new \WP_Error(
				'stonewright_motion_renderer_mismatch',
				'This applier accepts gutenberg-fse plans only.',
				[ 'status' => 400 ]
			);
		}

		$plan_ops = array_values(
			array_filter(
				(array) ( $plan['operations'] ?? [] ),
				static fn( $op ): bool => is_array( $op ) && 'add-classes' === ( $op['op'] ?? '' )
			)
		);
		if ( [] === $plan_ops ) {
			return new \WP_Error(
				'stonewright_motion_plan_has_no_gutenberg_operations',
				'The plan contains no add-classes operations for Gutenberg.',
				[ 'status' => 400 ]
			);
		}

		// Validate requested targets: unique target ids, resolvable paths.
		$by_target = [];
		foreach ( $requested_targets as $entry ) {
			$target_id = (string) ( $entry['target_id'] ?? '' );
			$path      = array_values( array_map( 'intval', (array) ( $entry['path'] ?? [] ) ) );
			if ( '' === $target_id || isset( $by_target[ $target_id ] ) ) {
				return new \WP_Error(
					'stonewright_motion_target_invalid',
					sprintf( 'Target "%s" is empty or duplicated in the requested target list.', $target_id ),
					[ 'status' => 400 ]
				);
			}
			$block = BlockTree::get( $parsed_blocks, $path );
			if ( null === $block ) {
				return new \WP_Error(
					'stonewright_motion_target_unresolved',
					sprintf( 'Target "%s" path does not resolve to a block.', $target_id ),
					[ 'status' => 422, 'target_id' => $target_id ]
				);
			}
			$by_target[ $target_id ] = [ 'path' => $path, 'block' => $block ];
		}

		$operations    = [];
		$resolved      = [];
		$runtime_needed = false;

		foreach ( $plan_ops as $op ) {
			$target_id = (string) ( $op['target_id'] ?? '' );
			if ( ! isset( $by_target[ $target_id ] ) ) {
				return new \WP_Error(
					'stonewright_motion_target_missing_from_page',
					sprintf( 'Plan target "%s" was not supplied with a block path.', $target_id ),
					[ 'status' => 422, 'target_id' => $target_id ]
				);
			}

			$entry     = $by_target[ $target_id ];
			$existing  = self::existing_classes( $entry['block'] );
			$classes   = array_values( array_filter( array_map( 'strval', (array) ( $op['classes'] ?? [] ) ) ) );

			// Only registry-preset classes and stw-motion-target markers may be
			// appended; anything else in the plan op is refused.
			$allowed = array_merge(
				array_column( MotionPresetRegistry::presets(), 'class' ),
				[ 'stw-motion-runtime' ],
				array_column( (array) ( $plan['bindings']['target_map'] ?? [] ), 'marker' )
			);
			foreach ( $classes as $class ) {
				if ( ! in_array( $class, $allowed, true ) && ! self::is_configuration_class( $class ) ) {
					return new \WP_Error(
						'stonewright_motion_class_not_allowlisted',
						sprintf( 'Class "%s" is not an allowlisted motion product class.', $class ),
						[ 'status' => 422, 'target_id' => $target_id ]
					);
				}
			}

			$addition  = array_values( array_diff( $classes, $existing ) );
			if ( [] === $addition ) {
				$resolved[] = $target_id . ':noop';
				continue;
			}

			$operations[] = [
				'action' => 'update',
				'path'   => $entry['path'],
				'attrs'  => [
					'className' => trim( implode( ' ', array_merge( $existing, $addition ) ) ),
				],
			];
			$resolved[]   = $target_id;
			if ( ! empty( $op['runtime'] ) ) {
				$runtime_needed = true;
			}
		}

		return [
			'operations'     => $operations,
			'runtime_needed' => $runtime_needed,
			'resolved'       => $resolved,
		];
	}

	/**
	 * @param array<string, mixed> $block
	 * @return list<string>
	 */
	private static function existing_classes( array $block ): array {
		$raw = (string) ( $block['attrs']['className'] ?? '' );
		if ( '' === trim( $raw ) ) {
			return [];
		}
		return array_values( array_filter( array_map( 'trim', explode( ' ', $raw ) ) ) );
	}

	private static function is_configuration_class( string $class ): bool {
		if ( in_array( $class, [ 'stw-motion-trigger--load', 'stw-motion-trigger--viewport-enter', 'stw-motion-trigger--hover', 'stw-motion-trigger--focus-visible' ], true ) ) {
			return true;
		}
		if ( 1 !== preg_match( '/^stw-motion-(duration|delay|stagger-interval)--([0-9]{1,4})$/', $class, $matches ) ) {
			return false;
		}
		$value = (int) $matches[2];
		return match ( $matches[1] ) {
			'duration'         => $value <= 3000,
			'delay'            => $value <= 2000,
			'stagger-interval' => $value <= 250,
		};
	}
}
