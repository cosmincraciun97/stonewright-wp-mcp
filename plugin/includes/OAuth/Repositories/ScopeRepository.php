<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/repositories/scope-repository.php
 * Source SHA-256: df5d2af8a667f7d3d81fa4274be2b59e171be9954d3746aa98a321ebf4eed446
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth\Repositories;

// League interfaces use camelCase parameter names and pair entities with repositories.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use Stonewright\WpMcp\OAuth\Bootstrap;

defined( 'ABSPATH' ) || exit;

final class ScopeEntity implements ScopeEntityInterface {
	use EntityTrait;
	use ScopeTrait;
}

final class ScopeRepository implements ScopeRepositoryInterface {

	public function getScopeEntityByIdentifier( mixed $identifier ): ?ScopeEntityInterface {
		$identifier = (string) $identifier;
		if ( ! in_array( $identifier, Bootstrap::supported_scopes(), true ) ) {
			return null;
		}

		$entity = new ScopeEntity();
		$entity->setIdentifier( $identifier );
		return $entity;
	}

	/**
	 * @param array<array-key, ScopeEntityInterface> $scopes Requested scopes.
	 * @return array<array-key, ScopeEntityInterface>
	 */
	public function finalizeScopes(
		array $scopes,
		mixed $grantType,
		ClientEntityInterface $clientEntity,
		mixed $userIdentifier = null
	): array {
		unset( $grantType, $clientEntity, $userIdentifier );
		$granted = array_values(
			array_filter(
				$scopes,
				static fn( ScopeEntityInterface $scope ): bool => in_array(
					$scope->getIdentifier(),
					Bootstrap::supported_scopes(),
					true
				)
			)
		);

		foreach ( $granted as $scope ) {
			if ( 'mcp' === $scope->getIdentifier() ) {
				return $granted;
			}
		}

		$mcp = new ScopeEntity();
		$mcp->setIdentifier( 'mcp' );
		array_unshift( $granted, $mcp );
		return $granted;
	}
}
