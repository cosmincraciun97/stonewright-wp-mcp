<?php
/**
 * WordPress hooks of Stonewright Rescue.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Admin\RescuePage;
use Stonewright\WpMcp\Support\AgentNotices;

/**
 * Registers what Rescue needs on every request: the pending-incident banner for agents, the
 * internal probe token, the import of incidents another writer recorded, and the Rescue page.
 */
final class RescueHooks {

	public static function register(): void {
		self::register_notices();
		// Early, before wp-admin asks who is logged in: the health probe presents its internal token here.
		add_action( 'plugins_loaded', [ ProbeToken::class, 'authenticate_request' ], 1 );
		// Incidents recorded while the database was unavailable are imported on the first load that can.
		add_action( 'admin_init', [ self::class, 'sync_journal' ], 1 );
		add_action( 'rest_api_init', [ self::class, 'sync_journal' ], 1 );
		RescuePage::register();
	}

	/**
	 * While an incident is open, every ability response carries it as `pending_incident`.
	 */
	public static function register_notices(): void {
		AgentNotices::register_field( 'pending_incident', [ ChangeJournal::class, 'banner' ] );
	}

	public static function sync_journal(): void {
		ChangeJournal::sync_from_file();
	}
}
