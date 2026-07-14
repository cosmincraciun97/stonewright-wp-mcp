<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Write;

use Stonewright\WpMcp\Support\Json;

/** Stable hashes for optimistic concurrency and write readback. */
final class TreeHasher {
	public static function hash( mixed $value ): string {
		return Json::hash( self::canonicalize( $value ) );
	}

	private static function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		return $value;
	}
}
