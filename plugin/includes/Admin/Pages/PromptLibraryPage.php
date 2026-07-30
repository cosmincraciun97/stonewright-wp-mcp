<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Pages;

use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Support\PromptCatalog;

/**
 * Dedicated Prompt Library admin tab.
 */
final class PromptLibraryPage {

	public const SLUG       = 'stonewright-prompts';
	public const CAPABILITY = 'manage_options';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			'stonewright',
			__( 'Prompt Library', 'stonewright' ),
			__( 'Prompts', 'stonewright' ),
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

		$prompts  = PromptCatalog::all();
		$by_outcome = [];
		foreach ( $prompts as $prompt ) {
			$outcome = (string) ( $prompt['outcome'] ?? 'general' );
			if ( ! isset( $by_outcome[ $outcome ] ) ) {
				$by_outcome[ $outcome ] = [];
			}
			$by_outcome[ $outcome ][] = $prompt;
		}
		ksort( $by_outcome );

		AdminShell::open( self::SLUG );
		?>
		<div class="sw-prompts-page stonewright-prompt-library">
			<div class="stonewright-page-header">
				<div>
					<h1><?php esc_html_e( 'Prompt Library', 'stonewright' ); ?></h1>
					<p><?php esc_html_e( 'Outcome-grouped starters for Plugin and Direct mode. Connect Stonewright first, copy only the prompt you need, and keep credentials out of chat.', 'stonewright' ); ?></p>
				</div>
			</div>

			<div class="sw-prompt-safety" role="note">
				<strong><?php esc_html_e( 'Every prompt starts with stonewright-task-start.', 'stonewright' ); ?></strong>
				<?php esc_html_e( 'Mode badges show where it works. Prompts contain no site URL, username, Application Password, token, memory entry, or audit payload.', 'stonewright' ); ?>
			</div>

			<label class="screen-reader-text" for="sw-prompt-search"><?php esc_html_e( 'Search prompts', 'stonewright' ); ?></label>
			<input
				type="search"
				id="sw-prompt-search"
				class="sw-prompt-search regular-text"
				placeholder="<?php esc_attr_e( 'Filter by title, outcome, or tool…', 'stonewright' ); ?>"
				data-stonewright-prompt-search
			/>

			<?php if ( [] === $prompts ) : ?>
				<div class="sw-empty-state">
					<p><?php esc_html_e( 'No prompts in the catalog yet.', 'stonewright' ); ?></p>
				</div>
			<?php else : ?>
				<?php foreach ( $by_outcome as $outcome => $group ) : ?>
					<section class="sw-section" data-sw-prompt-outcome="<?php echo esc_attr( $outcome ); ?>">
						<div class="sw-section__head">
							<h2><?php echo esc_html( ucwords( str_replace( '-', ' ', $outcome ) ) ); ?></h2>
							<p class="sw-section__sub"><?php echo esc_html( sprintf( /* translators: %d: prompt count */ _n( '%d prompt', '%d prompts', count( $group ), 'stonewright' ), count( $group ) ) ); ?></p>
						</div>
						<div class="sw-blueprint-grid" data-stonewright-prompt-grid>
							<?php foreach ( $group as $prompt ) : ?>
								<?php
								$id      = (string) ( $prompt['id'] ?? '' );
								$title   = (string) ( $prompt['title'] ?? $id );
								$summary = (string) ( $prompt['summary'] ?? '' );
								$body    = (string) ( $prompt['prompt'] ?? '' );
								$tools   = is_array( $prompt['tools'] ?? null ) ? $prompt['tools'] : [];
								$modes   = is_array( $prompt['modes'] ?? null ) ? $prompt['modes'] : [ 'plugin' ];
								$prerequisites = is_array( $prompt['prerequisites'] ?? null ) ? $prompt['prerequisites'] : [];
								$verification  = (string) ( $prompt['verification'] ?? '' );
								$search  = strtolower(
									trim(
										$title . ' ' . $outcome . ' ' . $summary . ' ' . $id . ' ' . implode( ' ', array_map( 'strval', $tools ) )
									)
								);
								?>
								<article
									class="sw-blueprint-card"
									data-sw-prompt-card
									data-stonewright-prompt-card
									data-outcome="<?php echo esc_attr( $outcome ); ?>"
									data-title="<?php echo esc_attr( strtolower( $title ) ); ?>"
									data-search="<?php echo esc_attr( $search ); ?>"
								>
									<span class="sw-blueprint-card__industry"><?php echo esc_html( $outcome ); ?></span>
									<div class="sw-prompt-modes" aria-label="<?php esc_attr_e( 'Available modes', 'stonewright' ); ?>">
										<?php foreach ( $modes as $mode ) : ?>
											<span><?php echo esc_html( 'direct' === $mode ? __( 'Direct', 'stonewright' ) : __( 'Plugin', 'stonewright' ) ); ?></span>
										<?php endforeach; ?>
									</div>
									<h3 class="sw-blueprint-card__name"><?php echo esc_html( $title ); ?></h3>
									<?php if ( '' !== $summary ) : ?>
										<p class="sw-blueprint-card__desc"><?php echo esc_html( $summary ); ?></p>
									<?php endif; ?>
									<details class="sw-prompt-details">
										<summary><?php esc_html_e( 'Requirements and verification', 'stonewright' ); ?></summary>
										<?php if ( [] !== $prerequisites ) : ?>
											<strong><?php esc_html_e( 'Requires', 'stonewright' ); ?></strong>
											<ul>
												<?php foreach ( $prerequisites as $prerequisite ) : ?>
													<li><?php echo esc_html( (string) $prerequisite ); ?></li>
												<?php endforeach; ?>
											</ul>
										<?php endif; ?>
										<?php if ( '' !== $verification ) : ?>
											<strong><?php esc_html_e( 'Done when', 'stonewright' ); ?></strong>
											<p><?php echo esc_html( $verification ); ?></p>
										<?php endif; ?>
									</details>
									<?php if ( [] !== $tools ) : ?>
										<p class="sw-blueprint-card__meta">
											<?php foreach ( array_slice( $tools, 0, 4 ) as $tool ) : ?>
												<code><?php echo esc_html( (string) $tool ); ?></code>
											<?php endforeach; ?>
										</p>
									<?php endif; ?>
									<div class="sw-blueprint-card__actions">
										<button
											type="button"
											class="sw-btn sw-btn--primary sw-btn--sm sw-copy-prompt"
											data-prompt="<?php echo esc_attr( $body ); ?>"
											data-sw-tooltip="<?php echo esc_attr( __( 'Copy this prompt to your clipboard for pasting into an MCP client.', 'stonewright' ) ); ?>"
										>
											<?php esc_html_e( 'Copy prompt', 'stonewright' ); ?>
										</button>
									</div>
								</article>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
		AdminShell::close();
	}
}
