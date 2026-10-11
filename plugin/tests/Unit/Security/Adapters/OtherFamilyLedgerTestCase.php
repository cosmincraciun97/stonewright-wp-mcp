<?php
/**
 * Shared fixture for the user, comment, media, catalog, site and memory ledger tests.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

/**
 * The ledger is the real ChangeLedger over the in-memory table of PostLedgerTestCase. Users, comments,
 * attachments and the rest are the fakes of the shared bootstrap, plus the few stubs of
 * other-families-wp-stubs.php (see OtherFamilyFixtures). The current user (7) may do everything unless a test says
 * otherwise.
 */
abstract class OtherFamilyLedgerTestCase extends PostLedgerTestCase {

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
