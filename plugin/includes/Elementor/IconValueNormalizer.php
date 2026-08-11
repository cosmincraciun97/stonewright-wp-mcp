<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

/**
 * Normalizes DesignSpec / agent icon values against Elementor icon control shape.
 *
 * Accepts:
 * - Font Awesome class strings (`fas fa-arrow-circle-right`, `fa-solid fa-star`)
 * - Elementor eicons (`eicon-arrow-right`, `eicon-plus`)
 * - Structured `{ value, library }` objects
 * - SVG / media library payloads `{ value: { url, id }, library: 'svg' }`
 *
 * Rejects unknown libraries and incomplete payloads with a structured error
 * so button writes never partially land with a broken icon.
 */
final class IconValueNormalizer {

	public const ERROR_CODE = 'stonewright_elementor_icon_invalid';

	/** @var list<string> */
	private const FA_LIBRARIES = [
		'fa-solid',
		'fa-regular',
		'fa-brands',
		'fa-light',
		'fa-duotone',
		'fa-thin',
	];

	/** @var array<string, string> */
	private const FA_PREFIX_LIBRARY = [
		'fas'  => 'fa-solid',
		'far'  => 'fa-regular',
		'fal'  => 'fa-light',
		'fad'  => 'fa-duotone',
		'fat'  => 'fa-thin',
		'fab'  => 'fa-brands',
		'fass' => 'fa-solid',
	];

	/**
	 * @param mixed $raw Icon string or structured object from DesignSpec.
	 * @return array{value:string|array<string,mixed>,library:string}|\WP_Error
	 */
	public static function normalize( mixed $raw ): array|\WP_Error {
		if ( null === $raw || '' === $raw ) {
			return self::error( 'empty_icon', 'An icon value is required.' );
		}

		if ( is_string( $raw ) ) {
			return self::from_string( trim( $raw ) );
		}

		if ( ! is_array( $raw ) ) {
			return self::error( 'invalid_type', 'Icon must be a string or { value, library } object.' );
		}

		$library = isset( $raw['library'] ) ? strtolower( trim( (string) $raw['library'] ) ) : '';
		$value   = $raw['value'] ?? null;

		if ( '' === $library && is_string( $value ) ) {
			return self::from_string( trim( $value ) );
		}

		if ( 'svg' === $library || 'svg' === (string) ( $raw['type'] ?? '' ) ) {
			return self::from_svg( $raw, $value );
		}

		if ( in_array( $library, self::FA_LIBRARIES, true ) ) {
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				return self::error( 'missing_value', 'Icon value is required for library ' . $library . '.' );
			}
			$normalized = self::from_string( trim( $value ) );
			if ( $normalized instanceof \WP_Error ) {
				return $normalized;
			}
			if ( ! hash_equals( $library, $normalized['library'] ) ) {
				return self::error(
					'library_mismatch',
					'Icon value prefix does not match the declared Font Awesome library.',
					[ 'library' => $library, 'inferred_library' => $normalized['library'] ]
				);
			}
			return $normalized;
		}

		if ( 'eicons' === $library || 'eicon' === $library ) {
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				return self::error( 'missing_value', 'Icon value is required for library ' . $library . '.' );
			}
			$value = trim( $value );
			$value = str_starts_with( strtolower( $value ), 'eicon-' ) ? $value : 'eicon-' . ltrim( $value, '-' );
			if ( ! self::is_eicon( $value ) ) {
				return self::error( 'invalid_class', 'Eicon values must contain one eicon-* CSS class token.' );
			}
			return [ 'value' => strtolower( $value ), 'library' => 'eicons' ];
		}

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			// Library unknown but value present — try class-string inference.
			$inferred = self::from_string( trim( $value ) );
			if ( ! ( $inferred instanceof \WP_Error ) ) {
				return $inferred;
			}
		}

		return self::error(
			'unsupported_library',
			'Icon library is not allowed. Use fa-solid/fa-regular/fa-brands, eicons, or svg.',
			[
				'library'   => $library,
				'allowed'   => array_merge( self::FA_LIBRARIES, [ 'eicons', 'svg' ] ),
			]
		);
	}

	/**
	 * Normalize button icon position for Elementor `icon_align`.
	 *
	 * Elementor button uses `row` (start) / `row-reverse` (end). Accept common
	 * aliases so DesignSpec authors can write left/right/before/after.
	 */
	public static function normalize_position( mixed $position ): ?string {
		if ( ! is_string( $position ) || '' === trim( $position ) ) {
			return null;
		}
		$position = strtolower( trim( $position ) );

		return match ( $position ) {
			'row', 'start', 'left', 'before' => 'row',
			'row-reverse', 'end', 'right', 'after' => 'row-reverse',
			default => null,
		};
	}

	/**
	 * @return array{value:string,library:string}|\WP_Error
	 */
	private static function from_string( string $value ): array|\WP_Error {
		if ( '' === $value ) {
			return self::error( 'empty_icon', 'An icon value is required.' );
		}

		if ( self::is_eicon( $value ) ) {
			return [
				'value'   => $value,
				'library' => 'eicons',
			];
		}

		// fa-solid fa-arrow-circle-right style (FA5+ long form).
		if ( preg_match( '/^(fa-(?:solid|regular|brands|light|duotone|thin))\s+(fa-[a-z0-9-]+)$/i', $value, $m ) ) {
			return [
				'value'   => strtolower( $m[1] ) . ' ' . trim( $m[2] ),
				'library' => strtolower( $m[1] ),
			];
		}

		// fas fa-arrow-circle-right style (short prefix).
		if ( preg_match( '/^(fas|far|fal|fad|fat|fab|fass)\s+(fa-[a-z0-9-]+)$/i', $value, $m ) ) {
			$prefix  = strtolower( $m[1] );
			$library = self::FA_PREFIX_LIBRARY[ $prefix ] ?? 'fa-solid';
			return [
				'value'   => $prefix . ' ' . trim( $m[2] ),
				'library' => $library,
			];
		}

		// Bare fa-* glyph with no prefix → fa-solid.
		if ( preg_match( '/^fa-[a-z0-9-]+$/i', $value ) ) {
			return [
				'value'   => 'fas ' . strtolower( $value ),
				'library' => 'fa-solid',
			];
		}

		return self::error(
			'unrecognized_icon',
			'Could not parse icon string. Use FA classes (fas fa-arrow-circle-right), eicon-*, or a structured {value,library}.',
			[ 'got' => $value ]
		);
	}

	/**
	 * @param array<string, mixed> $raw
	 * @param mixed                $value
	 * @return array{value:array<string,mixed>,library:string}|\WP_Error
	 */
	private static function from_svg( array $raw, mixed $value ): array|\WP_Error {
		$payload = is_array( $value ) ? $value : [];
		if ( [] === $payload && isset( $raw['url'] ) ) {
			$payload = [
				'url' => (string) $raw['url'],
				'id'  => isset( $raw['id'] ) ? (int) $raw['id'] : '',
			];
		}

		$url = isset( $payload['url'] ) ? trim( (string) $payload['url'] ) : '';
		$id  = isset( $payload['id'] ) ? $payload['id'] : ( $payload['value']['id'] ?? '' );
		if ( '' !== $url ) {
			$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
			if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
				return self::error( 'svg_url_invalid', 'SVG/media icon URLs must use http or https.' );
			}
			$url = esc_url_raw( $url, [ 'http', 'https' ] );
			if ( '' === $url ) {
				return self::error( 'svg_url_invalid', 'SVG/media icon URL could not be normalized safely.' );
			}
		}

		// id is never null here: isset excludes null, and ?? '' collapses missing/null nested id.
		if ( '' === $url && ( '' === $id || 0 === $id || '0' === $id ) ) {
			return self::error( 'svg_incomplete', 'SVG/media icons require value.url and/or value.id.' );
		}

		return [
			'value'   => [
				'url' => $url,
				'id'  => is_numeric( $id ) ? (int) $id : $id,
			],
			'library' => 'svg',
		];
	}

	private static function is_eicon( string $value ): bool {
		return 1 === preg_match( '/^eicon-[a-z0-9-]+$/i', $value );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function error( string $reason, string $message, array $data = [] ): \WP_Error {
		return new \WP_Error(
			self::ERROR_CODE,
			$message,
			array_merge(
				[
					'status' => 400,
					'reason' => $reason,
					'repair' => 'Provide a supported icon (FA class, eicon, or svg media) without partial button settings.',
				],
				$data
			)
		);
	}
}
