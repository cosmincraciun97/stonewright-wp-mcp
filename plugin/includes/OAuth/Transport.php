<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/connect-methods.php
 * Source SHA-256: f3c46c9cfaf027a864c1671063d10b0b0c945c0458a9fa952bb86c668feb55db
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth transport safety gate.
 */
final class Transport {

	/**
	 * OAuth is safe over HTTPS, or over HTTP only for an explicit local environment.
	 */
	public static function allowed(): bool {
		if ( str_starts_with( strtolower( home_url() ), 'https://' ) ) {
			return true;
		}

		return 'local' === wp_get_environment_type();
	}
}
