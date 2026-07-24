<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/bootstrap.php
 * Source SHA-256: 4415540ea0aeebca8c65c4086924802dc936af8efca71c477101bef8b9ac2bb3
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical OAuth-protected MCP resource.
 */
final class Bootstrap {

	/**
	 * Canonical audience minted into every OAuth access token.
	 */
	public static function resource_identifier(): string {
		return rest_url( 'mcp/stonewright-oauth' );
	}

	/**
	 * Accept an omitted resource or this exact resource, ignoring a trailing slash.
	 */
	public static function resource_request_allowed( string $requested, string $expected ): bool {
		if ( '' === $requested ) {
			return true;
		}

		return rtrim( $requested, '/' ) === rtrim( $expected, '/' );
	}

	/**
	 * Scopes accepted by the OAuth server.
	 *
	 * `read` and `write` preserve compatibility with MCP bridges that request
	 * WordPress ecosystem defaults. `offline_access` lets clients request a
	 * refresh token explicitly.
	 *
	 * @return list<string>
	 */
	public static function supported_scopes(): array {
		return [ 'mcp', 'read', 'write', 'offline_access' ];
	}
}
