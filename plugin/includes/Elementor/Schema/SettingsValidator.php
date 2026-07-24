<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Schema;

use Stonewright\WpMcp\Support\TextIntegrity;

/**
 * Rejects Elementor settings that are not present in the live widget schema.
 */
final class SettingsValidator {
	private static ?\WP_Error $last_error = null;

	/**
	 * @param array<string, mixed> $settings Candidate widget settings.
	 * @return array{settings:array<string,mixed>,schema_hash:string,warnings:list<array<string,mixed>>}|\WP_Error
	 */
	public static function validate( string $widget_type, array $settings, bool $require_render_settings = true, bool $enforce_conditions = true, bool $preserve_unknown = false ): array|\WP_Error {
		$aliases  = SettingsKeyAliases::normalize( $settings );
		$settings = $aliases['settings'];
		$schema   = WidgetSchemaRepository::get( $widget_type );
		if ( $schema instanceof \WP_Error ) {
			return $schema;
		}
		return self::validate_schema( $widget_type, $settings, $schema, $require_render_settings, $enforce_conditions, $aliases['applied'], $preserve_unknown );
	}

	/**
	 * @param array<string, mixed> $settings Candidate structural-element settings.
	 * @return array{settings:array<string,mixed>,schema_hash:string,warnings:list<array<string,mixed>>}|\WP_Error
	 */
	public static function validate_container( array $settings, string $element_type = 'container', bool $enforce_conditions = true, bool $preserve_unknown = false ): array|\WP_Error {
		$aliases  = SettingsKeyAliases::normalize( $settings );
		$settings = $aliases['settings'];
		$schema   = ContainerSchemaRepository::get( $element_type );
		if ( $schema instanceof \WP_Error ) {
			return $schema;
		}
		return self::validate_schema( $element_type, $settings, $schema, false, $enforce_conditions, $aliases['applied'], $preserve_unknown );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $schema
	 * @return array{settings:array<string,mixed>,schema_hash:string,warnings:list<array<string,mixed>>}|\WP_Error
	 */
	private static function validate_schema( string $subject, array $settings, array $schema, bool $require_render_settings, bool $enforce_conditions, array $aliases = [], bool $preserve_unknown = false ): array|\WP_Error {

		$controls   = (array) ( $schema['controls'] ?? [] );
		$violations = [];
		$warnings   = [];
		$normalized = [];
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, [ '__dynamic__', '__globals__' ], true ) ) {
				self::validate_bindings( $key, $value, $controls, $violations );
				$normalized[ $key ] = $value;
				continue;
			}

			$control_key = self::control_key( $key, $controls );
			if ( null === $control_key ) {
				// P0 integrity: never strip unknown settings to "pass" validation.
				// Unknown keys are kept with a warning (Pro / runtime extras).
				if ( $preserve_unknown ) {
					$warnings[]         = self::violation( 'settings.' . $key, 'unknown_setting_preserved', 'a key from the live widget schema', $value, self::nearest_keys( $key, array_keys( $controls ) ) );
					$normalized[ $key ] = $value;
					continue;
				}
				$violations[] = self::violation( 'settings.' . $key, 'unknown_setting', 'a key from the live widget schema', $value, self::nearest_keys( $key, array_keys( $controls ) ) );
				continue;
			}

			$control = (array) $controls[ $control_key ];
			$error   = self::validate_value( $value, $control, 'settings.' . $key );
			if ( null !== $error ) {
				$violations[] = $error;
				continue;
			}
			if ( $enforce_conditions && ! self::condition_is_active( (array) ( $control['condition'] ?? [] ), $settings, $controls ) ) {
				$violations[] = self::violation( 'settings.' . $key, 'inactive_condition', 'the control condition/activator to be satisfied', $value, array_keys( (array) ( $control['condition'] ?? [] ) ) );
				continue;
			}
			$normalized[ $key ] = $value;
		}

		foreach ( $require_render_settings ? (array) ( $schema['required_for_render'] ?? [] ) : [] as $required ) {
			$required = (string) $required;
			if ( ! array_key_exists( $required, $normalized ) || ! self::has_value( $normalized[ $required ] ) ) {
				$violations[] = self::violation( 'settings.' . $required, 'required_missing', 'a non-empty value', $normalized[ $required ] ?? null );
			}
		}

		if ( [] !== $violations ) {
			$first      = $violations[0];
			$first_path = (string) ( $first['path'] ?? '' );
			$query      = preg_replace( '/^settings\./', '', $first_path );
			$query      = explode( '.', (string) $query )[0];
			$is_container = in_array( $subject, [ 'container', 'section', 'column' ], true );
			return new \WP_Error(
				'stonewright_elementor_settings_invalid',
				sprintf(
					/* translators: 1: setting path, 2: expected shape, 3: received PHP type */
					__( 'Elementor setting %1$s rejected: expected %2$s; received %3$s.', 'stonewright' ),
					$first_path,
					(string) ( $first['expected'] ?? 'a value accepted by the live widget schema' ),
					(string) ( $first['got_type'] ?? 'unknown' )
				),
				[
					'status'      => 400,
					'widget_type' => $subject,
					'schema_hash' => (string) ( $schema['schema_hash'] ?? '' ),
					'violations'  => $violations,
					'retryable'   => true,
					'schema_request' => [
						'ability' => $is_container ? 'stonewright/elementor-v3-container-schema' : 'stonewright/elementor-schema',
						'input'   => $is_container
							? [ 'query' => $query ]
							: [ 'mode' => 'summary', 'widget_type' => $subject, 'query' => $query ],
					],
					'repair'      => 'Call schema_request once, replace only rejected settings, then rerun the same batch dry-run.',
				]
			);
		}

		$alias_warnings = array_map(
			static fn( array $alias ): array => [
				'code'      => 'settings_alias_applied',
				'alias'     => (string) $alias['alias'],
				'canonical' => (string) $alias['canonical'],
			],
			$aliases
		);

		return [
			'settings'    => $normalized,
			'schema_hash' => (string) ( $schema['schema_hash'] ?? '' ),
			'warnings'    => array_merge( $alias_warnings, $warnings ),
		];
	}

	/**
	 * Final guard used immediately before persisting an Elementor document tree.
	 *
	 * Mixed V3/V4 documents may contain e-* atomic widgets; those are preserved
	 * (structure-only checks) so agents are not forced to convert them.
	 * Unknown settings are preserved so layout keys are never stripped-to-pass.
	 *
	 * @param array<int, array<string, mixed>> $tree Elementor element tree.
	 * @param list<string>|null $touched_ids Content-validation scope; null validates every node.
	 */
	public static function validate_tree( array $tree, ?array $touched_ids = null ): bool {
		self::$last_error = null;
		$seen_ids         = [];
		$scope            = null === $touched_ids ? null : array_fill_keys( array_map( 'strval', $touched_ids ), true );
		return self::validate_tree_nodes( $tree, 'root', $seen_ids, $scope );
	}

	/**
	 * @param array<int, mixed>        $tree
	 * @param array<string, true>      $seen_ids
	 * @param array<string, true>|null $scope
	 */
	private static function validate_tree_nodes( array $tree, string $path, array &$seen_ids, ?array $scope = null ): bool {
		foreach ( $tree as $index => $element ) {
			if ( ! is_array( $element ) ) {
				return self::tree_error( $path . '.' . (string) $index, 'invalid_element', 'an Elementor element object', $element );
			}
			$element_path = $path . '.' . (string) $index;
			$id           = isset( $element['id'] ) && is_scalar( $element['id'] ) ? trim( (string) $element['id'] ) : '';
			if ( '' === $id ) {
				return self::tree_error( $element_path . '.id', 'missing_id', 'a non-empty unique Elementor element id', $element['id'] ?? null );
			}
			if ( isset( $seen_ids[ $id ] ) ) {
				return self::tree_error( $element_path . '.id', 'duplicate_id', 'an Elementor element id unique within the tree', $id );
			}
			$seen_ids[ $id ] = true;

			$element_type = (string) ( $element['elType'] ?? '' );
			if ( '' === $element_type ) {
				return self::tree_error( $element_path . '.elType', 'missing_element_type', 'a non-empty Elementor element type', null );
			}
			if ( 'widget' === $element_type ) {
				$widget_type = (string) ( $element['widgetType'] ?? '' );
				if ( '' === $widget_type ) {
					return self::tree_error( $element_path . '.widgetType', 'missing_widget_type', 'a non-empty Elementor widget type', null );
				}
				$in_scope = null === $scope || isset( $scope[ $id ] );
				// Atomic and untouched widgets get structure-only validation.
				if ( $in_scope && ! str_starts_with( $widget_type, 'e-' ) && 'html' !== $widget_type ) {
					$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
					$text_error = TextIntegrity::first_violation( $settings, $element_path . '.settings' );
					if ( null !== $text_error ) {
						return self::tree_error( $text_error['path'], $text_error['code'], 'valid UTF-8 human text without stripped Unicode escapes or mojibake', $text_error['value'] );
					}
					// preserve_unknown=true: unknown Pro/runtime keys stay; invalid known values still fail.
					$result = self::validate( $widget_type, $settings, false, false, true );
					if ( $result instanceof \WP_Error ) {
						self::$last_error = $result;
						return false;
					}
				}
			} elseif ( ( null === $scope || isset( $scope[ $id ] ) ) && in_array( $element_type, [ 'container', 'section', 'column' ], true ) ) {
				$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
				$text_error = TextIntegrity::first_violation( $settings, $element_path . '.settings' );
				if ( null !== $text_error ) {
					return self::tree_error( $text_error['path'], $text_error['code'], 'valid UTF-8 human text without stripped Unicode escapes or mojibake', $text_error['value'] );
				}
				$result = self::validate_container( $settings, $element_type, false, true );
				if ( $result instanceof \WP_Error ) {
					self::$last_error = $result;
					return false;
				}
			}
			if ( isset( $element['elements'] ) && ! is_array( $element['elements'] ) ) {
				return self::tree_error( $element_path . '.elements', 'invalid_children', 'an array of child Elementor elements', $element['elements'] );
			}
			$children = isset( $element['elements'] ) ? $element['elements'] : [];
			if ( ! self::validate_tree_nodes( $children, $element_path . '.elements', $seen_ids, $scope ) ) {
				return false;
			}
		}
		return true;
	}

	private static function tree_error( string $path, string $code, string $expected, mixed $got ): bool {
		self::$last_error = new \WP_Error(
			'stonewright_elementor_tree_invalid',
			__( 'Elementor tree structure is invalid.', 'stonewright' ),
			[
				'status'     => 400,
				'violations' => [ self::violation( $path, $code, $expected, $got ) ],
			]
		);
		return false;
	}

	public static function last_error(): ?\WP_Error {
		return self::$last_error;
	}

	/**
	 * @param array<string, array<string, mixed>> $controls Controls by key.
	 * @param list<array<string, mixed>>          $violations Violations output.
	 */
	private static function validate_bindings( string $key, mixed $value, array $controls, array &$violations ): void {
		if ( ! is_array( $value ) ) {
			$violations[] = self::violation( 'settings.' . $key, 'invalid_shape', 'an object keyed by live control names', $value );
			return;
		}
		foreach ( $value as $target => $binding ) {
			if ( ! isset( $controls[ (string) $target ] ) ) {
				$violations[] = self::violation( 'settings.' . $key . '.' . (string) $target, 'unknown_binding_target', 'a live control name', $binding, self::nearest_keys( (string) $target, array_keys( $controls ) ) );
			}
			if ( ! is_string( $binding ) && ! is_array( $binding ) ) {
				$violations[] = self::violation( 'settings.' . $key . '.' . (string) $target, 'invalid_binding', 'a dynamic tag/global binding string or object', $binding );
			}
		}
	}

	/**
	 * @param array<string, array<string, mixed>> $controls Controls by key.
	 */
	private static function control_key( string $key, array $controls ): ?string {
		if ( isset( $controls[ $key ] ) ) {
			return $key;
		}
		foreach ( [ '_widescreen', '_laptop', '_tablet_extra', '_tablet', '_mobile_extra', '_mobile' ] as $suffix ) {
			if ( ! str_ends_with( $key, $suffix ) ) {
				continue;
			}
			$base = substr( $key, 0, -strlen( $suffix ) );
			if ( isset( $controls[ $base ] ) && ! empty( $controls[ $base ]['responsive'] ) ) {
				return $base;
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $control Normalized control schema.
	 * @return array<string, mixed>|null
	 */
	private static function validate_value( mixed $value, array $control, string $path ): ?array {
		$type = strtolower( (string) ( $control['type'] ?? '' ) );
		if ( in_array( $type, [ 'select', 'choose', 'select2' ], true ) && is_scalar( $value ) && isset( $control['options'] ) && is_array( $control['options'] ) ) {
			$options = array_map( 'strval', array_keys( $control['options'] ) );
			if ( ! in_array( (string) $value, $options, true ) ) {
				return self::violation( $path, 'invalid_option', 'one of the live control options', $value, array_slice( $options, 0, 10 ) );
			}
		}
		if ( isset( $control['fields'] ) && is_array( $control['fields'] ) ) {
			if ( ! is_array( $value ) ) {
				return self::violation( $path, 'invalid_repeater', 'a list of repeater rows', $value );
			}
			foreach ( $value as $row_index => $row ) {
				if ( ! is_array( $row ) ) {
					return self::violation( $path . '.' . (string) $row_index, 'invalid_repeater_row', 'an object', $row );
				}
				foreach ( $row as $field_key => $field_value ) {
					if ( '_id' === $field_key ) {
						continue;
					}
					if ( ! isset( $control['fields'][ $field_key ] ) ) {
						return self::violation( $path . '.' . (string) $row_index . '.' . (string) $field_key, 'unknown_repeater_field', 'a field from the live repeater schema', $field_value, self::nearest_keys( (string) $field_key, array_keys( $control['fields'] ) ) );
					}
					$error = self::validate_value( $field_value, (array) $control['fields'][ $field_key ], $path . '.' . (string) $row_index . '.' . (string) $field_key );
					if ( null !== $error ) {
						return $error;
					}
				}
			}
			return null;
		}

		$valid = match ( $type ) {
			'number', 'slider'                         => self::valid_number_or_slider( $value ),
			'url'                                      => self::valid_url_value( $value ),
			'media', 'gallery'                         => self::valid_media_value( $value ),
			'switcher', 'select', 'choose', 'select2'  => is_scalar( $value ) || is_array( $value ),
			'color', 'text', 'textarea', 'wysiwyg', 'code', 'date_time', 'hidden' => is_scalar( $value ) || null === $value,
			'dimensions'                               => is_array( $value ) && self::only_keys( $value, [ 'top', 'right', 'bottom', 'left', 'unit', 'isLinked' ] ),
			default                                    => is_scalar( $value ) || is_array( $value ) || null === $value,
		};

		return $valid ? null : self::violation( $path, 'invalid_shape', 'a value compatible with Elementor control type ' . ( '' !== $type ? $type : 'unknown' ), $value );
	}

	/**
	 * @param array<string, mixed>                $condition Elementor condition map.
	 * @param array<string, mixed>                $settings Candidate settings.
	 * @param array<string, array<string, mixed>> $controls Live controls.
	 */
	private static function condition_is_active( array $condition, array $settings, array $controls ): bool {
		foreach ( $condition as $raw_key => $expected ) {
			if ( ! is_string( $raw_key ) ) {
				continue;
			}
			if ( '__unresolved__' === $raw_key || ( is_array( $expected ) && array_key_exists( '__unresolved__', $expected ) ) ) {
				continue;
			}
			$negated = str_ends_with( $raw_key, '!' );
			$key     = $negated ? substr( $raw_key, 0, -1 ) : $raw_key;
			$actual  = $settings[ $key ] ?? ( $controls[ $key ]['default'] ?? null );
			$matches = is_array( $expected )
				? in_array( $actual, $expected, true )
				: $actual === $expected || ( is_scalar( $actual ) && is_scalar( $expected ) && (string) $actual === (string) $expected );
			if ( $negated ? $matches : ! $matches ) {
				return false;
			}
		}
		return true;
	}

	private static function valid_url_value( mixed $value ): bool {
		if ( is_string( $value ) ) {
			return '' === $value || false !== filter_var( $value, FILTER_VALIDATE_URL ) || str_starts_with( $value, '#' ) || str_starts_with( $value, '/' );
		}
		if ( ! is_array( $value ) || ! isset( $value['url'] ) || ! is_string( $value['url'] ) ) {
			return false;
		}
		return self::valid_url_value( $value['url'] );
	}

	/** Numbers and Elementor slider objects, including the cleared sentinel. */
	private static function valid_number_or_slider( mixed $value ): bool {
		if ( is_numeric( $value ) ) {
			return true;
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( array_key_exists( 'size', $value ) ) {
			$size = $value['size'];
			return is_numeric( $size ) || '' === $size || null === $size;
		}
		return array_key_exists( 'sizes', $value ) && is_array( $value['sizes'] );
	}

	private static function valid_media_value( mixed $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( array_is_list( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! self::valid_media_value( $item ) ) {
					return false;
				}
			}
			return true;
		}
		return ( isset( $value['id'] ) && is_numeric( $value['id'] ) )
			|| ( isset( $value['url'] ) && is_string( $value['url'] ) );
	}

	/**
	 * @param array<string, mixed> $value Candidate object.
	 * @param list<string>         $allowed Allowed keys.
	 */
	private static function only_keys( array $value, array $allowed ): bool {
		return [] === array_diff( array_keys( $value ), $allowed );
	}

	private static function has_value( mixed $value ): bool {
		return null !== $value && '' !== $value;
	}

	/**
	 * @param list<string> $candidates Candidate control keys.
	 * @return list<string>
	 */
	private static function nearest_keys( string $needle, array $candidates ): array {
		$distances = [];
		foreach ( $candidates as $candidate ) {
			$distances[ $candidate ] = levenshtein( $needle, $candidate );
		}
		asort( $distances );
		return array_slice( array_keys( $distances ), 0, 3 );
	}

	/**
	 * @param list<string> $suggestions Exact repair hints.
	 * @return array<string, mixed>
	 */
	private static function violation( string $path, string $code, string $expected, mixed $got, array $suggestions = [] ): array {
		return [
			'path'        => $path,
			'code'        => $code,
			'expected'    => $expected,
			'got_type'    => get_debug_type( $got ),
			'suggestions' => $suggestions,
		];
	}
}
