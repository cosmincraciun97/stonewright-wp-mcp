<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Media;

/**
 * Server-side stock image providers.
 *
 * Openverse is always available (public API, no key). Unsplash and Pexels are
 * enabled only when the corresponding site option holds an API key.
 *
 * @stonewright-status stable
 */
final class StockImageClient {

	public const PROVIDER_OPENVERSE = 'openverse';
	public const PROVIDER_UNSPLASH  = 'unsplash';
	public const PROVIDER_PEXELS    = 'pexels';

	public const OPTION_UNSPLASH = 'stonewright_unsplash_access_key';
	public const OPTION_PEXELS   = 'stonewright_pexels_api_key';

	/**
	 * @return list<string>
	 */
	public static function available_providers(): array {
		$providers = [ self::PROVIDER_OPENVERSE ];
		if ( '' !== self::unsplash_key() ) {
			$providers[] = self::PROVIDER_UNSPLASH;
		}
		if ( '' !== self::pexels_key() ) {
			$providers[] = self::PROVIDER_PEXELS;
		}

		return $providers;
	}

	public static function is_provider_available( string $provider ): bool {
		return in_array( $provider, self::available_providers(), true );
	}

	/**
	 * @return array{ok:bool,provider:string,query:string,count:int,results:list<array<string,mixed>>,page:int,per_page:int}|\WP_Error
	 */
	public static function search( string $query, string $provider = self::PROVIDER_OPENVERSE, int $page = 1, int $per_page = 12 ) {
		$query    = trim( $query );
		$provider = strtolower( trim( $provider ) );
		$page     = max( 1, $page );
		$per_page = max( 1, min( 30, $per_page ) );

		if ( '' === $query ) {
			return new \WP_Error( 'stonewright_stock_query_required', __( 'Search query is required.', 'stonewright' ) );
		}

		if ( ! self::is_provider_available( $provider ) ) {
			return new \WP_Error(
				'stonewright_stock_provider_unavailable',
				sprintf(
					/* translators: %s: provider name */
					__( 'Provider "%s" is not available. Openverse is always on; Unsplash/Pexels require API keys in Settings.', 'stonewright' ),
					$provider
				),
				[
					'provider'             => $provider,
					'available_providers'  => self::available_providers(),
				]
			);
		}

		return match ( $provider ) {
			self::PROVIDER_UNSPLASH => self::search_unsplash( $query, $page, $per_page ),
			self::PROVIDER_PEXELS   => self::search_pexels( $query, $page, $per_page ),
			default                 => self::search_openverse( $query, $page, $per_page ),
		};
	}

	/**
	 * @return array{ok:bool,provider:string,query:string,count:int,results:list<array<string,mixed>>,page:int,per_page:int}|\WP_Error
	 */
	private static function search_openverse( string $query, int $page, int $per_page ) {
		$url = add_query_arg(
			[
				'q'         => $query,
				'page'      => $page,
				'page_size' => $per_page,
				'format'    => 'json',
			],
			'https://api.openverse.org/v1/images/'
		);

		$body = self::get_json( $url, [] );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$results = [];
		foreach ( (array) ( $body['results'] ?? [] ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$normalized = self::normalize_openverse_item( $item );
			if ( null !== $normalized ) {
				$results[] = $normalized;
			}
		}

		return [
			'ok'       => true,
			'provider' => self::PROVIDER_OPENVERSE,
			'query'    => $query,
			'count'    => count( $results ),
			'results'  => $results,
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	/**
	 * @return array{ok:bool,provider:string,query:string,count:int,results:list<array<string,mixed>>,page:int,per_page:int}|\WP_Error
	 */
	private static function search_unsplash( string $query, int $page, int $per_page ) {
		$key = self::unsplash_key();
		$url = add_query_arg(
			[
				'query'    => $query,
				'page'     => $page,
				'per_page' => $per_page,
			],
			'https://api.unsplash.com/search/photos'
		);

		$body = self::get_json(
			$url,
			[
				'headers' => [
					'Authorization' => 'Client-ID ' . $key,
					'Accept-Version' => 'v1',
				],
			]
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$results = [];
		foreach ( (array) ( $body['results'] ?? [] ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$normalized = self::normalize_unsplash_item( $item );
			if ( null !== $normalized ) {
				$results[] = $normalized;
			}
		}

		return [
			'ok'       => true,
			'provider' => self::PROVIDER_UNSPLASH,
			'query'    => $query,
			'count'    => count( $results ),
			'results'  => $results,
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	/**
	 * @return array{ok:bool,provider:string,query:string,count:int,results:list<array<string,mixed>>,page:int,per_page:int}|\WP_Error
	 */
	private static function search_pexels( string $query, int $page, int $per_page ) {
		$key = self::pexels_key();
		$url = add_query_arg(
			[
				'query'    => $query,
				'page'     => $page,
				'per_page' => $per_page,
			],
			'https://api.pexels.com/v1/search'
		);

		$body = self::get_json(
			$url,
			[
				'headers' => [
					'Authorization' => $key,
				],
			]
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$results = [];
		foreach ( (array) ( $body['photos'] ?? [] ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$normalized = self::normalize_pexels_item( $item );
			if ( null !== $normalized ) {
				$results[] = $normalized;
			}
		}

		return [
			'ok'       => true,
			'provider' => self::PROVIDER_PEXELS,
			'query'    => $query,
			'count'    => count( $results ),
			'results'  => $results,
			'page'     => $page,
			'per_page' => $per_page,
		];
	}

	/**
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>|null
	 */
	private static function normalize_openverse_item( array $item ): ?array {
		$id  = (string) ( $item['id'] ?? '' );
		$url = (string) ( $item['url'] ?? '' );
		if ( '' === $id || '' === $url ) {
			return null;
		}

		$creator  = (string) ( $item['creator'] ?? '' );
		$license  = trim( (string) ( $item['license'] ?? '' ) . ' ' . (string) ( $item['license_version'] ?? '' ) );
		$land_url = (string) ( $item['foreign_landing_url'] ?? '' );
		$attr     = (string) ( $item['attribution'] ?? '' );
		if ( '' === $attr ) {
			$attr = self::build_attribution( $creator, $license, $land_url, 'Openverse' );
		}

		return [
			'id'            => $id,
			'provider'      => self::PROVIDER_OPENVERSE,
			'title'         => (string) ( $item['title'] ?? '' ),
			'url'           => $url,
			'thumbnail'     => (string) ( $item['thumbnail'] ?? $url ),
			'width'         => (int) ( $item['width'] ?? 0 ),
			'height'        => (int) ( $item['height'] ?? 0 ),
			'creator'       => $creator,
			'creator_url'   => (string) ( $item['creator_url'] ?? '' ),
			'license'       => trim( $license ),
			'license_url'   => (string) ( $item['license_url'] ?? '' ),
			'landing_url'   => $land_url,
			'attribution'   => $attr,
		];
	}

	/**
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>|null
	 */
	private static function normalize_unsplash_item( array $item ): ?array {
		$id   = (string) ( $item['id'] ?? '' );
		$urls = is_array( $item['urls'] ?? null ) ? $item['urls'] : [];
		$url  = (string) ( $urls['regular'] ?? $urls['full'] ?? $urls['raw'] ?? '' );
		if ( '' === $id || '' === $url ) {
			return null;
		}

		$user    = is_array( $item['user'] ?? null ) ? $item['user'] : [];
		$creator = (string) ( $user['name'] ?? '' );
		$links   = is_array( $item['links'] ?? null ) ? $item['links'] : [];
		$land    = (string) ( $links['html'] ?? '' );
		$attr    = self::build_attribution( $creator, 'Unsplash License', $land, 'Unsplash' );

		return [
			'id'          => $id,
			'provider'    => self::PROVIDER_UNSPLASH,
			'title'       => (string) ( $item['alt_description'] ?? $item['description'] ?? '' ),
			'url'         => $url,
			'thumbnail'   => (string) ( $urls['thumb'] ?? $urls['small'] ?? $url ),
			'width'       => (int) ( $item['width'] ?? 0 ),
			'height'      => (int) ( $item['height'] ?? 0 ),
			'creator'     => $creator,
			'creator_url' => (string) ( is_array( $user['links'] ?? null ) ? ( $user['links']['html'] ?? '' ) : '' ),
			'license'     => 'Unsplash License',
			'license_url' => 'https://unsplash.com/license',
			'landing_url' => $land,
			'attribution' => $attr,
		];
	}

	/**
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>|null
	 */
	private static function normalize_pexels_item( array $item ): ?array {
		$id    = (string) ( $item['id'] ?? '' );
		$src   = is_array( $item['src'] ?? null ) ? $item['src'] : [];
		$url   = (string) ( $src['large'] ?? $src['original'] ?? $src['medium'] ?? '' );
		if ( '' === $id || '' === $url ) {
			return null;
		}

		$creator = (string) ( $item['photographer'] ?? '' );
		$land    = (string) ( $item['url'] ?? '' );
		$attr    = self::build_attribution( $creator, 'Pexels License', $land, 'Pexels' );

		return [
			'id'          => $id,
			'provider'    => self::PROVIDER_PEXELS,
			'title'       => (string) ( $item['alt'] ?? '' ),
			'url'         => $url,
			'thumbnail'   => (string) ( $src['tiny'] ?? $src['small'] ?? $url ),
			'width'       => (int) ( $item['width'] ?? 0 ),
			'height'      => (int) ( $item['height'] ?? 0 ),
			'creator'     => $creator,
			'creator_url' => (string) ( $item['photographer_url'] ?? '' ),
			'license'     => 'Pexels License',
			'license_url' => 'https://www.pexels.com/license/',
			'landing_url' => $land,
			'attribution' => $attr,
		];
	}

	private static function build_attribution( string $creator, string $license, string $landing_url, string $provider ): string {
		$parts = [];
		if ( '' !== $creator ) {
			$parts[] = 'Photo by ' . $creator;
		}
		if ( '' !== $provider ) {
			$parts[] = 'via ' . $provider;
		}
		if ( '' !== $license ) {
			$parts[] = '(' . $license . ')';
		}
		if ( '' !== $landing_url ) {
			$parts[] = $landing_url;
		}

		return trim( implode( ' ', $parts ) );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function get_json( string $url, array $args ) {
		$defaults = [
			'timeout' => 20,
			'headers' => [
				'Accept'     => 'application/json',
				'User-Agent' => 'Stonewright-WP-MCP/1.0; stock-image-search',
			],
		];
		$merged   = array_replace_recursive( $defaults, $args );
		$response = wp_remote_get( $url, $merged );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'stonewright_stock_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Stock image provider returned HTTP %d.', 'stonewright' ),
					$code
				),
				[ 'status' => $code, 'body' => mb_substr( $body, 0, 300 ) ]
			);
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'stonewright_stock_invalid_json', __( 'Stock image provider returned invalid JSON.', 'stonewright' ) );
		}

		return $decoded;
	}

	private static function unsplash_key(): string {
		return trim( (string) get_option( self::OPTION_UNSPLASH, '' ) );
	}

	private static function pexels_key(): string {
		return trim( (string) get_option( self::OPTION_PEXELS, '' ) );
	}
}
