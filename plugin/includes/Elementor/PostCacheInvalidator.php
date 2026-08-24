<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

/**
 * Invalidates only the generated Elementor HTML cache for one edited post.
 * CSS is owned by CssAssetTransaction and must never be cleared here.
 */
final class PostCacheInvalidator {

	/**
	 * @return array{
	 *   ok:bool,
	 *   post_id:int,
	 *   method:string,
	 *   element_cache:array{key:string,existed:bool,deleted:bool,absent_after:bool},
	 *   css_cache:array{method:string,cleared:bool},
	 *   atomic_styles_notified:bool
	 * }
	 */
	public static function invalidate( int $post_id ): array {
		$cache_key    = self::element_cache_meta_key();
		$cache_existed = self::meta_exists( $post_id, $cache_key );
		$cache_result  = delete_post_meta( $post_id, $cache_key );
		clean_post_cache( $post_id );
		$cache_absent = ! self::meta_exists( $post_id, $cache_key );
		$cache_closed = $cache_absent && ( $cache_result || ! $cache_existed );

		return [
			'ok'        => $cache_closed,
			'post_id'   => $post_id,
			'method'    => 'element_cache_meta',
			'element_cache' => [
				'key'     => $cache_key,
				'existed' => $cache_existed,
				'deleted' => $cache_closed,
				'absent_after' => $cache_absent,
			],
			'css_cache' => [
				'method'  => 'not_touched',
				'cleared' => false,
			],
			'atomic_styles_notified' => false,
		];
	}

	/** @return array{key:string,exists:bool,value:mixed} */
	public static function snapshot( int $post_id ): array {
		$key    = self::element_cache_meta_key();
		$exists = self::meta_exists( $post_id, $key );
		return [
			'key'    => $key,
			'exists' => $exists,
			'value'  => $exists ? get_post_meta( $post_id, $key, true ) : null,
		];
	}

	/**
	 * @param array{key:string,exists:bool,value:mixed} $snapshot
	 * @return array{ok:bool,present:bool}
	 */
	public static function restore( int $post_id, array $snapshot ): array {
		$key = self::element_cache_meta_key();
		if ( (string) ( $snapshot['key'] ?? $key ) !== $key ) {
			return [ 'ok' => false, 'present' => self::meta_exists( $post_id, $key ) ];
		}
		$expected = (bool) ( $snapshot['exists'] ?? false );
		$ok = $expected
			? false !== update_post_meta( $post_id, $key, $snapshot['value'] ?? null )
			: ( ! self::meta_exists( $post_id, $key ) || delete_post_meta( $post_id, $key ) );
		$present = self::meta_exists( $post_id, $key );
		if ( $present !== $expected ) {
			$ok = false;
		}
		if ( $present && get_post_meta( $post_id, $key, true ) !== ( $snapshot['value'] ?? null ) ) {
			$ok = false;
		}
		return [ 'ok' => $ok, 'present' => $present ];
	}

	private static function meta_exists( int $post_id, string $key ): bool {
		if ( function_exists( 'metadata_exists' ) ) {
			return metadata_exists( 'post', $post_id, $key );
		}
		$all_meta = get_post_meta( $post_id );
		return is_array( $all_meta ) && array_key_exists( $key, $all_meta );
	}

	private static function element_cache_meta_key(): string {
		$document_class = '\\Elementor\\Core\\Base\\Document';
		if ( class_exists( $document_class ) && defined( $document_class . '::CACHE_META_KEY' ) ) {
			$key = constant( $document_class . '::CACHE_META_KEY' );
			if ( is_string( $key ) && '' !== $key ) {
				return $key;
			}
		}

		return '_elementor_element_cache';
	}
}
