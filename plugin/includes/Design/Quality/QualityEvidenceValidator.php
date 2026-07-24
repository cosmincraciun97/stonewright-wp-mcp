<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Quality;

use Stonewright\WpMcp\DesignTokens\BrandKit;
use WP_Error;

/**
 * Validates and normalizes rendered browser evidence.
 *
 * Evidence is measured in a browser and handed to the evaluator, which turns it
 * into a pass or fail verdict. That makes it the least trusted and most
 * consequential input in the quality subsystem, so this validator holds the
 * whole trust boundary:
 *
 * - Allowlist only. Unknown keys are rejected rather than stripped, because
 *   stripping would let a caller attach an unbounded DOM dump — or anything
 *   else — under a key the evaluator ignores, and it would hide the fact that
 *   the harness and the plugin disagree about the schema.
 * - Hard bounds on viewports, elements, string length, and total encoded size,
 *   so one heavy page cannot turn a check into a memory incident.
 * - Colors are resolved to lowercase six-digit hex exactly once, here. A color
 *   the plugin cannot measure — a CSS variable, a partially transparent color,
 *   a named keyword — is refused rather than guessed, because a guessed
 *   backdrop produces a confident and wrong contrast ratio.
 * - Absent evidence stays absent. No default is invented, and a partially
 *   captured `states` object is preserved as-is: "focus was captured and
 *   missing" and "states were never captured" must reach the evaluator as
 *   different facts, since the first is a defect and the second is unknown.
 */
final class QualityEvidenceValidator {

	/** @var string The only evidence schema version this release accepts. */
	public const SCHEMA_VERSION = '1.0';

	/** @var string Structured error code for every rejected evidence payload. */
	public const ERROR_CODE = 'stonewright_quality_evidence_invalid';

	/** @var int Maximum elements reported per viewport. */
	public const MAX_ELEMENTS = 200;

	/** @var int Maximum length of any single string value. */
	public const MAX_STRING_LENGTH = 2000;

	/** @var int Maximum encoded evidence size (128 KiB). */
	public const MAX_BYTES = 131072;

	/**
	 * Viewport identifiers, widest first. Report order follows this list.
	 *
	 * @var list<string>
	 */
	public const VIEWPORT_IDS = [ 'desktop', 'tablet', 'mobile' ];

	/**
	 * Element kinds. `container` elements carry layout evidence but no text.
	 *
	 * @var list<string>
	 */
	public const ELEMENT_KINDS = [ 'text', 'interactive', 'container' ];

	/**
	 * Allowlisted top-level keys, in canonical order.
	 *
	 * @var list<string>
	 */
	private const TOP_LEVEL_KEYS = [ 'schema_version', 'target', 'viewports' ];

	/**
	 * Allowlisted target keys.
	 *
	 * @var list<string>
	 */
	private const TARGET_KEYS = [ 'post_id', 'url', 'render_hash' ];

	/**
	 * Allowlisted viewport keys, in canonical order.
	 *
	 * @var list<string>
	 */
	private const VIEWPORT_KEYS = [ 'id', 'width', 'height', 'scroll_width', 'scroll_height', 'elements' ];

	/**
	 * Allowlisted element keys, in canonical order.
	 *
	 * @var list<string>
	 */
	private const ELEMENT_KEYS = [
		'ref',
		'kind',
		'box',
		'text_color',
		'background_color',
		'font',
		'content_box',
		'spacing',
		'states',
	];

	/**
	 * Allowlisted measurement groups mapped to their allowlisted keys.
	 *
	 * @var array<string,list<string>>
	 */
	private const MEASUREMENT_KEYS = [
		'box'         => [ 'width', 'height' ],
		'font'        => [ 'family', 'size_px', 'weight', 'line_height_px' ],
		'content_box' => [ 'scroll_width', 'client_width', 'scroll_height', 'client_height' ],
		'spacing'     => [
			'padding_top_px',
			'padding_bottom_px',
			'padding_left_px',
			'padding_right_px',
			'margin_top_px',
			'margin_bottom_px',
			'gap_px',
		],
	];

	/**
	 * Allowlisted interaction states mapped to their allowlisted keys.
	 *
	 * @var array<string,list<string>>
	 */
	private const STATE_KEYS = [
		'focus' => [ 'outline_color', 'outline_width_px', 'background_color' ],
		'hover' => [ 'background_color', 'text_color' ],
	];

	/**
	 * Element keys holding a measurable color.
	 *
	 * @var list<string>
	 */
	private const COLOR_KEYS = [ 'text_color', 'background_color' ];

	/**
	 * Validate and normalize rendered evidence.
	 *
	 * @param array<string,mixed> $evidence Raw evidence from a browser session.
	 * @return array<string,mixed>|WP_Error Normalized evidence, or a structured error.
	 */
	public static function validate( array $evidence ): array|WP_Error {
		$encoded = wp_json_encode( $evidence );
		if ( ! is_string( $encoded ) ) {
			return self::error( __( 'The evidence could not be encoded for validation.', 'stonewright' ) );
		}
		if ( strlen( $encoded ) > self::MAX_BYTES ) {
			return self::error(
				sprintf(
					/* translators: 1: evidence size in bytes, 2: maximum size in bytes. */
					__( 'The evidence is %1$d bytes, above the %2$d byte limit. Report measured elements, not the document.', 'stonewright' ),
					strlen( $encoded ),
					self::MAX_BYTES
				)
			);
		}

		$unknown = array_diff( array_keys( $evidence ), self::TOP_LEVEL_KEYS );
		if ( [] !== $unknown ) {
			return self::unknown_keys( 'evidence', $unknown );
		}

		if ( self::SCHEMA_VERSION !== ( $evidence['schema_version'] ?? null ) ) {
			return self::error(
				sprintf(
					/* translators: %s: supported schema version. */
					__( 'The evidence must declare schema_version %s.', 'stonewright' ),
					self::SCHEMA_VERSION
				)
			);
		}

		$target = self::target( $evidence['target'] ?? [] );
		if ( $target instanceof WP_Error ) {
			return $target;
		}

		$viewports = self::viewports( $evidence['viewports'] ?? null );
		if ( $viewports instanceof WP_Error ) {
			return $viewports;
		}

		return [
			'schema_version' => self::SCHEMA_VERSION,
			'target'         => $target,
			'viewports'      => $viewports,
		];
	}

	/**
	 * Normalize the optional target descriptor.
	 *
	 * @param mixed $raw Raw target value.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function target( $raw ): array|WP_Error {
		if ( ! is_array( $raw ) ) {
			return self::error( __( 'The evidence target must be an object.', 'stonewright' ) );
		}

		$unknown = array_diff( array_keys( $raw ), self::TARGET_KEYS );
		if ( [] !== $unknown ) {
			return self::unknown_keys( 'target', $unknown );
		}

		$target = [];
		if ( isset( $raw['post_id'] ) ) {
			if ( ! is_int( $raw['post_id'] ) || $raw['post_id'] < 0 ) {
				return self::error( __( 'The target post_id must be a non-negative integer.', 'stonewright' ) );
			}
			$target['post_id'] = $raw['post_id'];
		}

		foreach ( [ 'url', 'render_hash' ] as $key ) {
			if ( ! isset( $raw[ $key ] ) ) {
				continue;
			}
			$value = self::string_value( $raw[ $key ], 'target.' . $key );
			if ( $value instanceof WP_Error ) {
				return $value;
			}
			$target[ $key ] = $value;
		}

		return $target;
	}

	/**
	 * Normalize the viewport list, widest first.
	 *
	 * @param mixed $raw Raw viewport list.
	 * @return list<array<string,mixed>>|WP_Error
	 */
	private static function viewports( $raw ): array|WP_Error {
		if ( ! is_array( $raw ) || [] === $raw || array_keys( $raw ) !== range( 0, count( $raw ) - 1 ) ) {
			return self::error( __( 'The evidence must include a non-empty list of viewports.', 'stonewright' ) );
		}
		if ( count( $raw ) > count( self::VIEWPORT_IDS ) ) {
			return self::error( __( 'The evidence reports more viewports than Stonewright measures.', 'stonewright' ) );
		}

		$normalized = [];
		$seen       = [];
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				return self::error( __( 'Each viewport must be an object.', 'stonewright' ) );
			}

			$viewport = self::viewport( $entry );
			if ( $viewport instanceof WP_Error ) {
				return $viewport;
			}

			$id = (string) $viewport['id'];
			if ( in_array( $id, $seen, true ) ) {
				return self::error(
					sprintf(
						/* translators: %s: viewport identifier. */
						__( 'The evidence reports the %s viewport twice.', 'stonewright' ),
						$id
					)
				);
			}
			$seen[]                = $id;
			$normalized[ $id ]     = $viewport;
		}

		$ordered = [];
		foreach ( self::VIEWPORT_IDS as $id ) {
			if ( isset( $normalized[ $id ] ) ) {
				$ordered[] = $normalized[ $id ];
			}
		}

		return $ordered;
	}

	/**
	 * Normalize one viewport.
	 *
	 * @param array<string,mixed> $raw Raw viewport.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function viewport( array $raw ): array|WP_Error {
		$unknown = array_diff( array_keys( $raw ), self::VIEWPORT_KEYS );
		if ( [] !== $unknown ) {
			return self::unknown_keys( 'viewport', $unknown );
		}

		$id = $raw['id'] ?? null;
		if ( ! is_string( $id ) || ! in_array( $id, self::VIEWPORT_IDS, true ) ) {
			return self::error(
				sprintf(
					/* translators: %s: allowlisted viewport identifiers. */
					__( 'Each viewport id must be one of: %s.', 'stonewright' ),
					implode( ', ', self::VIEWPORT_IDS )
				)
			);
		}

		$viewport = [ 'id' => $id ];
		foreach ( [ 'width', 'height' ] as $key ) {
			$value = self::dimension( $raw[ $key ] ?? null, 'viewport.' . $key );
			if ( $value instanceof WP_Error ) {
				return $value;
			}
			$viewport[ $key ] = $value;
		}

		foreach ( [ 'scroll_width', 'scroll_height' ] as $key ) {
			if ( ! isset( $raw[ $key ] ) ) {
				continue;
			}
			$value = self::dimension( $raw[ $key ], 'viewport.' . $key );
			if ( $value instanceof WP_Error ) {
				return $value;
			}
			$viewport[ $key ] = $value;
		}

		$elements = self::elements( $raw['elements'] ?? [] );
		if ( $elements instanceof WP_Error ) {
			return $elements;
		}
		$viewport['elements'] = $elements;

		return $viewport;
	}

	/**
	 * Normalize the element list of one viewport.
	 *
	 * @param mixed $raw Raw element list.
	 * @return list<array<string,mixed>>|WP_Error
	 */
	private static function elements( $raw ): array|WP_Error {
		if ( ! is_array( $raw ) ) {
			return self::error( __( 'Each viewport must report a list of elements.', 'stonewright' ) );
		}
		if ( [] !== $raw && array_keys( $raw ) !== range( 0, count( $raw ) - 1 ) ) {
			return self::error( __( 'The element list must be a plain list.', 'stonewright' ) );
		}
		if ( count( $raw ) > self::MAX_ELEMENTS ) {
			return self::error(
				sprintf(
					/* translators: 1: reported element count, 2: maximum element count. */
					__( 'The evidence reports %1$d elements for one viewport, above the %2$d limit. Measure the elements the direction governs.', 'stonewright' ),
					count( $raw ),
					self::MAX_ELEMENTS
				)
			);
		}

		$elements = [];
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				return self::error( __( 'Each element must be an object.', 'stonewright' ) );
			}
			$element = self::element( $entry );
			if ( $element instanceof WP_Error ) {
				return $element;
			}
			$elements[] = $element;
		}

		return $elements;
	}

	/**
	 * Normalize one element.
	 *
	 * @param array<string,mixed> $raw Raw element.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function element( array $raw ): array|WP_Error {
		$unknown = array_diff( array_keys( $raw ), self::ELEMENT_KEYS );
		if ( [] !== $unknown ) {
			return self::unknown_keys( 'element', $unknown );
		}

		$ref = self::string_value( $raw['ref'] ?? null, 'element.ref' );
		if ( $ref instanceof WP_Error ) {
			return $ref;
		}
		if ( '' === $ref ) {
			return self::error( __( 'Each element must carry a stable ref so a finding can be located again.', 'stonewright' ) );
		}

		$kind = $raw['kind'] ?? null;
		if ( ! is_string( $kind ) || ! in_array( $kind, self::ELEMENT_KINDS, true ) ) {
			return self::error(
				sprintf(
					/* translators: %s: allowlisted element kinds. */
					__( 'Each element kind must be one of: %s.', 'stonewright' ),
					implode( ', ', self::ELEMENT_KINDS )
				)
			);
		}

		$element = [
			'ref'  => $ref,
			'kind' => $kind,
		];

		foreach ( self::COLOR_KEYS as $key ) {
			if ( ! isset( $raw[ $key ] ) ) {
				continue;
			}
			$color = self::color( $raw[ $key ], 'element.' . $key );
			if ( $color instanceof WP_Error ) {
				return $color;
			}
			$element[ $key ] = $color;
		}

		foreach ( self::MEASUREMENT_KEYS as $group => $keys ) {
			if ( ! isset( $raw[ $group ] ) ) {
				continue;
			}
			$measurements = self::measurements( $raw[ $group ], $group, $keys );
			if ( $measurements instanceof WP_Error ) {
				return $measurements;
			}
			$element[ $group ] = $measurements;
		}

		if ( isset( $raw['states'] ) ) {
			$states = self::states( $raw['states'] );
			if ( $states instanceof WP_Error ) {
				return $states;
			}
			$element['states'] = $states;
		}

		return self::in_canonical_order( $element, self::ELEMENT_KEYS );
	}

	/**
	 * Normalize one measurement group.
	 *
	 * @param mixed        $raw   Raw measurement group.
	 * @param string       $group Group name, for error messages.
	 * @param list<string> $keys  Allowlisted keys.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function measurements( $raw, string $group, array $keys ): array|WP_Error {
		if ( ! is_array( $raw ) ) {
			return self::error(
				sprintf(
					/* translators: %s: measurement group name. */
					__( 'The element %s measurements must be an object.', 'stonewright' ),
					$group
				)
			);
		}

		$unknown = array_diff( array_keys( $raw ), $keys );
		if ( [] !== $unknown ) {
			return self::unknown_keys( 'element.' . $group, $unknown );
		}

		$measurements = [];
		foreach ( $keys as $key ) {
			if ( ! isset( $raw[ $key ] ) ) {
				continue;
			}
			if ( 'family' === $key ) {
				$family = self::string_value( $raw[ $key ], 'element.font.family' );
				if ( $family instanceof WP_Error ) {
					return $family;
				}
				$measurements[ $key ] = $family;
				continue;
			}
			$value = self::dimension( $raw[ $key ], 'element.' . $group . '.' . $key );
			if ( $value instanceof WP_Error ) {
				return $value;
			}
			$measurements[ $key ] = $value;
		}

		return $measurements;
	}

	/**
	 * Normalize captured interaction states, preserving partial capture.
	 *
	 * @param mixed $raw Raw states object.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function states( $raw ): array|WP_Error {
		if ( ! is_array( $raw ) ) {
			return self::error( __( 'The element states must be an object.', 'stonewright' ) );
		}

		$unknown = array_diff( array_keys( $raw ), array_keys( self::STATE_KEYS ) );
		if ( [] !== $unknown ) {
			return self::unknown_keys( 'element.states', $unknown );
		}

		$states = [];
		foreach ( self::STATE_KEYS as $state => $keys ) {
			if ( ! isset( $raw[ $state ] ) ) {
				continue;
			}
			if ( ! is_array( $raw[ $state ] ) ) {
				return self::error(
					sprintf(
						/* translators: %s: state name. */
						__( 'The element %s state must be an object.', 'stonewright' ),
						$state
					)
				);
			}

			$unknown_state = array_diff( array_keys( $raw[ $state ] ), $keys );
			if ( [] !== $unknown_state ) {
				return self::unknown_keys( 'element.states.' . $state, $unknown_state );
			}

			$observed = [];
			foreach ( $keys as $key ) {
				if ( ! isset( $raw[ $state ][ $key ] ) ) {
					continue;
				}
				if ( str_ends_with( $key, '_px' ) ) {
					$value = self::dimension( $raw[ $state ][ $key ], 'element.states.' . $state . '.' . $key );
				} else {
					$value = self::color( $raw[ $state ][ $key ], 'element.states.' . $state . '.' . $key );
				}
				if ( $value instanceof WP_Error ) {
					return $value;
				}
				$observed[ $key ] = $value;
			}
			$states[ $state ] = $observed;
		}

		return $states;
	}

	/**
	 * Resolve a measurable color to lowercase six-digit hex.
	 *
	 * Anything the plugin cannot measure is refused. A named keyword, a CSS
	 * variable, or a partially transparent color has no single value to compare,
	 * and substituting a plausible one would produce a confident wrong ratio.
	 *
	 * @param mixed  $raw   Raw color value.
	 * @param string $field Field path, for error messages.
	 * @return string|WP_Error
	 */
	private static function color( $raw, string $field ): string|WP_Error {
		$value = self::string_value( $raw, $field );
		if ( $value instanceof WP_Error ) {
			return $value;
		}

		$hex = self::to_hex( $value );
		if ( null === $hex ) {
			return self::error(
				sprintf(
					/* translators: 1: field path, 2: reported value. */
					__( 'The %1$s value "%2$s" is not a measurable color. Report a resolved hex or rgb() color.', 'stonewright' ),
					$field,
					$value
				)
			);
		}

		return $hex;
	}

	/**
	 * Convert a resolved CSS color to lowercase six-digit hex.
	 *
	 * @param string $value Raw color value.
	 */
	public static function to_hex( string $value ): ?string {
		$value = strtolower( trim( $value ) );

		$rgb = BrandKit::parse_hex_color( $value );
		if ( null !== $rgb ) {
			return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
		}

		if ( 1 !== preg_match( '/^rgba?\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*(?:,\s*([0-9.]+)\s*)?\)$/', $value, $matches ) ) {
			return null;
		}

		if ( isset( $matches[4] ) && '' !== $matches[4] && 1.0 !== (float) $matches[4] ) {
			return null;
		}

		$channels = [ (int) $matches[1], (int) $matches[2], (int) $matches[3] ];
		foreach ( $channels as $channel ) {
			if ( $channel > 255 ) {
				return null;
			}
		}

		return sprintf( '#%02x%02x%02x', $channels[0], $channels[1], $channels[2] );
	}

	/**
	 * Validate a non-negative measurement.
	 *
	 * @param mixed  $raw   Raw measurement.
	 * @param string $field Field path, for error messages.
	 * @return int|float|WP_Error
	 */
	private static function dimension( $raw, string $field ): int|float|WP_Error {
		if ( ! is_int( $raw ) && ! is_float( $raw ) ) {
			return self::error(
				sprintf(
					/* translators: %s: field path. */
					__( 'The %s measurement must be a number.', 'stonewright' ),
					$field
				)
			);
		}
		if ( $raw < 0 ) {
			return self::error(
				sprintf(
					/* translators: %s: field path. */
					__( 'The %s measurement must not be negative.', 'stonewright' ),
					$field
				)
			);
		}

		return $raw;
	}

	/**
	 * Validate a bounded string.
	 *
	 * @param mixed  $raw   Raw value.
	 * @param string $field Field path, for error messages.
	 * @return string|WP_Error
	 */
	private static function string_value( $raw, string $field ): string|WP_Error {
		if ( ! is_string( $raw ) ) {
			return self::error(
				sprintf(
					/* translators: %s: field path. */
					__( 'The %s value must be a string.', 'stonewright' ),
					$field
				)
			);
		}
		if ( strlen( $raw ) > self::MAX_STRING_LENGTH ) {
			return self::error(
				sprintf(
					/* translators: 1: field path, 2: maximum string length. */
					__( 'The %1$s value is longer than %2$d characters.', 'stonewright' ),
					$field,
					self::MAX_STRING_LENGTH
				)
			);
		}

		return trim( $raw );
	}

	/**
	 * Reorder an array by an allowlist so encoded evidence is order-independent.
	 *
	 * @param array<string,mixed> $values Values to reorder.
	 * @param list<string>        $order  Canonical key order.
	 * @return array<string,mixed>
	 */
	private static function in_canonical_order( array $values, array $order ): array {
		$ordered = [];
		foreach ( $order as $key ) {
			if ( array_key_exists( $key, $values ) ) {
				$ordered[ $key ] = $values[ $key ];
			}
		}

		return $ordered;
	}

	/**
	 * Structured error for rejected keys.
	 *
	 * @param string       $where Location description.
	 * @param array<mixed> $keys  Rejected keys.
	 */
	private static function unknown_keys( string $where, array $keys ): WP_Error {
		return self::error(
			sprintf(
				/* translators: 1: location in the evidence, 2: rejected key list. */
				__( 'The %1$s reports keys Stonewright does not accept: %2$s.', 'stonewright' ),
				$where,
				implode( ', ', array_map( 'strval', $keys ) )
			)
		);
	}

	/**
	 * Structured rejection.
	 *
	 * @param string $message Human-readable reason.
	 */
	private static function error( string $message ): WP_Error {
		return new WP_Error( self::ERROR_CODE, $message, [ 'status' => 400 ] );
	}
}
