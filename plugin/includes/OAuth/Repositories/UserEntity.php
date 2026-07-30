<?php
/**
 * SPDX-FileCopyrightText: 2026 Stonewright contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * OAuth user entity.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth\Repositories;

use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\UserEntityInterface;

defined( 'ABSPATH' ) || exit;

/**
 * User identity attached to an approved authorization request.
 */
final class UserEntity implements UserEntityInterface {
	use EntityTrait;
}
