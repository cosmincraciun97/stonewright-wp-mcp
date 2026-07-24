<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/repositories/auth-code-repository.php
 * Source SHA-256: 54d80fc3a5c262f916a7e81e1c439d4b5d9734fb2e97da7bf8ccca2042902894
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth\Repositories;

// League interfaces use camelCase parameter names and pair entities with repositories.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

use DateTimeImmutable;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

defined( 'ABSPATH' ) || exit;

final class AuthCodeEntity implements AuthCodeEntityInterface {
	use AuthCodeTrait;
	use EntityTrait;
	use TokenEntityTrait;
}

final class AuthCodeRepository implements AuthCodeRepositoryInterface {

	public function getNewAuthCode(): AuthCodeEntityInterface {
		return new AuthCodeEntity();
	}

	public function persistNewAuthCode( AuthCodeEntityInterface $authCodeEntity ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'stonewright_oauth_auth_codes',
			[
				'identifier_hash' => hash( 'sha256', (string) $authCodeEntity->getIdentifier() ),
				'client_id'       => (string) $authCodeEntity->getClient()->getIdentifier(),
				'user_id'         => (int) $authCodeEntity->getUserIdentifier(),
				'expires_at'      => $authCodeEntity->getExpiryDateTime()->format( 'Y-m-d H:i:s' ),
				'scopes'          => wp_json_encode(
					array_map(
						static fn( ScopeEntityInterface $scope ): string => $scope->getIdentifier(),
						$authCodeEntity->getScopes()
					)
				),
				'redirect_uri'    => $authCodeEntity->getRedirectUri() ?? '',
				'revoked'         => 0,
			]
		);
	}

	/**
	 * @throws OAuthServerException When the code has already been redeemed.
	 */
	public function revokeAuthCode( mixed $codeId ): void {
		global $wpdb;

		$claimed = $wpdb->update(
			$wpdb->prefix . 'stonewright_oauth_auth_codes',
			[ 'revoked' => 1 ],
			[
				'identifier_hash' => hash( 'sha256', (string) $codeId ),
				'revoked'         => 0,
			]
		);
		if ( 1 !== $claimed ) {
			throw OAuthServerException::invalidGrant( 'Authorization code has already been redeemed' );
		}
	}

	public function isAuthCodeRevoked( mixed $codeId ): bool {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT revoked, expires_at FROM {$wpdb->prefix}stonewright_oauth_auth_codes
				WHERE identifier_hash = %s",
				hash( 'sha256', (string) $codeId )
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || 1 === (int) ( $row['revoked'] ?? 0 ) ) {
			return true;
		}

		return new DateTimeImmutable( (string) $row['expires_at'] . ' UTC' ) < new DateTimeImmutable( 'now' );
	}
}
