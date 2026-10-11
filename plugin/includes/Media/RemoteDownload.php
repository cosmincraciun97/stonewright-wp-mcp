<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Media;

/**
 * Downloads a remote file to a temporary path with a size cap, for the media abilities.
 *
 * The download goes through core's download_url(), which uses the safe HTTP API (it refuses internal
 * addresses). The cap is 25 MiB and can be changed with the `stonewright_media_max_bytes` filter.
 */
final class RemoteDownload {

	public const DEFAULT_MAX_BYTES = 26214400;

	/** Image types an import can be given a file name extension for. */
	private const IMAGE_EXTENSIONS = [
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
		'image/avif' => 'avif',
	];

	public static function max_bytes(): int {
		return max( 1, (int) apply_filters( 'stonewright_media_max_bytes', self::DEFAULT_MAX_BYTES ) );
	}

	public static function too_large_error(): \WP_Error {
		return new \WP_Error(
			'stonewright_media_too_large',
			sprintf(
				/* translators: %s: the size limit, for example 25 MB. */
				__( 'The file is larger than the %s limit for media uploads.', 'stonewright' ),
				size_format( self::max_bytes() )
			),
			[ 'status' => 413, 'max_bytes' => self::max_bytes() ]
		);
	}

	/**
	 * Downloads a URL to a temporary file no larger than the cap.
	 *
	 * @return string|\WP_Error The temporary file path, or an error. A file over the cap is deleted.
	 */
	public static function fetch( string $url, int $timeout = 60 ): string|\WP_Error {
		$limit = self::max_bytes();
		// Ask the HTTP layer to stop reading past the cap. The size of the file is checked afterwards as well, because
		// a stopped download leaves a file that is one byte over rather than an error.
		$cap = static function ( array $args ) use ( $limit ): array {
			$args['limit_response_size'] = $limit + 1;
			return $args;
		};
		add_filter( 'http_request_args', $cap, 10, 1 );
		try {
			$tmp = download_url( $url, $timeout );
		} finally {
			remove_filter( 'http_request_args', $cap, 10 );
		}
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$too_large = self::size_error( $tmp );
		if ( null !== $too_large ) {
			@unlink( $tmp );
			return $too_large;
		}
		return $tmp;
	}

	/**
	 * An error when a local file is over the cap.
	 */
	public static function size_error( string $path ): ?\WP_Error {
		$size = @filesize( $path );
		return ( false !== $size && $size > self::max_bytes() ) ? self::too_large_error() : null;
	}

	/**
	 * An error when a payload of this many bytes, before decoding, is over the cap.
	 */
	public static function base64_size_error( string $base64 ): ?\WP_Error {
		// Base64 grows the data by a third, so this refuses a payload before the memory for it is used twice.
		return strlen( $base64 ) > intdiv( self::max_bytes() * 4, 3 ) + 8 ? self::too_large_error() : null;
	}

	/**
	 * The file name extension for a downloaded image, or an empty string when the file is not an image
	 * type that WordPress can sideload.
	 */
	public static function image_extension( string $path ): string {
		$mime = '';
		if ( function_exists( 'wp_get_image_mime' ) ) {
			$detected = wp_get_image_mime( $path );
			$mime     = is_string( $detected ) ? $detected : '';
		} else {
			$info = @getimagesize( $path );
			$mime = is_array( $info ) ? (string) $info['mime'] : '';
		}
		return self::IMAGE_EXTENSIONS[ $mime ] ?? '';
	}
}
