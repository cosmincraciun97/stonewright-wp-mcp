<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Pages;

use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Skills\Skills;

/**
 * Stonewright Dashboard (formerly Status): read-only system overview.
 */
final class StatusPage {

	public const SLUG       = 'stonewright-status';
	public const CAPABILITY = 'manage_options';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			'stonewright',
			__( 'Dashboard', 'stonewright' ),
			__( 'Dashboard', 'stonewright' ),
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

		$mode           = (string) get_option( 'stonewright_mode', 'development' );
		$companion_url  = (string) get_option( 'stonewright_companion_url', 'http://127.0.0.1:8765' );
		$elementor_ver  = defined( 'ELEMENTOR_VERSION' ) ? (string) constant( 'ELEMENTOR_VERSION' ) : '';
		$elementor_pro  = class_exists( 'ElementorPro\Plugin' );
		$recent_entries = AuditLog::recent( 8, 1 );
		$daily_counts   = AuditLog::daily_counts( 14 );
		$abilities      = AbilityRegistry::all_abilities();
		$disabled       = (array) get_option( 'stonewright_disabled_abilities', [] );
		$tool_count     = max( 0, count( $abilities ) - count( array_intersect( array_column( $abilities, 'name' ), $disabled ) ) );
		$skills_count   = count( Skills::list() );
		$memory_count   = count( Memory::list_all( 10000 ) );
		$last_activity  = $recent_entries[0]['created_at'] ?? '';

		?>
		<?php \Stonewright\WpMcp\Admin\AdminShell::open( self::SLUG ); ?>
		<div class="sw-dashboard-page stonewright-status-page">
			<div class="stonewright-page-header">
				<div>
					<h1><?php esc_html_e( 'Dashboard', 'stonewright' ); ?></h1>
					<p><?php esc_html_e( 'Live overview of mode, tools, companion, and recent MCP activity.', 'stonewright' ); ?></p>
				</div>
			</div>

			<div class="sw-stat-grid stonewright-status-grid">
				<article class="sw-stat-card">
					<span class="sw-stat-card__icon" aria-hidden="true">⬡</span>
					<div class="sw-stat-card__value"><code><?php echo esc_html( $mode ); ?></code></div>
					<div class="sw-stat-card__label"><?php esc_html_e( 'Mode', 'stonewright' ); ?></div>
					<a class="sw-stat-card__link" href="<?php echo esc_url( admin_url( 'admin.php?page=stonewright' ) ); ?>">
						<?php esc_html_e( 'Details', 'stonewright' ); ?>
					</a>
				</article>

				<article class="sw-stat-card">
					<span class="sw-stat-card__icon" aria-hidden="true">▣</span>
					<div class="sw-stat-card__value">
						<?php if ( '' !== $elementor_ver ) : ?>
							<code><?php echo esc_html( $elementor_ver ); ?></code>
						<?php else : ?>
							<em><?php esc_html_e( 'Not detected', 'stonewright' ); ?></em>
						<?php endif; ?>
					</div>
					<div class="sw-stat-card__label">
						<?php
						echo $elementor_pro
							? esc_html__( 'Elementor + Pro', 'stonewright' )
							: esc_html__( 'Elementor', 'stonewright' );
						?>
					</div>
					<span class="sw-stat-card__meta">
						<?php
						echo $elementor_pro
							? esc_html__( 'Pro detected', 'stonewright' )
							: esc_html__( 'Core only or absent', 'stonewright' );
						?>
					</span>
				</article>

				<article class="sw-stat-card">
					<span class="sw-stat-card__icon" aria-hidden="true">⟷</span>
					<div class="sw-stat-card__value"><code><?php echo esc_html( $companion_url ); ?></code></div>
					<div class="sw-stat-card__label"><?php esc_html_e( 'Companion', 'stonewright' ); ?></div>
					<a class="sw-stat-card__link" href="<?php echo esc_url( admin_url( 'admin.php?page=stonewright' ) ); ?>">
						<?php esc_html_e( 'Details', 'stonewright' ); ?>
					</a>
				</article>

				<article class="sw-stat-card">
					<span class="sw-stat-card__icon" aria-hidden="true">⚙</span>
					<div class="sw-stat-card__value"><?php echo esc_html( (string) $tool_count ); ?></div>
					<div class="sw-stat-card__label"><?php esc_html_e( 'Tool surface', 'stonewright' ); ?></div>
					<a class="sw-stat-card__link" href="<?php echo esc_url( admin_url( 'admin.php?page=stonewright-abilities' ) ); ?>">
						<?php esc_html_e( 'Details', 'stonewright' ); ?>
					</a>
				</article>

				<article class="sw-stat-card">
					<span class="sw-stat-card__icon" aria-hidden="true">◷</span>
					<div class="sw-stat-card__value">
						<?php
						echo '' !== $last_activity
							? esc_html( self::relative_time( (string) $last_activity ) )
							: esc_html__( 'None yet', 'stonewright' );
						?>
					</div>
					<div class="sw-stat-card__label"><?php esc_html_e( 'Last MCP activity', 'stonewright' ); ?></div>
					<a class="sw-stat-card__link" href="<?php echo esc_url( admin_url( 'admin.php?page=stonewright-audit-log' ) ); ?>">
						<?php esc_html_e( 'Details', 'stonewright' ); ?>
					</a>
				</article>

				<article class="sw-stat-card">
					<span class="sw-stat-card__icon" aria-hidden="true">☰</span>
					<div class="sw-stat-card__value">
						<?php
						printf(
							/* translators: 1: skills count, 2: memory count */
							esc_html__( '%1$d / %2$d', 'stonewright' ),
							(int) $skills_count,
							(int) $memory_count
						);
						?>
					</div>
					<div class="sw-stat-card__label"><?php esc_html_e( 'Skills / Memory', 'stonewright' ); ?></div>
					<a class="sw-stat-card__link" href="<?php echo esc_url( admin_url( 'admin.php?page=stonewright-skills' ) ); ?>">
						<?php esc_html_e( 'Details', 'stonewright' ); ?>
					</a>
				</article>
			</div>

			<div class="sw-dashboard-panels">
				<section class="sw-card sw-dashboard-panel">
					<header class="sw-card__header">
						<h2><?php esc_html_e( 'Activity (14 days)', 'stonewright' ); ?></h2>
					</header>
					<?php self::render_sparkline( $daily_counts ); ?>
				</section>

				<section class="sw-card sw-dashboard-panel">
					<header class="sw-card__header">
						<h2><?php esc_html_e( 'Recent Audit Log', 'stonewright' ); ?></h2>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=stonewright-audit-log' ) ); ?>">
							<?php esc_html_e( 'View full audit log', 'stonewright' ); ?>
						</a>
					</header>
					<?php if ( empty( $recent_entries ) ) : ?>
						<div class="stonewright-empty-state sw-empty-state">
							<p><?php esc_html_e( 'No audit entries yet.', 'stonewright' ); ?></p>
						</div>
					<?php else : ?>
						<ul class="sw-audit-feed">
							<?php foreach ( $recent_entries as $row ) : ?>
								<?php
								$status = strtolower( (string) $row['result_status'] );
								$dot    = 'ok' === $status ? 'sw-status-dot--ok' : 'sw-status-dot--error';
								$filter = add_query_arg(
									[
										'page'    => 'stonewright-audit-log',
										'ability' => (string) $row['ability_name'],
									],
									admin_url( 'admin.php' )
								);
								?>
								<li class="sw-audit-feed__item">
									<span class="sw-status-dot <?php echo esc_attr( $dot ); ?>" aria-hidden="true"></span>
									<a href="<?php echo esc_url( $filter ); ?>">
										<code><?php echo esc_html( (string) $row['ability_name'] ); ?></code>
									</a>
									<span class="sw-badge sw-badge--<?php echo esc_attr( 'ok' === $status ? 'ok' : 'error' ); ?>">
										<?php echo esc_html( strtoupper( $status ) ); ?>
									</span>
									<time datetime="<?php echo esc_attr( (string) $row['created_at'] ); ?>">
										<?php echo esc_html( self::relative_time( (string) $row['created_at'] ) ); ?>
									</time>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</section>
			</div>
		</div>
		<?php \Stonewright\WpMcp\Admin\AdminShell::close(); ?>
		<?php
	}

	/**
	 * @param array<string, int> $daily_counts
	 */
	private static function render_sparkline( array $daily_counts ): void {
		$values = array_values( $daily_counts );
		$max    = max( 1, ...$values );
		$width  = 280;
		$height = 56;
		$n      = count( $values );
		$step   = $n > 1 ? $width / ( $n - 1 ) : $width;
		$points = [];

		foreach ( $values as $i => $value ) {
			$x        = (int) round( $i * $step );
			$y        = (int) round( $height - ( ( $value / $max ) * ( $height - 4 ) ) - 2 );
			$points[] = $x . ',' . $y;
		}

		$total = array_sum( $values );
		?>
		<?php
		$sparkline_label = sprintf(
			/* translators: %d: total MCP calls in the window */
			__( 'MCP calls over the last 14 days: %d total', 'stonewright' ),
			(int) $total
		);
		?>
		<div class="sw-sparkline" role="img" aria-label="<?php echo esc_attr( $sparkline_label ); ?>">
			<svg viewBox="0 0 <?php echo (int) $width; ?> <?php echo (int) $height; ?>" width="100%" height="<?php echo (int) $height; ?>" preserveAspectRatio="none">
				<polyline
					fill="none"
					stroke="currentColor"
					stroke-width="2"
					points="<?php echo esc_attr( implode( ' ', $points ) ); ?>"
				/>
			</svg>
			<p class="sw-sparkline__caption">
				<?php
				printf(
					/* translators: %d: total MCP calls */
					esc_html__( '%d calls in the last 14 days', 'stonewright' ),
					(int) $total
				);
				?>
			</p>
		</div>
		<?php
	}

	private static function relative_time( string $mysql_utc ): string {
		$ts = strtotime( $mysql_utc . ' UTC' );
		if ( false === $ts ) {
			return $mysql_utc;
		}

		$diff = time() - $ts;
		if ( $diff < 60 ) {
			return __( 'just now', 'stonewright' );
		}
		if ( $diff < HOUR_IN_SECONDS ) {
			$mins = (int) floor( $diff / MINUTE_IN_SECONDS );
			return sprintf(
				/* translators: %d: minutes */
				_n( '%d min ago', '%d mins ago', $mins, 'stonewright' ),
				$mins
			);
		}
		if ( $diff < DAY_IN_SECONDS ) {
			$hours = (int) floor( $diff / HOUR_IN_SECONDS );
			return sprintf(
				/* translators: %d: hours */
				_n( '%d hour ago', '%d hours ago', $hours, 'stonewright' ),
				$hours
			);
		}
		$days = (int) floor( $diff / DAY_IN_SECONDS );
		return sprintf(
			/* translators: %d: days */
			_n( '%d day ago', '%d days ago', $days, 'stonewright' ),
			$days
		);
	}
}
