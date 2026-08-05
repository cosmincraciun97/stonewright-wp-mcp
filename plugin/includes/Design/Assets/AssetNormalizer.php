<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Assets;

use Stonewright\WpMcp\Support\Json;

/** Normalizes design assets without fetching or persisting them. */
final class AssetNormalizer {

	private const MAX_ASSETS = 250;
	private const MAX_RESPONSIVE_SOURCES = 12;
	private const MAX_SVG_BYTES = 2097152;
	private const MAX_SVG_NODES = 2048;

	/** @var list<string> */
	private const SVG_ELEMENTS = [
		'svg',
		'g',
		'path',
		'rect',
		'circle',
		'ellipse',
		'line',
		'polyline',
		'polygon',
		'title',
		'desc',
		'defs',
		'lineargradient',
		'radialgradient',
		'stop',
		'clippath',
		'mask',
		'use',
		'symbol',
	];

	/** @var list<string> */
	private const SVG_ATTRIBUTES = [
		'xmlns',
		'xmlns:xlink',
		'viewbox',
		'version',
		'width',
		'height',
		'x',
		'y',
		'x1',
		'y1',
		'x2',
		'y2',
		'cx',
		'cy',
		'r',
		'rx',
		'ry',
		'd',
		'points',
		'transform',
		'fill',
		'fill-rule',
		'fill-opacity',
		'stroke',
		'stroke-width',
		'stroke-linecap',
		'stroke-linejoin',
		'stroke-miterlimit',
		'stroke-dasharray',
		'stroke-dashoffset',
		'stroke-opacity',
		'opacity',
		'vector-effect',
		'id',
		'role',
		'aria-label',
		'aria-hidden',
		'focusable',
		'preserveaspectratio',
		'offset',
		'stop-color',
		'stop-opacity',
		'gradientunits',
		'gradienttransform',
		'spreadmethod',
		'href',
		'xlink:href',
	];

	/** @param array<int,mixed> $assets @return array<int,array<string,mixed>>|\WP_Error */
	public static function normalize_many( array $assets ): array|\WP_Error {
		if ( count( $assets ) > self::MAX_ASSETS ) {
			return new \WP_Error(
				'stonewright_asset_limit_exceeded',
				__( 'The design manifest contains too many assets.', 'stonewright' ),
				[ 'status' => 400, 'limit' => self::MAX_ASSETS ]
			);
		}

		$out  = [];
		$seen = [];
		foreach ( $assets as $index => $asset ) {
			if ( ! is_array( $asset ) ) {
				return new \WP_Error( 'stonewright_asset_invalid', __( 'Every design asset must be an object.', 'stonewright' ), [ 'path' => 'assets.' . $index ] );
			}
			$normalized = self::normalize( $asset, (int) $index );
			if ( $normalized instanceof \WP_Error ) {
				return $normalized;
			}
			$hash = (string) $normalized['content_hash'];
			if ( isset( $seen[ $hash ] ) ) {
				continue;
			}
			$seen[ $hash ] = true;
			$out[] = $normalized;
		}
		return $out;
	}

	/** @param array<string,mixed> $asset @return array<string,mixed>|\WP_Error */
	public static function normalize( array $asset, int $index = 0 ): array|\WP_Error {
		$raw_source = $asset['source_url'] ?? $asset['url'] ?? $asset['src'] ?? '';
		$source     = self::safe_url( $raw_source );
		if ( is_scalar( $raw_source ) && '' !== trim( (string) $raw_source ) && '' === $source ) {
			return new \WP_Error( 'stonewright_asset_source_invalid', __( 'Asset source URLs must use HTTP or HTTPS without embedded credentials.', 'stonewright' ), [ 'path' => 'assets.' . $index . '.source_url' ] );
		}

		$raw_markup = $asset['svg_markup'] ?? $asset['svg'] ?? '';
		if ( ! is_string( $raw_markup ) && null !== $raw_markup && '' !== $raw_markup ) {
			return new \WP_Error( 'stonewright_svg_invalid', __( 'Inline SVG markup must be a string.', 'stonewright' ), [ 'path' => 'assets.' . $index . '.svg_markup' ] );
		}
		$markup = '';
		if ( is_string( $raw_markup ) && '' !== trim( $raw_markup ) ) {
			$markup = self::safe_svg( $raw_markup );
			if ( $markup instanceof \WP_Error ) {
				return $markup;
			}
		}

		$attachment_id = is_numeric( $asset['attachment_id'] ?? null ) ? (int) $asset['attachment_id'] : 0;
		if ( $attachment_id < 0 ) {
			return new \WP_Error( 'stonewright_asset_attachment_invalid', __( 'Attachment IDs must be positive integers.', 'stonewright' ), [ 'path' => 'assets.' . $index . '.attachment_id' ] );
		}
		if ( '' === $markup && '' === $source && 0 === $attachment_id ) {
			return new \WP_Error( 'stonewright_asset_source_missing', __( 'Every asset needs an attachment ID, a safe source URL, or sanitized inline SVG.', 'stonewright' ), [ 'path' => 'assets.' . $index ] );
		}

		$raw_hash = is_scalar( $asset['content_hash'] ?? null ) ? strtolower( trim( (string) $asset['content_hash'] ) ) : '';
		if ( '' !== $raw_hash && 1 !== preg_match( '/^[a-f0-9]{64}$/', $raw_hash ) ) {
			return new \WP_Error( 'stonewright_asset_hash_invalid', __( 'Asset content hashes must be SHA-256 values.', 'stonewright' ), [ 'path' => 'assets.' . $index . '.content_hash' ] );
		}

		$hash_verified = false;
		$hash_basis    = 'reference';
		if ( '' !== $markup ) {
			$computed_hash = hash( 'sha256', $markup );
			if ( '' !== $raw_hash && ! hash_equals( $computed_hash, $raw_hash ) ) {
				return new \WP_Error( 'stonewright_asset_hash_mismatch', __( 'The supplied SVG hash does not match the sanitized asset bytes.', 'stonewright' ), [ 'path' => 'assets.' . $index . '.content_hash' ] );
			}
			$content_hash = $computed_hash;
			$hash_verified = true;
			$hash_basis    = 'sanitized_bytes';
		} elseif ( '' !== $raw_hash ) {
			$content_hash = $raw_hash;
			$reference    = '' !== $source
				? [ 'source_url' => $source ]
				: [ 'attachment_id' => $attachment_id, 'shape' => self::shape( $asset ) ];
			$hash_basis   = 'reference' === (string) ( $asset['hash_basis'] ?? '' ) && hash_equals( Json::hash( $reference ), $raw_hash )
				? 'reference'
				: 'caller_supplied_unverified';
		} else {
			$reference = '' !== $source
				? [ 'source_url' => $source ]
				: [ 'attachment_id' => $attachment_id, 'shape' => self::shape( $asset ) ];
			$content_hash = Json::hash(
				$reference
			);
		}

		$declared_format = strtolower( self::safe_text( $asset['format'] ?? '', 12 ) );
		$extension       = '' !== $markup ? 'svg' : self::extension( $source, $declared_format );
		$asset_id        = self::safe_text( $asset['asset_id'] ?? $asset['id'] ?? '', 96 );
		if ( '' === $asset_id ) {
			$asset_id = 'asset-' . substr( $content_hash, 0, 12 );
		}

		return [
			'asset_id'            => $asset_id,
			'content_hash'        => $content_hash,
			'hash_verified'       => $hash_verified,
			'hash_basis'          => $hash_basis,
			'filename'            => 'asset-' . substr( $content_hash, 0, 12 ) . '.' . $extension,
			'format'              => $extension,
			'mime_type'           => self::mime_type( $extension ),
			'source_url'          => $source,
			'source_ref'          => self::safe_text( $asset['source_ref'] ?? $asset['provenance'] ?? '', 190 ),
			'alt'                 => self::safe_text( $asset['alt'] ?? '', 160 ),
			'width'               => self::non_negative_number( $asset['width'] ?? null ),
			'height'              => self::non_negative_number( $asset['height'] ?? null ),
			'attachment_id'       => $attachment_id,
			'responsive_sources'  => self::responsive_sources( $asset['responsive_sources'] ?? [] ),
			'svg_safe'            => '' !== $markup,
			'svg_markup'          => '' !== $markup ? $markup : null,
			'sanitization_status' => '' !== $markup ? 'sanitized' : ( 'svg' === $extension ? 'remote_unverified' : 'not_applicable' ),
			'license'             => self::safe_text( $asset['license'] ?? '', 190 ),
		];
	}

	public static function safe_svg( string $svg ): string|\WP_Error {
		$svg = trim( $svg );
		if ( '' === $svg || strlen( $svg ) > self::MAX_SVG_BYTES ) {
			return new \WP_Error( 'stonewright_svg_invalid', __( 'The SVG asset is empty or too large.', 'stonewright' ) );
		}
		if ( preg_match( '/<!DOCTYPE|<!ENTITY|<\?xml/i', $svg ) ) {
			return new \WP_Error( 'stonewright_svg_unsafe', __( 'The SVG contains a forbidden declaration.', 'stonewright' ) );
		}
		if ( ! class_exists( \DOMDocument::class ) ) {
			return new \WP_Error( 'stonewright_svg_sanitizer_unavailable', __( 'The DOM extension is required to sanitize inline SVG.', 'stonewright' ) );
		}

		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		$loaded   = $document->loadXML( $svg, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$root = $document->documentElement;
		if ( ! $loaded || ! $root instanceof \DOMElement || 'svg' !== strtolower( $root->localName ) ) {
			return new \WP_Error( 'stonewright_svg_invalid', __( 'The asset must contain exactly one valid SVG root.', 'stonewright' ) );
		}

		$elements = $document->getElementsByTagName( '*' );
		if ( $elements->length > self::MAX_SVG_NODES ) {
			return new \WP_Error( 'stonewright_svg_invalid', __( 'The SVG contains too many nodes.', 'stonewright' ) );
		}
		foreach ( $elements as $element ) {
			if ( ! $element instanceof \DOMElement ) {
				continue;
			}
			$error = self::validate_svg_element( $element );
			if ( $error instanceof \WP_Error ) {
				return $error;
			}
		}

		self::remove_svg_comments( $document );
		$sanitized = $document->saveXML( $root );
		if ( ! is_string( $sanitized ) || '' === $sanitized ) {
			return new \WP_Error( 'stonewright_svg_invalid', __( 'The SVG could not be normalized safely.', 'stonewright' ) );
		}
		return trim( $sanitized );
	}

	private static function validate_svg_element( \DOMElement $element ): ?\WP_Error {
		$name      = strtolower( $element->localName );
		$namespace = (string) $element->namespaceURI;
		if ( ! in_array( $name, self::SVG_ELEMENTS, true ) || ( '' !== $namespace && 'http://www.w3.org/2000/svg' !== $namespace ) ) {
			return new \WP_Error( 'stonewright_svg_unsafe', __( 'The SVG contains an element outside the safe allowlist.', 'stonewright' ), [ 'element' => $name ] );
		}

		foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
			if ( ! $attribute instanceof \DOMAttr ) {
				continue;
			}
			$attribute_name = strtolower( $attribute->nodeName );
			$value          = trim( $attribute->value );
			if ( ! in_array( $attribute_name, self::SVG_ATTRIBUTES, true ) ) {
				return new \WP_Error( 'stonewright_svg_unsafe', __( 'The SVG contains an attribute outside the safe allowlist.', 'stonewright' ), [ 'attribute' => $attribute_name ] );
			}
			if ( str_starts_with( $attribute_name, 'on' ) || str_contains( strtolower( $value ), '@import' ) ) {
				return new \WP_Error( 'stonewright_svg_unsafe', __( 'The SVG contains executable styling or event handlers.', 'stonewright' ), [ 'attribute' => $attribute_name ] );
			}
			if ( 'xmlns' === $attribute_name && 'http://www.w3.org/2000/svg' === $value ) {
				continue;
			}
			if ( 'xmlns:xlink' === $attribute_name && 'http://www.w3.org/1999/xlink' === $value ) {
				continue;
			}
			if ( in_array( $attribute_name, [ 'href', 'xlink:href' ], true ) && ! self::local_fragment( $value ) ) {
				return new \WP_Error( 'stonewright_svg_unsafe', __( 'SVG references must point to a local fragment.', 'stonewright' ), [ 'attribute' => $attribute_name ] );
			}
			if ( self::external_svg_reference( $value ) ) {
				return new \WP_Error( 'stonewright_svg_unsafe', __( 'The SVG contains an external or executable reference.', 'stonewright' ), [ 'attribute' => $attribute_name ] );
			}
		}
		return null;
	}

	private static function external_svg_reference( string $value ): bool {
		$lower = strtolower( preg_replace( '/\s+/', '', $value ) ?? $value );
		if ( str_contains( $lower, 'javascript:' ) || str_contains( $lower, 'data:' ) || str_contains( $lower, 'http:' ) || str_contains( $lower, 'https:' ) || str_contains( $lower, '//') ) {
			return true;
		}
		if ( ! str_contains( $lower, 'url(' ) ) {
			return false;
		}
		return 1 !== preg_match( '/^url\(["\']?#[a-z_][a-z0-9_.:-]*["\']?\)$/i', $lower );
	}

	private static function local_fragment( string $value ): bool {
		return 1 === preg_match( '/^#[A-Za-z_][A-Za-z0-9_.:-]*$/', $value );
	}

	private static function remove_svg_comments( \DOMDocument $document ): void {
		$xpath = new \DOMXPath( $document );
		$nodes = $xpath->query( '//comment() | //processing-instruction()' );
		if ( false === $nodes ) {
			return;
		}
		foreach ( iterator_to_array( $nodes ) as $node ) {
			$node->parentNode?->removeChild( $node );
		}
	}

	private static function safe_url( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		$parts = wp_parse_url( $value );
		if (
			'' === $value
			|| ! is_array( $parts )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| ! isset( $parts['scheme'], $parts['host'] )
			|| ! in_array( strtolower( (string) $parts['scheme'] ), [ 'http', 'https' ], true )
		) {
			return '';
		}
		return mb_substr( esc_url_raw( $value ), 0, 500 );
	}

	/** @return list<string> */
	private static function responsive_sources( mixed $sources ): array {
		if ( ! is_array( $sources ) ) {
			return [];
		}
		$out = [];
		foreach ( array_slice( $sources, 0, self::MAX_RESPONSIVE_SOURCES ) as $source ) {
			$safe = self::safe_url( $source );
			if ( '' !== $safe ) {
				$out[] = $safe;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function extension( string $url, string $declared ): string {
		$extension = strtolower( pathinfo( (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: '' ), PATHINFO_EXTENSION ) );
		$allowed   = [ 'jpg', 'jpeg', 'png', 'webp', 'avif', 'gif', 'svg' ];
		if ( in_array( $extension, $allowed, true ) ) {
			return $extension;
		}
		return in_array( $declared, $allowed, true ) ? $declared : 'bin';
	}

	private static function mime_type( string $extension ): string {
		return match ( $extension ) {
			'jpg', 'jpeg' => 'image/jpeg',
			'png'         => 'image/png',
			'webp'        => 'image/webp',
			'avif'        => 'image/avif',
			'gif'         => 'image/gif',
			'svg'         => 'image/svg+xml',
			default       => 'application/octet-stream',
		};
	}

	private static function non_negative_number( mixed $value ): float {
		return is_numeric( $value ) ? max( 0, (float) $value ) : 0.0;
	}

	private static function safe_text( mixed $value, int $length ): string {
		return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $length ) : '';
	}

	/** @param array<string,mixed> $asset @return array<string,mixed> */
	private static function shape( array $asset ): array {
		return array_intersect_key( $asset, array_fill_keys( [ 'id', 'asset_id', 'format', 'width', 'height', 'source_ref' ], true ) );
	}
}
