<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Write;

use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Elementor\Schema\ContainerSchemaRepository;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/** Validates compact provenance attached to every planned widget setting. */
final class EvidenceValidator {
	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $evidence
	 * @return list<array<string, mixed>>|\WP_Error
	 */
	public static function validate( string $widget_type, array $settings, array $evidence, bool $required ): array|\WP_Error {
		if ( [] === $settings ) {
			return [];
		}
		if ( ! $required && [] === $evidence ) {
			return [];
		}

		$schema_hash = in_array( $widget_type, [ 'container', 'section', 'column' ], true )
			? self::structural_schema_hash( $widget_type )
			: self::widget_schema_hash( $widget_type );
		if ( $schema_hash instanceof \WP_Error ) {
			return $schema_hash;
		}

		$rows = [];
		foreach ( $settings as $setting => $value ) {
			$setting = (string) $setting;
			if ( in_array( $setting, [ '__dynamic__', '__globals__' ], true ) ) {
				continue;
			}
			$row = isset( $evidence[ $setting ] ) && is_array( $evidence[ $setting ] ) ? $evidence[ $setting ] : null;
			if ( null === $row ) {
				return self::error( $widget_type, $setting, 'missing_evidence', 'Add settings_evidence for this planned setting.' );
			}
			$control_key = (string) ( $row['control_key'] ?? self::base_control_key( $setting ) );
			if ( self::base_control_key( $setting ) !== $control_key ) {
				return self::error( $widget_type, $setting, 'control_key_mismatch', 'Use the base live control key.' );
			}

			$source     = trim( (string) ( $row['source'] ?? '' ) );
			$confidence = $row['confidence'] ?? null;
			$scope      = trim( (string) ( $row['responsive_scope'] ?? '' ) );
			if ( '' === $source || ! is_numeric( $confidence ) || (float) $confidence < 0 || (float) $confidence > 1 || '' === $scope || ! is_bool( $row['requires_confirmation'] ?? null ) ) {
				return self::error( $widget_type, $setting, 'invalid_evidence', 'Provide source, confidence 0..1, responsive_scope, and requires_confirmation.' );
			}

			$hash = (string) ( $row['schema_hash'] ?? '' );
			if ( self::is_direction_brief_source( $source ) && self::is_token_derived_setting( $setting ) ) {
				if ( (int) get_option( DesignDirectionService::ACTIVE_OPTION, 0 ) < 1 ) {
					return self::error(
						$widget_type,
						$setting,
						'missing_direction',
						'Activate a design direction, then cite source=direction-brief on token-derived color, typography, and spacing settings.'
					);
				}
				if ( '' === $hash ) {
					$hash = $schema_hash;
				}
			}

			if ( ! hash_equals( $schema_hash, $hash ) ) {
				return self::error( $widget_type, $setting, 'schema_hash_mismatch', 'Refresh the live Elementor schema and rebuild the plan.' );
			}

			$rows[] = [
				'control_key'           => $control_key,
				'schema_hash'           => $schema_hash,
				'source'                => $source,
				'confidence'            => (float) $confidence,
				'responsive_scope'      => $scope,
				'requires_confirmation' => (bool) $row['requires_confirmation'],
			];
		}
		return $rows;
	}

	private static function widget_schema_hash( string $widget_type ): string|\WP_Error {
		$schema = WidgetSchemaRepository::get( $widget_type );
		return $schema instanceof \WP_Error ? $schema : (string) ( $schema['schema_hash'] ?? '' );
	}

	private static function structural_schema_hash( string $element_type ): string|\WP_Error {
		$schema = ContainerSchemaRepository::get( $element_type );
		return $schema instanceof \WP_Error ? $schema : (string) ( $schema['schema_hash'] ?? '' );
	}

	private static function base_control_key( string $key ): string {
		return (string) preg_replace( '/_(widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile)$/', '', $key );
	}

	private static function is_direction_brief_source( string $source ): bool {
		$source = strtolower( $source );
		return in_array( $source, [ 'direction-brief', 'design-direction', 'stonewright-direction' ], true )
			|| str_starts_with( $source, 'direction:' )
			|| str_starts_with( $source, 'design-direction:' );
	}

	private static function is_token_derived_setting( string $setting ): bool {
		$base = self::base_control_key( $setting );
		return 1 === preg_match( '/(color|padding|margin|gap|font|typography|spacing|radius|letter_spacing|line_height|background)/i', $base );
	}

	private static function error( string $widget_type, string $setting, string $reason, string $repair ): \WP_Error {
		$is_container = in_array( $widget_type, [ 'container', 'section', 'column' ], true );
		$query        = self::base_control_key( $setting );
		return new \WP_Error(
			'stonewright_elementor_evidence_invalid',
			__( 'Elementor setting evidence is incomplete or stale.', 'stonewright' ),
			[
				'status'           => 400,
				'setting'          => $setting,
				'widget_type'      => $widget_type,
				'reason'           => $reason,
				'repair'           => $repair,
				'execution_status' => 'blocked',
				'schema_request'   => [
					'ability' => $is_container ? 'stonewright/elementor-v3-container-schema' : 'stonewright/elementor-schema',
					'input'   => $is_container
						? [ 'query' => $query ]
						: [ 'mode' => 'summary', 'widget_type' => $widget_type, 'query' => $query ],
				],
			]
		);
	}
}
