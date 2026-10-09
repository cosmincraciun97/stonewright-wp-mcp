<?php
/**
 * What an ability does to the site, read from the verb in its name.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

/**
 * An ability is a read, a write or a destructive write. The name is the only source: abilities declare no kind.
 */
final class AbilityKind {

	public const READ        = 'read';
	public const WRITE       = 'write';
	public const DESTRUCTIVE = 'destructive';

	public static function of( string $name ): string {
		if ( 1 === preg_match( '/-(delete|remove|deactivate)\b/', $name ) ) {
			return self::DESTRUCTIVE;
		}

		if ( 1 === preg_match(
			'/-(create|update|write|apply|insert|save|set|move|upload|optimize|activate|toggle|register|define|bulk|record|duplicate)\b/',
			$name
		) ) {
			return self::WRITE;
		}

		return self::READ;
	}

	public static function label( string $kind ): string {
		return match ( $kind ) {
			self::DESTRUCTIVE => __( 'Destructive', 'stonewright' ),
			self::WRITE       => __( 'Write', 'stonewright' ),
			default           => __( 'Read', 'stonewright' ),
		};
	}
}
