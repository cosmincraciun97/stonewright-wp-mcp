<?php
/**
 * Failed authorization persistence.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

/**
 * The database refused a statement or a commit could not be made durable. Nothing
 * that depended on the failed statement was committed; the transport answers with a
 * server error. The message never contains credential material.
 */
final class StorageFailure extends \RuntimeException {
}
