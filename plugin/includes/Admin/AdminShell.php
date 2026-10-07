<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\HubNav;
use Stonewright\WpMcp\Admin\Ui\PageHeader;
use Stonewright\WpMcp\Admin\Ui\Scope;

/**
 * The frame every Stonewright admin page is printed in: a skip link, one page header (title, a line of
 * explanation, status and the page's main action), the tab bar of the hub the page belongs to, and a content
 * region.
 *
 * The WordPress sidebar is the only global navigation. There is no second navigation bar: the tab bar lists the
 * pages of one hub and nothing else.
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
	 * Sidebar title of a page that is still changing: its name, then "Beta" in words. HTML is allowed in a menu title.
	 */
	public static function beta_menu_title( string $label ): string {
		return '<span class="sw-menu-label">' . esc_html( $label ) . '</span> <span class="sw-menu-beta">' . esc_html__( 'Beta', 'stonewright' ) . '</span>';
	}

	/**
	 * Open the frame.
	 *
	 * @phpstan-param array{
	 *     title?: string,
	 *     lede?: string,
	 *     actions?: string,
	 *     hub?: string,
	 *     beta?: bool
	 * } $args "title" and "lede" default to the registry entry of the page. "actions" is markup built with the Ui
	 *         helpers (the page's main action and its status): it is printed as given, so it must already be escaped.
	 *         "hub" names the tab bar to show; an empty string shows none, and it defaults to the hub of the page.
	 *         "beta" marks the page as still changing and defaults to the registry entry.
	 */
	public static function open( string $current_slug, array $args = [] ): void {
		$entry = MenuRegistry::entry( $current_slug, MenuRegistry::requested_tab() );
		$title = array_key_exists( 'title', $args ) ? (string) $args['title'] : ( null !== $entry ? $entry['title'] : __( 'Stonewright', 'stonewright' ) );
		$lede  = array_key_exists( 'lede', $args ) ? (string) $args['lede'] : ( null !== $entry ? $entry['lede'] : '' );
		$hub   = array_key_exists( 'hub', $args ) ? (string) $args['hub'] : ( null !== $entry ? $entry['hub'] : '' );
		$beta  = array_key_exists( 'beta', $args ) ? (bool) $args['beta'] : ( null !== $entry && $entry['beta'] );

		$aside = '';
		if ( $beta ) {
			$aside .= Badge::render( __( 'Beta', 'stonewright' ), [ 'variant' => 'info' ] )
				. Html::element( 'span', [ 'class' => 'sw-ui-hint' ], Html::text( __( 'Still changing: it may behave differently between releases.', 'stonewright' ) ) );
		}
		$aside .= (string) ( $args['actions'] ?? '' );

		$header = PageHeader::render(
			$title,
			[
				'eyebrow'    => __( 'Stonewright', 'stonewright' ),
				'lede'       => $lede,
				'aside_html' => $aside,
			]
		);
		$nav    = '';
		if ( '' !== $hub ) {
			$nav = HubNav::render(
				MenuRegistry::links( $hub, $current_slug, MenuRegistry::requested_tab() ),
				sprintf(
					/* translators: %s: name of a group of pages, for example Knowledge */
					__( '%s sections', 'stonewright' ),
					MenuRegistry::hub_label( $hub )
				)
			);
		}

		?>
		<div class="sw-shell wrap stonewright-admin-shell" data-sw-shell>
			<a class="screen-reader-shortcut" href="#sw-main"><?php esc_html_e( 'Skip to Stonewright content', 'stonewright' ); ?></a>
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
