<?php
/**
 * Shared fixture for the rollback engine tests of the user, comment, media, catalog, theme and memory families.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Rollback;

use Stonewright\WpMcp\Tests\Unit\Security\Adapters\OtherFamilyFixtures;

/**
 * The engine fixture of RollbackTestCase with the users, comments, attachments and theme names of the
 * other-family ledger tests.
 */
abstract class OtherRollbackTestCase extends RollbackTestCase {

	use OtherFamilyFixtures;

	protected function setUp(): void {
		parent::setUp();
		$this->other_families_set_up();
	}

	protected function tearDown(): void {
		$this->other_families_tear_down();
		parent::tearDown();
	}
}
