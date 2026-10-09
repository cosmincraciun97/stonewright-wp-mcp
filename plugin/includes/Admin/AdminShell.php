<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Ui\Band;
use Stonewright\WpMcp\Admin\Ui\HubNav;
use Stonewright\WpMcp\Admin\Ui\PageHeader;
use Stonewright\WpMcp\Admin\Ui\Scope;

/**
 * The frame every Stonewright admin page is printed in: a skip link, the band (the product name and a link to
 * every page, grouped by hub, with the current page marked), one page header (title, a line of explanation, status
 * and the page's main action), the tab bar of a page that has tabs of its own, and a content region.
 *
 * The WordPress sidebar and the band both list the pages. The tab bar lists the tabs of the page that is open and
 * nothing else.
 *
 * Presentation only. Form handlers, nonces and capability checks stay on the pages.
 */
final class AdminShell {

	/**
	 * Every registered page, slug to its tab label.
	 *
	 * @return array<string, string>
	 */
	public static function pages(): array {
		return MenuRegistry::pages();
	}

	/**
	 * Sidebar title of a page that is still changing: its name, then the small "EXP" marker. The marker is hidden
	 * from assistive technology and its words follow as hidden text, so the entry reads "Design This feature is
	 * experimental." The tooltip is CSS only (AdminBootstrap::output_menu_styles), because the sidebar is on every
	 * admin page. HTML is allowed in a menu title.
	 */
	public static function beta_menu_title( string $label ): string {
		return '<span class="sw-menu-label">' . esc_html( $label ) . '</span> '
			. '<span class="sw-menu-exp" aria-hidden="true" data-sw-tip="' . esc_attr( Band::exp_hint() ) . '">' . esc_html( Band::exp_text() ) . '</span>'
			. '<span class="screen-reader-text"> ' . esc_html( Band::exp_hint() ) . '</span>';
	}

	/**
	 * Open the frame.
	 *
	 * @phpstan-param array{
	 *     title?: string,
	 *     lede?: string,
	 *     actions?: string,
	 *     current?: string
	 * } $args "title" and "lede" default to the registry entry of the page. "actions" is markup built with the Ui
	 *         helpers (the page's main action and its status): it is printed as given, so it must already be escaped.
	 *         "current" names the page whose link the band marks, for a page that is a view of another registered page;
	 *         it defaults to the page itself.
	 */
	public static function open( string $current_slug, array $args = [] ): void {
		$tab   = MenuRegistry::requested_tab();
		$entry = MenuRegistry::entry( $current_slug, $tab );
		$title = array_key_exists( 'title', $args ) ? (string) $args['title'] : ( null !== $entry ? $entry['title'] : __( 'Stonewright', 'stonewright' ) );
		$lede  = array_key_exists( 'lede', $args ) ? (string) $args['lede'] : ( null !== $entry ? $entry['lede'] : '' );

		$band   = Scope::wrap( Band::render( MenuRegistry::band_groups( (string) ( $args['current'] ?? $current_slug ) ), [ 'logo_url' => self::logo_url() ] ) );
		$header = PageHeader::render(
			$title,
			[
				'lede'       => $lede,
				'aside_html' => (string) ( $args['actions'] ?? '' ),
			]
		);
		$nav    = '';
		if ( null !== $entry ) {
			$nav = HubNav::render(
				MenuRegistry::tab_links( $current_slug, $tab ),
				sprintf(
					/* translators: %s: name of a page that has tabs of its own, for example Custom code */
					__( '%s sections', 'stonewright' ),
					$entry['title']
				)
			);
		}

		?>
		<div class="sw-shell wrap stonewright-admin-shell" data-sw-shell>
			<a class="screen-reader-shortcut" href="#sw-main"><?php esc_html_e( 'Skip to Stonewright content', 'stonewright' ); ?></a>
			<?php echo $band; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value. ?>
			<div class="sw-shell__content">
				<?php echo Scope::wrap( $header . $nav, [ 'class' => 'sw-shell__chrome' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value. ?>
				<hr class="wp-header-end">
				<details class="sw-notice-drawer" data-sw-notice-drawer data-sw-notice-labels="<?php echo esc_attr( self::notice_labels() ); ?>" hidden>
					<summary class="sw-notice-drawer__summary" data-sw-notice-summary><?php esc_html_e( 'Other WordPress notices', 'stonewright' ); ?></summary>
					<div class="sw-notice-drawer__body" data-sw-notice-body></div>
				</details>
				<div class="sw-shell__main" id="sw-main" tabindex="-1">
		<?php
	}

	/**
	 * Close the frame.
	 */
	public static function close(): void {
		?>
				</div><!-- #sw-main -->
			</div><!-- .sw-shell__content -->
		</div><!-- .sw-shell -->
		<?php
	}

	/** The address of the product mark shown in the band, or an empty string when the plugin address is not known. */
	private static function logo_url(): string {
		return defined( 'STONEWRIGHT_URL' ) ? (string) constant( 'STONEWRIGHT_URL' ) . 'assets/admin/stonewright-logo.png' : '';
	}

	/**
	 * The words the notice drawer puts in its title, for the script that counts the notices it folds.
	 */
	private static function notice_labels(): string {
		return (string) wp_json_encode(
			[
				'heading' => __( 'Other WordPress notices', 'stonewright' ),
				'error'   => [ __( '%d error', 'stonewright' ), __( '%d errors', 'stonewright' ) ],
				'warning' => [ __( '%d warning', 'stonewright' ), __( '%d warnings', 'stonewright' ) ],
				'update'  => [ __( '%d update', 'stonewright' ), __( '%d updates', 'stonewright' ) ],
				'notice'  => [ __( '%d notice', 'stonewright' ), __( '%d notices', 'stonewright' ) ],
			]
		);
	}
}
