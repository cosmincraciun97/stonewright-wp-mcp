<?php
declare( strict_types=1 );

/**
 * Plugin Name: Stonewright E2E Environment
 * Description: Keeps WordPress core's own update notice off the admin pages of the e2e site, so the pages are measured with the notices Stonewright and its test fixtures print, and turns Elementor's Google fonts off so that no test depends on network speed.
 * Version: 1.0.0
 * License: MIT
 */

// Elementor downloads every Google font file the first time a page uses a font, which takes seconds to minutes
// depending on the network. No test looks at fonts, so none are loaded on the e2e site.
add_filter( 'elementor/frontend/print_google_fonts', '__return_false' );

// WordPress prints "WordPress X is available" above every admin page of a site that is behind the newest release.
// The e2e site pins one release, so the notice would always be there.
add_action(
	'admin_init',
	static function (): void {
		remove_action( 'admin_notices', 'update_nag', 3 );
		remove_action( 'network_admin_notices', 'update_nag', 3 );
	}
);
