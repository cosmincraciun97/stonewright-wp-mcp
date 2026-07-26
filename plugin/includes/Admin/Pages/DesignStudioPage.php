<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Pages;

use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\DesignStudioRestApi;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Direction\DirectionSummary;
use Stonewright\WpMcp\Security\Permissions;

/**
 * The Design Studio admin page.
 *
 * The page renders the shell, the view tabs, and the region the Design Studio
 * script boots into. Every read and write it performs afterwards goes over
 * `DesignStudioRestApi`, which delegates to the typed design abilities — so no
 * design rule lives here either.
 */
final class DesignStudioPage {

	public const SLUG = 'stonewright-design-studio';

	public const CAPABILITY = 'manage_options';

	/**
	 * The four views the page can show. The URL carries the current one so a
	 * reload, a bookmark, and the back button all land where the user was.
	 */
	public const VIEWS = [ 'overview', 'editor', 'quality', 'history' ];

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			'stonewright',
			__( 'Design Studio', 'stonewright' ),
			__( 'Design Studio', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * The requested view, falling back to the overview.
	 */
	public static function current_view(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selector.
		$requested = isset( $_GET['view'] ) ? sanitize_key( (string) wp_unslash( $_GET['view'] ) ) : '';

		return in_array( $requested, self::VIEWS, true ) ? $requested : 'overview';
	}

	/**
	 * Everything the front-end needs to boot without a second round trip.
	 *
	 * @return array<string, mixed>
	 */
	public static function boot_payload( ?DesignDirectionService $service = null ): array {
		$service = $service ?? new DesignDirectionService();
		$active  = $service->active();

		return [
			'restRoot'           => rest_url( DesignStudioRestApi::REST_NAMESPACE . DesignStudioRestApi::ROUTE_PREFIX ),
			'nonce'              => wp_create_nonce( DesignStudioRestApi::NONCE_ACTION ),
			'view'               => self::current_view(),
			'views'              => self::VIEWS,
			// The quality view appends `post_id` and `editor` to this base so a
			// finding can be opened in the workspace that produced it.
			'visualWorkspaceUrl' => VisualWorkspacePage::url(),
			'activeDirection'    => is_array( $active )
				? DirectionSummary::row( $active, (int) ( $active['id'] ?? 0 ) )
				: null,
			'can'                => [
				'manageOptions' => Permissions::manage_options(),
				'manageDesign'  => Permissions::can_manage_design(),
			],
		];
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'stonewright' ),
				esc_html__( 'Forbidden', 'stonewright' ),
				[ 'response' => 403 ]
			);
		}

		$current = self::current_view();
		$labels  = self::view_labels();

		AdminShell::open( self::SLUG );
		?>
		<div
			class="sw-design-studio"
			data-sw-design-studio
			data-sw-current-view="<?php echo esc_attr( $current ); ?>"
		>
			<div class="stonewright-page-header">
				<div>
					<h1><?php esc_html_e( 'Design Studio', 'stonewright' ); ?></h1>
					<p><?php esc_html_e( 'One validated design direction per site: its tokens, its dials, its guidance, and the evidence that the rendered result matches it.', 'stonewright' ); ?></p>
				</div>
			</div>

			<details class="sw-ds-guide">
				<summary><?php esc_html_e( 'New here? Follow the four-step design loop', 'stonewright' ); ?></summary>
				<div class="sw-ds-guide__grid">
					<p><strong><?php esc_html_e( '1. Overview', 'stonewright' ); ?></strong><span><?php esc_html_e( 'Choose the direction that defines the site’s visual intent and see whether it is ready.', 'stonewright' ); ?></span></p>
					<p><strong><?php esc_html_e( '2. Editor', 'stonewright' ); ?></strong><span><?php esc_html_e( 'Set tokens, layout dials, guidance, and readiness. Saving creates a restorable revision.', 'stonewright' ); ?></span></p>
					<p><strong><?php esc_html_e( '3. Quality', 'stonewright' ); ?></strong><span><?php esc_html_e( 'Read measured browser evidence for one post. Unchecked rules never masquerade as passes.', 'stonewright' ); ?></span></p>
					<p><strong><?php esc_html_e( '4. History', 'stonewright' ); ?></strong><span><?php esc_html_e( 'Compare revisions and restore an older contract without erasing the audit trail.', 'stonewright' ); ?></span></p>
				</div>
			</details>

			<div class="sw-ds-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Design Studio views', 'stonewright' ); ?>">
				<?php foreach ( self::VIEWS as $view ) : ?>
					<?php $is_current = ( $view === $current ); ?>
					<a
						class="sw-ds-tab<?php echo $is_current ? ' is-current' : ''; ?>"
						role="tab"
						id="sw-ds-tab-<?php echo esc_attr( $view ); ?>"
						href="<?php echo esc_url( self::view_url( $view ) ); ?>"
						data-sw-view="<?php echo esc_attr( $view ); ?>"
						data-sw-tooltip="<?php echo esc_attr( self::view_help()[ $view ] ); ?>"
						aria-selected="<?php echo $is_current ? 'true' : 'false'; ?>"
						aria-controls="sw-ds-panel-<?php echo esc_attr( $view ); ?>"
						tabindex="<?php echo $is_current ? '0' : '-1'; ?>"
					><?php echo esc_html( $labels[ $view ] ); ?></a>
				<?php endforeach; ?>
			</div>

			<p class="sw-ds-status" data-sw-ds-status role="status" aria-live="polite"></p>

			<?php foreach ( self::VIEWS as $view ) : ?>
				<section
					class="sw-ds-panel"
					role="tabpanel"
					id="sw-ds-panel-<?php echo esc_attr( $view ); ?>"
					aria-labelledby="sw-ds-tab-<?php echo esc_attr( $view ); ?>"
					data-sw-panel="<?php echo esc_attr( $view ); ?>"
					<?php echo $view === $current ? '' : 'hidden'; ?>
				>
					<div class="sw-ds-panel__loading" data-sw-ds-loading>
						<?php echo esc_html( $labels[ $view ] ); ?>
					</div>
				</section>
			<?php endforeach; ?>

			<noscript>
				<div class="sw-empty-state">
					<p><?php esc_html_e( 'The Design Studio needs JavaScript. Design directions remain readable and writable through the Stonewright MCP abilities.', 'stonewright' ); ?></p>
				</div>
			</noscript>
		</div>
		<?php
		AdminShell::close();
	}

	/**
	 * @return array<string, string>
	 */
	private static function view_labels(): array {
		return [
			'overview' => __( 'Overview', 'stonewright' ),
			'editor'   => __( 'Editor', 'stonewright' ),
			'quality'  => __( 'Quality', 'stonewright' ),
			'history'  => __( 'History', 'stonewright' ),
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function view_help(): array {
		return [
			'overview' => __( 'Select and activate the site-wide visual contract. This screen does not change Elementor by itself.', 'stonewright' ),
			'editor'   => __( 'Edit validated tokens, dials, guidance, and readiness. Every save creates a revision.', 'stonewright' ),
			'quality'  => __( 'Load stored browser evidence for a post and continue a finding in Visual Workspace.', 'stonewright' ),
			'history'  => __( 'Inspect immutable revisions and restore one as a new revision.', 'stonewright' ),
		];
	}

	private static function view_url( string $view ): string {
		return admin_url( 'admin.php?page=' . rawurlencode( self::SLUG ) . '&view=' . rawurlencode( $view ) );
	}
}
