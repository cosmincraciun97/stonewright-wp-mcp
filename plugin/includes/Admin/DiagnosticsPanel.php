<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

/**
 * Shared diagnostics markup for Setup and Troubleshoot.
 */
final class DiagnosticsPanel {

	/**
	 * @param array<string, mixed> $report Optional preloaded report.
	 */
	public static function render( string $return_page, string $heading, array $report = [] ): void {
		if ( ! in_array( $return_page, [ 'stonewright', 'stonewright-troubleshoot' ], true ) ) {
			$return_page = 'stonewright';
		}
		if ( [] === $report ) {
			$report = self::report_for_display();
		}

		$checks   = isset( $report['checks'] ) && is_array( $report['checks'] ) ? $report['checks'] : [];
		$versions = isset( $report['versions'] ) && is_array( $report['versions'] ) ? $report['versions'] : [];
		$mode     = isset( $report['mode'] ) ? sanitize_key( (string) $report['mode'] ) : 'both';
		if ( ! in_array( $mode, [ 'both', 'http', 'stdio' ], true ) ) {
			$mode = 'both';
		}

		$errors = 0;
		$warns  = 0;
		foreach ( $checks as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}
			$status = (string) ( $check['status'] ?? 'error' );
			if ( 'error' === $status ) {
				++$errors;
			} elseif ( 'warn' === $status ) {
				++$warns;
			}
		}

		$copy = self::plaintext_report( $report );
		?>
		<section class="sw-setup-diagnostics" data-stonewright-diagnostics aria-label="<?php echo esc_attr( $heading ); ?>">
			<h2><?php echo esc_html( $heading ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Run these checks when an AI client cannot connect. They probe this site the way a client does and point at what to fix.', 'stonewright' ); ?>
			</p>

			<div class="sw-diag-field">
				<label for="stonewright-diag-symptom"><?php esc_html_e( 'What do you see in your AI client?', 'stonewright' ); ?></label>
				<select id="stonewright-diag-symptom" data-stonewright-diag-symptom>
					<option value=""><?php esc_html_e( 'Optional — pick a symptom', 'stonewright' ); ?></option>
					<option value="tools"><?php esc_html_e( 'Stonewright tools never appear', 'stonewright' ); ?></option>
					<option value="auth"><?php esc_html_e( 'Authorization or login fails', 'stonewright' ); ?></option>
					<option value="unreachable"><?php esc_html_e( 'The client cannot reach this site', 'stonewright' ); ?></option>
					<option value="other"><?php esc_html_e( 'Something else', 'stonewright' ); ?></option>
				</select>
				<div class="sw-diag-help" data-stonewright-diag-help hidden></div>
			</div>

			<form
				id="stonewright-diagnostics-form"
				method="post"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				class="sw-diagnostics-run"
			>
				<input type="hidden" name="action" value="stonewright_run_diagnostics"/>
				<?php wp_nonce_field( 'stonewright_run_diagnostics' ); ?>
				<input type="hidden" name="stonewright_diagnostics_return" value="<?php echo esc_attr( $return_page ); ?>"/>

				<div class="sw-diag-field">
					<label for="stonewright-diag-mode"><?php esc_html_e( 'How do you connect?', 'stonewright' ); ?></label>
					<select id="stonewright-diag-mode" name="mode" data-stonewright-diag-mode>
						<option value="both"<?php selected( $mode, 'both' ); ?>><?php esc_html_e( 'Not sure (check both)', 'stonewright' ); ?></option>
						<option value="http"<?php selected( $mode, 'http' ); ?>><?php esc_html_e( 'Remote Streamable HTTP / OAuth', 'stonewright' ); ?></option>
						<option value="stdio"<?php selected( $mode, 'stdio' ); ?>><?php esc_html_e( 'Local companion (stdio)', 'stonewright' ); ?></option>
					</select>
				</div>

				<div class="sw-diag-pills" data-stonewright-diag-pills>
					<button type="button" class="sw-diag-pill sw-diag-pill--error" data-stonewright-diag-problems<?php echo 0 === $errors ? ' hidden' : ''; ?>>
						<?php echo esc_html( sprintf( '%d Problems', $errors ) ); ?>
					</button>
					<button type="button" class="sw-diag-pill sw-diag-pill--warn" data-stonewright-diag-warnings<?php echo 0 === $warns ? ' hidden' : ''; ?>>
						<?php echo esc_html( sprintf( '%d Warnings', $warns ) ); ?>
					</button>
				</div>

				<div class="sw-diag-cards" data-stonewright-diag-cards aria-live="polite">
					<?php foreach ( $checks as $check ) : ?>
						<?php
						if ( ! is_array( $check ) ) {
							continue;
						}
						self::render_card( $check );
						?>
					<?php endforeach; ?>
				</div>

				<div class="sw-diag-actions">
					<button type="submit" class="button button-primary" data-stonewright-run-diagnostics>
						<?php esc_html_e( 'Run diagnostics', 'stonewright' ); ?>
					</button>
					<button type="button" class="button" data-stonewright-copy="stonewright-diagnostics-copy">
						<?php esc_html_e( 'Copy report for support', 'stonewright' ); ?>
					</button>
				</div>
			</form>

			<textarea id="stonewright-diagnostics-copy" class="sw-diag-copy-source" readonly hidden><?php echo esc_textarea( $copy ); ?></textarea>
			<div class="sw-copy-modal" data-stonewright-copy-modal hidden>
				<div class="sw-copy-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="stonewright-copy-modal-title">
					<p id="stonewright-copy-modal-title"><?php esc_html_e( 'Press Ctrl/Cmd+C', 'stonewright' ); ?></p>
					<textarea readonly></textarea>
					<button type="button" class="button" data-stonewright-copy-modal-dismiss>
						<?php esc_html_e( 'Close', 'stonewright' ); ?>
					</button>
				</div>
			</div>
			<p class="description">
				<?php
				echo esc_html(
					ConfigurationPage::diagnostics_version_copy(
						(string) ( $versions['plugin'] ?? '' ),
						(string) ( $versions['companion_contract'] ?? '' )
					)
				);
				?>
			</p>
		</section>
		<?php
	}

	/**
	 * @param array<string, mixed> $report
	 */
	public static function plaintext_report( array $report ): string {
		$lines    = [ 'Stonewright diagnostics' ];
		$mode     = isset( $report['mode'] ) ? (string) $report['mode'] : '';
		$versions = isset( $report['versions'] ) && is_array( $report['versions'] ) ? $report['versions'] : [];
		$checks   = isset( $report['checks'] ) && is_array( $report['checks'] ) ? $report['checks'] : [];

		if ( '' !== $mode ) {
			$lines[] = 'Mode: ' . $mode;
		}
		if ( isset( $versions['plugin'] ) ) {
			$lines[] = 'Plugin: ' . (string) $versions['plugin'];
		}
		if ( isset( $versions['companion_contract'] ) ) {
			$lines[] = 'Companion HTTP contract: ' . (string) $versions['companion_contract'];
		}
		if ( isset( $versions['wordpress'] ) ) {
			$lines[] = 'WordPress: ' . (string) $versions['wordpress'];
		}
		if ( isset( $versions['php'] ) ) {
			$lines[] = 'PHP: ' . (string) $versions['php'];
		}
		$lines[] = '';

		foreach ( $checks as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}
			$lines[] = '[' . (string) ( $check['status'] ?? 'error' ) . '] ' . (string) ( $check['label'] ?? '' );
			$detail  = (string) ( $check['detail'] ?? '' );
			if ( '' !== $detail ) {
				$lines[] = $detail;
			}
			$ticket = (string) ( $check['ticket'] ?? '' );
			if ( '' !== $ticket ) {
				$lines[] = $ticket;
			}
			$lines[] = '';
		}

		return trim( implode( "\n", $lines ) );
	}

	/**
	 * @return array{ready: bool, checks: list<array{id: string, status: string, label: string, detail: string}>, versions: array<string, string|int>, mode?: string}
	 */
	private static function report_for_display(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag after nonce-checked admin-post.
		$show_last = isset( $_GET['stonewright_diagnostics'] )
			&& '1' === sanitize_key( wp_unslash( (string) $_GET['stonewright_diagnostics'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $show_last ) {
			$last = get_option( 'stonewright_diagnostics_last' );
			if ( is_array( $last ) && isset( $last['checks'] ) && is_array( $last['checks'] ) ) {
				/** @var array{ready: bool, checks: list<array{id: string, status: string, label: string, detail: string}>, versions: array<string, string|int>, mode?: string} $last */
				return $last;
			}
		}

		return SetupDiagnostics::report();
	}

	/**
	 * @param array<string, mixed> $check
	 */
	private static function render_card( array $check ): void {
		$status = sanitize_key( (string) ( $check['status'] ?? 'error' ) );
		if ( ! in_array( $status, [ 'ok', 'warn', 'error', 'info' ], true ) ) {
			$status = 'error';
		}
		$icon = match ( $status ) {
			'ok'   => '✓',
			'warn' => '!',
			'info' => 'ⓘ',
			default => '✗',
		};
		$ticket    = (string) ( $check['ticket'] ?? '' );
		$ticket_id = 'stonewright-diag-ticket-' . sanitize_key( (string) ( $check['id'] ?? '' ) );
		?>
		<div class="sw-diag-card sw-diag-card--<?php echo esc_attr( $status ); ?>" data-status="<?php echo esc_attr( $status ); ?>">
			<span class="sw-diag-card__icon" aria-hidden="true"><?php echo esc_html( $icon ); ?></span>
			<span class="sw-diag-card__body">
				<strong class="sw-diag-card__label"><?php echo esc_html( (string) ( $check['label'] ?? '' ) ); ?></strong>
				<span class="sw-diag-card__detail"><?php echo esc_html( (string) ( $check['detail'] ?? '' ) ); ?></span>
				<?php if ( '' !== $ticket && 'stonewright-diag-ticket-' !== $ticket_id ) : ?>
					<button type="button" class="button" data-stonewright-copy="<?php echo esc_attr( $ticket_id ); ?>">
						<?php esc_html_e( 'Copy ticket', 'stonewright' ); ?>
					</button>
					<textarea id="<?php echo esc_attr( $ticket_id ); ?>" class="sw-diag-copy-source" readonly hidden><?php echo esc_textarea( $ticket ); ?></textarea>
				<?php endif; ?>
			</span>
		</div>
		<?php
	}
}
