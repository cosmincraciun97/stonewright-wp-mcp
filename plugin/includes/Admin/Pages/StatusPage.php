<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Pages;

use Stonewright\WpMcp\Security\AuditLog;

/**
 * Stonewright Status admin page: read-only system overview.
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
			__( 'Status', 'stonewright' ),
			__( 'Status', 'stonewright' ),
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
		$recent_entries = AuditLog::recent( 5, 1 );

		?>
		<?php \Stonewright\WpMcp\Admin\AdminShell::open( self::SLUG ); ?>
		<div class="stonewright-status-page">
			<div class="stonewright-page-header">
				<div>
					<h1><?php esc_html_e( 'Stonewright Status', 'stonewright' ); ?></h1>
					<p><?php esc_html_e( 'Read-only overview of the current plugin state.', 'stonewright' ); ?></p>
				</div>
			</div>

			<div class="stonewright-status-grid">
				<section class="stonewright-panel">
					<h2><?php esc_html_e( 'Environment', 'stonewright' ); ?></h2>
					<table class="form-table stonewright-status-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Mode', 'stonewright' ); ?></th>
								<td>
									<code><?php echo esc_html( $mode ); ?></code>
									<?php if ( 'production-safe' === $mode ) : ?>
										<span class="stonewright-badge stonewright-badge--warning"><?php esc_html_e( 'Production Safe', 'stonewright' ); ?></span>
									<?php elseif ( 'staging' === $mode ) : ?>
										<span class="stonewright-badge stonewright-badge--info"><?php esc_html_e( 'Staging', 'stonewright' ); ?></span>
									<?php else : ?>
										<span class="stonewright-badge stonewright-badge--dev"><?php esc_html_e( 'Development', 'stonewright' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Elementor Version', 'stonewright' ); ?></th>
								<td>
									<?php if ( '' !== $elementor_ver ) : ?>
										<code><?php echo esc_html( $elementor_ver ); ?></code>
									<?php else : ?>
										<em><?php esc_html_e( 'Not detected', 'stonewright' ); ?></em>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Elementor Pro', 'stonewright' ); ?></th>
								<td>
									<?php if ( $elementor_pro ) : ?>
										<span class="stonewright-badge stonewright-badge--ok"><?php esc_html_e( 'Detected', 'stonewright' ); ?></span>
									<?php else : ?>
										<span class="stonewright-badge stonewright-badge--neutral"><?php esc_html_e( 'Not detected', 'stonewright' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Companion URL', 'stonewright' ); ?></th>
								<td>
									<code><?php echo esc_html( $companion_url ); ?></code>
									<?php if ( '' === get_option( 'stonewright_companion_url', '' ) ) : ?>
										<span class="stonewright-muted"><?php esc_html_e( '(default)', 'stonewright' ); ?></span>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=stonewright' ) ); ?>"><?php esc_html_e( 'Configure', 'stonewright' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>
				</section>

				<section class="stonewright-panel">
					<h2><?php esc_html_e( 'Recent Audit Log', 'stonewright' ); ?></h2>
					<?php if ( empty( $recent_entries ) ) : ?>
						<div class="stonewright-empty-state">
							<p><?php esc_html_e( 'No audit entries yet.', 'stonewright' ); ?></p>
						</div>
					<?php else : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'ID', 'stonewright' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Ability', 'stonewright' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Status', 'stonewright' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Time (UTC)', 'stonewright' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $recent_entries as $row ) : ?>
									<tr>
										<td><?php echo (int) $row['id']; ?></td>
										<td><code><?php echo esc_html( (string) $row['ability_name'] ); ?></code></td>
										<td><?php echo esc_html( strtoupper( (string) $row['result_status'] ) ); ?></td>
										<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=stonewright-audit-log' ) ); ?>">
								<?php esc_html_e( 'View full audit log', 'stonewright' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</section>
			</div>
		</div>
		<?php \Stonewright\WpMcp\Admin\AdminShell::close(); ?>
		<?php
	}
}
