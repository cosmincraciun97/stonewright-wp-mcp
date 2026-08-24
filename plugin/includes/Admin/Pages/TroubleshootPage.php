<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Pages;

use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\DiagnosticsPanel;
use Stonewright\WpMcp\Core\McpAbilitiesCompatibilityPreflight;
use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;
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
			<?php self::render_compatibility_preflight(); ?>
			<?php self::render_elementor_provider_discovery(); ?>
		</div>
		<?php
		AdminShell::close();
	}

	private static function render_compatibility_preflight(): void {
		$report = McpAbilitiesCompatibilityPreflight::current() ?? McpAbilitiesCompatibilityPreflight::inspect();
		$compatible = true === ( $report['compatible'] ?? false );
		$symbols = [
			$report['adapter'] ?? [],
			$report['abilities']['registry'] ?? [],
			$report['abilities']['ability'] ?? [],
		];
		?>
		<section class="sw-setup-diagnostics" aria-label="<?php esc_attr_e( 'MCP runtime compatibility', 'stonewright' ); ?>">
			<h2><?php esc_html_e( 'MCP runtime compatibility', 'stonewright' ); ?></h2>
			<?php if ( $compatible ) : ?>
				<div class="sw-diag-card sw-diag-card--ok">
					<span class="sw-diag-card__icon" aria-hidden="true">✓</span>
					<span class="sw-diag-card__body">
						<strong class="sw-diag-card__label"><?php esc_html_e( 'Runtime contract compatible', 'stonewright' ); ?></strong>
						<span class="sw-diag-card__detail"><?php esc_html_e( 'Every loaded MCP and Abilities symbol satisfies its exact ABI.', 'stonewright' ); ?></span>
					</span>
				</div>
			<?php else : ?>
				<?php foreach ( $symbols as $symbol ) : ?>
					<?php
					$status = sanitize_key( (string) ( $symbol['status'] ?? '' ) );
					$abi_status = sanitize_key( (string) ( $symbol['abi']['status'] ?? '' ) );
					if ( 'conflict' !== $status && 'incompatible' !== $abi_status ) {
						continue;
					}
					$class = sanitize_text_field( (string) ( $symbol['class'] ?? 'unknown' ) );
					$reason = sanitize_key( (string) ( $symbol['reason'] ?? 'runtime_abi_incompatible' ) );
					$remediation = sanitize_text_field( (string) ( $symbol['remediation'] ?? '' ) );
					$issues = array_values( array_filter( array_map( 'sanitize_key', (array) ( $symbol['abi']['issues'] ?? [] ) ) ) );
					$owners = [];
					foreach ( (array) ( $symbol['owner_details'] ?? [] ) as $owner ) {
						if ( ! is_array( $owner ) ) {
							continue;
						}
						$name = sanitize_text_field( (string) ( $owner['owner'] ?? '' ) );
						$version = sanitize_text_field( (string) ( $owner['version'] ?? '' ) );
						if ( '' !== $name ) {
							$owners[] = '' === $version ? $name . ' — version unknown' : $name . ' — ' . $version;
						}
					}
					?>
					<div class="sw-diag-card sw-diag-card--error">
						<span class="sw-diag-card__icon" aria-hidden="true">×</span>
						<span class="sw-diag-card__body">
							<strong class="sw-diag-card__label"><?php echo esc_html( $class ); ?></strong>
							<span class="sw-diag-card__detail"><?php echo esc_html( [] === $owners ? __( 'Owner and version unavailable.', 'stonewright' ) : implode( '; ', $owners ) ); ?></span>
							<span class="sw-diag-card__detail"><?php echo esc_html( sprintf( __( 'Reason: %s', 'stonewright' ), $reason ) ); ?></span>
							<?php if ( [] !== $issues ) : ?>
								<span class="sw-diag-card__detail"><?php echo esc_html( sprintf( __( 'ABI issues: %s', 'stonewright' ), implode( ', ', $issues ) ) ); ?></span>
							<?php endif; ?>
							<span class="sw-diag-card__detail"><?php echo esc_html( $remediation ); ?></span>
						</span>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_elementor_provider_discovery(): void {
		$report = ( new ProviderRouter() )->inspect( 0, 'auto' );
		$providers = is_array( $report['providers'] ?? null ) ? $report['providers'] : [];
		$preference = is_array( $report['native_preferred']['elementor/manage-default-styles'] ?? null ) ? $report['native_preferred']['elementor/manage-default-styles'] : [];
		$state = sanitize_key( (string) ( $preference['certification'] ?? 'unsupported' ) );
		?>
		<section class="sw-setup-diagnostics" aria-label="<?php esc_attr_e( 'Elementor provider discovery', 'stonewright' ); ?>">
			<h2><?php esc_html_e( 'Elementor provider discovery', 'stonewright' ); ?></h2>
			<div class="sw-diag-card sw-diag-card--info">
				<span class="sw-diag-card__icon" aria-hidden="true">ⓘ</span>
				<span class="sw-diag-card__body">
					<strong class="sw-diag-card__label"><?php esc_html_e( 'elementor/manage-default-styles', 'stonewright' ); ?></strong>
					<span class="sw-diag-card__detail">
						<?php echo esc_html( sprintf( __( 'Certification: %1$s. Providers discovered: %2$d. Upstream writes remain disabled until the full Stonewright safety closure is available.', 'stonewright' ), $state, count( $providers ) ) ); ?>
					</span>
				</span>
			</div>
		</section>
		<?php
	}
}
