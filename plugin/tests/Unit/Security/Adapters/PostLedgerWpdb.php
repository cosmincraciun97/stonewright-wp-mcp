<?php
/**
 * The in-memory ledger database, with the one statement the post adapter asks before it records.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\LedgerWpdb;

/**
 * Answers SHOW COLUMNS for the ledger table, so that the table counts as installed.
 */
class PostLedgerWpdb extends LedgerWpdb {

	public function get_col( $query = null, $x = 0 ) {
		if ( is_string( $query ) && str_starts_with( $query, 'SHOW COLUMNS FROM ' ) ) {
			return ChangeLedger::COLUMNS;
		}
		return parent::get_col( $query, $x );
	}
}
