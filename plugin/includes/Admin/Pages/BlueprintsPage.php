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
		<div class="stonewright-blueprints-page">
			<div class="stonewright-page-header">
				<div>
					<h1><?php esc_html_e( 'Blueprints & Brand Kits', 'stonewright' ); ?></h1>
					<p><?php esc_html_e( 'Complete landing structures and brand kits. Apply via stonewright/blueprint-apply and stonewright/brand-kit-apply.', 'stonewright' ); ?></p>
				</div>
			</div>

			<section class="sw-section">
				<div class="sw-section__head">
					<h2><?php esc_html_e( 'Blueprints', 'stonewright' ); ?></h2>
					<p class="sw-section__sub"><?php esc_html_e( 'Complete landing structures. Ask your AI to apply one and adapt the copy.', 'stonewright' ); ?></p>
				</div>
				<?php if ( [] === $blueprints ) : ?>
					<div class="sw-empty-state">
						<p><?php esc_html_e( 'No blueprints bundled yet.', 'stonewright' ); ?></p>
					</div>
				<?php else : ?>
					<div class="sw-blueprint-grid">
						<?php foreach ( $blueprints as $bp ) : ?>
							<?php
							$id       = (string) ( $bp['id'] ?? '' );
							$prompt   = sprintf( 'Apply blueprint %s and adapt copy for {business}.', $id );
							$industry = (string) ( $bp['industry'] ?? '' );
							?>
							<article class="sw-blueprint-card">
								<?php if ( '' !== $industry ) : ?>
									<span class="sw-blueprint-card__industry"><?php echo esc_html( $industry ); ?></span>
								<?php endif; ?>
								<h3 class="sw-blueprint-card__name"><?php echo esc_html( (string) ( $bp['name'] ?? '' ) ); ?></h3>
								<p class="sw-blueprint-card__meta">
									<code><?php echo esc_html( $id ); ?></code>
									<span class="sw-blueprint-card__sha">sha <?php echo esc_html( (string) ( $bp['spec_sha8'] ?? '' ) ); ?></span>
								</p>
								<button
									type="button"
									class="sw-btn sw-btn--ghost sw-btn--sm sw-copy-prompt"
									data-prompt="<?php echo esc_attr( $prompt ); ?>"
								>
									<?php esc_html_e( 'Copy AI Prompt', 'stonewright' ); ?>
								</button>
							</article>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>

			<section class="sw-section">
				<div class="sw-section__head">
					<h2><?php esc_html_e( 'Brand Kits', 'stonewright' ); ?></h2>
					<p class="sw-section__sub"><?php esc_html_e( 'Color and type kits ready for AI application.', 'stonewright' ); ?></p>
				</div>
				<?php if ( [] === $brand_kits ) : ?>
					<div class="sw-empty-state">
						<p><?php esc_html_e( 'No brand kits bundled yet.', 'stonewright' ); ?></p>
					</div>
				<?php else : ?>
					<div class="sw-blueprint-grid">
						<?php foreach ( $brand_kits as $kit ) : ?>
							<?php
							$kit_id = (string) ( $kit['id'] ?? '' );
							$prompt = sprintf( 'Apply brand kit %s to the current design system.', $kit_id );
							$colors = ! empty( $kit['colors'] ) && is_array( $kit['colors'] ) ? array_slice( $kit['colors'], 0, 4, true ) : [];
							?>
							<article class="sw-blueprint-card">
								<h3 class="sw-blueprint-card__name"><?php echo esc_html( (string) ( $kit['name'] ?? '' ) ); ?></h3>
								<?php if ( ! empty( $kit['description'] ) ) : ?>
									<p class="sw-blueprint-card__desc"><?php echo esc_html( (string) $kit['description'] ); ?></p>
								<?php endif; ?>
								<p class="sw-blueprint-card__meta">
									<code><?php echo esc_html( $kit_id ); ?></code>
								</p>
								<?php if ( [] !== $colors ) : ?>
									<div class="sw-kit-swatches" aria-hidden="true">
										<?php foreach ( $colors as $hex ) : ?>
											<span style="background:<?php echo esc_attr( (string) $hex ); ?>" title="<?php echo esc_attr( (string) $hex ); ?>"></span>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
								<button
									type="button"
									class="sw-btn sw-btn--ghost sw-btn--sm sw-copy-prompt"
									data-prompt="<?php echo esc_attr( $prompt ); ?>"
								>
									<?php esc_html_e( 'Copy AI Prompt', 'stonewright' ); ?>
								</button>
							</article>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>
		</div>
		<?php
		AdminShell::close();
	}
}
