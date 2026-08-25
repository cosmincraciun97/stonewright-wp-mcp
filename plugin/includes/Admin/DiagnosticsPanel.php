<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Diagnostics\DiagnosticCheck;
use Stonewright\WpMcp\Admin\Diagnostics\SupportReport;

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
		$method   = SetupDiagnostics::resolve_method( $report );
		$grouped  = self::group_checks( $checks );
		$counts   = self::counts_from_report( $report, $grouped );
		$copy     = self::plaintext_report( $report );
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
						<option value="oauth-http"<?php selected( $method, 'oauth-http' ); ?>><?php esc_html_e( 'OAuth', 'stonewright' ); ?></option>
						<option value="application-password-stdio"<?php selected( $method, 'application-password-stdio' ); ?>><?php esc_html_e( 'Application Password', 'stonewright' ); ?></option>
						<option value="stdio"<?php selected( $method, 'stdio' ); ?>><?php esc_html_e( 'Local companion', 'stonewright' ); ?></option>
						<option value="not-sure"<?php selected( $method, 'not-sure' ); ?>><?php esc_html_e( 'Not sure', 'stonewright' ); ?></option>
					</select>
				</div>

				<div class="sw-diag-pills" data-stonewright-diag-pills>
					<button type="button" class="sw-diag-pill sw-diag-pill--error" data-stonewright-diag-problems<?php echo 0 === $counts['problem'] ? ' hidden' : ''; ?>>
						<?php echo esc_html( sprintf( '%d Problems', $counts['problem'] ) ); ?>
					</button>
					<button type="button" class="sw-diag-pill sw-diag-pill--warn" data-stonewright-diag-warnings<?php echo 0 === $counts['warning'] ? ' hidden' : ''; ?>>
						<?php echo esc_html( sprintf( '%d Warnings', $counts['warning'] ) ); ?>
					</button>
				</div>

				<div class="sw-diag-cards" data-stonewright-diag-cards aria-live="polite">
					<?php foreach ( $grouped['problem'] as $check ) : ?>
						<?php self::render_card( $check ); ?>
					<?php endforeach; ?>
					<?php foreach ( $grouped['warning'] as $check ) : ?>
						<?php self::render_card( $check ); ?>
					<?php endforeach; ?>
					<?php foreach ( $grouped['skipped'] as $check ) : ?>
						<?php self::render_card( $check ); ?>
					<?php endforeach; ?>
					<?php
					$success = array_merge( $grouped['ok'], $grouped['info'] );
					if ( [] !== $success ) :
						?>
						<details class="sw-diag-success">
							<summary>
								<?php echo esc_html( sprintf( '%d successful checks', count( $success ) ) ); ?>
							</summary>
							<?php foreach ( $success as $check ) : ?>
								<?php self::render_card( $check ); ?>
							<?php endforeach; ?>
						</details>
					<?php endif; ?>
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
		return SupportReport::render( $report );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function report_for_display(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag after nonce-checked admin-post.
		$show_last = isset( $_GET['stonewright_diagnostics'] )
			&& '1' === sanitize_key( wp_unslash( (string) $_GET['stonewright_diagnostics'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $show_last ) {
			$last = get_option( 'stonewright_diagnostics_last' );
			if ( is_array( $last ) && isset( $last['checks'] ) && is_array( $last['checks'] ) ) {
				return $last;
			}
		}

		return SetupDiagnostics::report();
	}

	/**
	 * @param array<string, mixed> $check
	 */
	private static function render_card( array $check ): void {
		$status     = self::normalize_status( (string) ( $check['status'] ?? 'problem' ) );
		$css_status = self::css_status( $status );
		$icon       = match ( $status ) {
			'ok'      => '✓',
			'warning' => '!',
			'info', 'skipped' => 'ⓘ',
			default   => '✗',
		};
		$summary = (string) ( $check['summary'] ?? $check['detail'] ?? '' );
		$remedy  = (string) ( $check['remedy'] ?? '' );
		$copy    = (string) ( $check['copy'] ?? $check['ticket'] ?? '' );
		$check_id = sanitize_key( (string) ( $check['id'] ?? '' ) );
		$copy_id  = '' !== $check_id ? 'stonewright-diag-ticket-' . $check_id : '';
		$action   = self::safe_action( $check['action'] ?? null, $copy_id, $copy );
		if ( is_array( $action ) && 'copy' === $action['type'] && '' !== $action['target'] ) {
			$copy_id = $action['target'];
		}
		?>
		<div class="sw-diag-card sw-diag-card--<?php echo esc_attr( $css_status ); ?>" data-status="<?php echo esc_attr( $status ); ?>">
			<span class="sw-diag-card__icon" aria-hidden="true"><?php echo esc_html( $icon ); ?></span>
			<span class="sw-diag-card__body">
				<strong class="sw-diag-card__label"><?php echo esc_html( (string) ( $check['label'] ?? '' ) ); ?></strong>
				<span class="sw-diag-card__detail"><?php echo esc_html( $summary ); ?></span>
				<?php if ( '' !== $remedy && in_array( $status, [ 'problem', 'warning' ], true ) ) : ?>
					<span class="sw-diag-card__detail"><?php echo esc_html( $remedy ); ?></span>
				<?php endif; ?>
				<?php if ( null !== $action ) : ?>
					<?php if ( 'link' === $action['type'] ) : ?>
						<a class="button" href="<?php echo esc_url( $action['target'] ); ?>">
							<?php echo esc_html( $action['label'] ); ?>
						</a>
					<?php elseif ( 'retry' === $action['type'] ) : ?>
						<button type="button" class="button" data-stonewright-run-diagnostics>
							<?php echo esc_html( $action['label'] ); ?>
						</button>
					<?php else : ?>
						<button type="button" class="button" data-stonewright-copy="<?php echo esc_attr( $action['target'] ); ?>">
							<?php echo esc_html( $action['label'] ); ?>
						</button>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( '' !== $copy && '' !== $check_id ) : ?>
					<textarea id="<?php echo esc_attr( $copy_id ); ?>" class="sw-diag-copy-source" readonly hidden><?php echo esc_textarea( $copy ); ?></textarea>
				<?php endif; ?>
			</span>
		</div>
		<?php
	}

	/**
	 * @param list<mixed> $checks
	 * @return array{problem: list<array<string, mixed>>, warning: list<array<string, mixed>>, skipped: list<array<string, mixed>>, ok: list<array<string, mixed>>, info: list<array<string, mixed>>}
	 */
	private static function group_checks( array $checks ): array {
		$grouped = [
			'problem' => [],
			'warning' => [],
			'skipped' => [],
			'ok'      => [],
			'info'    => [],
		];
		foreach ( $checks as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}
			$status = self::normalize_status( (string) ( $check['status'] ?? 'problem' ) );
			$grouped[ isset( $grouped[ $status ] ) ? $status : 'problem' ][] = $check;
		}

		return $grouped;
	}

	/**
	 * @param array<string, mixed> $report
	 * @param array<string, list<array<string, mixed>>> $grouped
	 * @return array{problem: int, warning: int, info: int, ok: int, skipped: int}
	 */
	private static function counts_from_report( array $report, array $grouped ): array {
		$counts = [
			'problem' => count( $grouped['problem'] ),
			'warning' => count( $grouped['warning'] ),
			'info'    => count( $grouped['info'] ),
			'ok'      => count( $grouped['ok'] ),
			'skipped' => count( $grouped['skipped'] ),
		];
		if ( isset( $report['counts'] ) && is_array( $report['counts'] ) ) {
			foreach ( $counts as $key => $value ) {
				if ( isset( $report['counts'][ $key ] ) && is_numeric( $report['counts'][ $key ] ) ) {
					$counts[ $key ] = (int) $report['counts'][ $key ];
				}
			}
		}

		return $counts;
	}

	private static function normalize_status( string $status ): string {
		$status = sanitize_key( $status );
		return match ( $status ) {
			'error' => 'problem',
			'warn'  => 'warning',
			'ok', 'info', 'warning', 'problem', 'skipped' => $status,
			default => 'problem',
		};
	}

	private static function css_status( string $status ): string {
		return match ( $status ) {
			'ok'      => 'ok',
			'warning' => 'warn',
			'info', 'skipped' => 'info',
			default   => 'error',
		};
	}

	/**
	 * @param mixed $action Raw action.
	 * @return array{type: string, label: string, target: string}|null
	 */
	private static function safe_action( mixed $action, string $copy_id, string $copy ): ?array {
		if ( is_array( $action ) ) {
			$type   = sanitize_key( (string) ( $action['type'] ?? '' ) );
			$label  = sanitize_text_field( (string) ( $action['label'] ?? '' ) );
			$target = trim( (string) ( $action['target'] ?? '' ) );
			if ( in_array( $type, DiagnosticCheck::ACTION_TYPES, true ) && '' !== $label && '' !== $target ) {
				if ( 'link' === $type ) {
					if ( str_starts_with( strtolower( $target ), 'javascript:' ) ) {
						return null;
					}
					$safe = esc_url_raw( $target );
					if ( '' === $safe ) {
						return null;
					}
					return [
						'type'   => 'link',
						'label'  => $label,
						'target' => $safe,
					];
				}
				return [
					'type'   => $type,
					'label'  => $label,
					'target' => sanitize_html_class( $target ),
				];
			}
		}
		if ( '' !== $copy && '' !== $copy_id ) {
			return [
				'type'   => 'copy',
				'label'  => __( 'Copy hosting request', 'stonewright' ),
				'target' => $copy_id,
			];
		}

		return null;
	}
}
