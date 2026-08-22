<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\DesignSpec;

use Stonewright\WpMcp\Design\Motion\MotionPresetRegistry;
use Stonewright\WpMcp\Design\Semantics\ActionValidator;
use Stonewright\WpMcp\Security\RuleEnforcer;

/**
 * Validates Stonewright Design Specs against the bundled JSON Schema.
 *
 * Uses opis/json-schema when available (composer dependency); otherwise falls
 * back to a hand-rolled structural check sufficient to keep bad payloads from
 * reaching renderers without requiring the dependency at runtime.
 */
final class Validator {

	public const SCHEMA_ID = 'https://stonewright.dev/schemas/design-spec/1.0.0.json';

	public const SCHEMA_ID_V2 = 'https://stonewright.dev/schemas/design-spec/2.0.0.json';

	/**
	 * Validates and normalizes a design spec.
	 *
	 * @param array<string, mixed> $spec
	 * @return array<string, mixed>|\WP_Error Normalized spec on success; WP_Error with code
	 *         stonewright_spec_invalid on failure.
	 */
	public static function validate( array $spec ) {
		$errors     = [];
		$normalized = self::normalize( $spec );
		$is_v2      = self::is_v2( $normalized );
		$schema_id  = $is_v2 ? self::SCHEMA_ID_V2 : self::SCHEMA_ID;

		if ( class_exists( '\\Opis\\JsonSchema\\Validator' ) ) {
			try {
				$validator = new \Opis\JsonSchema\Validator();
				$resolver  = $validator->resolver();
				$schema    = $is_v2 ? self::load_schema_object_v2() : self::load_schema_object();
				if ( null !== $schema && null !== $resolver ) {
					$resolver->registerRaw( $schema, $schema_id );
				}
				$result = $validator->validate( json_decode( wp_json_encode( $normalized ) ), $schema_id );
				if ( ! $result->isValid() ) {
					// Flatten nested wrappers (items/anyOf/…) down to leaf
					// errors so precise keywords like "required" surface with
					// their real path instead of a broad parent aggregate.
					foreach ( self::flatten_schema_errors( $result->error() ) as $error ) {
						$errors[] = $error;
					}
				}
			} catch ( \Throwable $e ) {
				$errors[] = [ 'keyword' => 'exception', 'message' => $e->getMessage(), 'path' => [] ];
			}
		} else {
			$errors = self::structural_check( $normalized );
		}

		$errors = array_merge( self::repair_checks( $normalized ), $errors );
		$errors = array_merge( self::native_policy_checks( $normalized ), $errors );
		$errors = array_merge( self::motion_checks( $normalized ), $errors );
		foreach ( ActionValidator::validate_design_spec( $normalized ) as $diagnostic ) {
			$errors[] = [
				'keyword' => (string) ( $diagnostic['code'] ?? 'semantic' ),
				'message' => (string) ( $diagnostic['repair'] ?? 'Resolve the semantic design error.' ),
				'path'    => self::parse_path( (string) ( $diagnostic['path'] ?? '' ) ),
			];
		}
		$errors = self::enrich_errors( $errors, $normalized );

		if ( ! empty( $errors ) ) {
			return self::invalid( $errors );
		}

		$style_errors = StyleFidelityGuard::validate( $normalized );
		if ( ! empty( $style_errors ) ) {
			return self::invalid( $style_errors );
		}

		return $normalized;
	}

	/**
	 * Flattens an Opis error tree into leaf diagnostics.
	 *
	 * Wrapper keywords (items, anyOf, oneOf, allOf, properties…) aggregate
	 * child errors; a root-level violation such as additionalProperties:false
	 * carries no sub-errors at all (subErrors() returns [] rather than null).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function flatten_schema_errors( object $error ): array {
		$sub_errors = $error->subErrors();
		if ( null === $sub_errors || [] === $sub_errors ) {
			return [
				[
					'keyword' => $error->keyword(),
					'message' => $error->message(),
					'path'    => $error->data()->path(),
				],
			];
		}

		$out = [];
		foreach ( $sub_errors as $sub_error ) {
			if ( ! $sub_error ) {
				continue;
			}
			$out = array_merge( $out, self::flatten_schema_errors( $sub_error ) );
		}
		return $out;
	}

	/**
	 * Rejection carrying the native rule that requires validation before render.
	 *
	 * @param array<int|string, mixed> $errors Collected validation errors.
	 */
	private static function invalid( array $errors ): \WP_Error {
		return RuleEnforcer::attribute(
			new \WP_Error(
				'stonewright_spec_invalid',
				'Design spec failed validation.',
				[ 'errors' => $errors ]
			),
			'validate-spec-before-render'
		);
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private static function is_v2( array $spec ): bool {
		$version = isset( $spec['version'] ) ? (string) $spec['version'] : '1.0.0';
		return str_starts_with( $version, '2.' );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function load_schema(): ?array {
		$path = STONEWRIGHT_DIR . 'schemas/stonewright.schema.json';
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Returns the schema as a stdClass tree so Opis can register it correctly.
	 */
	private static function load_schema_object(): ?\stdClass {
		return self::load_schema_object_from( STONEWRIGHT_DIR . 'schemas/stonewright.schema.json' );
	}

	/**
	 * DesignSpec v2 schema (optional progressive keys + native policy).
	 */
	private static function load_schema_object_v2(): ?\stdClass {
		return self::load_schema_object_from( STONEWRIGHT_DIR . 'schemas/stonewright.schema.v2.json' );
	}

	private static function load_schema_object_from( string $path ): ?\stdClass {
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw );
		return $decoded instanceof \stdClass ? $decoded : null;
	}

	/**
	 * Native policy gates for blueprint/render paths.
	 *
	 * When native_policy.strict is true:
	 * - HTML / Custom HTML widgets are blocked
	 * - custom_css requires a structured native_gap reason
	 * - heading hierarchy soft-checks (H1→H2→H3 order) become errors
	 *
	 * @param array<string, mixed> $spec
	 * @return array<int, array<string, mixed>>
	 */
	public static function native_policy_checks( array $spec ): array {
		$policy = isset( $spec['native_policy'] ) && is_array( $spec['native_policy'] )
			? $spec['native_policy']
			: [];
		$strict = ! empty( $policy['strict'] );
		if ( ! $strict && empty( $policy ) ) {
			// No policy declared — only soft-enforce nothing; keep v1 behavior.
			return [];
		}

		$block_html     = $strict || ! array_key_exists( 'block_html_widgets', $policy ) || ! empty( $policy['block_html_widgets'] );
		$require_gap    = $strict || ! array_key_exists( 'require_native_gap_for_custom_css', $policy ) || ! empty( $policy['require_native_gap_for_custom_css'] );
		$heading_check  = $strict || ! empty( $policy['enforce_heading_hierarchy'] );
		$errors         = [];
		$blocked_types  = [ 'html', 'html-widget', 'custom-html', 'raw-html', 'html_widget' ];
		$heading_levels = [];

		foreach ( (array) ( $spec['sections'] ?? [] ) as $si => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			foreach ( (array) ( $section['blocks'] ?? [] ) as $bi => $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				$type = strtolower( (string) ( $block['type'] ?? '' ) );
				$path = [ 'sections', $si, 'blocks', $bi ];

				if ( $block_html && in_array( $type, $blocked_types, true ) ) {
					$errors[] = [
						'keyword' => 'native_policy_html_widget',
						'message' => 'Native policy blocks HTML / Custom HTML widgets in the blueprint render path. Use native heading, paragraph, button, or container blocks.',
						'path'    => array_merge( $path, [ 'type' ] ),
					];
				}

				$custom_css = (string) ( $block['custom_css'] ?? $block['customCSS'] ?? '' );
				if ( $require_gap && '' !== trim( $custom_css ) ) {
					$gap = $block['native_gap'] ?? null;
					$reason = is_array( $gap ) ? trim( (string) ( $gap['reason'] ?? '' ) ) : '';
					if ( '' === $reason ) {
						$errors[] = [
							'keyword' => 'native_policy_custom_css',
							'message' => 'custom_css requires a structured native_gap.reason explaining why no native control covers the need.',
							'path'    => array_merge( $path, [ 'custom_css' ] ),
						];
					}
				}

				if ( 'heading' === $type && isset( $block['level'] ) ) {
					$heading_levels[] = [
						'level' => (int) $block['level'],
						'path'  => array_merge( $path, [ 'level' ] ),
					];
				}
			}
		}

		if ( $heading_check && count( $heading_levels ) > 1 ) {
			$prev = $heading_levels[0]['level'];
			foreach ( array_slice( $heading_levels, 1 ) as $entry ) {
				$level = (int) $entry['level'];
				// Soft hierarchy: do not skip more than one level downward (e.g. H1 → H3).
				if ( $level > $prev + 1 ) {
					$errors[] = [
						'keyword' => 'native_policy_heading_hierarchy',
						'message' => sprintf(
							'Heading hierarchy jump from h%d to h%d is not allowed under native policy. Use sequential heading levels.',
							$prev,
							$level
						),
						'path'    => $entry['path'],
					];
				}
				$prev = $level;
			}
		}

		return $errors;
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return array<string, mixed>
	 */
	public static function normalize( array $spec ): array {
		$version = isset( $spec['version'] ) ? (string) $spec['version'] : '1.0.0';
		// Accept 1.x and 2.x; unknown → 1.0.0 for backward compatibility.
		if ( ! preg_match( '/^[12]\./', $version ) ) {
			$version = '1.0.0';
		}
		$spec['version']  = $version;
		$spec['page']     = isset( $spec['page'] ) && is_array( $spec['page'] ) ? $spec['page'] : [];
		$spec['sections'] = isset( $spec['sections'] ) && is_array( $spec['sections'] ) ? array_values( $spec['sections'] ) : [];

		if ( isset( $spec['tokens'] ) && is_array( $spec['tokens'] ) && ! empty( $spec['tokens'] ) ) {
			// keep provided tokens
		} else {
			unset( $spec['tokens'] );
		}

		foreach ( $spec['sections'] as $i => $section ) {
			$section          = is_array( $section ) ? $section : [];
			$section['id']    = isset( $section['id'] ) ? (string) $section['id'] : 'section_' . $i;
			$section['blocks'] = isset( $section['blocks'] ) && is_array( $section['blocks'] ) ? array_values( $section['blocks'] ) : [];
			$spec['sections'][ $i ] = $section;
		}

		return $spec;
	}

	/**
	 * Semantic motion checks that JSON Schema cannot express: target
	 * resolution, global ID uniqueness, hover/focus parity, loop control
	 * requirements, provider engine identity, and stagger span arithmetic.
	 *
	 * Motion absent from a spec is fully compatible with legacy payloads.
	 *
	 * @param array<string, mixed> $spec
	 * @return array<int, array<string, mixed>>
	 */
	public static function motion_checks( array $spec ): array {
		$items = self::motion_items( $spec );
		if ( [] === $items ) {
			return [];
		}

		$errors       = [];
		$seen_ids     = [];
		$hover_pairs  = [];

		foreach ( $items as $entry ) {
			/** @var array<string, mixed> $item */
			$item = $entry['item'];
			$path = $entry['path'];
			$id   = (string) ( $item['id'] ?? '' );

			if ( isset( $seen_ids[ $id ] ) ) {
				$errors[] = self::motion_error( 'motion_duplicate_id', 'Motion id "' . $id . '" is declared more than once; motion ids are globally unique in the document.', $path );
			}
			$seen_ids[ $id ] = true;

			// Effect slugs must exist in the versioned preset registry —
			// never free text, never an unknown slug.
			$effect = (string) ( $item['effect'] ?? '' );
			if ( ! MotionPresetRegistry::has( $effect ) ) {
				$errors[] = self::motion_error( 'motion_effect_unknown', 'Effect "' . $effect . '" is not in the versioned MotionPresetRegistry; use one of: ' . implode( ', ', MotionPresetRegistry::slugs() ) . '.', $path );
			}

			// The orchestration preset carries its own stagger configuration.
			if ( 'stagger-reveal' === $effect && empty( $item['stagger'] ) ) {
				$errors[] = self::motion_error( 'motion_stagger_required', 'Effect stagger-reveal is an orchestration preset and requires a stagger configuration.', $path );
			}

			// Target resolution: zero or ambiguous matches are invalid.
			$matches = self::motion_target_matches( $spec, $entry );
			if ( 0 === count( $matches ) ) {
				$errors[] = self::motion_error( 'motion_target_missing', 'Motion "' . $id . '" targets "' . (string) ( $item['target_id'] ?? '' ) . '" which does not resolve inside its declared scope. target_id must be a spec ID, never a CSS selector.', $path );
			} elseif ( count( $matches ) > 1 ) {
				$errors[] = self::motion_error( 'motion_target_ambiguous', 'Motion "' . $id . '" target "' . (string) ( $item['target_id'] ?? '' ) . '" matches multiple nodes; target IDs must be unique.', $path );
			}

			// Hover requires focus-visible parity on interactive elements.
			if ( 'hover' === ( $item['trigger'] ?? null ) ) {
				$hover_pairs[] = [
					'target' => (string) ( $item['target_id'] ?? '' ),
					'effect' => (string) ( $item['effect'] ?? '' ),
					'path'   => $path,
				];
			}

			// Loop playback is blocked by default.
			if ( 'loop' === ( $item['playback'] ?? null ) ) {
				if ( 'decorative' === ( $item['purpose'] ?? null ) ) {
					$errors[] = self::motion_error( 'motion_loop_decoration', 'Loop playback is not allowed for decorative purpose.', $path );
				}
				if ( empty( $item['control_target_id'] ) || empty( $item['control_label'] ) ) {
					$errors[] = self::motion_error( 'motion_loop_requires_control', 'playback=loop requires control_target_id and control_label pointing at a persistent, keyboard- and touch-operable control.', $path );
				} else {
					$control_matches = self::motion_id_matches( $spec, (string) $item['control_target_id'] );
					if ( 0 === count( $control_matches ) ) {
						$errors[] = self::motion_error( 'motion_loop_control_missing', 'Loop control_target_id "' . (string) $item['control_target_id'] . '" does not resolve to any section or block in the spec.', $path );
					}
				}
			}

			// Provider engine demands identity.
			if ( 'provider' === ( $item['engine'] ?? null ) && empty( $item['provider_id'] ) ) {
				$errors[] = self::motion_error( 'motion_provider_id_required', 'engine=provider requires provider_id from the capability digest.', $path );
			}

			// Stagger arithmetic.
			if ( isset( $item['stagger'] ) && is_array( $item['stagger'] ) ) {
				$count    = count( $item['stagger']['target_ids'] ?? [] );
				$interval = (int) ( $item['stagger']['interval_ms'] ?? 0 );
				$span     = (int) ( $item['stagger']['span_ms'] ?? 0 );
				if ( $count >= 2 && $interval * ( $count - 1 ) > $span ) {
					$errors[] = self::motion_error( 'motion_stagger_span_exceeded', sprintf( 'stagger interval_ms * (count - 1) = %d exceeds span_ms = %d.', $interval * ( $count - 1 ), $span ), $path );
				}
			}
		}

		foreach ( $hover_pairs as $pair ) {
			$has_parity = false;
			foreach ( $items as $entry ) {
				$item = $entry['item'];
				if ( 'focus-visible' === ( $item['trigger'] ?? null )
					&& (string) ( $item['target_id'] ?? '' ) === $pair['target']
					&& (string) ( $item['effect'] ?? '' ) === $pair['effect'] ) {
					$has_parity = true;
					break;
				}
			}
			if ( ! $has_parity ) {
				$errors[] = self::motion_error( 'motion_hover_focus_parity', 'Hover motion on target "' . $pair['target'] . '" needs an equivalent focus-visible motion with the same effect for keyboard users.', $pair['path'] );
			}
		}

		return $errors;
	}

	/**
	 * Collects every declared motion item with its validation path.
	 *
	 * @param array<string, mixed> $spec
	 * @return list<array{si:int, bi:int|null, owner:string, scope:string, item:array<string, mixed>, path:list<int|string>}>
	 */
	private static function motion_items( array $spec ): array {
		$out = [];

		foreach ( (array) ( $spec['sections'] ?? [] ) as $si => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			foreach ( (array) ( $section['motion'] ?? [] ) as $mi => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$out[] = [
					'si'    => (int) $si,
					'bi'    => null,
					'owner' => (string) ( $section['id'] ?? '' ),
					'scope' => 'section',
					'item'  => $item,
					'path'  => [ 'sections', $si, 'motion', $mi ],
				];
			}

			foreach ( (array) ( $section['blocks'] ?? [] ) as $bi => $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				foreach ( (array) ( $block['motion'] ?? [] ) as $mi => $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					$out[] = [
						'si'    => (int) $si,
						'bi'    => (int) $bi,
						'owner' => (string) ( $block['id'] ?? sprintf( 's%d_b%d', $si, $bi ) ),
						'scope' => 'block',
						'item'  => $item,
						'path'  => [ 'sections', $si, 'blocks', $bi, 'motion', $mi ],
					];
				}
			}
		}

		return $out;
	}

	/**
	 * Resolves a semantic target ID inside a motion item's scope.
	 *
	 * Section-declared motion may target its own section or any descendant
	 * block; block-declared motion may target only its own block. Duplicate
	 * matches (ambiguous targets) are reported by the caller.
	 *
	 * @param array<string, mixed> $spec
	 * @param array{si:int, bi:int|null, owner:string, scope:string, item:array<string, mixed>, path:list<int|string>} $entry
	 * @return list<string>
	 */
	private static function motion_target_matches( array $spec, array $entry ): array {
		$target = (string) ( $entry['item']['target_id'] ?? '' );
		if ( '' === $target ) {
			return [];
		}

		$matches = [];

		foreach ( (array) ( $spec['sections'] ?? [] ) as $si => $section ) {
			if ( ! is_array( $section ) || (int) $si !== $entry['si'] ) {
				continue;
			}

			if ( 'block' === $entry['scope'] ) {
				$blocks = array_values( (array) ( $section['blocks'] ?? [] ) );
				$block  = $blocks[ $entry['bi'] ] ?? null;
				if ( is_array( $block ) && $entry['owner'] === $target ) {
					$matches[] = 'block:' . $si . '_' . $entry['bi'];
				}
				continue;
			}

			if ( (string) ( $section['id'] ?? '' ) === $target ) {
				$matches[] = 'section:' . $si;
			}

			foreach ( (array) ( $section['blocks'] ?? [] ) as $bi => $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				$block_id = (string) ( $block['id'] ?? '' );
				if ( '' !== $block_id && $block_id === $target ) {
					$matches[] = 'block:' . $si . '_' . $bi;
				}
			}
		}

		return $matches;
	}

	/**
	 * Counts occurrences of an ID across all sections and blocks.
	 *
	 * @param array<string, mixed> $spec
	 * @return list<string>
	 */
	private static function motion_id_matches( array $spec, string $id ): array {
		$matches = [];
		foreach ( (array) ( $spec['sections'] ?? [] ) as $si => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			if ( (string) ( $section['id'] ?? '' ) === $id ) {
				$matches[] = 'section:' . $si;
			}
			foreach ( (array) ( $section['blocks'] ?? [] ) as $bi => $block ) {
				if ( is_array( $block ) && (string) ( $block['id'] ?? '' ) === $id ) {
					$matches[] = 'block:' . $si . '_' . $bi;
				}
			}
		}
		return $matches;
	}

	/**
	 * @param list<int|string> $path
	 * @return array<string, mixed>
	 */
	private static function motion_error( string $keyword, string $message, array $path ): array {
		return [
			'keyword' => $keyword,
			'message' => $message,
			'path'    => $path,
		];
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return array<int, array<string, mixed>>
	 */
	private static function structural_check( array $spec ): array {
		$errors = [];

		if ( empty( $spec['page']['title'] ) ) {
			$errors[] = [ 'keyword' => 'required', 'message' => 'page.title is required', 'path' => [ 'page', 'title' ] ];
		}
		if ( ! is_array( $spec['sections'] ) || empty( $spec['sections'] ) ) {
			$errors[] = [ 'keyword' => 'required', 'message' => 'sections must contain at least one entry', 'path' => [ 'sections' ] ];
		} else {
			foreach ( $spec['sections'] as $i => $section ) {
				if ( ! is_array( $section ) || empty( $section['blocks'] ) ) {
					$errors[] = [ 'keyword' => 'required', 'message' => 'section[' . $i . '] must contain blocks', 'path' => [ 'sections', $i ] ];
				}
			}
		}
		return $errors;
	}

	/**
	 * Adds precise repair checks for schema branches that Opis can report at a
	 * broad parent path such as `sections`.
	 *
	 * @param array<string, mixed> $spec
	 * @return array<int, array<string, mixed>>
	 */
	private static function repair_checks( array $spec ): array {
		$errors = [];
		foreach ( (array) ( $spec['sections'] ?? [] ) as $index => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			if ( array_key_exists( 'layout', $section ) ) {
				$layout = $section['layout'];
				// Scalar stack/row/grid or a viewport map of those intents (desktop/tablet/mobile).
				$allowed = [ 'stack', 'row', 'grid', 'horizontal', 'vertical' ];
				$ok      = is_string( $layout ) && in_array( $layout, $allowed, true );
				if ( ! $ok && is_array( $layout ) ) {
					$ok = [] !== $layout;
					foreach ( $layout as $bp => $value ) {
						if ( ! is_string( $bp ) || ! in_array( $bp, [ 'desktop', 'tablet', 'mobile' ], true ) ) {
							$ok = false;
							break;
						}
						if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
							$ok = false;
							break;
						}
					}
				}
				if ( ! $ok ) {
					$errors[] = [
						'keyword' => 'enum',
						'message' => 'section layout must be stack, row, grid (or legacy horizontal/vertical), or a desktop/tablet/mobile map of those values',
						'path'    => [ 'sections', $index, 'layout' ],
					];
				}
			}
			if ( array_key_exists( 'direction', $section ) ) {
				$direction = $section['direction'];
				$allowed   = [ 'row', 'column', 'row-reverse', 'column-reverse', 'horizontal', 'vertical' ];
				$ok        = is_string( $direction ) && in_array( $direction, $allowed, true );
				if ( ! $ok && is_array( $direction ) ) {
					$ok = [] !== $direction;
					foreach ( $direction as $bp => $value ) {
						if ( ! is_string( $bp ) || ! in_array( $bp, [ 'desktop', 'tablet', 'mobile' ], true ) ) {
							$ok = false;
							break;
						}
						if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
							$ok = false;
							break;
						}
					}
				}
				if ( ! $ok ) {
					$errors[] = [
						'keyword' => 'enum',
						'message' => 'section direction must be row, column, row-reverse, column-reverse (or legacy horizontal/vertical), or a desktop/tablet/mobile map of those values',
						'path'    => [ 'sections', $index, 'direction' ],
					];
				}
			}
		}
		foreach ( self::placeholder_copy_paths( (array) ( $spec['sections'] ?? [] ), [ 'sections' ] ) as $path => $value ) {
			$errors[] = [
				'keyword' => 'placeholder_copy',
				'message' => 'Placeholder copy cannot drive an Elementor write: ' . $value,
				'path'    => explode( '.', $path ),
			];
		}
		return $errors;
	}

	/** @param array<mixed> $value @param list<string|int> $path @return array<string, string> */
	private static function placeholder_copy_paths( array $value, array $path ): array {
		$out = [];
		foreach ( $value as $key => $item ) {
			$current = array_merge( $path, [ $key ] );
			if ( is_array( $item ) ) {
				$out += self::placeholder_copy_paths( $item, $current );
				continue;
			}
			if ( ! is_string( $item ) || ! in_array( (string) $key, [ 'text', 'title', 'label', 'content' ], true ) ) {
				continue;
			}
			$copy = strtolower( trim( strip_tags( $item ) ) );
			if ( 1 === preg_match( '/^(?:titlu(?:\s+card|\s+\d+)?|text(?:\s+card)?|icon\s*\+\s*title\s*\d*|type your paragraph here|lorem ipsum|card(?: featured)?|beneficiu\s*\d+)$/u', $copy ) ) {
				$out[ implode( '.', array_map( 'strval', $current ) ) ] = $item;
			}
		}
		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $errors
	 * @param array<string, mixed>            $spec
	 * @return array<int, array<string, mixed>>
	 */
	private static function enrich_errors( array $errors, array $spec ): array {
		$out = [];
		$seen = [];
		foreach ( $errors as $error ) {
			$path = isset( $error['path'] ) && is_array( $error['path'] ) ? array_values( $error['path'] ) : [];
			$key  = (string) ( $error['keyword'] ?? '' ) . ':' . self::path_string( $path );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$value = self::value_at_path( $spec, $path );
			$error['path']                  = $path;
			$error['path_string']           = self::path_string( $path );
			$error['received_type']         = self::received_type( $value );
			$error['allowed_shapes']        = self::allowed_shapes( $path );
			$error['nearest_valid_example'] = self::nearest_valid_example( $path );
			$error['repair_hint']           = self::repair_hint( $path, (string) ( $error['keyword'] ?? '' ) );
			$out[] = $error;
		}
		return $out;
	}

	/**
	 * @param array<int, mixed> $path
	 */
	private static function path_string( array $path ): string {
		$out = '';
		foreach ( $path as $part ) {
			if ( is_int( $part ) || ctype_digit( (string) $part ) ) {
				$out .= '[' . (string) $part . ']';
				continue;
			}
			$out .= '' === $out ? (string) $part : '.' . (string) $part;
		}
		return $out;
	}

	/** @return array<int, int|string> */
	private static function parse_path( string $path ): array {
		if ( '' === $path ) {
			return [];
		}
		$parts = preg_split( '/\.|\[|\]/', $path, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		return array_map(
			static fn( string $part ): int|string => ctype_digit( $part ) ? (int) $part : $part,
			$parts
		);
	}

	/**
	 * @param array<string, mixed> $spec
	 * @param array<int, mixed>    $path
	 */
	private static function value_at_path( array $spec, array $path ): mixed {
		$value = $spec;
		foreach ( $path as $part ) {
			if ( is_array( $value ) && array_key_exists( $part, $value ) ) {
				$value = $value[ $part ];
				continue;
			}
			return null;
		}
		return $value;
	}

	private static function received_type( mixed $value ): string {
		if ( null === $value ) {
			return 'missing';
		}
		if ( is_array( $value ) ) {
			return self::array_is_list( $value ) ? 'array' : 'object';
		}
		return get_debug_type( $value );
	}

	/**
	 * @param array<mixed> $value
	 */
	private static function array_is_list( array $value ): bool {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $value );
		}
		$expected = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $expected++ ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<int, mixed> $path
	 * @return array<int, mixed>
	 */
	private static function allowed_shapes( array $path ): array {
		$last = end( $path );
		if ( 'layout' === $last && in_array( 'sections', $path, true ) ) {
			return [ 'stack', 'row', 'grid' ];
		}
		if ( 'sections' === $last ) {
			return [ 'non-empty array of section objects' ];
		}
		if ( 'blocks' === $last ) {
			return [ 'array of block objects' ];
		}
		if ( 'type' === $last ) {
			return [ 'supported block type string' ];
		}
		return [];
	}

	/**
	 * @param array<int, mixed> $path
	 * @return array<string, mixed>
	 */
	private static function nearest_valid_example( array $path ): array {
		$last = end( $path );
		if ( 'layout' === $last && in_array( 'sections', $path, true ) ) {
			return [ 'layout' => 'row' ];
		}
		if ( 'sections' === $last ) {
			return [
				'sections' => [
					[
						'id'     => 'hero',
						'blocks' => [
							[ 'type' => 'heading', 'text' => 'Hello' ],
						],
					],
				],
			];
		}
		if ( 'blocks' === $last ) {
			return [ 'blocks' => [ [ 'type' => 'paragraph', 'text' => 'Text' ] ] ];
		}
		return [];
	}

	/**
	 * @param array<int, mixed> $path
	 */
	private static function repair_hint( array $path, string $keyword ): string {
		$path_string = self::path_string( $path );
		$last        = end( $path );
		if ( 'layout' === $last && in_array( 'sections', $path, true ) ) {
			return 'Set ' . $path_string . ' to "stack", "row", or "grid"; do not pass an object for section layout.';
		}
		if ( 'sections' === $last ) {
			return 'Set sections to a non-empty array. Each section needs id and blocks.';
		}
		if ( 'blocks' === $last ) {
			return 'Set ' . $path_string . ' to an array of block objects. Each block needs a supported type.';
		}
		return 'Repair ' . ( '' !== $path_string ? $path_string : 'spec' ) . ' to satisfy schema keyword ' . $keyword . '.';
	}
}
