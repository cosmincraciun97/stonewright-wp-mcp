<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Pages;

use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\DiagnosticsPanel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Dedicated connection diagnostics page under Connect.
 */
final class TroubleshootPage {

	public const SLUG       = 'stonewright-troubleshoot';
	public const CAPABILITY = 'manage_options';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			'stonewright',
			__( 'Troubleshoot', 'stonewright' ),
			AdminShell::experimental_menu_title( __( 'Troubleshoot', 'stonewright' ) ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! Permissions::manage_options() ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'stonewright' ),
				esc_html__( 'Forbidden', 'stonewright' ),
				[ 'response' => 403 ]
			);
		}

		AdminShell::open( self::SLUG );
		?>
		<div class="sw-troubleshoot-page stonewright-troubleshoot-page">
			<header class="sw-setup-header">
				<div>
					<h1><?php esc_html_e( 'Troubleshoot', 'stonewright' ); ?></h1>
					<p><?php esc_html_e( 'Diagnose why an AI client cannot connect to this WordPress site.', 'stonewright' ); ?></p>
				</div>
			</header>
			<?php DiagnosticsPanel::render( self::SLUG, __( 'Connection checks', 'stonewright' ) ); ?>
		</div>
		<?php
		AdminShell::close();
	}
}
