<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

/**
 * Targeted Elementor CSS regeneration for one resolved post or loop asset.
 */
final class CssRegenerator {

	/**
	 * @return array{ok:bool,post_id:int,kind:string,method:string,detail:string,path_sha256?:string,url_sha256?:string,error_class?:string}
	 */
	public static function regenerate( CssTarget $target ): array {
		$post_id = $target->post_id();
		$class   = $target->runtime_class();
		if ( ! class_exists( $class ) ) {
			return [
				'ok'      => false,
				'post_id' => $post_id,
				'kind'    => $target->kind(),
				'method'  => 'unavailable',
				'detail'  => 'elementor_css_api_unavailable',
			];
		}

		try {
			$css = $class::create( $post_id );
			if ( ! is_object( $css ) ) {
				return self::failure( $target, 'elementor_css_object_invalid' );
			}
			if ( ! self::has_public_update_file( $css ) ) {
				return self::failure( $target, 'elementor_css_object_invalid' );
			}
			if ( ! method_exists( $css, 'get_path' ) || ! method_exists( $css, 'get_url' ) ) {
				return self::failure( $target, 'reported_location_unavailable' );
			}

			$expected = [
				'path' => $target->path(),
				'url'  => $target->url(),
			];
			$path_before = (string) $css->get_path();
			$url_before  = (string) $css->get_url();
			if ( ! self::reported_location_matches( $expected, $path_before, $url_before ) ) {
				return self::failure( $target, self::path_matches( $expected['path'], $path_before ) ? 'url_mismatch' : 'path_mismatch' );
			}

			// @phpstan-ignore-next-line Elementor runtime API (Post/Loop::update_file).
			$css->update_file();

			$path = (string) $css->get_path();
			$url  = (string) $css->get_url();
			if ( ! self::reported_location_matches( $expected, $path, $url ) ) {
				return self::failure( $target, self::path_matches( $expected['path'], $path ) ? 'url_mismatch' : 'path_mismatch' );
			}

			return [
				'ok'          => true,
				'post_id'     => $post_id,
				'kind'        => $target->kind(),
				'method'      => 'elementor_css_update_file',
				'detail'      => 'regenerated',
				'path_sha256' => hash( 'sha256', $path ),
				'url_sha256'  => hash( 'sha256', $url ),
			];
		} catch ( \Throwable $error ) {
			return [
				'ok'          => false,
				'post_id'     => $post_id,
				'kind'        => $target->kind(),
				'method'      => 'exception',
				'detail'      => 'update_failed',
				'error_class' => get_class( $error ),
			];
		}
	}

	private static function has_public_update_file( object $css ): bool {
		if ( ! method_exists( $css, 'update_file' ) ) {
			return false;
		}
		try {
			$method = new \ReflectionMethod( $css, 'update_file' );
		} catch ( \ReflectionException $error ) {
			return false;
		}
		return $method->isPublic();
	}

	/** @param array{path:string,url:string} $expected */
	private static function reported_location_matches( array $expected, string $path, string $url ): bool {
		return self::path_matches( $expected['path'], $path )
			&& self::css_url_matches( $expected['url'], $url );
	}

	private static function path_matches( string $expected, string $actual ): bool {
		return self::canonical_filesystem_path( $actual ) === self::canonical_filesystem_path( $expected );
	}

	private static function canonical_filesystem_path( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		if ( 1 === preg_match( '#^(?:https?|file)://(/.*)$#i', $path, $matches ) ) {
			$path = $matches[1];
		}
		return wp_normalize_path( $path );
	}

	private static function css_url_matches( string $expected, string $actual ): bool {
		if ( ! self::same_origin( home_url( '/' ), $actual ) || ! self::same_origin( $expected, $actual ) ) {
			return false;
		}
		$expected_parts = wp_parse_url( $expected );
		$actual_parts   = wp_parse_url( $actual );
		if ( ! is_array( $expected_parts ) || ! is_array( $actual_parts ) ) {
			return false;
		}
		$expected_path = wp_normalize_path( (string) ( $expected_parts['path'] ?? '' ) );
		$actual_path   = wp_normalize_path( (string) ( $actual_parts['path'] ?? '' ) );
		return '' !== $expected_path && $expected_path === $actual_path;
	}

	private static function same_origin( string $left, string $right ): bool {
		$a = wp_parse_url( $left );
		$b = wp_parse_url( $right );
		if ( ! is_array( $a ) || ! is_array( $b ) ) {
			return false;
		}
		$scheme_a = strtolower( (string) ( $a['scheme'] ?? '' ) );
		$scheme_b = strtolower( (string) ( $b['scheme'] ?? '' ) );
		$host_a   = strtolower( (string) ( $a['host'] ?? '' ) );
		$host_b   = strtolower( (string) ( $b['host'] ?? '' ) );
		$port_a   = (int) ( $a['port'] ?? ( 'https' === $scheme_a ? 443 : 80 ) );
		$port_b   = (int) ( $b['port'] ?? ( 'https' === $scheme_b ? 443 : 80 ) );
		return in_array( $scheme_a, [ 'http', 'https' ], true )
			&& $scheme_a === $scheme_b
			&& '' !== $host_a
			&& $host_a === $host_b
			&& $port_a === $port_b;
	}

	/** @return array{ok:false,post_id:int,kind:string,method:string,detail:string} */
	private static function failure( CssTarget $target, string $detail ): array {
		return [
			'ok'      => false,
			'post_id' => $target->post_id(),
			'kind'    => $target->kind(),
			'method'  => 'elementor_css_update_file',
			'detail'  => sanitize_key( $detail ),
		];
	}
}
