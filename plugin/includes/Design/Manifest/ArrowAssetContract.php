<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Manifest;

use Stonewright\WpMcp\Design\Assets\AssetNormalizer;

/** Explicit asset, geometry, and accessibility contract for carousel arrows. */
final class ArrowAssetContract {

	/**
	 * @param array<string,mixed> $input
	 * @param array<int,array<string,mixed>> $assets
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function validate( array $input, bool $required = true, array $assets = [] ): array|\WP_Error {
		if ( ! $required ) {
			$rtl = self::optional_bool( $input, 'rtl' );
			if ( $rtl instanceof \WP_Error ) {
				return $rtl;
			}
			return [
				'enabled'   => false,
				'previous'  => null,
				'next'      => null,
				'placement' => self::safe_map( $input['placement'] ?? [] ),
				'rtl'       => $rtl,
			];
		}

		$rtl = self::optional_bool( $input, 'rtl' );
		if ( $rtl instanceof \WP_Error ) {
			return $rtl;
		}
		$out = [
			'enabled'   => true,
			'previous'  => self::arrow( $input['previous'] ?? null, 'previous', $assets ),
			'next'      => self::arrow( $input['next'] ?? null, 'next', $assets ),
			'placement' => self::safe_map( $input['placement'] ?? [] ),
			'rtl'       => $rtl,
		];
		foreach ( [ 'previous', 'next' ] as $key ) {
			if ( $out[ $key ] instanceof \WP_Error ) {
				return $out[ $key ];
			}
		}
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $assets
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function arrow( mixed $input, string $direction, array $assets ): array|\WP_Error {
		if ( ! is_array( $input ) ) {
			return new \WP_Error( 'stonewright_arrow_asset_missing', __( 'Every active carousel arrow needs an explicit asset.', 'stonewright' ), [ 'direction' => $direction ] );
		}

		$label = self::text( $input['aria_label'] ?? $input['label'] ?? '', 160 );
		if ( '' === $label ) {
			return new \WP_Error( 'stonewright_arrow_label_missing', __( 'Every active carousel arrow needs an accessibility label.', 'stonewright' ), [ 'direction' => $direction ] );
		}

		$asset = self::asset( $input, $direction, $assets );
		if ( $asset instanceof \WP_Error ) {
			return $asset;
		}
		$size = self::optional_pair( $input, 'size', $direction );
		if ( $size instanceof \WP_Error ) {
			return $size;
		}
		$hit = self::optional_pair( $input, 'hit_area', $direction );
		if ( $hit instanceof \WP_Error ) {
			return $hit;
		}

		return [
			'asset'       => $asset,
			'width'       => is_array( $size ) ? $size['width'] : null,
			'height'      => is_array( $size ) ? $size['height'] : null,
			'hit_width'   => is_array( $hit ) ? $hit['width'] : null,
			'hit_height'  => is_array( $hit ) ? $hit['height'] : null,
			'color'       => self::optional_text( $input, 'color', 64 ),
			'background'  => self::optional_text( $input, 'background', 64 ),
			'border'      => self::safe_map( $input['border'] ?? [] ),
			'radius'      => self::safe_map( $input['radius'] ?? [] ),
			'offsets'     => self::safe_map( $input['offsets'] ?? [] ),
			'states'      => self::safe_map( $input['states'] ?? [] ),
			'aria_label'  => $label,
			'provenance'  => self::safe_map( $input['provenance'] ?? [] ),
			'license'     => self::optional_text( $input, 'license', 190 ),
		];
	}

	/**
	 * @param array<string,mixed> $input
	 * @param array<int,array<string,mixed>> $assets
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function asset( array $input, string $direction, array $assets ): array|\WP_Error {
		if ( is_array( $input['asset'] ?? null ) ) {
			$input = self::expand_normalized_asset( $input, $input['asset'] );
		}
		$raw_media   = $input['media_id'] ?? $input['attachment_id'] ?? null;
		$media_id    = is_numeric( $raw_media ) ? (int) $raw_media : 0;
		$library     = self::text( $input['library_icon'] ?? '', 190 );
		$asset_ref   = self::text( $input['asset_ref'] ?? '', 96 );
		$raw_svg     = $input['svg_markup'] ?? $input['svg'] ?? '';
		$has_svg     = is_string( $raw_svg ) && '' !== trim( $raw_svg );
		$source_count = ( $media_id > 0 ? 1 : 0 ) + ( '' !== $library ? 1 : 0 ) + ( '' !== $asset_ref ? 1 : 0 ) + ( $has_svg ? 1 : 0 );
		if ( null !== $raw_media && $media_id <= 0 ) {
			return new \WP_Error( 'stonewright_arrow_media_invalid', __( 'Carousel arrow media IDs must be positive integers.', 'stonewright' ), [ 'direction' => $direction ] );
		}

		if ( 0 === $source_count ) {
			return new \WP_Error( 'stonewright_arrow_asset_missing', __( 'Every active carousel arrow needs one media, icon-library, manifest-asset, or inline-SVG source.', 'stonewright' ), [ 'direction' => $direction ] );
		}
		if ( 1 !== $source_count ) {
			return new \WP_Error( 'stonewright_arrow_asset_ambiguous', __( 'Every carousel arrow must use exactly one asset source.', 'stonewright' ), [ 'direction' => $direction ] );
		}
		if ( $media_id > 0 ) {
			return [
				'kind'                => 'media',
				'media_id'            => $media_id,
				'content_hash'        => null,
				'hash_verified'       => false,
				'sanitization_status' => 'wordpress_media_reference',
			];
		}
		if ( '' !== $library ) {
			return [
				'kind'                => 'library_icon',
				'library_icon'        => $library,
				'content_hash'        => null,
				'hash_verified'       => false,
				'sanitization_status' => 'renderer_library_reference',
			];
		}
		if ( '' !== $asset_ref ) {
			foreach ( $assets as $candidate ) {
				if ( $asset_ref !== (string) ( $candidate['asset_id'] ?? '' ) ) {
					continue;
				}
				if ( 'svg' === (string) ( $candidate['format'] ?? '' ) && 'sanitized' !== (string) ( $candidate['sanitization_status'] ?? '' ) ) {
					return new \WP_Error( 'stonewright_arrow_asset_invalid', __( 'Remote SVG arrow assets must be sanitized inline before use.', 'stonewright' ), [ 'direction' => $direction, 'asset_ref' => $asset_ref ] );
				}
				return [
					'kind'                => 'manifest_asset',
					'asset_ref'           => $asset_ref,
					'content_hash'        => (string) ( $candidate['content_hash'] ?? '' ),
					'hash_verified'       => (bool) ( $candidate['hash_verified'] ?? false ),
					'mime_type'           => (string) ( $candidate['mime_type'] ?? '' ),
					'sanitization_status' => (string) ( $candidate['sanitization_status'] ?? '' ),
				];
			}
			return new \WP_Error( 'stonewright_arrow_asset_unknown', __( 'The carousel arrow references an asset that is not present in the normalized manifest.', 'stonewright' ), [ 'direction' => $direction, 'asset_ref' => $asset_ref ] );
		}

		if ( ! is_string( $raw_svg ) ) {
			return new \WP_Error( 'stonewright_arrow_asset_invalid', __( 'Inline arrow SVG must be a string.', 'stonewright' ), [ 'direction' => $direction ] );
		}
		$svg = AssetNormalizer::safe_svg( $raw_svg );
		if ( $svg instanceof \WP_Error ) {
			return new \WP_Error( 'stonewright_arrow_asset_invalid', $svg->get_error_message(), [ 'direction' => $direction, 'root_error_code' => $svg->get_error_code() ] );
		}
		return [
			'kind'                => 'inline_svg',
			'svg_markup'          => $svg,
			'mime_type'           => 'image/svg+xml',
			'content_hash'        => hash( 'sha256', $svg ),
			'hash_verified'       => true,
			'sanitization_status' => 'sanitized',
		];
	}

	/** @param array<string,mixed> $input @param array<string,mixed> $asset @return array<string,mixed> */
	private static function expand_normalized_asset( array $input, array $asset ): array {
		switch ( (string) ( $asset['kind'] ?? '' ) ) {
			case 'media':
				$input['media_id'] = $asset['media_id'] ?? null;
				break;
			case 'library_icon':
				$input['library_icon'] = $asset['library_icon'] ?? '';
				break;
			case 'manifest_asset':
				$input['asset_ref'] = $asset['asset_ref'] ?? '';
				break;
			case 'inline_svg':
				$input['svg_markup'] = $asset['svg_markup'] ?? '';
				break;
		}
		unset( $input['asset'] );
		return $input;
	}

	/** @param array<string,mixed> $input @return array{width:float,height:float}|null|\WP_Error */
	private static function optional_pair( array $input, string $key, string $direction ): array|null|\WP_Error {
		$alternate = 'size' === $key ? [ 'width', 'height' ] : [ 'hit_width', 'hit_height' ];
		if ( ! array_key_exists( $key, $input ) && ! array_key_exists( $alternate[0], $input ) && ! array_key_exists( $alternate[1], $input ) ) {
			return null;
		}
		$value = array_key_exists( $key, $input )
			? $input[ $key ]
			: [ 'width' => $input[ $alternate[0] ] ?? null, 'height' => $input[ $alternate[1] ] ?? null ];
		if ( null === $value ) {
			return null;
		}
		if ( ! is_array( $value ) ) {
			return new \WP_Error( 'stonewright_arrow_geometry_invalid', __( 'Arrow size and hit-area measurements must contain positive width and height values.', 'stonewright' ), [ 'direction' => $direction, 'path' => $key ] );
		}
		if ( null === ( $value['width'] ?? null ) && null === ( $value['height'] ?? null ) ) {
			return null;
		}
		if ( ! is_numeric( $value['width'] ?? null ) || ! is_numeric( $value['height'] ?? null ) || (float) $value['width'] <= 0 || (float) $value['height'] <= 0 ) {
			return new \WP_Error( 'stonewright_arrow_geometry_invalid', __( 'Arrow size and hit-area measurements must contain positive width and height values.', 'stonewright' ), [ 'direction' => $direction, 'path' => $key ] );
		}
		return [ 'width' => (float) $value['width'], 'height' => (float) $value['height'] ];
	}

	/** @param array<string,mixed> $input */
	private static function optional_bool( array $input, string $key ): bool|null|\WP_Error {
		if ( ! array_key_exists( $key, $input ) || null === $input[ $key ] ) {
			return null;
		}
		if ( ! is_bool( $input[ $key ] ) ) {
			return new \WP_Error( 'stonewright_arrow_boolean_invalid', __( 'Arrow boolean settings must be explicit JSON booleans.', 'stonewright' ), [ 'path' => $key ] );
		}
		return $input[ $key ];
	}

	/** @param array<string,mixed> $input */
	private static function optional_text( array $input, string $key, int $length ): ?string {
		if ( ! array_key_exists( $key, $input ) ) {
			return null;
		}
		$value = self::text( $input[ $key ], $length );
		return '' !== $value ? $value : null;
	}

	/** @return array<string,mixed> */
	private static function safe_map( mixed $value, int $depth = 0 ): array {
		if ( ! is_array( $value ) || $depth > 3 ) {
			return [];
		}
		$out = [];
		foreach ( array_slice( $value, 0, 50, true ) as $key => $item ) {
			$safe_key = is_string( $key ) ? sanitize_key( $key ) : (string) $key;
			if ( '' === $safe_key ) {
				continue;
			}
			if ( is_array( $item ) ) {
				$out[ $safe_key ] = self::safe_map( $item, $depth + 1 );
			} elseif ( is_bool( $item ) || is_int( $item ) || is_float( $item ) || null === $item ) {
				$out[ $safe_key ] = $item;
			} elseif ( is_scalar( $item ) ) {
				$out[ $safe_key ] = self::text( $item, 190 );
			}
		}
		return $out;
	}

	private static function text( mixed $value, int $length ): string {
		return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $length ) : '';
	}
}
