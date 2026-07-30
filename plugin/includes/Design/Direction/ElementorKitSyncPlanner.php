<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Direction;

use WP_Error;

/**
 * Compares a stored direction contract with the live Elementor kit.
 *
 * Planning is the read-only half of synchronization, and it is the only place
 * that decides what the writer is allowed to touch. That makes its properties
 * the safety properties of sync itself:
 *
 * - Elementor's globals cover colors and typography. Contract sections the kit
 *   has no global for are reported as warnings and stay in the contract; they
 *   are never bent into a field that does not exist.
 * - A value Elementor cannot store — a CSS variable, a calculated length, a
 *   unitless size — blocks the plan instead of being coerced into something
 *   storable. Coercion would make the stored contract and the live kit disagree
 *   while both looked synced.
 * - Identical values produce no operation, so applying an already-synced
 *   direction is a genuine no-op rather than a rewrite of the kit.
 * - The plan carries a `base_hash` over the live kit it was computed from. Apply
 *   re-reads the kit and refuses when that hash moved, so two agents cannot
 *   overwrite each other with stale plans.
 *
 * The planner performs no reads and no writes. Callers pass the normalized kit
 * from the typed reader.
 */
final class ElementorKitSyncPlanner {

	/** @var string Structured error code for an unusable contract or live kit. */
	public const ERROR_CODE = 'stonewright_direction_sync_invalid';

	/** @var string Structured error code for a kit that changed since the dry run. */
	public const STALE_CODE = 'stonewright_direction_sync_stale';

	/** @var string Structured error code for a plan holding values the kit cannot store. */
	public const BLOCKED_CODE = 'stonewright_direction_sync_blocked';

	/** @var string Warning reason: the kit has no global for this token group. */
	public const REASON_UNSUPPORTED_GROUP = 'unsupported_token_group';

	/** @var string Warning reason: the kit has no global for this property. */
	public const REASON_UNSUPPORTED_PROPERTY = 'unsupported_property';

	/** @var string Blocked reason: the kit cannot store this value. */
	public const REASON_UNSUPPORTED_VALUE = 'unsupported_value';

	/**
	 * Allowlisted live-kit envelope keys.
	 *
	 * @var list<string>
	 */
	private const LIVE_KEYS = [ 'kit_id', 'colors', 'typography' ];

	/**
	 * Token groups Elementor has no global for, in report order.
	 *
	 * @var list<string>
	 */
	private const UNSUPPORTED_GROUPS = [ 'elevation', 'motion', 'radii', 'spacing' ];

	/**
	 * Typography properties Elementor stores as kit globals.
	 *
	 * @var list<string>
	 */
	public const TYPOGRAPHY_PROPERTIES = [
		'font-family',
		'font-size',
		'font-weight',
		'letter-spacing',
		'line-height',
	];

	/**
	 * Typography properties Elementor stores as a size/unit pair.
	 *
	 * @var list<string>
	 */
	private const DIMENSION_PROPERTIES = [ 'font-size', 'letter-spacing', 'line-height' ];

	private const HEX_PATTERN = '/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i';

	private const FUNCTIONAL_COLOR_PATTERN = '/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9a-z%.,\s\/]+\)$/i';

	private const DIMENSION_PATTERN = '/^(-?\d+(?:\.\d+)?)(px|em|rem|%|vw|vh)$/';

	private const NUMBER_PATTERN = '/^\d+(?:\.\d+)?$/';

	private const FONT_WEIGHT_PATTERN = '/^(?:normal|bold|lighter|bolder|[1-9]00)$/';

	private const FONT_FAMILY_PATTERN = '/^[A-Za-z0-9\s,\'"_-]+$/';

	/** @var string Unit Elementor uses for a line height given as a bare number. */
	private const DEFAULT_LINE_HEIGHT_UNIT = 'em';

	/**
	 * Plans the change set that would bring the live kit in line with a contract.
	 *
	 * @param array<string,mixed> $contract  Stored direction contract.
	 * @param array<string,mixed> $live_kit  Normalized kit from the typed reader.
	 * @return array{base_hash:string,kit_id:int,operations:list<array<string,mixed>>,warnings:list<array{path:string,reason:string}>,blocked:list<array{path:string,reason:string}>,ready_to_apply:bool}|WP_Error
	 */
	public static function plan( array $contract, array $live_kit ) {
		$validated = DirectionContractValidator::validate( $contract );
		if ( $validated instanceof WP_Error ) {
			return self::error( $validated->get_error_message() );
		}

		$live = self::check_live_kit( $live_kit );
		if ( $live instanceof WP_Error ) {
			return $live;
		}

		$operations = [];
		$warnings   = [];
		$blocked    = [];

		self::plan_colors( $validated, $live, $operations, $blocked );
		self::plan_typography( $validated, $live, $operations, $warnings, $blocked );
		self::report_unsupported( $validated, $warnings );

		usort(
			$operations,
			static fn( array $a, array $b ): int => strcmp( (string) $a['path'], (string) $b['path'] )
		);

		$sync_ready = true === ( $validated['readiness']['sync_ready'] ?? false );

		return [
			'base_hash'      => self::fingerprint( $live ),
			'kit_id'         => (int) $live['kit_id'],
			'operations'     => array_values( $operations ),
			'warnings'       => array_values( $warnings ),
			'blocked'        => array_values( $blocked ),
			'ready_to_apply' => $sync_ready && [] === $blocked,
		];
	}

	/**
	 * Canonical string form of a kit or contract value, or null when unusable.
	 *
	 * Both halves of the comparison run through this, so "48px" from a contract
	 * and `['size' => 48, 'unit' => 'px']` from the kit are recognized as the same
	 * value instead of planning a pointless write.
	 *
	 * @param string $property Contract property name.
	 * @param mixed  $raw      Untrusted value from either side.
	 */
	public static function canonical_value( string $property, $raw ): ?string {
		if ( is_array( $raw ) ) {
			if ( ! in_array( $property, self::DIMENSION_PROPERTIES, true ) ) {
				return null;
			}

			$size = $raw['size'] ?? null;
			$unit = isset( $raw['unit'] ) && is_string( $raw['unit'] ) ? strtolower( $raw['unit'] ) : '';

			if ( ! is_numeric( $size ) || '' === $unit ) {
				return null;
			}

			return self::number( (float) $size ) . $unit;
		}

		if ( is_int( $raw ) || is_float( $raw ) ) {
			$raw = self::number( (float) $raw );
		}

		if ( ! is_string( $raw ) ) {
			return null;
		}

		$value = trim( $raw );
		if ( '' === $value ) {
			return null;
		}

		if ( 'color' === $property ) {
			if ( 1 === preg_match( self::HEX_PATTERN, $value ) ) {
				return strtoupper( $value );
			}

			return 1 === preg_match( self::FUNCTIONAL_COLOR_PATTERN, $value ) ? strtolower( $value ) : null;
		}

		if ( 'font-family' === $property ) {
			return 1 === preg_match( self::FONT_FAMILY_PATTERN, $value ) ? $value : null;
		}

		if ( 'font-weight' === $property ) {
			return 1 === preg_match( self::FONT_WEIGHT_PATTERN, $value ) ? strtolower( $value ) : null;
		}

		if ( ! in_array( $property, self::DIMENSION_PROPERTIES, true ) ) {
			return null;
		}

		$matched = [];
		if ( 1 === preg_match( self::DIMENSION_PATTERN, strtolower( $value ), $matched ) ) {
			return self::number( (float) $matched[1] ) . $matched[2];
		}

		// Elementor stores a bare line height as a unitless em value.
		if ( 'line-height' === $property && 1 === preg_match( self::NUMBER_PATTERN, $value ) ) {
			return self::number( (float) $value ) . self::DEFAULT_LINE_HEIGHT_UNIT;
		}

		return null;
	}

	/**
	 * Splits a canonical dimension into the size/unit pair Elementor stores.
	 *
	 * @return array{size:float,unit:string}|null
	 */
	public static function dimension_parts( string $canonical ): ?array {
		$matched = [];
		if ( 1 !== preg_match( self::DIMENSION_PATTERN, $canonical, $matched ) ) {
			return null;
		}

		return [
			'size' => (float) $matched[1],
			'unit' => $matched[2],
		];
	}

	public static function is_dimension_property( string $property ): bool {
		return in_array( $property, self::DIMENSION_PROPERTIES, true );
	}

	/**
	 * Content hash of the live kit, used to detect a kit that moved.
	 *
	 * @param array<string,mixed> $live Normalized live kit.
	 */
	private static function fingerprint( array $live ): string {
		$encoded = wp_json_encode( $live );

		return hash( 'sha256', is_string( $encoded ) ? $encoded : '' );
	}

	/**
	 * @param array<string,mixed>              $contract   Validated contract.
	 * @param array<string,mixed>              $live       Normalized live kit.
	 * @param list<array<string,mixed>>        $operations Planned operations, appended to.
	 * @param list<array{path:string,reason:string}> $blocked Blocked paths, appended to.
	 */
	private static function plan_colors( array $contract, array $live, array &$operations, array &$blocked ): void {
		$entries = self::by_slug( $live['colors'] );

		foreach ( (array) ( $contract['tokens']['colors'] ?? [] ) as $slug => $value ) {
			$slug = (string) $slug;
			$path = 'tokens.colors.' . $slug;

			$to = self::canonical_value( 'color', $value );
			if ( null === $to ) {
				$blocked[] = [
					'path'   => $path,
					'reason' => self::REASON_UNSUPPORTED_VALUE,
				];
				continue;
			}

			$entry = $entries[ $slug ] ?? null;
			$from  = null === $entry ? null : self::canonical_value( 'color', $entry['color'] ?? null );

			if ( null !== $from && $from === $to ) {
				continue;
			}

			$operations[] = [
				'group'    => 'colors',
				'action'   => null === $entry ? 'create' : 'update',
				'bucket'   => null === $entry ? 'custom' : (string) $entry['bucket'],
				'target'   => null === $entry ? $slug : (string) $entry['id'],
				'property' => 'color',
				'path'     => $path,
				'from'     => null === $entry ? null : (string) ( $entry['color'] ?? '' ),
				'to'       => is_string( $value ) ? trim( $value ) : $to,
			];
		}
	}

	/**
	 * @param array<string,mixed>                    $contract   Validated contract.
	 * @param array<string,mixed>                    $live       Normalized live kit.
	 * @param list<array<string,mixed>>              $operations Planned operations, appended to.
	 * @param list<array{path:string,reason:string}> $warnings   Reported paths, appended to.
	 * @param list<array{path:string,reason:string}> $blocked    Blocked paths, appended to.
	 */
	private static function plan_typography( array $contract, array $live, array &$operations, array &$warnings, array &$blocked ): void {
		$entries = self::by_slug( $live['typography'] );

		foreach ( (array) ( $contract['tokens']['typography'] ?? [] ) as $slug => $properties ) {
			$slug  = (string) $slug;
			$entry = $entries[ $slug ] ?? null;

			foreach ( (array) $properties as $property => $value ) {
				$property = (string) $property;
				$path     = 'tokens.typography.' . $slug . '.' . $property;

				if ( ! in_array( $property, self::TYPOGRAPHY_PROPERTIES, true ) ) {
					$warnings[] = [
						'path'   => $path,
						'reason' => self::REASON_UNSUPPORTED_PROPERTY,
					];
					continue;
				}

				$to = self::canonical_value( $property, $value );
				if ( null === $to ) {
					$blocked[] = [
						'path'   => $path,
						'reason' => self::REASON_UNSUPPORTED_VALUE,
					];
					continue;
				}

				$from = null === $entry
					? null
					: self::canonical_value( $property, $entry['properties'][ $property ] ?? null );

				if ( null !== $from && $from === $to ) {
					continue;
				}

				$operations[] = [
					'group'    => 'typography',
					'action'   => null === $entry ? 'create' : 'update',
					'bucket'   => null === $entry ? 'custom' : (string) $entry['bucket'],
					'target'   => null === $entry ? $slug : (string) $entry['id'],
					'property' => $property,
					'path'     => $path,
					'from'     => $from,
					'to'       => $to,
				];
			}
		}
	}

	/**
	 * Records contract sections Elementor has no global for.
	 *
	 * @param array<string,mixed>                    $contract Validated contract.
	 * @param list<array{path:string,reason:string}> $warnings Reported paths, appended to.
	 */
	private static function report_unsupported( array $contract, array &$warnings ): void {
		foreach ( self::UNSUPPORTED_GROUPS as $group ) {
			if ( [] !== (array) ( $contract['tokens'][ $group ] ?? [] ) ) {
				$warnings[] = [
					'path'   => 'tokens.' . $group,
					'reason' => self::REASON_UNSUPPORTED_GROUP,
				];
			}
		}

		foreach ( array_keys( (array) ( $contract['components'] ?? [] ) ) as $component ) {
			$warnings[] = [
				'path'   => 'components.' . (string) $component,
				'reason' => self::REASON_UNSUPPORTED_GROUP,
			];
		}
	}

	/**
	 * Indexes live entries by token slug, keeping the first occurrence.
	 *
	 * @param list<array<string,mixed>> $entries Normalized live entries.
	 * @return array<string,array<string,mixed>>
	 */
	private static function by_slug( array $entries ): array {
		$indexed = [];

		foreach ( $entries as $entry ) {
			$slug = (string) $entry['slug'];
			if ( ! array_key_exists( $slug, $indexed ) ) {
				$indexed[ $slug ] = $entry;
			}
		}

		return $indexed;
	}

	/**
	 * Validates the live-kit envelope the planner was handed.
	 *
	 * @param array<string,mixed> $live_kit Untrusted live kit.
	 * @return array{kit_id:int,colors:list<array<string,mixed>>,typography:list<array<string,mixed>>}|WP_Error
	 */
	private static function check_live_kit( array $live_kit ) {
		$unknown = array_diff( array_keys( $live_kit ), self::LIVE_KEYS );
		if ( [] !== $unknown ) {
			return self::error(
				sprintf(
					/* translators: %s: comma-separated list of rejected live kit keys. */
					__( 'Unknown live kit field(s): %s.', 'stonewright' ),
					implode( ', ', array_map( 'strval', $unknown ) )
				)
			);
		}

		$kit_id = isset( $live_kit['kit_id'] ) && is_numeric( $live_kit['kit_id'] ) ? (int) $live_kit['kit_id'] : 0;
		if ( $kit_id < 1 ) {
			return self::error( __( 'The live kit must name the Elementor kit it came from.', 'stonewright' ) );
		}

		$checked = [ 'kit_id' => $kit_id ];

		foreach ( [ 'colors', 'typography' ] as $group ) {
			$entries = $live_kit[ $group ] ?? [];

			if ( ! is_array( $entries ) || ( [] !== $entries && array_values( $entries ) !== $entries ) ) {
				return self::error(
					sprintf(
						/* translators: %s: live kit group name. */
						__( 'Live kit %s must be a list of entries.', 'stonewright' ),
						$group
					)
				);
			}

			if ( count( $entries ) > DirectionContract::MAX_LIST_ITEMS ) {
				return self::error(
					sprintf(
						/* translators: 1: live kit group name, 2: maximum entries. */
						__( 'Live kit %1$s holds more than %2$d entries.', 'stonewright' ),
						$group,
						DirectionContract::MAX_LIST_ITEMS
					)
				);
			}

			$normalized = [];

			foreach ( $entries as $entry ) {
				$checked_entry = self::check_live_entry( $entry, $group );
				if ( $checked_entry instanceof WP_Error ) {
					return $checked_entry;
				}

				$normalized[] = $checked_entry;
			}

			$checked[ $group ] = $normalized;
		}

		return $checked;
	}

	/**
	 * @param mixed  $entry Untrusted live entry.
	 * @param string $group Live kit group name.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function check_live_entry( $entry, string $group ) {
		if ( ! is_array( $entry ) ) {
			return self::error(
				sprintf(
					/* translators: %s: live kit group name. */
					__( 'Each live kit %s entry must be a map.', 'stonewright' ),
					$group
				)
			);
		}

		$id = isset( $entry['id'] ) && is_string( $entry['id'] ) ? trim( $entry['id'] ) : '';
		if ( '' === $id ) {
			return self::error(
				sprintf(
					/* translators: %s: live kit group name. */
					__( 'Each live kit %s entry must carry the kit entry id.', 'stonewright' ),
					$group
				)
			);
		}

		$slug = isset( $entry['slug'] ) && is_string( $entry['slug'] ) ? trim( $entry['slug'] ) : '';
		if ( '' === $slug ) {
			return self::error(
				sprintf(
					/* translators: %s: live kit group name. */
					__( 'Each live kit %s entry must carry a token slug.', 'stonewright' ),
					$group
				)
			);
		}

		$normalized = [
			'slug'   => $slug,
			'id'     => $id,
			'title'  => isset( $entry['title'] ) && is_string( $entry['title'] ) ? $entry['title'] : '',
			'bucket' => 'system' === ( $entry['bucket'] ?? '' ) ? 'system' : 'custom',
		];

		if ( 'colors' === $group ) {
			$normalized['color'] = isset( $entry['color'] ) && is_string( $entry['color'] ) ? trim( $entry['color'] ) : '';

			return $normalized;
		}

		$properties = [];

		foreach ( (array) ( $entry['properties'] ?? [] ) as $property => $value ) {
			$property = (string) $property;
			if ( in_array( $property, self::TYPOGRAPHY_PROPERTIES, true ) && ( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_array( $value ) ) ) {
				$properties[ $property ] = $value;
			}
		}

		ksort( $properties );
		$normalized['properties'] = $properties;

		return $normalized;
	}

	/**
	 * Shortest decimal form of a float, so 48.0 and 48 hash alike.
	 */
	private static function number( float $value ): string {
		$formatted = rtrim( rtrim( sprintf( '%.4F', $value ), '0' ), '.' );

		return '' === $formatted || '-' === $formatted ? '0' : $formatted;
	}

	/**
	 * @param string $message Human-readable reason.
	 */
	private static function error( string $message ): WP_Error {
		return new WP_Error( self::ERROR_CODE, $message, [ 'status' => 400 ] );
	}
}
