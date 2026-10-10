<?php
/**
 * Grant subject permission checks.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Ports\SubjectAuthority;
use Stonewright\WpMcp\Security\Permissions;

/**
 * A subject key is a WordPress user ID. The user must still exist and still hold the
 * capability the MCP transport requires, checked through Security\Permissions, for
 * every operation (consent, code exchange, refresh and each protected request).
 * Refusal is access_denied (HTTP 403); token endpoints turn it into invalid_grant.
 */
final class PermissionSubjectAuthority implements SubjectAuthority {

	public function require_allowed( string $subject_key, string $operation, array $context ): void {
		$user_id = self::user_id( $subject_key );
		if ( null === $user_id || ! Permissions::user_can_use_mcp( $user_id ) ) {
			throw new OAuthFault( 'access_denied', 403 );
		}
	}

	/** The positive user ID a subject key names, or null for any other text. */
	public static function user_id( string $subject_key ): ?int {
		if ( ! preg_match( '/^[1-9][0-9]{0,18}$/D', $subject_key ) ) {
			return null;
		}
		$user_id = (int) $subject_key;
		return (string) $user_id === $subject_key ? $user_id : null;
	}
}
