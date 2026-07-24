<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/repositories/user-repository.php
 * Source SHA-256: c38a70f966717115088ad50a39b3bd6f1b966f1147f91cfa46eae3f9a793d777
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth\Repositories;

// League interfaces use camelCase parameter names and pair entities with repositories.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;

defined( 'ABSPATH' ) || exit;

final class UserEntity implements UserEntityInterface {
	use EntityTrait;
}

final class UserRepository implements UserRepositoryInterface {

	public function getUserEntityByUserCredentials(
		mixed $username,
		mixed $password,
		mixed $grantType,
		ClientEntityInterface $clientEntity
	): ?UserEntityInterface {
		unset( $username, $password, $grantType, $clientEntity );
		return null;
	}
}
