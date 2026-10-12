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
	public static function validate( string $widget_type, array $settings, bool $require_render_settings = true, bool $enforce_conditions = true, bool $preserve_unknown = false, ?array $condition_settings = null ): array|\WP_Error {
		$schema = WidgetSchemaRepository::get( $widget_type );
		if ( $schema instanceof \WP_Error ) {
			return $schema;
		}
		$controls = (array) ( $schema['controls'] ?? [] );
		$aliases  = SettingsKeyAliases::normalize( $settings, $controls );
		$settings = $aliases['settings'];
		return self::validate_schema( $widget_type, $settings, $schema, $require_render_settings, $enforce_conditions, $aliases['applied'], $preserve_unknown, self::normalize_condition_settings( $condition_settings, $controls ) );
	}

	/**
	 * @param array<string, mixed> $settings Candidate structural-element settings.
	 * @return array{settings:array<string,mixed>,schema_hash:string,warnings:list<array<string,mixed>>}|\WP_Error
	 */
	public static function validate_container( array $settings, string $element_type = 'container', bool $enforce_conditions = true, bool $preserve_unknown = false, ?array $condition_settings = null ): array|\WP_Error {
		$schema = ContainerSchemaRepository::get( $element_type );
		if ( $schema instanceof \WP_Error ) {
			return $schema;
		}
		// A key that is a control of this element is never renamed: a legacy section has its own `gap`.
		$aliases  = SettingsKeyAliases::normalize( $settings, (array) ( $schema['controls'] ?? [] ) );
		$settings = $aliases['settings'];
		return self::validate_schema( $element_type, $settings, $schema, false, $enforce_conditions, $aliases['applied'], $preserve_unknown, self::normalize_condition_settings( $condition_settings ) );
	}

	/**
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $schema
	 * @return array{settings:array<string,mixed>,schema_hash:string,warnings:list<array<string,mixed>>}|\WP_Error
	 */
	private static function validate_schema( string $subject, array $settings, array $schema, bool $require_render_settings, bool $enforce_conditions, array $aliases = [], bool $preserve_unknown = false, ?array $condition_settings = null ): array|\WP_Error {

		$controls   = (array) ( $schema['controls'] ?? [] );
		$violations = [];
		$warnings   = [];
		$normalized = [];
		$condition_context = $condition_settings ?? $settings;
		$alias_of          = [];
		foreach ( $aliases as $alias ) {
			$alias_of[ (string) $alias['canonical'] ] = (string) $alias['alias'];
		}
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, [ '__dynamic__', '__globals__' ], true ) ) {
				self::validate_bindings( $key, $value, $controls, $violations );
				$normalized[ $key ] = $value;
				continue;
			}

			$expected_visibility = ResponsiveScope::expected_visibility_value( $key );
			if ( null !== $expected_visibility && ! ResponsiveScope::visibility_value_is_valid( $key, $value ) ) {
				$violations[] = self::violation(
					'settings.' . $key,
					'invalid_responsive_visibility_value',
					'an empty value or ' . $expected_visibility,
					$value,
					[ '', $expected_visibility ],
					isset( $controls[ $key ] ) ? (array) $controls[ $key ] : null,
					[ 'allowed_values' => [ '', $expected_visibility ], 'example' => [ $key => $expected_visibility ] ]
				);
				continue;
			}

			$control_key = self::control_key( $key, $controls );
			if ( null === $control_key ) {
				// P0 integrity: never strip unknown settings to "pass" validation.
				// Unknown keys are kept with a warning (Pro / runtime extras).
				if ( $preserve_unknown ) {
					$warnings[]         = self::unknown_violation( $key, $value, $controls, 'unknown_setting_preserved' );
					$normalized[ $key ] = $value;
					continue;
				}
				$violations[] = self::unknown_violation( $key, $value, $controls, 'unknown_setting' );
				continue;
			}

			$control = (array) $controls[ $control_key ];
			if ( 'column' === $subject && '_inline_size' === $control_key && ( null === $value || '' === $value ) ) {
				// Elementor stores a column without a custom width as an empty `_inline_size`.
				$normalized[ $key ] = $value;
				continue;
			}
			if ( 'boxed_width' === $control_key && [] === (array) ( $control['condition'] ?? [] ) ) {
				$control['condition'] = [ 'content_width' => 'boxed' ];
			}
			$sent_as = isset( $alias_of[ $key ] ) ? [ 'sent_as' => $alias_of[ $key ] ] : [];
			$coerced = self::coerced_value( $key, $control, $value );
			if ( $coerced !== $value ) {
				$warnings[] = [
					'code'     => 'value_coerced',
					'path'     => 'settings.' . $key,
					'from'     => get_debug_type( $value ),
					'to'       => get_debug_type( $coerced ),
					'policy'   => 'select_options_are_strings',
				];
				$value = $coerced;
			}
			$error   = self::validate_value( $value, $control, 'settings.' . $key );
			if ( null !== $error ) {
				$violations[] = $error + $sent_as;
				continue;
			}
			if ( $enforce_conditions ) {
				$condition   = self::without_own_key( (array) ( $control['condition'] ?? [] ), $control_key );
				$unsupported = [];
				if ( ! self::condition_is_active( $condition, $condition_context, $controls, $unsupported ) ) {
					$names        = ControlHints::condition_control_names( $condition );
					$requires     = ControlHints::requirements( $condition, $controls );
					$violations[] = ( [] === $unsupported
						? self::violation(
							'settings.' . $key,
							'inactive_condition',
							[] !== $requires
								? 'the control to be active: send ' . self::requirements_phrase( $requires ) . ' in the same call, or leave this setting out'
								: 'the control condition/activator to be satisfied',
							$value,
							$names,
							$control,
							[ 'requires' => $requires, 'example' => [] !== $requires ? $requires + [ $key => $value ] : null ]
						)
						: self::violation(
							'settings.' . $key,
							'unsupported_condition_operator',
							'a condition with a supported operator; the control stays inactive because the operator ' . implode( ', ', $unsupported ) . ' on ' . implode( ', ', $names ) . ' is not supported',
							$value,
							$names,
							$control
						) ) + $sent_as;
					continue;
				}
				foreach ( $unsupported as $operator ) {
					$warnings[] = self::violation( 'settings.' . $key, 'unsupported_condition_operator', 'a supported condition operator; ' . $operator . ' was not evaluated', $value );
				}
			}
			$normalized[ $key ] = $value;
		}

		foreach ( $require_render_settings ? (array) ( $schema['required_for_render'] ?? [] ) : [] as $required ) {
			$required = (string) $required;
			if ( ! array_key_exists( $required, $normalized ) || ! self::has_value( $normalized[ $required ] ) ) {
				$required_control = isset( $controls[ $required ] ) ? (array) $controls[ $required ] : null;
				$violations[]     = self::violation(
					'settings.' . $required,
					'required_missing',
					'a non-empty value',
					$normalized[ $required ] ?? null,
					[],
					$required_control,
					[ 'example' => null !== $required_control && null !== ControlHints::example_value( $required, $required_control ) ? [ $required => ControlHints::example_value( $required, $required_control ) ] : null ]
				);
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
				self::refusal_message( $subject, $violations ),
				[
					'status'      => 400,
					'widget_type' => $subject,
					'schema_hash' => (string) ( $schema['schema_hash'] ?? '' ),
					'violations'  => $violations,
					'rejected_total' => count( $violations ),
					'setting_path'   => $first_path,
					'expected_type'  => mb_substr( (string) ( $first['expected'] ?? '' ), 0, 200 ),
					'actual_type'    => (string) ( $first['got_type'] ?? '' ),
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
	 * @param list<string> $tolerated_root_widget_ids Ids of widgets the stored document already has at its root. A widget at the
	 *                                                root is a structure error whatever the scope, except for these.
	 */
	public static function validate_tree( array $tree, ?array $touched_ids = null, array $tolerated_root_widget_ids = [] ): bool {
		self::$last_error = null;
		$seen_ids         = [];
		$scope            = null === $touched_ids ? null : array_fill_keys( array_map( 'strval', $touched_ids ), true );
		$tolerated        = array_fill_keys( array_map( 'strval', $tolerated_root_widget_ids ), true );
		return self::validate_tree_nodes( $tree, 'root', $seen_ids, $scope, $tolerated );
	}

	/**
	 * @param array<int, mixed>        $tree
	 * @param array<string, true>      $seen_ids
	 * @param array<string, true>|null $scope
	 * @param array<string, true>      $tolerated_root_widgets Root widgets that are not refused, by id.
	 */
	private static function validate_tree_nodes( array $tree, string $path, array &$seen_ids, ?array $scope = null, array $tolerated_root_widgets = [] ): bool {
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
				if ( 'root' === $path && ! isset( $tolerated_root_widgets[ $id ] ) ) {
					return self::tree_error( $element_path, 'widget_at_root', 'a container, section or column at the document root; a widget belongs inside one', $element );
				}
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
	private static function validate_bindings( string $key, mixed $value, array $controls, array &$violations, string $base = 'settings' ): void {
		$path = $base . '.' . $key;
		if ( ! is_array( $value ) ) {
			$violations[] = self::violation( $path, 'invalid_shape', 'an object keyed by live control names', $value, [], null, [ 'example' => '__globals__' === $key ? [ $key => [ 'title_color' => 'globals/colors?id=primary' ] ] : null ] );
			return;
		}
		foreach ( $value as $target => $binding ) {
			$target_path = $path . '.' . (string) $target;
			if ( ! isset( $controls[ (string) $target ] ) ) {
				$violations[] = self::violation( $target_path, 'unknown_binding_target', 'a live control name', $binding, self::nearest_keys( (string) $target, array_keys( $controls ) ) );
			}
			if ( '__globals__' === $key ) {
				if ( ! CssValueGuard::is_global_reference( $binding ) ) {
					$violations[] = self::violation( $target_path, 'invalid_binding', 'a global reference such as globals/colors?id=primary, or an empty string', $binding, [], null, [ 'example' => [ $key => [ (string) $target => 'globals/colors?id=primary' ] ] ] );
				}
				continue;
			}
			if ( ! is_string( $binding ) && ! is_array( $binding ) ) {
				$violations[] = self::violation( $target_path, 'invalid_binding', 'a dynamic tag/global binding string or object', $binding );
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
			if ( isset( $controls[ $base ] ) && ResponsiveScope::control_is_responsive( (array) $controls[ $base ], $base ) ) {
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
		$type   = strtolower( (string) ( $control['type'] ?? '' ) );
		$fields = isset( $control['fields'] ) && is_array( $control['fields'] );
		// Elementor stores an unset control as the empty string, null or an empty list, so each of them means "not set"
		// for every control except a repeater, whatever its type and whichever options it lists.
		if ( ! $fields && ( null === $value || '' === $value || [] === $value ) ) {
			return null;
		}
		if ( in_array( $type, [ 'select', 'choose', 'select2' ], true ) && is_scalar( $value ) && isset( $control['options'] ) && is_array( $control['options'] ) ) {
			$options = array_map( 'strval', array_keys( $control['options'] ) );
			// A value the control no longer lists but still maps (its `selectors_dictionary`) renders as it did when it was stored.
			$mapped = isset( $control['selectors_dictionary'] ) && is_array( $control['selectors_dictionary'] ) && array_key_exists( (string) $value, $control['selectors_dictionary'] );
			if ( ! $mapped && ! in_array( (string) $value, $options, true ) ) {
				return self::violation( $path, 'invalid_option', 'one of the live control options', $value, array_slice( $options, 0, 10 ), $control );
			}
		}
		if ( $fields ) {
			if ( ! is_array( $value ) ) {
				return self::violation( $path, 'invalid_repeater', 'a list of repeater rows', $value, [], $control );
			}
			foreach ( $value as $row_index => $row ) {
				$row_path = $path . '.' . (string) $row_index;
				if ( ! is_array( $row ) ) {
					return self::violation( $row_path, 'invalid_repeater_row', 'an object', $row, [], $control );
				}
				foreach ( $row as $field_key => $field_value ) {
					if ( '_id' === $field_key ) {
						continue;
					}
					if ( in_array( $field_key, [ '__globals__', '__dynamic__' ], true ) ) {
						// A row binds its own fields to a global or a dynamic tag the way the element binds its controls.
						$bindings = [];
						self::validate_bindings( (string) $field_key, $field_value, (array) $control['fields'], $bindings, $row_path );
						if ( [] !== $bindings ) {
							return $bindings[0];
						}
						continue;
					}
					if ( ! isset( $control['fields'][ $field_key ] ) ) {
						return self::violation( $row_path . '.' . (string) $field_key, 'unknown_repeater_field', 'a field from the live repeater schema', $field_value, self::nearest_keys( (string) $field_key, array_keys( $control['fields'] ) ), null, [ 'allowed_values' => array_slice( array_map( 'strval', array_keys( $control['fields'] ) ), 0, 30 ) ] );
					}
					$error = self::validate_value( $field_value, (array) $control['fields'][ $field_key ], $row_path . '.' . (string) $field_key );
					if ( null !== $error ) {
						return $error;
					}
				}
			}
			return null;
		}

		$typography = CssValueGuard::typography_field_is_valid( (string) ( $control['key'] ?? '' ), $value );
		if ( false === $typography ) {
			return self::violation( $path, 'invalid_typography_value', CssValueGuard::typography_expected( (string) $control['key'] ), $value, [], $control );
		}

		$valid = match ( $type ) {
			'number'                                   => self::valid_number_or_slider( $value ),
			'slider'                                   => self::valid_number_or_slider( $value ) && ( ! is_array( $value ) || CssValueGuard::slider_parts_safe( $value ) ),
			'url'                                      => self::valid_url_value( $value ),
			'media', 'gallery'                         => self::valid_media_value( $value ),
			'switcher', 'select', 'choose', 'select2'  => is_scalar( $value ) || is_array( $value ),
			'color'                                    => CssValueGuard::is_color( $value ),
			'font'                                     => CssValueGuard::is_font_family( $value ),
			'text', 'textarea', 'wysiwyg', 'code', 'date_time', 'hidden' => is_scalar( $value ) || null === $value,
			'dimensions'                               => is_array( $value ) && self::only_keys( $value, [ 'top', 'right', 'bottom', 'left', 'unit', 'isLinked' ] ) && CssValueGuard::dimensions_parts_safe( $value ),
			'box_shadow'                               => CssValueGuard::is_shadow( $value, true ),
			'text_shadow'                              => CssValueGuard::is_shadow( $value, false ),
			default                                    => is_scalar( $value ) || is_array( $value ) || null === $value,
		};
		if ( $valid ) {
			return null;
		}

		$expected = match ( $type ) {
			'color'                                    => CssValueGuard::COLOR_EXPECTED,
			'font'                                     => CssValueGuard::typography_expected( 'font_family' ),
			'slider'                                   => is_array( $value ) && ( array_key_exists( 'size', $value ) || array_key_exists( 'sizes', $value ) || array_key_exists( 'unit', $value ) )
				? 'a number or an object with numeric size and a unit from the supported list (the custom unit takes a CSS length expression such as calc(100% - 20px))'
				: 'a value compatible with Elementor control type slider',
			'dimensions'                               => 'an object with numeric sides and a unit from the supported list',
			'box_shadow', 'text_shadow'                => 'a shadow object with numeric geometry and a real colour',
			'url'                                      => 'a link: a URL string, or an object with a url (not a script scheme), is_external, nofollow and custom_attributes',
			default                                    => 'a value compatible with Elementor control type ' . ( '' !== $type ? $type : 'unknown' ),
		};
		return self::violation( $path, 'invalid_shape', $expected, $value, [], $control );
	}

	/**
	 * The value the control stores for what was sent, when the two differ only in type: a number sent for a select whose
	 * options are numeric strings (a font weight, a column span) is the string the editor stores.
	 *
	 * @param array<string, mixed> $control
	 */
	private static function coerced_value( string $key, array $control, mixed $value ): mixed {
		if ( ! is_int( $value ) && ! ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value && abs( $value ) < 1.0e9 ) ) {
			return $value;
		}
		$type = strtolower( (string) ( $control['type'] ?? '' ) );
		if ( 1 === preg_match( '/(?:^|_)font_weight$/', (string) ( $control['key'] ?? $key ) ) ) {
			return (string) (int) $value;
		}
		if ( in_array( $type, [ 'select', 'choose' ], true ) && isset( $control['options'] ) && is_array( $control['options'] ) ) {
			$options = array_map( 'strval', array_keys( $control['options'] ) );
			if ( in_array( (string) (int) $value, $options, true ) ) {
				return (string) (int) $value;
			}
		}

		return $value;
	}

	/**
	 * A condition without the parts that name the control itself. Elementor hides a few controls "unless in use" with a
	 * condition on their own key (`size!` => `default`); such a control is not hidden by another setting, so it never
	 * makes the value inactive.
	 *
	 * @param array<string, mixed> $condition
	 * @return array<string, mixed>
	 */
	private static function without_own_key( array $condition, string $key ): array {
		if ( isset( $condition['terms'] ) && is_array( $condition['terms'] ) ) {
			$terms = [];
			foreach ( $condition['terms'] as $term ) {
				if ( is_array( $term ) && isset( $term['terms'] ) && is_array( $term['terms'] ) ) {
					$inner = self::without_own_key( $term, $key );
					if ( [] !== $inner ) {
						$terms[] = $inner;
					}
					continue;
				}
				$name = is_array( $term ) && is_string( $term['name'] ?? null ) ? (string) preg_replace( '/\[.*$/', '', $term['name'] ) : '';
				if ( $name !== $key ) {
					$terms[] = $term;
				}
			}

			return [] === $terms ? [] : array_merge( $condition, [ 'terms' => $terms ] );
		}
		$out = [];
		foreach ( $condition as $raw => $expected ) {
			$name = is_string( $raw ) ? (string) preg_replace( '/\[.*$/', '', str_ends_with( $raw, '!' ) ? substr( $raw, 0, -1 ) : $raw ) : '';
			if ( $name === $key ) {
				continue;
			}
			$out[ $raw ] = $expected;
		}

		return $out;
	}

	/** @param array<string, mixed> $requires */
	private static function requirements_phrase( array $requires ): string {
		$parts = [];
		foreach ( $requires as $name => $value ) {
			$parts[] = (string) $name . '=' . ( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) );
		}

		return implode( ' and ', $parts );
	}

	/**
	 * A setting key that is not a control of the element: the closest real keys, and what the key looks like when it
	 * is a mistake that has a known repair (a breakpoint variant of a control that is not responsive, a control group
	 * sent as an object).
	 *
	 * @param array<string, array<string, mixed>> $controls
	 * @return array<string, mixed>
	 */
	private static function unknown_violation( string $key, mixed $value, array $controls, string $code ): array {
		$suggestions = self::nearest_keys( $key, array_keys( $controls ) );
		$extra       = [];
		foreach ( [ '_widescreen', '_laptop', '_tablet_extra', '_tablet', '_mobile_extra', '_mobile' ] as $suffix ) {
			if ( ! str_ends_with( $key, $suffix ) ) {
				continue;
			}
			$base = substr( $key, 0, -strlen( $suffix ) );
			if ( isset( $controls[ $base ] ) ) {
				$suggestions   = array_values( array_unique( array_merge( [ $base ], $suggestions ) ) );
				$extra['hint'] = sprintf( 'The control "%1$s" is not responsive, so it has no %2$s variant. Set %1$s itself.', $base, $suffix );
				$extra['example'] = [ $base => $value ];
			}
			break;
		}
		if ( ! isset( $extra['hint'] ) && is_array( $value ) ) {
			$group = ControlHints::group_keys( $key, $controls );
			if ( [] !== $group ) {
				$extra['hint']          = sprintf( '"%1$s" is a control group, not a setting: send its controls as flat keys of the same settings object, for example %2$s.', $key, implode( ', ', array_slice( $group, 0, 4 ) ) );
				$extra['group_controls'] = $group;
				$suggestions            = array_values( array_unique( array_merge( array_slice( $group, 0, 3 ), $suggestions ) ) );
			}
		}
		if ( ! isset( $extra['example'] ) && [] !== $suggestions && ! is_array( $value ) ) {
			$nearest = (string) $suggestions[0];
			if ( levenshtein( $key, $nearest ) <= max( 2, intdiv( strlen( $key ), 4 ) ) ) {
				$extra['example'] = [ $nearest => $value ];
			}
		}

		return self::violation( 'settings.' . $key, $code, 'a key from the live widget schema', $value, $suggestions, null, $extra );
	}

	/**
	 * The words of a refusal: the first rejected settings with what each expected and what it got, and for a single
	 * rejected setting what the control takes and an example that would be accepted.
	 *
	 * @param list<array<string, mixed>> $violations
	 */
	private static function refusal_message( string $subject, array $violations ): string {
		$phrase = static function ( array $violation ): string {
			$received = is_array( $violation['received_value'] ?? null ) ? $violation['received_value'] : [];
			$type     = (string) ( $received['type'] ?? $violation['got_type'] ?? 'unknown' );
			if ( [] === $received || in_array( $type, [ 'array', 'object' ], true ) || str_starts_with( $type, 'class@' ) ) {
				return $type;
			}
			$preview = (string) ( $received['preview'] ?? '' );
			if ( in_array( $type, [ 'null', 'bool' ], true ) ) {
				return $type . ' ' . $preview;
			}

			return $type . ' "' . ( mb_strlen( $preview ) > 40 ? mb_substr( $preview, 0, 39 ) . '…' : $preview ) . '"';
		};
		$first = $violations[0];
		$count = count( $violations );
		if ( 1 === $count ) {
			$message = sprintf(
				/* translators: 1: setting path, 2: expected shape, 3: received value (type and a short preview) */
				__( 'Elementor setting %1$s rejected: expected %2$s; received %3$s.', 'stonewright' ),
				(string) ( $first['path'] ?? '' ),
				(string) ( $first['expected'] ?? 'a value accepted by the live widget schema' ),
				$phrase( $first )
			);
			if ( is_array( $first['allowed_values'] ?? null ) && [] !== $first['allowed_values'] && 'unknown_repeater_field' !== ( $first['code'] ?? '' ) ) {
				$allowed  = array_slice( array_map( 'strval', $first['allowed_values'] ), 0, 12 );
				$message .= ' Allowed: ' . implode( ', ', array_map( static fn( string $item ): string => '' === trim( $item ) ? '"' . $item . '"' : $item, $allowed ) ) . ( count( $first['allowed_values'] ) > 12 ? ', …' : '' ) . '.';
			}
			if ( is_array( $first['control']['units'] ?? null ) && [] !== $first['control']['units'] && in_array( $first['code'] ?? '', [ 'invalid_shape', 'invalid_typography_value' ], true ) ) {
				$message .= ' Units: ' . implode( ', ', array_map( 'strval', $first['control']['units'] ) ) . '.';
			}
			if ( '' !== (string) ( $first['hint'] ?? '' ) ) {
				$message .= ' ' . (string) $first['hint'];
			}
			if ( is_array( $first['suggestions'] ?? null ) && [] !== $first['suggestions'] && in_array( $first['code'] ?? '', [ 'unknown_setting', 'unknown_setting_preserved', 'unknown_binding_target', 'unknown_repeater_field' ], true ) ) {
				$message .= ' Closest: ' . implode( ', ', array_map( 'strval', array_slice( $first['suggestions'], 0, 3 ) ) ) . '.';
			}
			if ( is_array( $first['example'] ?? null ) ) {
				$message .= ' Example: ' . (string) wp_json_encode( $first['example'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '.';
			}

			return $message;
		}

		$parts = [];
		foreach ( array_slice( $violations, 0, 3 ) as $violation ) {
			$parts[] = sprintf( '%1$s (expected %2$s; received %3$s)', (string) ( $violation['path'] ?? '' ), (string) ( $violation['expected'] ?? 'a value accepted by the live widget schema' ), $phrase( $violation ) );
		}

		return sprintf(
			/* translators: 1: number of rejected settings, 2: element or widget type, 3: the first rejected settings */
			__( 'Elementor rejected %1$d settings of %2$s: %3$s%4$s. Each rejected setting is listed in violations with its control, allowed values and an example.', 'stonewright' ),
			$count,
			$subject,
			implode( ' | ', $parts ),
			$count > 3 ? sprintf( ' | and %d more', $count - 3 ) : ''
		);
	}

	/**
	 * @param array<string, mixed>|null $condition_settings Optional merge-context for control conditions.
	 * @return array<string, mixed>|null
	 */
	private static function normalize_condition_settings( ?array $condition_settings, ?array $controls = null ): ?array {
		if ( null === $condition_settings ) {
			return null;
		}
		$aliases = SettingsKeyAliases::normalize( $condition_settings, $controls );
		return $aliases['settings'];
	}

	/**
	 * @param array<string, mixed>                $condition Elementor condition map.
	 * @param array<string, mixed>                $settings Candidate settings.
	 * @param array<string, array<string, mixed>> $controls Live controls.
	 */
	private static function condition_is_active( array $condition, array $settings, array $controls, array &$unsupported = [] ): bool {
		if ( self::is_terms_condition( $condition ) ) {
			return self::terms_are_satisfied( $condition, $settings, $controls, $unsupported );
		}
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
			if ( is_array( $expected ) ) {
				$matches = in_array( $actual, $expected, true );
			} elseif ( is_array( $actual ) && [] !== $actual ) {
				// A multiple-value control (select2) is met when its value list contains the expected one.
				$matches = in_array( $expected, $actual, true );
			} else {
				$matches = $actual === $expected || ( is_scalar( $actual ) && is_scalar( $expected ) && (string) $actual === (string) $expected );
			}
			if ( $negated ? $matches : ! $matches ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a condition is the `relation` / `terms` form rather than the flat `{control: value}` map.
	 *
	 * @param array<string, mixed> $condition Elementor condition.
	 */
	private static function is_terms_condition( array $condition ): bool {
		if ( ! isset( $condition['terms'] ) || ! is_array( $condition['terms'] ) || ! array_is_list( $condition['terms'] ) ) {
			return false;
		}
		foreach ( $condition['terms'] as $term ) {
			if ( ! is_array( $term ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Evaluates a `relation` / `terms` group: `and` (the default) needs every term, `or`
	 * needs one, and a term may itself be a nested group. A term with an operator that is
	 * not defined counts as not satisfied and is listed in $unsupported.
	 *
	 * @param array<string, mixed>                $group Condition group with `terms`.
	 * @param array<string, mixed>                $settings Candidate settings.
	 * @param array<string, array<string, mixed>> $controls Live controls.
	 * @param list<string>                        $unsupported Collects unsupported operators.
	 */
	private static function terms_are_satisfied( array $group, array $settings, array $controls, array &$unsupported ): bool {
		$is_or     = 'or' === ( $group['relation'] ?? null );
		$satisfied = ! $is_or;
		foreach ( (array) $group['terms'] as $term ) {
			$term = (array) $term;
			if ( ! empty( $term['terms'] ) && is_array( $term['terms'] ) ) {
				$result = self::terms_are_satisfied( $term, $settings, $controls, $unsupported );
			} else {
				$result = self::term_is_satisfied( $term, $settings, $controls, $unsupported );
			}
			if ( $is_or && $result ) {
				$satisfied = true;
			} elseif ( ! $is_or && ! $result ) {
				$satisfied = false;
			}
		}
		return $satisfied;
	}

	/**
	 * @param array<string, mixed>                $term One `{name, operator, value}` term.
	 * @param array<string, mixed>                $settings Candidate settings.
	 * @param array<string, array<string, mixed>> $controls Live controls.
	 * @param list<string>                        $unsupported Collects unsupported operators.
	 */
	private static function term_is_satisfied( array $term, array $settings, array $controls, array &$unsupported ): bool {
		$name = is_string( $term['name'] ?? null ) ? $term['name'] : '';
		if ( 1 !== preg_match( '/(\w+)(?:\[(\w+)])?/', $name, $parts ) ) {
			return false;
		}
		if ( array_key_exists( $parts[1], $settings ) ) {
			$actual = $settings[ $parts[1] ];
		} elseif ( isset( $controls[ $parts[1] ] ) ) {
			// A control that is not sent has its default; data controls without one default to ''.
			$actual = $controls[ $parts[1] ]['default'] ?? '';
		} else {
			$actual = null;
		}
		if ( isset( $parts[2] ) && '' !== $parts[2] ) {
			$actual = is_array( $actual ) ? ( $actual[ $parts[2] ] ?? null ) : null;
		}
		$expected = $term['value'] ?? null;
		$operator = isset( $term['operator'] ) && is_string( $term['operator'] ) ? $term['operator'] : '';

		// phpcs:disable Universal.Operators.StrictComparisons.LooseEqual, Universal.Operators.StrictComparisons.LooseNotEqual -- the `==` and `!=` operators compare loosely.
		switch ( $operator ) {
			case '':
			case '===':
				return $actual === $expected;
			case '!==':
				return $actual !== $expected;
			case '==':
				return $actual == $expected;
			case '!=':
				return $actual != $expected;
			case 'in':
				return is_array( $expected ) && in_array( $actual, $expected, true );
			case '!in':
				return ! is_array( $expected ) || ! in_array( $actual, $expected, true );
			case 'contains':
				return is_array( $actual ) && in_array( $expected, $actual, true );
			case '!contains':
				return ! is_array( $actual ) || ! in_array( $expected, $actual, true );
			case '<':
				return $actual < $expected;
			case '<=':
				return $actual <= $expected;
			case '>':
				return $actual > $expected;
			case '>=':
				return $actual >= $expected;
		}
		// phpcs:enable
		if ( ! in_array( $operator, $unsupported, true ) ) {
			$unsupported[] = $operator;
		}
		return false;
	}

	/**
	 * A link: a URL string, or an object with a `url` and the link options. The target may be absolute with any scheme
	 * that is not a script scheme (https, mailto, tel and app links), a path, a query or an anchor. An object may
	 * carry only the options being changed, since a patch is checked before it is merged with the stored link.
	 */
	private static function valid_url_value( mixed $value ): bool {
		if ( is_string( $value ) ) {
			return self::valid_link_target( $value );
		}
		if ( ! is_array( $value ) || [] === $value || array_is_list( $value ) ) {
			return false;
		}
		if ( array_key_exists( 'url', $value ) ) {
			return is_string( $value['url'] ) && self::valid_link_target( $value['url'] );
		}

		return [] !== array_intersect_key( $value, array_flip( [ 'is_external', 'nofollow', 'custom_attributes' ] ) );
	}

	private static function valid_link_target( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url ) {
			return true;
		}
		// A control character or a space never belongs in a link, and a tag or quote would end the attribute it is printed in.
		if ( 1 === preg_match( '/[\x00-\x20\x7f<>"]/', $url ) ) {
			return false;
		}

		return 1 !== preg_match( '/^(?:javascript|vbscript|data|file|blob):/i', $url );
	}

	/** Numbers and Elementor slider objects, including the cleared sentinel and an object that carries only a unit. */
	private static function valid_number_or_slider( mixed $value ): bool {
		if ( is_numeric( $value ) ) {
			return true;
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( array_key_exists( 'size', $value ) ) {
			$size = $value['size'];
			// The custom unit holds a CSS length expression instead of a number; its text is checked with the other parts.
			return is_numeric( $size ) || '' === $size || null === $size || ( is_string( $size ) && CssValueGuard::is_custom_unit( $value ) );
		}

		return array_key_exists( 'sizes', $value ) ? is_array( $value['sizes'] ) : array_key_exists( 'unit', $value );
	}

	/** An image or file object (id and url, either may be empty), or a list of them for a gallery. */
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
		if ( ! array_key_exists( 'id', $value ) && ! array_key_exists( 'url', $value ) ) {
			return false;
		}
		$id  = $value['id'] ?? null;
		$url = $value['url'] ?? null;

		return ( null === $id || '' === $id || is_numeric( $id ) ) && ( null === $url || is_string( $url ) );
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

	/** Codes of a value that was sent for a real control and is wrong; they get an example of a valid value. */
	private const VALUE_CODES = [ 'invalid_shape', 'invalid_option', 'invalid_typography_value', 'invalid_repeater', 'invalid_repeater_row', 'invalid_responsive_visibility_value' ];

	/**
	 * One rejected setting, with everything a caller needs to correct it: the setting, what was received (type and a
	 * short redacted preview), the control that refused it, what that control accepts and an example that would pass.
	 *
	 * @param list<string>              $suggestions Exact repair hints.
	 * @param array<string, mixed>|null $control     Normalized control the value was checked against.
	 * @param array<string, mixed>      $extra       Further fields; a null value is left out.
	 * @return array<string, mixed>
	 */
	private static function violation( string $path, string $code, string $expected, mixed $got, array $suggestions = [], ?array $control = null, array $extra = [] ): array {
		$parts   = explode( '.', (string) preg_replace( '/^settings\./', '', $path ) );
		$setting = (string) ( $parts[0] ?? '' );
		$out     = [
			'path'           => $path,
			'code'           => $code,
			'expected'       => $expected,
			'got_type'       => get_debug_type( $got ),
			'suggestions'    => $suggestions,
			'setting'        => $setting,
			'received_value' => ControlHints::received( $got ),
		];
		if ( null !== $control ) {
			$key          = (string) ( $control['key'] ?? $setting );
			$out['control'] = ControlHints::control( $key, $control );
			$allowed      = ControlHints::allowed_values( $control );
			if ( [] !== $allowed ) {
				$out['allowed_values'] = $allowed;
			}
			if ( in_array( $code, self::VALUE_CODES, true ) && ! array_key_exists( 'example', $extra ) ) {
				$example = ControlHints::example_value( $key, $control );
				if ( null !== $example ) {
					$extra['example'] = self::nest_example( $parts, $example );
				}
			}
		}
		foreach ( $extra as $name => $item ) {
			if ( null !== $item ) {
				$out[ $name ] = $item;
			}
		}
		if ( isset( $out['example'] ) && strlen( (string) wp_json_encode( $out['example'] ) ) > 600 ) {
			unset( $out['example'] );
		}

		return $out;
	}

	/**
	 * An example value placed where the rejected value was sent: the setting itself, or the field of a repeater row.
	 *
	 * @param list<string> $parts Path parts after `settings.`.
	 * @return array<string, mixed>
	 */
	private static function nest_example( array $parts, mixed $value ): array {
		$node = $value;
		for ( $i = count( $parts ) - 1; $i >= 1; --$i ) {
			$node = ctype_digit( $parts[ $i ] ) ? [ $node ] : [ $parts[ $i ] => $node ];
		}

		return [ (string) $parts[0] => $node ];
	}
}
