<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Direction;

use WP_Error;

/**
 * Turns bounded, read-only Elementor evidence into a draft direction contract.
 *
 * Capture is the only path where a contract is produced by machine instead of
 * authored, so it is deliberately conservative:
 *
 * - The evidence shape is an allowlist. Unknown keys are rejected, not stripped,
 *   so a caller cannot smuggle raw kit meta through in a field this class does
 *   not understand.
 * - A value that is not in the evidence stays absent. Nothing is defaulted,
 *   rounded, or inferred, because a guessed token reads exactly like a measured
 *   one once it is stored.
 * - Contradictory evidence keeps the first occurrence and records the conflict.
 *   Silent last-write-wins would make the result depend on kit ordering.
 * - Every mapped value gets a provenance entry naming the kit it came from, so
 *   a reviewer can trace any token back to its source.
 * - The result is never ready and never sync-ready. Capture proposes; a human or
 *   agent reviews and promotes.
 *
 * This class performs no reads of its own. Callers pass evidence collected by
 * the typed Elementor abilities, never raw post meta.
 */
final class ElementorDirectionCapture {

	/** @var string Structured error code for unusable capture evidence. */
	public const ERROR_CODE = 'stonewright_direction_capture_invalid';

	/** @var string Provenance source recorded for every captured value. */
	public const SOURCE = 'elementor-kit';

	/** @var string Placeholder identity used only while validating, never returned. */
	private const UNNAMED = 'untitled-kit';

	/**
	 * Allowlisted top-level evidence keys.
	 *
	 * @var list<string>
	 */
	private const EVIDENCE_KEYS = [
		'kit_id',
		'kit_title',
		'colors',
		'typography',
		'layout',
		'breakpoints',
		'buttons',
	];

	/**
	 * Evidence keys holding a list of entries.
	 *
	 * @var list<string>
	 */
	private const LIST_KEYS = [ 'colors', 'typography' ];

	/**
	 * Evidence keys holding a map of scalars.
	 *
	 * @var list<string>
	 */
	private const MAP_KEYS = [ 'layout', 'breakpoints', 'buttons' ];

	/**
	 * Typography evidence properties mapped to contract property names.
	 *
	 * @var array<string,string>
	 */
	private const TYPOGRAPHY_PROPERTIES = [
		'font_family'    => 'font-family',
		'font_weight'    => 'font-weight',
		'font_size'      => 'font-size',
		'line_height'    => 'line-height',
		'letter_spacing' => 'letter-spacing',
	];

	/**
	 * Layout evidence keys mapped to spacing token names.
	 *
	 * @var array<string,string>
	 */
	private const LAYOUT_TOKENS = [
		'container_width' => 'container-width',
		'content_width'   => 'content-width',
		'widget_spacing'  => 'widget-spacing',
		'section_spacing' => 'section-spacing',
	];

	/**
	 * Button evidence keys mapped to component property names.
	 *
	 * @var array<string,string>
	 */
	private const BUTTON_PROPERTIES = [
		'border_radius'    => 'border-radius',
		'background_color' => 'background-color',
		'text_color'       => 'text-color',
		'padding'          => 'padding',
		'font_size'        => 'font-size',
	];

	/**
	 * Allowlisted breakpoint names, in canonical order.
	 *
	 * @var list<string>
	 */
	private const BREAKPOINTS = [ 'mobile', 'mobile_extra', 'tablet', 'tablet_extra', 'laptop', 'widescreen' ];

	/**
	 * Maps evidence to a draft contract plus a report of what it could not use.
	 *
	 * @param array<string,mixed> $evidence Bounded evidence from typed Elementor reads.
	 * @return array{contract:array<string,mixed>,issues:list<string>,conflicts:list<string>,unmapped:list<string>,mapped:array<string,int>}|WP_Error
	 */
	public static function from_evidence( array $evidence ) {
		$too_large = DirectionPayload::size_error( $evidence, DirectionContract::MAX_CONTRACT_BYTES, 'evidence' );
		if ( null !== $too_large ) {
			return $too_large;
		}

		$checked = self::check_shape( $evidence );
		if ( $checked instanceof WP_Error ) {
			return $checked;
		}

		$kit_id    = (int) $checked['kit_id'];
		$issues    = [];
		$conflicts = [];
		$unmapped  = [];

		$colors = self::map_colors( $checked['colors'], $kit_id, $issues, $conflicts, $unmapped );
		if ( $colors instanceof WP_Error ) {
			return $colors;
		}

		$typography = self::map_typography( $checked['typography'], $kit_id, $issues, $conflicts, $unmapped );
		if ( $typography instanceof WP_Error ) {
			return $typography;
		}

		$spacing = self::map_spacing( $checked['layout'], $kit_id, $unmapped );

		$breakpoints = self::map_breakpoints( $checked['breakpoints'], $kit_id, $unmapped );
		if ( $breakpoints instanceof WP_Error ) {
			return $breakpoints;
		}

		$button = self::map_button( $checked['buttons'], $kit_id, $unmapped );

		$components = [];
		$provenance = array_merge(
			$colors['provenance'],
			$typography['provenance'],
			$spacing['provenance'],
			$breakpoints['provenance'],
			$button['provenance']
		);

		if ( [] !== $breakpoints['values'] ) {
			$components['breakpoints'] = $breakpoints['values'];
		}

		if ( [] !== $button['values'] ) {
			$components['button'] = $button['values'];
		}

		$name = trim( (string) $checked['kit_title'] );
		if ( '' === $name ) {
			$issues[] = __( 'The kit reported no title, so the direction has no identity name yet.', 'stonewright' );
		}

		if ( [] === $colors['values'] && [] === $typography['values'] ) {
			$issues[] = __( 'The kit reported no reusable colors or typography, so there are no global tokens to review.', 'stonewright' );
		}

		$contract = DirectionContract::defaults();

		$contract['identity']['name']       = '' !== $name ? $name : self::UNNAMED;
		$contract['tokens']['colors']       = $colors['values'];
		$contract['tokens']['typography']   = $typography['values'];
		$contract['tokens']['spacing']      = $spacing['values'];
		$contract['components']             = $components;
		$contract['provenance']             = $provenance;
		$contract['readiness']['issues']    = array_values( $issues );

		$validated = DirectionContractValidator::validate( $contract );
		if ( $validated instanceof WP_Error ) {
			return self::error( $validated->get_error_message() );
		}

		// The contract requires a name; a kit without a title has none to give.
		// Validating under a placeholder and then clearing it keeps the rest of
		// the contract checked without inventing an identity the kit never had.
		// The readiness issue above is what tells the reviewer to supply one.
		if ( '' === $name ) {
			$validated['identity']['name'] = '';
		}

		return [
			'contract'  => $validated,
			'issues'    => array_values( $issues ),
			'conflicts' => array_values( $conflicts ),
			'unmapped'  => array_values( $unmapped ),
			'mapped'    => [
				'colors'     => count( $colors['values'] ),
				'typography' => count( $typography['values'] ),
				'spacing'    => count( $spacing['values'] ),
				'components' => count( $components ),
			],
		];
	}

	/**
	 * Validates the evidence envelope and fills absent sections with empties.
	 *
	 * @param array<string,mixed> $evidence Untrusted evidence.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function check_shape( array $evidence ) {
		$unknown = array_diff( array_keys( $evidence ), self::EVIDENCE_KEYS );
		if ( [] !== $unknown ) {
			return self::error(
				sprintf(
					/* translators: %s: comma-separated list of rejected evidence keys. */
					__( 'Unknown capture evidence field(s): %s.', 'stonewright' ),
					implode( ', ', array_map( 'strval', $unknown ) )
				)
			);
		}

		$kit_id = isset( $evidence['kit_id'] ) && is_numeric( $evidence['kit_id'] ) ? (int) $evidence['kit_id'] : 0;
		if ( $kit_id < 1 ) {
			return self::error( __( 'Capture evidence must name the Elementor kit it came from.', 'stonewright' ) );
		}

		if ( isset( $evidence['kit_title'] ) && ! is_string( $evidence['kit_title'] ) ) {
			return self::error( __( 'Capture evidence kit_title must be a string.', 'stonewright' ) );
		}

		$checked = [
			'kit_id'    => $kit_id,
			'kit_title' => isset( $evidence['kit_title'] ) ? (string) $evidence['kit_title'] : '',
		];

		foreach ( self::LIST_KEYS as $key ) {
			$value = $evidence[ $key ] ?? [];

			if ( ! is_array( $value ) || ( [] !== $value && array_values( $value ) !== $value ) ) {
				return self::error(
					sprintf(
						/* translators: %s: evidence field name. */
						__( 'Capture evidence %s must be a list of entries.', 'stonewright' ),
						$key
					)
				);
			}

			if ( count( $value ) > DirectionContract::MAX_LIST_ITEMS ) {
				return self::error(
					sprintf(
						/* translators: 1: evidence field name, 2: maximum entries. */
						__( 'Capture evidence %1$s holds more than %2$d entries.', 'stonewright' ),
						$key,
						DirectionContract::MAX_LIST_ITEMS
					)
				);
			}

			$checked[ $key ] = $value;
		}

		foreach ( self::MAP_KEYS as $key ) {
			$value = $evidence[ $key ] ?? [];

			if ( ! is_array( $value ) || ( [] !== $value && array_values( $value ) === $value ) ) {
				return self::error(
					sprintf(
						/* translators: %s: evidence field name. */
						__( 'Capture evidence %s must be a map of named values.', 'stonewright' ),
						$key
					)
				);
			}

			if ( count( $value ) > DirectionContract::MAX_LIST_ITEMS ) {
				return self::error(
					sprintf(
						/* translators: 1: evidence field name, 2: maximum entries. */
						__( 'Capture evidence %1$s holds more than %2$d entries.', 'stonewright' ),
						$key,
						DirectionContract::MAX_LIST_ITEMS
					)
				);
			}

			$checked[ $key ] = $value;
		}

		return $checked;
	}

	/**
	 * @param array<int,mixed>  $entries   Color evidence entries.
	 * @param int               $kit_id    Kit the evidence came from.
	 * @param list<string>      $issues    Readiness issues, appended to.
	 * @param list<string>      $conflicts Conflicting contract paths, appended to.
	 * @param list<string>      $unmapped  Unusable evidence paths, appended to.
	 * @return array{values:array<string,string>,provenance:array<string,array{source:string,reference:string}>}|WP_Error
	 */
	private static function map_colors( array $entries, int $kit_id, array &$issues, array &$conflicts, array &$unmapped ) {
		$values     = [];
		$provenance = [];

		foreach ( $entries as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				return self::error( __( 'Each capture color entry must be a map.', 'stonewright' ) );
			}

			$slug = self::entry_slug( $entry );
			if ( '' === $slug ) {
				$unmapped[] = 'colors.' . $index;
				continue;
			}

			$color = isset( $entry['color'] ) && is_string( $entry['color'] ) ? trim( $entry['color'] ) : '';
			if ( '' === $color || DirectionContractValidator::validate( self::color_probe( $color ) ) instanceof WP_Error ) {
				$unmapped[] = 'colors.' . $slug;
				continue;
			}

			if ( array_key_exists( $slug, $values ) ) {
				if ( $values[ $slug ] !== $color ) {
					$conflicts[] = 'tokens.colors.' . $slug;
					$issues[]    = sprintf(
						/* translators: %s: token name. */
						__( 'The kit reports more than one value for color "%s"; the first was kept.', 'stonewright' ),
						$slug
					);
				}
				continue;
			}

			$values[ $slug ]                            = $color;
			$provenance[ 'tokens.colors.' . $slug ]     = self::provenance( $kit_id, 'colors.' . $slug );
		}

		ksort( $values );

		return [
			'values'     => $values,
			'provenance' => $provenance,
		];
	}

	/**
	 * @param array<int,mixed>  $entries   Typography evidence entries.
	 * @param int               $kit_id    Kit the evidence came from.
	 * @param list<string>      $issues    Readiness issues, appended to.
	 * @param list<string>      $conflicts Conflicting contract paths, appended to.
	 * @param list<string>      $unmapped  Unusable evidence paths, appended to.
	 * @return array{values:array<string,array<string,int|float|string>>,provenance:array<string,array{source:string,reference:string}>}|WP_Error
	 */
	private static function map_typography( array $entries, int $kit_id, array &$issues, array &$conflicts, array &$unmapped ) {
		$values     = [];
		$provenance = [];

		foreach ( $entries as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				return self::error( __( 'Each capture typography entry must be a map.', 'stonewright' ) );
			}

			$slug = self::entry_slug( $entry );
			if ( '' === $slug ) {
				$unmapped[] = 'typography.' . $index;
				continue;
			}

			$properties = [];

			foreach ( self::TYPOGRAPHY_PROPERTIES as $evidence_key => $property ) {
				if ( ! array_key_exists( $evidence_key, $entry ) ) {
					continue;
				}

				$value = self::scalar( $entry[ $evidence_key ] );
				if ( null === $value ) {
					continue;
				}

				$properties[ $property ] = $value;
			}

			if ( [] === $properties ) {
				$unmapped[] = 'typography.' . $slug;
				continue;
			}

			ksort( $properties );

			if ( array_key_exists( $slug, $values ) ) {
				if ( $values[ $slug ] !== $properties ) {
					$conflicts[] = 'tokens.typography.' . $slug;
					$issues[]    = sprintf(
						/* translators: %s: token name. */
						__( 'The kit reports more than one value for typography "%s"; the first was kept.', 'stonewright' ),
						$slug
					);
				}
				continue;
			}

			$values[ $slug ]                            = $properties;
			$provenance[ 'tokens.typography.' . $slug ] = self::provenance( $kit_id, 'typography.' . $slug );
		}

		ksort( $values );

		return [
			'values'     => $values,
			'provenance' => $provenance,
		];
	}

	/**
	 * @param array<string,mixed> $layout   Layout evidence.
	 * @param int                 $kit_id   Kit the evidence came from.
	 * @param list<string>        $unmapped Unusable evidence paths, appended to.
	 * @return array{values:array<string,int|float|string>,provenance:array<string,array{source:string,reference:string}>}
	 */
	private static function map_spacing( array $layout, int $kit_id, array &$unmapped ): array {
		$values     = [];
		$provenance = [];

		foreach ( $layout as $key => $raw ) {
			$key = (string) $key;

			if ( ! isset( self::LAYOUT_TOKENS[ $key ] ) ) {
				$unmapped[] = 'layout.' . $key;
				continue;
			}

			$value = self::scalar( $raw );
			if ( null === $value ) {
				$unmapped[] = 'layout.' . $key;
				continue;
			}

			$token                                      = self::LAYOUT_TOKENS[ $key ];
			$values[ $token ]                           = $value;
			$provenance[ 'tokens.spacing.' . $token ]   = self::provenance( $kit_id, 'layout.' . $key );
		}

		ksort( $values );

		return [
			'values'     => $values,
			'provenance' => $provenance,
		];
	}

	/**
	 * @param array<string,mixed> $breakpoints Breakpoint evidence.
	 * @param int                 $kit_id      Kit the evidence came from.
	 * @param list<string>        $unmapped    Unusable evidence paths, appended to.
	 * @return array{values:array<string,int>,provenance:array<string,array{source:string,reference:string}>}|WP_Error
	 */
	private static function map_breakpoints( array $breakpoints, int $kit_id, array &$unmapped ) {
		$values = [];

		foreach ( $breakpoints as $key => $raw ) {
			$key = (string) $key;

			if ( ! in_array( $key, self::BREAKPOINTS, true ) ) {
				$unmapped[] = 'breakpoints.' . $key;
				continue;
			}

			if ( ! is_int( $raw ) && ! ( is_string( $raw ) && 1 === preg_match( '/^\d+$/', $raw ) ) ) {
				return self::error(
					sprintf(
						/* translators: %s: breakpoint name. */
						__( 'Capture breakpoint %s must be a positive pixel integer.', 'stonewright' ),
						$key
					)
				);
			}

			$pixels = (int) $raw;
			if ( $pixels < 1 ) {
				return self::error(
					sprintf(
						/* translators: %s: breakpoint name. */
						__( 'Capture breakpoint %s must be a positive pixel integer.', 'stonewright' ),
						$key
					)
				);
			}

			$values[ str_replace( '_', '-', $key ) ] = $pixels;
		}

		if ( [] === $values ) {
			return [
				'values'     => [],
				'provenance' => [],
			];
		}

		ksort( $values );

		return [
			'values'     => $values,
			'provenance' => [ 'components.breakpoints' => self::provenance( $kit_id, 'breakpoints' ) ],
		];
	}

	/**
	 * @param array<string,mixed> $buttons  Button evidence.
	 * @param int                 $kit_id   Kit the evidence came from.
	 * @param list<string>        $unmapped Unusable evidence paths, appended to.
	 * @return array{values:array<string,int|float|string>,provenance:array<string,array{source:string,reference:string}>}
	 */
	private static function map_button( array $buttons, int $kit_id, array &$unmapped ): array {
		$values = [];

		foreach ( $buttons as $key => $raw ) {
			$key = (string) $key;

			if ( ! isset( self::BUTTON_PROPERTIES[ $key ] ) ) {
				$unmapped[] = 'buttons.' . $key;
				continue;
			}

			$value = self::scalar( $raw );
			if ( null === $value ) {
				$unmapped[] = 'buttons.' . $key;
				continue;
			}

			$values[ self::BUTTON_PROPERTIES[ $key ] ] = $value;
		}

		if ( [] === $values ) {
			return [
				'values'     => [],
				'provenance' => [],
			];
		}

		ksort( $values );

		return [
			'values'     => $values,
			'provenance' => [ 'components.button' => self::provenance( $kit_id, 'buttons' ) ],
		];
	}

	/**
	 * Token slug for an entry: its title when present, otherwise its kit id.
	 *
	 * @param array<string,mixed> $entry Evidence entry.
	 */
	private static function entry_slug( array $entry ): string {
		foreach ( [ 'title', 'id' ] as $key ) {
			$raw = $entry[ $key ] ?? null;
			if ( ! is_string( $raw ) && ! is_int( $raw ) ) {
				continue;
			}

			$slug = sanitize_title( (string) $raw );
			if ( '' !== $slug ) {
				return $slug;
			}
		}

		return '';
	}

	/**
	 * A usable scalar, or null when the evidence carried nothing.
	 *
	 * @param mixed $value Untrusted evidence value.
	 * @return int|float|string|null
	 */
	private static function scalar( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return trim( $value );
		}

		return null;
	}

	/**
	 * A contract carrying one color, used to reuse the contract color grammar.
	 *
	 * Capture must accept exactly what the contract accepts. Probing the real
	 * validator keeps the two from drifting, which a second regex here would not.
	 *
	 * @return array<string,mixed>
	 */
	private static function color_probe( string $color ): array {
		$probe                              = DirectionContract::defaults();
		$probe['identity']['name']          = self::UNNAMED;
		$probe['tokens']['colors']['probe'] = $color;

		return $probe;
	}

	/**
	 * @return array{source:string,reference:string}
	 */
	private static function provenance( int $kit_id, string $path ): array {
		return [
			'source'    => self::SOURCE,
			'reference' => 'kit:' . $kit_id . ':' . $path,
		];
	}

	/**
	 * @param string $message Human-readable reason.
	 */
	private static function error( string $message ): WP_Error {
		return new WP_Error( self::ERROR_CODE, $message, [ 'status' => 400 ] );
	}
}
