<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Direction;

use WP_Error;

/**
 * Validates and normalizes a Stonewright Design Direction contract.
 *
 * Validation is allowlist-only: unknown top-level fields, unknown token
 * groups, unslugged token keys, and CSS values outside the accepted grammar
 * are rejected rather than stripped, so a caller can never silently ship a
 * contract that means something different from what it sent.
 *
 * Successful validation returns the contract in canonical key order with
 * absent optional sections filled from defaults, which makes the encoded
 * contract and its hash stable regardless of input ordering.
 */
final class DirectionContractValidator {

	/** @var string Token and component keys: lowercase slugs. */
	private const SLUG_PATTERN = '/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/';

	/** @var string Provenance keys: dotted lowercase paths such as tokens.colors. */
	private const PATH_PATTERN = '/^[a-z0-9]+(?:[-_.][a-z0-9]+)*$/';

	/** @var string URL schemes that must never appear in a stored reference. */
	private const UNSAFE_SCHEME_PATTERN = '/^\s*(?:javascript|data|vbscript|file)\s*:/i';

	/**
	 * Substrings that turn a CSS value into an injection vector.
	 *
	 * @var list<string>
	 */
	private const UNSAFE_CSS_SUBSTRINGS = [
		'url(',
		'image-set(',
		'expression(',
		'javascript:',
		'data:',
		'@import',
		'\\',
		'<',
		'>',
		'{',
		'}',
		';',
		'/*',
		'*/',
	];

	/** @var string Characters permitted in a non-color CSS value. */
	private const CSS_VALUE_PATTERN = '/^[a-zA-Z0-9\s%#().,\/+\'"_-]+$/';

	/**
	 * Validates a direction contract.
	 *
	 * @param array<string,mixed> $input Untrusted contract payload.
	 * @return array<string,mixed>|WP_Error Canonical contract, or
	 *         stonewright_direction_invalid on failure.
	 */
	public static function validate( array $input ) {
		$unknown = array_diff( array_keys( $input ), DirectionContract::TOP_LEVEL_KEYS );
		if ( [] !== $unknown ) {
			return self::error(
				'Unknown contract field(s): ' . implode( ', ', $unknown ) . '.',
				[ 'fields' => array_values( $unknown ) ]
			);
		}

		if ( ( $input['schema_version'] ?? null ) !== DirectionContract::SCHEMA_VERSION ) {
			return self::error(
				'Unsupported contract schema version. Expected ' . DirectionContract::SCHEMA_VERSION . '.'
			);
		}

		$contract = DirectionContract::defaults();

		$identity = self::validate_identity( $input['identity'] ?? null );
		if ( $identity instanceof WP_Error ) {
			return $identity;
		}
		$contract['identity'] = $identity;

		$tokens = self::validate_tokens( $input['tokens'] ?? [] );
		if ( $tokens instanceof WP_Error ) {
			return $tokens;
		}
		$contract['tokens'] = $tokens;

		$components = self::validate_components( $input['components'] ?? [] );
		if ( $components instanceof WP_Error ) {
			return $components;
		}
		$contract['components'] = $components;

		$dials = self::validate_dials( $input['dials'] ?? null );
		if ( $dials instanceof WP_Error ) {
			return $dials;
		}
		$contract['dials'] = $dials;

		$guidance = self::validate_guidance( $input['guidance'] ?? [] );
		if ( $guidance instanceof WP_Error ) {
			return $guidance;
		}
		$contract['guidance'] = $guidance;

		$provenance = self::validate_provenance( $input['provenance'] ?? [] );
		if ( $provenance instanceof WP_Error ) {
			return $provenance;
		}
		$contract['provenance'] = $provenance;

		$waivers = self::validate_waivers( $input['waivers'] ?? [] );
		if ( $waivers instanceof WP_Error ) {
			return $waivers;
		}
		$contract['waivers'] = $waivers;

		$readiness = self::validate_readiness( $input['readiness'] ?? [] );
		if ( $readiness instanceof WP_Error ) {
			return $readiness;
		}
		$contract['readiness'] = $readiness;

		$encoded = wp_json_encode( $contract );
		if ( ! is_string( $encoded ) ) {
			return self::error( 'Contract could not be encoded.' );
		}

		if ( strlen( $encoded ) > DirectionContract::MAX_CONTRACT_BYTES ) {
			return self::error(
				'Contract exceeds the ' . DirectionContract::MAX_CONTRACT_BYTES . ' byte limit.',
				[ 'bytes' => strlen( $encoded ) ]
			);
		}

		return $contract;
	}

	/**
	 * @param mixed $identity Untrusted identity section.
	 * @return array{name:string,summary:string}|WP_Error
	 */
	private static function validate_identity( $identity ) {
		if ( ! is_array( $identity ) ) {
			return self::error( 'Contract identity is required.' );
		}

		$unknown = array_diff( array_keys( $identity ), [ 'name', 'summary' ] );
		if ( [] !== $unknown ) {
			return self::error( 'Unknown identity field(s): ' . implode( ', ', $unknown ) . '.' );
		}

		$name = self::validate_string( $identity['name'] ?? null, 'identity.name' );
		if ( $name instanceof WP_Error ) {
			return $name;
		}

		if ( '' === trim( $name ) ) {
			return self::error( 'Contract identity name cannot be empty.' );
		}

		$summary = self::validate_string( $identity['summary'] ?? '', 'identity.summary' );
		if ( $summary instanceof WP_Error ) {
			return $summary;
		}

		return [
			'name'    => trim( $name ),
			'summary' => trim( $summary ),
		];
	}

	/**
	 * @param mixed $tokens Untrusted token section.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function validate_tokens( $tokens ) {
		if ( ! is_array( $tokens ) ) {
			return self::error( 'Contract tokens must be a map of token groups.' );
		}

		$unknown = array_diff( array_keys( $tokens ), DirectionContract::TOKEN_GROUPS );
		if ( [] !== $unknown ) {
			return self::error( 'Unknown token group(s): ' . implode( ', ', $unknown ) . '.' );
		}

		$validated = DirectionContract::defaults()['tokens'];

		foreach ( DirectionContract::TOKEN_GROUPS as $group ) {
			if ( ! array_key_exists( $group, $tokens ) ) {
				continue;
			}

			$entries = self::normalize_map( $tokens[ $group ], 'tokens.' . $group );
			if ( $entries instanceof WP_Error ) {
				return $entries;
			}

			$group_values = [];

			foreach ( $entries as $key => $value ) {
				$path = 'tokens.' . $group . '.' . $key;

				if ( 'typography' === $group ) {
					$checked = self::validate_typography_entry( $value, $path );
				} elseif ( 'colors' === $group ) {
					$checked = self::validate_color( $value, $path );
				} else {
					$checked = self::validate_css_scalar( $value, $path );
				}

				if ( $checked instanceof WP_Error ) {
					return $checked;
				}

				$group_values[ $key ] = $checked;
			}

			ksort( $group_values );
			$validated[ $group ] = $group_values;
		}

		return $validated;
	}

	/**
	 * @param mixed  $entry Untrusted typography entry.
	 * @param string $path  Contract path for error messages.
	 * @return array<string,int|float|string>|WP_Error
	 */
	private static function validate_typography_entry( $entry, string $path ) {
		$properties = self::normalize_map( $entry, $path );
		if ( $properties instanceof WP_Error ) {
			return $properties;
		}

		$validated = [];

		foreach ( $properties as $key => $value ) {
			$checked = self::validate_css_scalar( $value, $path . '.' . $key );
			if ( $checked instanceof WP_Error ) {
				return $checked;
			}

			$validated[ $key ] = $checked;
		}

		ksort( $validated );

		return $validated;
	}

	/**
	 * @param mixed $components Untrusted component section.
	 * @return array<string,array<string,mixed>>|WP_Error
	 */
	private static function validate_components( $components ) {
		$entries = self::normalize_map( $components, 'components' );
		if ( $entries instanceof WP_Error ) {
			return $entries;
		}

		$validated = [];

		foreach ( $entries as $key => $value ) {
			$properties = self::normalize_map( $value, 'components.' . $key );
			if ( $properties instanceof WP_Error ) {
				return $properties;
			}

			$checked_properties = [];

			foreach ( $properties as $property => $property_value ) {
				$checked = self::validate_css_scalar( $property_value, 'components.' . $key . '.' . $property, true );
				if ( $checked instanceof WP_Error ) {
					return $checked;
				}

				$checked_properties[ $property ] = $checked;
			}

			ksort( $checked_properties );
			$validated[ $key ] = $checked_properties;
		}

		ksort( $validated );

		return $validated;
	}

	/**
	 * @param mixed $dials Untrusted dial section.
	 * @return array{variance:int,density:int,motion:int}|WP_Error
	 */
	private static function validate_dials( $dials ) {
		if ( ! is_array( $dials ) ) {
			return self::error( 'Contract dials are required.' );
		}

		$unknown = array_diff( array_keys( $dials ), DirectionContract::DIALS );
		if ( [] !== $unknown ) {
			return self::error( 'Unknown dial(s): ' . implode( ', ', $unknown ) . '.' );
		}

		$validated = [];

		foreach ( DirectionContract::DIALS as $dial ) {
			if ( ! array_key_exists( $dial, $dials ) ) {
				return self::error( 'Dial ' . $dial . ' is required.' );
			}

			$value = $dials[ $dial ];
			if ( ! is_int( $value ) ) {
				return self::error( 'Dial ' . $dial . ' must be an integer.' );
			}

			if ( $value < DirectionContract::DIAL_MIN || $value > DirectionContract::DIAL_MAX ) {
				return self::error(
					'Dial ' . $dial . ' must be between ' . DirectionContract::DIAL_MIN
					. ' and ' . DirectionContract::DIAL_MAX . '.'
				);
			}

			$validated[ $dial ] = $value;
		}

		return [
			'variance' => $validated['variance'],
			'density'  => $validated['density'],
			'motion'   => $validated['motion'],
		];
	}

	/**
	 * @param mixed $guidance Untrusted guidance section.
	 * @return array{do:list<string>,avoid:list<string>}|WP_Error
	 */
	private static function validate_guidance( $guidance ) {
		if ( ! is_array( $guidance ) ) {
			return self::error( 'Contract guidance must be a map of do and avoid lists.' );
		}

		$unknown = array_diff( array_keys( $guidance ), [ 'do', 'avoid' ] );
		if ( [] !== $unknown ) {
			return self::error( 'Unknown guidance field(s): ' . implode( ', ', $unknown ) . '.' );
		}

		$validated = [
			'do'    => [],
			'avoid' => [],
		];

		foreach ( [ 'do', 'avoid' ] as $bucket ) {
			$list = self::validate_string_list( $guidance[ $bucket ] ?? [], 'guidance.' . $bucket );
			if ( $list instanceof WP_Error ) {
				return $list;
			}

			$validated[ $bucket ] = $list;
		}

		return $validated;
	}

	/**
	 * @param mixed $provenance Untrusted provenance section.
	 * @return array<string,array{source:string,reference:string}>|WP_Error
	 */
	private static function validate_provenance( $provenance ) {
		$entries = self::normalize_map( $provenance, 'provenance', self::PATH_PATTERN );
		if ( $entries instanceof WP_Error ) {
			return $entries;
		}

		$validated = [];

		foreach ( $entries as $key => $value ) {
			if ( ! is_array( $value ) ) {
				return self::error( 'Provenance entry ' . $key . ' must be a map.' );
			}

			$unknown = array_diff( array_keys( $value ), [ 'source', 'reference' ] );
			if ( [] !== $unknown ) {
				return self::error( 'Unknown provenance field(s) on ' . $key . ': ' . implode( ', ', $unknown ) . '.' );
			}

			$source = self::validate_string( $value['source'] ?? null, 'provenance.' . $key . '.source' );
			if ( $source instanceof WP_Error ) {
				return $source;
			}

			$reference = self::validate_reference( $value['reference'] ?? null, 'provenance.' . $key . '.reference' );
			if ( $reference instanceof WP_Error ) {
				return $reference;
			}

			$validated[ $key ] = [
				'source'    => trim( $source ),
				'reference' => $reference,
			];
		}

		ksort( $validated );

		return $validated;
	}

	/**
	 * @param mixed $waivers Untrusted waiver list.
	 * @return list<array{rule_id:string,reason:string}>|WP_Error
	 */
	private static function validate_waivers( $waivers ) {
		if ( ! is_array( $waivers ) ) {
			return self::error( 'Contract waivers must be a list.' );
		}

		if ( count( $waivers ) > DirectionContract::MAX_LIST_ITEMS ) {
			return self::error( 'Contract waivers exceed ' . DirectionContract::MAX_LIST_ITEMS . ' items.' );
		}

		$validated = [];

		foreach ( array_values( $waivers ) as $index => $waiver ) {
			if ( ! is_array( $waiver ) ) {
				return self::error( 'Waiver ' . $index . ' must be a map.' );
			}

			$unknown = array_diff( array_keys( $waiver ), [ 'rule_id', 'reason' ] );
			if ( [] !== $unknown ) {
				return self::error( 'Unknown waiver field(s): ' . implode( ', ', $unknown ) . '.' );
			}

			$rule_id = self::validate_string( $waiver['rule_id'] ?? null, 'waivers.' . $index . '.rule_id' );
			if ( $rule_id instanceof WP_Error ) {
				return $rule_id;
			}

			$reason = self::validate_string( $waiver['reason'] ?? null, 'waivers.' . $index . '.reason' );
			if ( $reason instanceof WP_Error ) {
				return $reason;
			}

			if ( '' === trim( $rule_id ) || '' === trim( $reason ) ) {
				return self::error( 'Waiver ' . $index . ' requires both rule_id and reason.' );
			}

			$validated[] = [
				'rule_id' => trim( $rule_id ),
				'reason'  => trim( $reason ),
			];
		}

		return $validated;
	}

	/**
	 * @param mixed $readiness Untrusted readiness section.
	 * @return array{ready:bool,sync_ready:bool,issues:list<string>}|WP_Error
	 */
	private static function validate_readiness( $readiness ) {
		if ( ! is_array( $readiness ) ) {
			return self::error( 'Contract readiness must be a map.' );
		}

		$unknown = array_diff( array_keys( $readiness ), [ 'ready', 'sync_ready', 'issues' ] );
		if ( [] !== $unknown ) {
			return self::error( 'Unknown readiness field(s): ' . implode( ', ', $unknown ) . '.' );
		}

		foreach ( [ 'ready', 'sync_ready' ] as $flag ) {
			if ( array_key_exists( $flag, $readiness ) && ! is_bool( $readiness[ $flag ] ) ) {
				return self::error( 'Readiness ' . $flag . ' must be a boolean.' );
			}
		}

		$issues = self::validate_string_list( $readiness['issues'] ?? [], 'readiness.issues' );
		if ( $issues instanceof WP_Error ) {
			return $issues;
		}

		return [
			'ready'      => (bool) ( $readiness['ready'] ?? false ),
			'sync_ready' => (bool) ( $readiness['sync_ready'] ?? false ),
			'issues'     => $issues,
		];
	}

	/**
	 * Normalizes a map: enforces slug keys, rejects duplicates after
	 * normalization, and enforces the item cap.
	 *
	 * @param mixed  $value        Untrusted map.
	 * @param string $path         Contract path for error messages.
	 * @param string $key_pattern  Key pattern to enforce.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function normalize_map( $value, string $path, string $key_pattern = self::SLUG_PATTERN ) {
		if ( ! is_array( $value ) ) {
			return self::error( $path . ' must be a map.' );
		}

		if ( count( $value ) > DirectionContract::MAX_LIST_ITEMS ) {
			return self::error( $path . ' exceeds ' . DirectionContract::MAX_LIST_ITEMS . ' entries.' );
		}

		$normalized = [];

		foreach ( $value as $key => $entry ) {
			if ( ! is_string( $key ) ) {
				return self::error( $path . ' keys must be strings.' );
			}

			$slug = strtolower( trim( $key ) );

			if ( 1 !== preg_match( $key_pattern, $slug ) ) {
				return self::error( $path . ' key "' . $key . '" must be a lowercase slug.' );
			}

			if ( array_key_exists( $slug, $normalized ) ) {
				return self::error( $path . ' contains duplicate key "' . $slug . '" after normalization.' );
			}

			$normalized[ $slug ] = $entry;
		}

		return $normalized;
	}

	/**
	 * @param mixed  $value Untrusted list of strings.
	 * @param string $path  Contract path for error messages.
	 * @return list<string>|WP_Error
	 */
	private static function validate_string_list( $value, string $path ) {
		if ( ! is_array( $value ) ) {
			return self::error( $path . ' must be a list.' );
		}

		if ( count( $value ) > DirectionContract::MAX_LIST_ITEMS ) {
			return self::error( $path . ' exceeds ' . DirectionContract::MAX_LIST_ITEMS . ' items.' );
		}

		$validated = [];

		foreach ( array_values( $value ) as $index => $entry ) {
			$checked = self::validate_string( $entry, $path . '.' . $index );
			if ( $checked instanceof WP_Error ) {
				return $checked;
			}

			$validated[] = trim( $checked );
		}

		return $validated;
	}

	/**
	 * @param mixed  $value Untrusted string.
	 * @param string $path  Contract path for error messages.
	 * @return string|WP_Error
	 */
	private static function validate_string( $value, string $path ) {
		if ( ! is_string( $value ) ) {
			return self::error( $path . ' must be a string.' );
		}

		if ( strlen( $value ) > DirectionContract::MAX_STRING_LENGTH ) {
			return self::error( $path . ' exceeds ' . DirectionContract::MAX_STRING_LENGTH . ' characters.' );
		}

		return $value;
	}

	/**
	 * Validates a color against the accepted grammar: hex, rgb(a), hsl(a),
	 * or a CSS custom-property reference with an optional color fallback.
	 *
	 * @param mixed  $value Untrusted color.
	 * @param string $path  Contract path for error messages.
	 * @return string|WP_Error
	 */
	private static function validate_color( $value, string $path ) {
		$color = self::validate_string( $value, $path );
		if ( $color instanceof WP_Error ) {
			return $color;
		}

		if ( self::is_color( trim( $color ) ) ) {
			return $color;
		}

		return self::error( $path . ' is not an accepted color value.' );
	}

	private static function is_color( string $color ): bool {
		$number  = '-?\d+(?:\.\d+)?';
		$hex     = '#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})';
		$rgb     = 'rgba?\(\s*' . $number . '%?\s*[,\s]\s*' . $number . '%?\s*[,\s]\s*' . $number
			. '%?\s*(?:[,\/]\s*' . $number . '%?\s*)?\)';
		$hsl     = 'hsla?\(\s*' . $number . '(?:deg|rad|turn)?\s*[,\s]\s*' . $number . '%\s*[,\s]\s*'
			. $number . '%\s*(?:[,\/]\s*' . $number . '%?\s*)?\)';
		$literal = '(?:' . $hex . '|' . $rgb . '|' . $hsl . ')';
		$var     = 'var\(\s*--[a-z0-9-]+\s*(?:,\s*' . $literal . '\s*)?\)';

		return 1 === preg_match( '/^(?:' . $literal . '|' . $var . ')$/i', $color );
	}

	/**
	 * Validates a scalar CSS-bound value: a bounded number, a boolean, or a
	 * string restricted to the safe CSS grammar.
	 *
	 * @param mixed  $value       Untrusted value.
	 * @param string $path        Contract path for error messages.
	 * @param bool   $allow_bool  Whether booleans are accepted.
	 * @return int|float|string|bool|WP_Error
	 */
	private static function validate_css_scalar( $value, string $path, bool $allow_bool = false ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( $allow_bool && is_bool( $value ) ) {
			return $value;
		}

		$string = self::validate_string( $value, $path );
		if ( $string instanceof WP_Error ) {
			return $string;
		}

		if ( '' === trim( $string ) ) {
			return self::error( $path . ' cannot be empty.' );
		}

		foreach ( self::UNSAFE_CSS_SUBSTRINGS as $needle ) {
			if ( false !== stripos( $string, $needle ) ) {
				return self::error( $path . ' contains an unsafe CSS construct.' );
			}
		}

		if ( 1 !== preg_match( self::CSS_VALUE_PATTERN, $string ) ) {
			return self::error( $path . ' contains characters outside the accepted CSS grammar.' );
		}

		return $string;
	}

	/**
	 * @param mixed  $value Untrusted provenance reference.
	 * @param string $path  Contract path for error messages.
	 * @return string|WP_Error
	 */
	private static function validate_reference( $value, string $path ) {
		$reference = self::validate_string( $value, $path );
		if ( $reference instanceof WP_Error ) {
			return $reference;
		}

		$reference = trim( $reference );

		if ( 1 === preg_match( self::UNSAFE_SCHEME_PATTERN, $reference ) ) {
			return self::error( $path . ' uses an unsafe URL scheme.' );
		}

		if ( false !== strpos( $reference, '<' ) || false !== strpos( $reference, '>' ) ) {
			return self::error( $path . ' cannot contain markup.' );
		}

		return $reference;
	}

	/**
	 * @param string              $message Human-readable reason.
	 * @param array<string,mixed> $data    Structured error data.
	 */
	private static function error( string $message, array $data = [] ): WP_Error {
		return new WP_Error( DirectionContract::ERROR_CODE, $message, $data );
	}
}
