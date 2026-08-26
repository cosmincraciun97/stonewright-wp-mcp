<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/server-factory.php
 * Source SHA-256: b380ba448da4736c501849ae1997679cafda5dc863ea7a9ea511b4e9a561a44e
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth;

use DateInterval;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;
use Stonewright\WpMcp\OAuth\Repositories\AccessTokenRepository;
use Stonewright\WpMcp\OAuth\Repositories\AuthCodeRepository;
use Stonewright\WpMcp\OAuth\Repositories\ClientRepository;
use Stonewright\WpMcp\OAuth\Repositories\RefreshTokenRepository;
use Stonewright\WpMcp\OAuth\Repositories\ScopeRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Construct League OAuth authorization and resource servers.
 */
final class ServerFactory {

	public const ACCESS_TOKEN_TTL = 'PT1H';

	public const REFRESH_FAMILY_TTL = 'P14D';

	public const CONTINUITY_TARGET_SECONDS = 604800;

	public static function authorization_server(): AuthorizationServer {
		$keys   = Keys::get();
		$server = new AuthorizationServer(
			new ClientRepository(),
			new AccessTokenRepository(),
			new ScopeRepository(),
			$keys['private'],
			$keys['encryption']
		);

		$auth_code = new AuthCodeGrant(
			new AuthCodeRepository(),
			new RefreshTokenRepository(),
			new DateInterval( 'PT1M' )
		);
		$auth_code->setRefreshTokenTTL( new DateInterval( self::REFRESH_FAMILY_TTL ) );
		$server->enableGrantType( $auth_code, new DateInterval( self::ACCESS_TOKEN_TTL ) );

		$refresh = new RefreshTokenGrant( new RefreshTokenRepository() );
		$refresh->setRefreshTokenTTL( new DateInterval( self::REFRESH_FAMILY_TTL ) );
		$server->enableGrantType( $refresh, new DateInterval( self::ACCESS_TOKEN_TTL ) );

		return $server;
	}

	public static function resource_server(): ResourceServer {
		return new ResourceServer( new AccessTokenRepository(), Keys::get()['public'] );
	}
}
