<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Pages;

use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Blueprints\BlueprintStore;
use Stonewright\WpMcp\DesignTokens\BrandKit;

/**
 * Read-only admin catalog of bundled blueprints and brand kits.
 * Apply happens via MCP abilities; this page surfaces inventory + AI prompt hints.
 */
final class BlueprintsPage {

	public const SLUG       = 'stonewright-blueprints';
	public const CAPABILITY = 'manage_options';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			'stonewright',
			__( 'Blueprints', 'stonewright' ),
			__( 'Blueprints', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'stonewright' ),
				esc_html__( 'Forbidden', 'stonewright' ),
				[ 'response' => 403 ]
			);
		}

		$blueprints = BlueprintStore::list();
		$brand_kits = BrandKit::list();

		AdminShell::open( self::SLUG );
		?>
		<div class="sw-dashboard-page stonewright-blueprints-page">
			<div class="stonewright-page-header">
				<div>
					<h1><?php esc_html_e( 'Blueprints & Brand Kits', 'stonewright' ); ?></h1>
					<p><?php esc_html_e( 'Original DesignSpec landing blueprints and brand kits. Apply via stonewright/blueprint-apply and stonewright/brand-kit-apply.', 'stonewright' ); ?></p>
				</div>
			</div>

			<section class="sw-section">
				<h2><?php esc_html_e( 'Blueprints', 'stonewright' ); ?></h2>
				<div class="sw-stat-grid">
					<?php foreach ( $blueprints as $bp ) : ?>
						<article class="sw-stat-card">
							<div class="sw-stat-card__label"><?php echo esc_html( (string) ( $bp['industry'] ?? '' ) ); ?></div>
							<div class="sw-stat-card__value"><?php echo esc_html( (string) ( $bp['name'] ?? '' ) ); ?></div>
							<p><?php echo esc_html( (string) ( $bp['id'] ?? '' ) ); ?> · sha <?php echo esc_html( (string) ( $bp['spec_sha8'] ?? '' ) ); ?></p>
							<p class="description">
								<?php
								printf(
									/* translators: %s: blueprint id */
									esc_html__( 'Apply with AI: Apply blueprint %s and adapt copy for {business}.', 'stonewright' ),
									esc_html( (string) ( $bp['id'] ?? '' ) )
								);
								?>
							</p>
						</article>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="sw-section" style="margin-top:2rem;">
				<h2><?php esc_html_e( 'Brand kits', 'stonewright' ); ?></h2>
				<div class="sw-stat-grid">
					<?php foreach ( $brand_kits as $kit ) : ?>
						<article class="sw-stat-card">
							<div class="sw-stat-card__value"><?php echo esc_html( (string) ( $kit['name'] ?? '' ) ); ?></div>
							<div class="sw-stat-card__label"><?php echo esc_html( (string) ( $kit['id'] ?? '' ) ); ?></div>
							<p><?php echo esc_html( (string) ( $kit['description'] ?? '' ) ); ?></p>
							<?php if ( ! empty( $kit['colors'] ) && is_array( $kit['colors'] ) ) : ?>
								<p>
									<?php foreach ( array_slice( $kit['colors'], 0, 4, true ) as $hex ) : ?>
										<span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:<?php echo esc_attr( (string) $hex ); ?>;border:1px solid rgba(0,0,0,.15);margin-right:4px;"></span>
									<?php endforeach; ?>
								</p>
							<?php endif; ?>
						</article>
					<?php endforeach; ?>
				</div>
			</section>
		</div>
		<?php
		AdminShell::close();
	}
}
