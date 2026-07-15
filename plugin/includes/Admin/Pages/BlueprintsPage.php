<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Pages;

use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Blueprints\BlueprintStore;
use Stonewright\WpMcp\DesignTokens\BrandKit;

/**
 * Read-only admin catalog of bundled blueprints and brand kits.
 * Apply happens via MCP abilities; this page surfaces inventory + full AI prompts.
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
					<p class="sw-section__sub"><?php esc_html_e( 'Complete landing structures. Copy the full AI prompt, then replace {business} with the client name.', 'stonewright' ); ?></p>
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
							$industry = (string) ( $bp['industry'] ?? '' );
							$prompt   = self::blueprint_prompt( $bp );
							?>
							<article class="sw-blueprint-card">
								<?php if ( '' !== $industry ) : ?>
									<span class="sw-blueprint-card__industry"><?php echo esc_html( $industry ); ?></span>
								<?php endif; ?>
								<h3 class="sw-blueprint-card__name"><?php echo esc_html( (string) ( $bp['name'] ?? '' ) ); ?></h3>
								<?php if ( ! empty( $bp['description'] ) ) : ?>
									<p class="sw-blueprint-card__desc"><?php echo esc_html( (string) $bp['description'] ); ?></p>
								<?php endif; ?>
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
							$prompt = self::brand_kit_prompt( $kit );
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

	/**
	 * Full multi-line prompt an agent can execute with Stonewright tools.
	 *
	 * @param array<string, mixed> $bp
	 */
	public static function blueprint_prompt( array $bp ): string {
		$id          = (string) ( $bp['id'] ?? '' );
		$name        = (string) ( $bp['name'] ?? $id );
		$industry    = (string) ( $bp['industry'] ?? '' );
		$description = (string) ( $bp['description'] ?? '' );
		$sections    = array_values( array_filter( array_map( 'strval', (array) ( $bp['section_ids'] ?? [] ) ) ) );
		$palette     = is_array( $bp['palette'] ?? null ) ? $bp['palette'] : [];
		$fonts       = is_array( $bp['fonts'] ?? null ) ? $bp['fonts'] : [];
		$sha8        = (string) ( $bp['spec_sha8'] ?? '' );

		$palette_line = [];
		foreach ( [ 'primary', 'secondary', 'accent', 'background', 'text' ] as $key ) {
			if ( ! empty( $palette[ $key ] ) && is_scalar( $palette[ $key ] ) ) {
				$palette_line[] = $key . '=' . (string) $palette[ $key ];
			}
		}
		$font_line = [];
		foreach ( [ 'heading', 'body' ] as $key ) {
			if ( ! empty( $fonts[ $key ] ) && is_scalar( $fonts[ $key ] ) ) {
				$font_line[] = $key . '=' . (string) $fonts[ $key ];
			}
		}

		$lines = [
			'Use Stonewright MCP on this WordPress site to apply a landing blueprint and adapt it for the client.',
			'',
			'## Blueprint',
			'- id: ' . $id,
			'- name: ' . $name,
			'- industry: ' . ( '' !== $industry ? $industry : 'general' ),
		];
		if ( '' !== $sha8 ) {
			$lines[] = '- spec_sha8: ' . $sha8;
		}
		if ( '' !== $description ) {
			$lines[] = '- description: ' . $description;
		}
		if ( [] !== $sections ) {
			$lines[] = '- sections: ' . implode( ', ', $sections );
		}
		if ( [] !== $palette_line ) {
			$lines[] = '- palette: ' . implode( '; ', $palette_line );
		}
		if ( [] !== $font_line ) {
			$lines[] = '- fonts: ' . implode( '; ', $font_line );
		}

		$lines = array_merge(
			$lines,
			[
				'',
				'## Client',
				'- business: {business}',
				'- replace all placeholder brand names, phone numbers, cities, and CTAs with real client copy for {business}.',
				'',
				'## Required tool path',
				'1. Call stonewright-task-start with task="Apply blueprint ' . $id . ' for {business}", surface="elementor" (or gutenberg if the site is block-first), intent="write".',
				'2. Call stonewright/blueprint-get with id="' . $id . '" and read the returned DesignSpec before writing.',
				'3. Call stonewright/blueprint-apply with id="' . $id . '" (or the apply args returned by the ability) targeting a draft page unless the user named an existing post_id.',
				'4. After apply, adapt section copy, headings, buttons, and contact details for {business}; keep the layout structure from the blueprint.',
				'5. Snapshot/backup gates must remain on; pass the context token on every write.',
				'6. Finish with a visual QA pass (desktop + mobile) and report the page URL + post_id.',
				'',
				'## Constraints',
				'- Prefer native Elementor/Gutenberg widgets from the blueprint; do not invent unsupported widgets.',
				'- Keep the palette and type roles from the blueprint unless the user supplies a brand kit.',
				'- Do not skip stonewright-task-start or stonewright/blueprint-get.',
			]
		);

		return implode( "\n", $lines );
	}

	/**
	 * @param array<string, mixed> $kit
	 */
	public static function brand_kit_prompt( array $kit ): string {
		$id          = (string) ( $kit['id'] ?? '' );
		$name        = (string) ( $kit['name'] ?? $id );
		$description = (string) ( $kit['description'] ?? '' );
		$colors      = is_array( $kit['colors'] ?? null ) ? $kit['colors'] : [];

		$color_bits = [];
		$i          = 0;
		foreach ( $colors as $key => $hex ) {
			if ( $i >= 8 ) {
				break;
			}
			if ( is_scalar( $hex ) ) {
				$label        = is_string( $key ) ? $key : 'c' . (string) $i;
				$color_bits[] = $label . '=' . (string) $hex;
				++$i;
			}
		}

		$lines = [
			'Use Stonewright MCP to apply a brand kit to the current design system.',
			'',
			'## Brand kit',
			'- id: ' . $id,
			'- name: ' . $name,
		];
		if ( '' !== $description ) {
			$lines[] = '- description: ' . $description;
		}
		if ( [] !== $color_bits ) {
			$lines[] = '- colors: ' . implode( '; ', $color_bits );
		}

		$lines = array_merge(
			$lines,
			[
				'',
				'## Required tool path',
				'1. Call stonewright-task-start with task="Apply brand kit ' . $id . '", surface="design", intent="write".',
				'2. Call stonewright/brand-kit-apply with id="' . $id . '".',
				'3. Confirm global styles / design tokens updated; report what changed.',
				'',
				'## Constraints',
				'- Do not invent colors outside the kit unless the user overrides them.',
				'- Pass the context token on writes; keep backup gates enabled.',
			]
		);

		return implode( "\n", $lines );
	}
}
