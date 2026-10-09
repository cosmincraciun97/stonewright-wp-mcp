<?php
declare( strict_types=1 );

/**
 * Plugin Name: Stonewright E2E Environment
 * Description: Keeps WordPress core's own update notice off the admin pages of the e2e site, so the pages are measured with the notices Stonewright and its test fixtures print.
 * Version: 1.0.0
 * License: MIT
 */

// WordPress prints "WordPress X is available" above every admin page of a site that is behind the newest release.
// The e2e site pins one release, so the notice would always be there.
add_action(
	'admin_init',
	static function (): void {
		remove_action( 'admin_notices', 'update_nag', 3 );
		remove_action( 'network_admin_notices', 'update_nag', 3 );
	}
);
