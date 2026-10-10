<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Assets;

/**
 * The accent colours of WordPress's nine admin colour schemes, as the colour scheme tests need them.
 *
 * Core publishes each as --wp-admin-theme-color on body.admin-color-<scheme>, with two darker steps. The default
 * scheme ("fresh") sets nothing and inherits the :root value.
 */
final class CoreAdminSchemes {

	/** @var list<string> */
	public const NAMES = [ 'fresh', 'light', 'modern', 'blue', 'coffee', 'ectoplasm', 'midnight', 'ocean', 'sunrise' ];

	/**
	 * Accent, darker-10 and darker-20 of every scheme as WordPress 7.1.2 serves them (:root for the default scheme).
	 *
	 * @var array<string, array{0: string, 1: string, 2: string}>
	 */
	public const WORDPRESS_7_1 = [
		'fresh'     => [ '#007cba', '#006ba1', '#005a87' ],
		'light'     => [ '#007cba', '#006ba1', '#005a87' ],
		'modern'    => [ '#3858e9', '#2145e6', '#183ad6' ],
		'blue'      => [ '#437aa8', '#3c6d96', '#346084' ],
		'coffee'    => [ '#916745', '#805b3d', '#6e4e35' ],
		'ectoplasm' => [ '#646c3e', '#555c35', '#464c2b' ],
		'midnight'  => [ '#cf4339', '#c0382f', '#ab322a' ],
		'ocean'     => [ '#567958', '#4b6a4d', '#415b42' ],
		'sunrise'   => [ '#ad631e', '#97571a', '#824a16' ],
	];

	/**
	 * Brighter accents of the same schemes, used as stress inputs: the plugin supports WordPress 6.7 and later,
	 * and four of these are too light for text or white button labels. The darker steps are derived the way
	 * core derives them, by lowering the HSL lightness by 5 and 10 points.
	 *
	 * @var array<string, string>
	 */
	public const EARLIER_ACCENTS = [
		'fresh'     => '#007cba',
		'light'     => '#0085ba',
		'modern'    => '#3858e9',
		'blue'      => '#096484',
		'coffee'    => '#46403c',
		'ectoplasm' => '#523f6d',
		'midnight'  => '#e14d43',
		'ocean'     => '#627c83',
		'sunrise'   => '#dd823b',
	];

	/** @return array<string, array{0: array{0: float, 1: float, 2: float}, 1: array{0: float, 1: float, 2: float}, 2: array{0: float, 1: float, 2: float}}> */
	public static function earlier(): array {
		$palette = [];
		foreach ( self::EARLIER_ACCENTS as $name => $hex ) {
			$accent          = CssSource::hex_to_rgb( $hex );
			$palette[ $name ] = [ $accent, self::lighten( $accent, -5 ), self::lighten( $accent, -10 ) ];
		}

		return $palette;
	}

	/** @return array<string, array{0: array{0: float, 1: float, 2: float}, 1: array{0: float, 1: float, 2: float}, 2: array{0: float, 1: float, 2: float}}> */
	public static function current(): array {
		$palette = [];
		foreach ( self::WORDPRESS_7_1 as $name => $steps ) {
			$palette[ $name ] = [ CssSource::hex_to_rgb( $steps[0] ), CssSource::hex_to_rgb( $steps[1] ), CssSource::hex_to_rgb( $steps[2] ) ];
		}

		return $palette;
	}

	/**
	 * Change the HSL lightness of a colour by a number of points.
	 *
	 * @param array{0: float, 1: float, 2: float} $rgb
	 * @return array{0: float, 1: float, 2: float}
	 */
	public static function lighten( array $rgb, float $points ): array {
		[ $h, $s, $l ] = self::to_hsl( $rgb );

		return self::from_hsl( $h, $s, max( 0.0, min( 1.0, $l + $points / 100 ) ) );
	}

	/**
	 * @param array{0: float, 1: float, 2: float} $rgb
	 * @return array{0: float, 1: float, 2: float}
	 */
	public static function to_hsl( array $rgb ): array {
		[ $r, $g, $b ] = array_map( static fn ( float $v ): float => $v / 255, $rgb );
		$max           = max( $r, $g, $b );
		$min           = min( $r, $g, $b );
		$l             = ( $max + $min ) / 2;
		if ( $max === $min ) {
			return [ 0.0, 0.0, $l ];
		}
		$d = $max - $min;
		$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
		if ( $max === $r ) {
			$h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 );
		} elseif ( $max === $g ) {
			$h = ( $b - $r ) / $d + 2;
		} else {
			$h = ( $r - $g ) / $d + 4;
		}

		return [ $h / 6, $s, $l ];
	}

	/** @return array{0: float, 1: float, 2: float} */
	public static function from_hsl( float $h, float $s, float $l ): array {
		if ( 0.0 === $s ) {
			return [ $l * 255, $l * 255, $l * 255 ];
		}
		$q       = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
		$p       = 2 * $l - $q;
		$channel = static function ( float $t ) use ( $p, $q ): float {
			if ( $t < 0 ) {
				$t += 1;
			}
			if ( $t > 1 ) {
				$t -= 1;
			}
			if ( $t < 1 / 6 ) {
				return $p + ( $q - $p ) * 6 * $t;
			}
			if ( $t < 1 / 2 ) {
				return $q;
			}
			if ( $t < 2 / 3 ) {
				return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;
			}

			return $p;
		};

		return [ $channel( $h + 1 / 3 ) * 255, $channel( $h ) * 255, $channel( $h - 1 / 3 ) * 255 ];
	}
}
