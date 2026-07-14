<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Write;

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
				return self::error( $setting, 'missing_evidence', 'Add settings_evidence for this planned setting.' );
			}
			$control_key = (string) ( $row['control_key'] ?? self::base_control_key( $setting ) );
			if ( self::base_control_key( $setting ) !== $control_key ) {
				return self::error( $setting, 'control_key_mismatch', 'Use the base live control key.' );
			}
			if ( ! hash_equals( $schema_hash, (string) ( $row['schema_hash'] ?? '' ) ) ) {
				return self::error( $setting, 'schema_hash_mismatch', 'Refresh the live Elementor schema and rebuild the plan.' );
			}
			$source     = trim( (string) ( $row['source'] ?? '' ) );
			$confidence = $row['confidence'] ?? null;
			$scope      = trim( (string) ( $row['responsive_scope'] ?? '' ) );
			if ( '' === $source || ! is_numeric( $confidence ) || (float) $confidence < 0 || (float) $confidence > 1 || '' === $scope || ! is_bool( $row['requires_confirmation'] ?? null ) ) {
				return self::error( $setting, 'invalid_evidence', 'Provide source, confidence 0..1, responsive_scope, and requires_confirmation.' );
			}
			$rows[] = [
				'control_key'          => $control_key,
				'schema_hash'          => $schema_hash,
				'source'               => $source,
				'confidence'           => (float) $confidence,
				'responsive_scope'     => $scope,
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

	private static function error( string $setting, string $reason, string $repair ): \WP_Error {
		return new \WP_Error(
			'stonewright_elementor_evidence_invalid',
			__( 'Elementor setting evidence is incomplete or stale.', 'stonewright' ),
			[ 'status' => 400, 'setting' => $setting, 'reason' => $reason, 'repair' => $repair ]
		);
	}
}
