<?php
/**
 * Stored UTC times as time elements, shown in site time.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Ui;

/**
 * Times are stored in UTC. A page shows them in the site's time zone inside a `time` element whose `datetime`
 * is the UTC instant, and whose `title` repeats that instant so the stored value is one pointer away.
 */
final class UtcTime {

	/**
	 * @param string $mysql_utc A UTC "Y-m-d H:i:s" value as stored. Anything else is printed as text.
	 */
	public static function render( string $mysql_utc, string $format = 'M j, Y, H:i' ): string {
		$value = trim( $mysql_utc );
		if ( '' === $value || str_starts_with( $value, '0000-00-00' ) ) {
			return '';
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
			return Html::text( $value );
		}
		$timestamp = strtotime( $value . ' UTC' );
		if ( false === $timestamp ) {
			return Html::text( $value );
		}

		return Html::element(
			'time',
			[
				'datetime' => gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ),
				'title'    => $value . ' UTC',
			],
			Html::text( (string) wp_date( $format, $timestamp ) )
		);
	}
}
