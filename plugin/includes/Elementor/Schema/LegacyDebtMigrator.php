<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Schema;

use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Support\ElementorData;
use Stonewright\WpMcp\Support\Json;

/**
 * Plans only explicit, live-schema-backed Elementor legacy normalizations.
 * No heuristic fallback is allowed: a replacement must come from a live
 * selectors dictionary or an exact live control default.
 */
final class LegacyDebtMigrator {

	/**
	 * @param array<int,array<string,mixed>> $tree
	 * @param list<string>                   $requested_paths
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function plan( array $tree, string $element_id, array $requested_paths ): array|\WP_Error {
		$path = ElementorData::find_path( $tree, $element_id );
		if ( null === $path ) {
			return new \WP_Error( 'stonewright_element_not_found', __( 'The requested Elementor element was not found.', 'stonewright' ), [ 'status' => 404, 'element_id' => $element_id ] );
		}
		$element = self::resolve( $tree, $path );
		if ( ! is_array( $element ) || 'widget' !== (string) ( $element['elType'] ?? '' ) ) {
			return new \WP_Error( 'stonewright_legacy_migration_widget_required', __( 'Legacy setting migration currently requires one existing Elementor V3 widget.', 'stonewright' ), [ 'status' => 400, 'element_id' => $element_id ] );
		}
		$widget_type = sanitize_key( (string) ( $element['widgetType'] ?? '' ) );
		if ( '' === $widget_type || str_starts_with( $widget_type, 'e-' ) ) {
			return new \WP_Error( 'stonewright_legacy_migration_architecture_blocked', __( 'Atomic Elementor widgets must use a typed V4 migration path.', 'stonewright' ), [ 'status' => 409, 'element_id' => $element_id ] );
		}
		$schema = WidgetSchemaRepository::get( $widget_type );
		if ( $schema instanceof \WP_Error ) {
			return $schema;
		}
		$settings = is_array( $element['settings'] ?? null ) ? $element['settings'] : [];
		$controls = is_array( $schema['controls'] ?? null ) ? $schema['controls'] : [];
		$schema_hash = (string) ( $schema['schema_hash'] ?? '' );
		$issues = [];
		$update_settings = [];
		$update_breakpoints = [];
		$repeater_operations = [];
		$seen_paths = [];

		foreach ( $requested_paths as $requested_path ) {
			$requested_path = self::normalize_path( $requested_path );
			if ( '' === $requested_path || isset( $seen_paths[ $requested_path ] ) ) {
				continue;
			}
			$seen_paths[ $requested_path ] = true;
			$parts = explode( '.', $requested_path );
			$key   = (string) ( $parts[0] ?? '' );
			if ( '' === $key || ! array_key_exists( $key, $settings ) ) {
				$issues[] = self::unavailable_issue( $requested_path, 'setting_path_not_found' );
				continue;
			}

			if ( 1 === count( $parts ) ) {
				$base_key = self::base_control_key( $key );
				$control  = is_array( $controls[ $base_key ] ?? null ) ? $controls[ $base_key ] : null;
				if ( null === $control ) {
					$issues[] = self::unavailable_issue( $requested_path, 'unknown_or_third_party_control' );
					continue;
				}
				$migration = self::replacement( $settings[ $key ], $control, self::is_responsive_key( $key ) );
				if ( null === $migration ) {
					$issues[] = self::unavailable_issue( $requested_path, 'no_explicit_live_schema_mapping', $settings[ $key ] );
					continue;
				}
				$validated = SettingsValidator::validate( $widget_type, [ $key => $migration['value'] ], false, false, false );
				if ( $validated instanceof \WP_Error || ! array_key_exists( $key, $validated['settings'] ) ) {
					$issues[] = self::unavailable_issue( $requested_path, 'mapped_value_rejected_by_live_schema', $settings[ $key ] );
					continue;
				}
				$update_settings[ $key ] = $validated['settings'][ $key ];
				$update_breakpoints[] = self::breakpoint( $key );
				$issues[] = self::available_issue( $requested_path, $settings[ $key ], $validated['settings'][ $key ], (string) $migration['source'] );
				continue;
			}

			if ( 3 !== count( $parts ) || ! ctype_digit( $parts[1] ) ) {
				$issues[] = self::unavailable_issue( $requested_path, 'nested_path_not_safely_addressable' );
				continue;
			}
			$index = (int) $parts[1];
			$field = (string) $parts[2];
			$control = is_array( $controls[ $key ] ?? null ) ? $controls[ $key ] : [];
			$field_control = is_array( $control['fields'][ $field ] ?? null ) ? $control['fields'][ $field ] : null;
			$row = is_array( $settings[ $key ][ $index ] ?? null ) ? $settings[ $key ][ $index ] : null;
			if ( 'repeater' !== (string) ( $control['type'] ?? '' ) || null === $field_control || null === $row || ! array_key_exists( $field, $row ) ) {
				$issues[] = self::unavailable_issue( $requested_path, 'unknown_or_third_party_repeater_field' );
				continue;
			}
			$selector = self::selector( $row );
			if ( [] === $selector ) {
				$issues[] = self::unavailable_issue( $requested_path, 'stable_repeater_identity_missing', $row[ $field ] );
				continue;
			}
			$migration = self::replacement( $row[ $field ], $field_control, false );
			if ( null === $migration ) {
				$issues[] = self::unavailable_issue( $requested_path, 'no_explicit_live_schema_mapping', $row[ $field ] );
				continue;
			}
			$operation_key = $key . '|' . Json::hash( $selector );
			if ( ! isset( $repeater_operations[ $operation_key ] ) ) {
				$repeater_operations[ $operation_key ] = [
					'action'            => 'patch_repeater_row',
					'element_id'        => $element_id,
					'repeater_key'       => $key,
					'selector'           => $selector,
					'row_patch'          => [],
					'expected_row_hash'  => Json::hash( $row ),
					'settings_evidence'  => [
						$key => self::evidence( $key, $schema_hash ),
					],
				];
			}
			$repeater_operations[ $operation_key ]['row_patch'][ $field ] = $migration['value'];
			$issues[] = self::available_issue( $requested_path, $row[ $field ], $migration['value'], (string) $migration['source'] );
		}

		$operations = [];
		if ( [] !== $update_settings ) {
			$evidence = [];
			foreach ( array_keys( $update_settings ) as $key ) {
				$evidence[ $key ] = self::evidence( $key, $schema_hash );
			}
			$operations[] = [
				'action'              => 'update_element',
				'element_id'          => $element_id,
				'mode'                => 'merge',
				'settings'            => $update_settings,
				'allowed_breakpoints' => array_values( array_unique( $update_breakpoints ) ),
				'settings_evidence'   => $evidence,
			];
		}
		foreach ( $repeater_operations as $operation ) {
			$operations[] = $operation;
		}

		return [
			'element_id'       => $element_id,
			'widget_type'      => $widget_type,
			'schema_hash'      => $schema_hash,
			'before_tree_hash' => TreeHasher::hash( $tree ),
			'issues'           => $issues,
			'operations'       => $operations,
			'safe_count'       => count( $operations ),
			'unavailable_count'=> count( array_filter( $issues, static fn ( array $issue ): bool => empty( $issue['safe_migration_available'] ) ) ),
			'write_performed'  => false,
		];
	}

	/** @param array<string,mixed> $control @return array{value:mixed,source:string}|null */
	private static function replacement( mixed $before, array $control, bool $responsive ): ?array {
		if ( is_scalar( $before ) && isset( $control['selectors_dictionary'] ) && is_array( $control['selectors_dictionary'] ) && array_key_exists( (string) $before, $control['selectors_dictionary'] ) ) {
			$mapped = $control['selectors_dictionary'][ (string) $before ];
			if ( $mapped !== $before ) {
				return [ 'value' => $mapped, 'source' => 'live_selectors_dictionary' ];
			}
		}
		if ( $responsive || ! array_key_exists( 'default', $control ) ) {
			return null;
		}
		$type = strtolower( (string) ( $control['type'] ?? '' ) );
		if ( null === $before && in_array( $type, [ 'media', 'gallery', 'url', 'icons' ], true ) ) {
			return [ 'value' => $control['default'], 'source' => 'live_control_default' ];
		}
		if ( '' === $before && in_array( $type, [ 'number', 'slider' ], true ) && '' !== $control['default'] && null !== $control['default'] ) {
			return [ 'value' => $control['default'], 'source' => 'live_control_default' ];
		}
		return null;
	}

	/** @return array<string,string> */
	private static function selector( array $row ): array {
		foreach ( [ 'custom_id', '_id' ] as $key ) {
			$value = is_scalar( $row[ $key ] ?? null ) ? trim( (string) $row[ $key ] ) : '';
			if ( '' !== $value ) {
				return [ $key => $value ];
			}
		}
		return [];
	}

	/** @return array<string,mixed> */
	private static function evidence( string $key, string $schema_hash ): array {
		return [
			'control_key'          => self::base_control_key( $key ),
			'schema_hash'          => $schema_hash,
			'source'               => 'live_elementor_schema_explicit_migration_map',
			'confidence'           => 1.0,
			'responsive_scope'     => self::breakpoint( $key ),
			'requires_confirmation'=> true,
		];
	}

	/** @return array<string,mixed> */
	private static function available_issue( string $path, mixed $before, mixed $after, string $source ): array {
		return [
			'path'                     => $path,
			'issue_code'               => 'explicit_legacy_mapping_available',
			'safe_migration_available' => true,
			'mapping_source'           => $source,
			'before_type'              => get_debug_type( $before ),
			'after_type'               => get_debug_type( $after ),
			'before_hash'              => Json::hash( $before ),
			'after_hash'               => Json::hash( $after ),
			'raw_values_returned'      => false,
		];
	}

	/** @return array<string,mixed> */
	private static function unavailable_issue( string $path, string $reason, mixed $before = null ): array {
		return [
			'path'                     => $path,
			'issue_code'               => $reason,
			'safe_migration_available' => false,
			'mapping_source'           => 'none',
			'before_type'              => get_debug_type( $before ),
			'before_hash'              => Json::hash( $before ),
			'raw_values_returned'      => false,
		];
	}

	private static function normalize_path( string $path ): string {
		$path = trim( preg_replace( '/\[(\d+)\]/', '.$1', $path ) ?? $path );
		$path = preg_replace( '/^settings\./', '', $path ) ?? $path;
		$parts = array_values( array_filter( explode( '.', $path ), static fn ( string $part ): bool => '' !== $part && '..' !== $part ) );
		return implode( '.', array_map( static fn ( string $part ): string => sanitize_key( $part ), $parts ) );
	}

	private static function base_control_key( string $key ): string {
		return (string) preg_replace( '/_(widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile)$/', '', $key );
	}

	private static function is_responsive_key( string $key ): bool {
		return self::base_control_key( $key ) !== $key;
	}

	private static function breakpoint( string $key ): string {
		return preg_match( '/_(widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile)$/', $key, $matches ) ? (string) $matches[1] : 'desktop';
	}

	/** @param array<int,array<string,mixed>> $tree @param list<int> $path @return array<string,mixed>|null */
	private static function resolve( array $tree, array $path ): ?array {
		$current = $tree;
		$node    = null;
		foreach ( $path as $index ) {
			if ( ! isset( $current[ $index ] ) || ! is_array( $current[ $index ] ) ) {
				return null;
			}
			$node    = $current[ $index ];
			$current = isset( $node['elements'] ) && is_array( $node['elements'] ) ? $node['elements'] : [];
		}
		return $node;
	}
}
