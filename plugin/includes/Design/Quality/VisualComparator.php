<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Quality;

use Stonewright\WpMcp\Support\Json;

/** Deterministic, bounded, evidence-only visual comparison receipt. */
final class VisualComparator {

	private const MAX_VIEWPORTS = 8;
	private const MAX_ELEMENTS_PER_VIEWPORT = 250;
	private const MAX_FINDINGS = 500;

	/** @param array<string,mixed> $expected @param array<string,mixed> $observed @return array<string,mixed>|\WP_Error */
	public static function compare( array $expected, array $observed ): array|\WP_Error {
		$tolerances        = self::tolerances( $expected['tolerances'] ?? [] );
		$expected_viewports = self::viewports( $expected['viewports'] ?? $expected, 'expected' );
		if ( $expected_viewports instanceof \WP_Error ) {
			return $expected_viewports;
		}
		if ( [] === $expected_viewports ) {
			return new \WP_Error( 'stonewright_visual_evidence_invalid', __( 'Expected visual evidence needs at least one viewport.', 'stonewright' ), [ 'path' => 'expected.viewports' ] );
		}
		$observed_viewports = self::viewports( $observed['viewports'] ?? $observed, 'observed' );
		if ( $observed_viewports instanceof \WP_Error ) {
			return $observed_viewports;
		}

		$findings       = [];
		$anchor_results = [];
		$error_count    = 0;
		$warning_count  = 0;
		$truncated      = 0;
		$checked        = 0;
		$passed         = 0;

		foreach ( $expected_viewports as $viewport_id => $viewport ) {
			$expected_elements = self::elements( $viewport, 'expected.' . $viewport_id, true );
			if ( $expected_elements instanceof \WP_Error ) {
				return $expected_elements;
			}
			$actual            = $observed_viewports[ $viewport_id ] ?? [];
			$actual_elements   = self::elements( $actual, 'observed.' . $viewport_id, false );
			if ( $actual_elements instanceof \WP_Error ) {
				return $actual_elements;
			}

			self::compare_viewport_metrics( $findings, $error_count, $warning_count, $truncated, $viewport_id, $viewport, $actual, $tolerances );
			foreach ( $expected_elements as $ref => $expected_box ) {
				++$checked;
				$before = $error_count;
				if ( ! isset( $actual_elements[ $ref ] ) ) {
					self::push( $findings, $error_count, $warning_count, $truncated, self::finding( $viewport_id, (string) $ref, 'missing_element', 'error', [], $expected_box ) );
				} else {
					$observed_box = $actual_elements[ $ref ];
					self::compare_geometry( $findings, $error_count, $warning_count, $truncated, $viewport_id, (string) $ref, $expected_box, $observed_box, $tolerances );
					self::compare_style( $findings, $error_count, $warning_count, $truncated, $viewport_id, (string) $ref, $expected_box, $observed_box, $tolerances );
				}
				$anchor_passed = $before === $error_count;
				if ( $anchor_passed ) {
					++$passed;
				}
				$anchor_results[] = [
					'viewport_id'  => $viewport_id,
					'ref'          => (string) $ref,
					'ok'           => $anchor_passed,
					'error_count'  => $error_count - $before,
					'target_setting' => self::target_setting( $expected_box ),
				];
			}
			foreach ( array_diff( array_keys( $actual_elements ), array_keys( $expected_elements ) ) as $extra ) {
				self::push( $findings, $error_count, $warning_count, $truncated, self::finding( $viewport_id, (string) $extra, 'extra_element', 'warning', [] ) );
			}
		}

		foreach ( array_diff( array_keys( $observed_viewports ), array_keys( $expected_viewports ) ) as $extra_viewport ) {
			self::push( $findings, $error_count, $warning_count, $truncated, self::finding( (string) $extra_viewport, '@viewport', 'extra_viewport', 'warning', [] ) );
		}

		$severity_order = [ 'error' => 0, 'warning' => 1 ];
		usort(
			$findings,
			static fn( array $a, array $b ): int => [ $severity_order[ $a['severity'] ] ?? 9, $a['viewport_id'], $a['ref'], $a['code'], (string) ( $a['evidence']['property'] ?? '' ) ] <=> [ $severity_order[ $b['severity'] ] ?? 9, $b['viewport_id'], $b['ref'], $b['code'], (string) ( $b['evidence']['property'] ?? '' ) ]
		);

		return [
			'ok'                => 0 === $error_count,
			'checked'           => $checked,
			'passed'            => $passed,
			'findings'          => $findings,
			'findings_truncated' => $truncated,
			'error_count'       => $error_count,
			'warning_count'     => $warning_count,
			'anchor_results'    => $anchor_results,
			'aggregate_score'   => 0 === $checked ? null : round( $passed / $checked, 4 ),
			'comparison_hash'   => Json::hash( [ $expected_viewports, $observed_viewports, $tolerances, $findings, $truncated ] ),
			'tolerances'        => $tolerances,
		];
	}

	/** @param list<array<string,mixed>> $findings @param array<string,mixed> $expected @param array<string,mixed> $observed @param array<string,float> $tolerances */
	private static function compare_geometry( array &$findings, int &$errors, int &$warnings, int &$truncated, string $viewport, string $ref, array $expected, array $observed, array $tolerances ): void {
		foreach ( [ 'x', 'y', 'width', 'height' ] as $key ) {
			if ( ! is_numeric( $observed[ $key ] ?? null ) ) {
				self::push( $findings, $errors, $warnings, $truncated, self::finding( $viewport, $ref, 'measurement_missing', 'error', [ 'property' => $key ], $expected ) );
				continue;
			}
			$delta = round( (float) $observed[ $key ] - (float) $expected[ $key ], 3 );
			if ( abs( $delta ) > $tolerances[ $key ] ) {
				self::push(
					$findings,
					$errors,
					$warnings,
					$truncated,
					self::finding(
						$viewport,
						$ref,
						'box_delta',
						'error',
						[ 'property' => $key, 'expected' => (float) $expected[ $key ], 'observed' => (float) $observed[ $key ], 'delta' => $delta, 'tolerance' => $tolerances[ $key ] ],
						$expected
					)
				);
			}
		}
	}

	/** @param list<array<string,mixed>> $findings @param array<string,mixed> $expected @param array<string,mixed> $observed @param array<string,float> $tolerances */
	private static function compare_style( array &$findings, int &$errors, int &$warnings, int &$truncated, string $viewport, string $ref, array $expected, array $observed, array $tolerances ): void {
		$numeric = [
			'font_size'      => 'font_size',
			'line_height'    => 'line_height',
			'letter_spacing' => 'letter_spacing',
			'gap'            => 'spacing',
			'padding.top'    => 'spacing',
			'padding.right'  => 'spacing',
			'padding.bottom' => 'spacing',
			'padding.left'   => 'spacing',
			'margin.top'     => 'spacing',
			'margin.right'   => 'spacing',
			'margin.bottom'  => 'spacing',
			'margin.left'    => 'spacing',
		];
		foreach ( $numeric as $property => $tolerance_key ) {
			$expected_value = self::property( $expected, $property );
			if ( null === $expected_value ) {
				continue;
			}
			$observed_value = self::property( $observed, $property );
			if ( ! is_numeric( $observed_value ) ) {
				self::push( $findings, $errors, $warnings, $truncated, self::finding( $viewport, $ref, 'measurement_missing', 'error', [ 'property' => $property ], $expected ) );
				continue;
			}
			$delta = round( (float) $observed_value - (float) $expected_value, 3 );
			if ( abs( $delta ) > $tolerances[ $tolerance_key ] ) {
				self::push( $findings, $errors, $warnings, $truncated, self::finding( $viewport, $ref, 'style_delta', 'error', [ 'property' => $property, 'expected' => (float) $expected_value, 'observed' => (float) $observed_value, 'delta' => $delta, 'tolerance' => $tolerances[ $tolerance_key ] ], $expected ) );
			}
		}

		foreach ( [ 'color', 'background_color', 'font_family', 'font_weight', 'text_align' ] as $property ) {
			if ( ! array_key_exists( $property, $expected ) ) {
				continue;
			}
			if ( ! array_key_exists( $property, $observed ) ) {
				self::push( $findings, $errors, $warnings, $truncated, self::finding( $viewport, $ref, 'measurement_missing', 'error', [ 'property' => $property ], $expected ) );
				continue;
			}
			if ( self::canonical_text( $expected[ $property ] ) !== self::canonical_text( $observed[ $property ] ) ) {
				$code = in_array( $property, [ 'color', 'background_color' ], true ) ? 'color_delta' : 'typography_delta';
				self::push( $findings, $errors, $warnings, $truncated, self::finding( $viewport, $ref, $code, 'error', [ 'property' => $property, 'expected' => (string) $expected[ $property ], 'observed' => (string) $observed[ $property ] ], $expected ) );
			}
		}
	}

	/** @param list<array<string,mixed>> $findings @param array<string,mixed> $expected @param array<string,mixed> $observed @param array<string,float> $tolerances */
	private static function compare_viewport_metrics( array &$findings, int &$errors, int &$warnings, int &$truncated, string $viewport, array $expected, array $observed, array $tolerances ): void {
		foreach ( [ 'width', 'height', 'device_pixel_ratio' ] as $property ) {
			if ( ! array_key_exists( $property, $expected ) ) {
				continue;
			}
			if ( ! is_numeric( $observed[ $property ] ?? null ) ) {
				self::push( $findings, $errors, $warnings, $truncated, self::finding( $viewport, '@viewport', 'viewport_measurement_missing', 'error', [ 'property' => $property ] ) );
				continue;
			}
			$tolerance = 'device_pixel_ratio' === $property ? $tolerances['device_pixel_ratio'] : $tolerances[ $property ];
			$delta     = round( (float) $observed[ $property ] - (float) $expected[ $property ], 3 );
			if ( abs( $delta ) > $tolerance ) {
				self::push( $findings, $errors, $warnings, $truncated, self::finding( $viewport, '@viewport', 'viewport_delta', 'error', [ 'property' => $property, 'expected' => (float) $expected[ $property ], 'observed' => (float) $observed[ $property ], 'delta' => $delta, 'tolerance' => $tolerance ] ) );
			}
		}
	}

	/** @return array<string,float> */
	private static function tolerances( mixed $value ): array {
		$value = is_array( $value ) ? $value : [];
		return [
			'x'                  => self::non_negative( $value['x'] ?? 2.0, 2.0 ),
			'y'                  => self::non_negative( $value['y'] ?? 2.0, 2.0 ),
			'width'              => self::non_negative( $value['width'] ?? 2.0, 2.0 ),
			'height'             => self::non_negative( $value['height'] ?? 2.0, 2.0 ),
			'font_size'          => self::non_negative( $value['font_size'] ?? 0.5, 0.5 ),
			'line_height'        => self::non_negative( $value['line_height'] ?? 0.5, 0.5 ),
			'letter_spacing'     => self::non_negative( $value['letter_spacing'] ?? 0.25, 0.25 ),
			'spacing'            => self::non_negative( $value['spacing'] ?? 1.0, 1.0 ),
			'device_pixel_ratio' => self::non_negative( $value['device_pixel_ratio'] ?? 0.01, 0.01 ),
		];
	}

	/** @return array<string,array<string,mixed>>|\WP_Error */
	private static function viewports( mixed $value, string $side ): array|\WP_Error {
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'stonewright_visual_evidence_invalid', __( 'Visual viewport evidence must be an object or array.', 'stonewright' ), [ 'path' => $side . '.viewports' ] );
		}
		if ( count( $value ) > self::MAX_VIEWPORTS ) {
			return new \WP_Error( 'stonewright_visual_evidence_limit_exceeded', __( 'Visual evidence contains too many viewports.', 'stonewright' ), [ 'path' => $side . '.viewports', 'limit' => self::MAX_VIEWPORTS ] );
		}
		$out = [];
		foreach ( $value as $key => $row ) {
			if ( ! is_array( $row ) ) {
				return new \WP_Error( 'stonewright_visual_evidence_invalid', __( 'Every viewport must be an object.', 'stonewright' ), [ 'path' => $side . '.viewports.' . $key ] );
			}
			$id = self::safe_ref( is_string( $key ) ? $key : (string) ( $row['id'] ?? $key ) );
			if ( '' === $id || isset( $out[ $id ] ) ) {
				return new \WP_Error( 'stonewright_visual_evidence_invalid', __( 'Viewport IDs must be non-empty and unique.', 'stonewright' ), [ 'path' => $side . '.viewports.' . $key ] );
			}
			$out[ $id ] = $row;
		}
		return $out;
	}

	/** @return array<string,array<string,mixed>>|\WP_Error */
	private static function elements( array $viewport, string $path, bool $strict ): array|\WP_Error {
		$rows = $viewport['elements'] ?? $viewport['boxes'] ?? [];
		if ( ! is_array( $rows ) ) {
			return new \WP_Error( 'stonewright_visual_evidence_invalid', __( 'Viewport elements must be an object or array.', 'stonewright' ), [ 'path' => $path . '.elements' ] );
		}
		if ( count( $rows ) > self::MAX_ELEMENTS_PER_VIEWPORT ) {
			return new \WP_Error( 'stonewright_visual_evidence_limit_exceeded', __( 'A viewport contains too many comparison elements.', 'stonewright' ), [ 'path' => $path . '.elements', 'limit' => self::MAX_ELEMENTS_PER_VIEWPORT ] );
		}
		$out = [];
		foreach ( $rows as $key => $row ) {
			if ( ! is_array( $row ) ) {
				return new \WP_Error( 'stonewright_visual_evidence_invalid', __( 'Every comparison element must be an object.', 'stonewright' ), [ 'path' => $path . '.elements.' . $key ] );
			}
			$ref = self::safe_ref( (string) ( $row['ref'] ?? $row['node_id'] ?? $key ) );
			if ( '' === $ref || isset( $out[ $ref ] ) ) {
				return new \WP_Error( 'stonewright_visual_evidence_invalid', __( 'Comparison element references must be non-empty and unique.', 'stonewright' ), [ 'path' => $path . '.elements.' . $key ] );
			}
			if ( $strict ) {
				foreach ( [ 'x', 'y', 'width', 'height' ] as $property ) {
					if ( ! is_numeric( $row[ $property ] ?? null ) ) {
						return new \WP_Error( 'stonewright_visual_evidence_invalid', __( 'Expected comparison elements need numeric x, y, width, and height measurements.', 'stonewright' ), [ 'path' => $path . '.elements.' . $ref . '.' . $property ] );
					}
				}
				if ( (float) $row['width'] < 0 || (float) $row['height'] < 0 ) {
					return new \WP_Error( 'stonewright_visual_evidence_invalid', __( 'Expected width and height cannot be negative.', 'stonewright' ), [ 'path' => $path . '.elements.' . $ref ] );
				}
			}
			$out[ $ref ] = $row;
		}
		return $out;
	}

	private static function property( array $row, string $path ): mixed {
		$value = $row;
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				$flat = str_replace( '.', '_', $path );
				return array_key_exists( $flat, $row ) ? $row[ $flat ] : null;
			}
			$value = $value[ $part ];
		}
		return $value;
	}

	/** @param list<array<string,mixed>> $findings @param array<string,mixed> $finding */
	private static function push( array &$findings, int &$errors, int &$warnings, int &$truncated, array $finding ): void {
		if ( 'error' === $finding['severity'] ) {
			++$errors;
		} else {
			++$warnings;
		}
		if ( count( $findings ) >= self::MAX_FINDINGS ) {
			++$truncated;
			return;
		}
		$findings[] = $finding;
	}

	/** @param array<string,mixed> $evidence @param array<string,mixed> $expected */
	private static function finding( string $viewport, string $ref, string $code, string $severity, array $evidence, array $expected = [] ): array {
		$target = self::target_setting( $expected );
		if ( null !== $target ) {
			$evidence['target_setting'] = $target;
		}
		return [ 'viewport_id' => $viewport, 'ref' => $ref, 'code' => $code, 'severity' => $severity, 'evidence' => $evidence ];
	}

	/** @param array<string,mixed> $row */
	private static function target_setting( array $row ): ?string {
		$value = is_scalar( $row['target_setting'] ?? null ) ? sanitize_text_field( (string) $row['target_setting'] ) : '';
		return '' !== $value ? mb_substr( $value, 0, 190 ) : null;
	}

	private static function canonical_text( mixed $value ): string {
		return strtolower( trim( is_scalar( $value ) ? (string) $value : '' ) );
	}

	private static function safe_ref( string $value ): string {
		return mb_substr( sanitize_text_field( $value ), 0, 190 );
	}

	private static function non_negative( mixed $value, float $fallback ): float {
		return is_numeric( $value ) ? max( 0.0, (float) $value ) : $fallback;
	}
}
