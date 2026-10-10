<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Diagnostics\DiagnosticCheck;
use Stonewright\WpMcp\Admin\Diagnostics\SupportReport;
use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Card;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Icon;
use Stonewright\WpMcp\Admin\Ui\Scope;
use Stonewright\WpMcp\Admin\Ui\Table;

/**
 * The connection checks of the Troubleshoot page: a form that runs them, a summary, the checks that need
 * attention first, and the rest folded away. Built from the admin UI layer; assets/admin/pages/troubleshoot.js
 * paints the same structure from the JSON the run returns.
 */
final class DiagnosticsPanel {

	/** Status of a check to its badge: variant, icon and the word shown. */
	private static function badge_for( string $status ): string {
		return match ( $status ) {
			'ok'      => Badge::render( __( 'Passed', 'stonewright' ), [ 'variant' => 'ok', 'icon' => 'check' ] ),
			'warning' => Badge::render( __( 'Warning', 'stonewright' ), [ 'variant' => 'warn', 'icon' => 'alert' ] ),
			'info'    => Badge::render( __( 'Info', 'stonewright' ), [ 'variant' => 'info', 'icon' => 'info' ] ),
			'skipped' => Badge::render( __( 'Skipped', 'stonewright' ) ),
			default   => Badge::render( __( 'Problem', 'stonewright' ), [ 'variant' => 'danger', 'icon' => 'x' ] ),
		};
	}

	/**
	 * Print the panel inside its own layer scope. Pages that build their content as one string call html() instead.
	 *
	 * @param array<string, mixed> $report Optional preloaded report.
	 */
	public static function render( string $return_page, string $heading, array $report = [] ): void {
		echo Scope::wrap( self::html( $return_page, $heading, $report ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
	}

	/**
	 * @param array<string, mixed> $report Optional preloaded report.
	 */
	public static function html( string $return_page, string $heading, array $report = [] ): string {
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
		$unfinished = self::has_unrun_checks( $checks );

		$field = static function ( string $id, string $label, string $control, string $help = '' ): string {
			return Html::element(
				'div',
				[ 'class' => 'sw-ui-field sw-ui-field--md' ],
				Html::element( 'label', [ 'class' => 'sw-ui-field__label', 'for' => $id ], Html::text( $label ) ) . $control . $help
			);
		};
		$option = static fn ( string $value, string $text, bool $selected = false ): string => Html::element( 'option', [ 'value' => $value, 'selected' => $selected ], Html::text( $text ) );

		$mode_select    = Html::element(
			'select',
			[ 'class' => 'sw-ui-select', 'id' => 'stonewright-diag-mode', 'name' => 'mode', 'data-sw-diag-mode' => true ],
			$option( 'oauth-http', __( 'OAuth', 'stonewright' ), 'oauth-http' === $method )
				. $option( 'application-password-stdio', __( 'Application Password', 'stonewright' ), 'application-password-stdio' === $method )
				. $option( 'stdio', __( 'Local companion', 'stonewright' ), 'stdio' === $method )
				. $option( 'not-sure', __( 'Not sure', 'stonewright' ), 'not-sure' === $method )
		);
		$symptom_select = Html::element(
			'select',
			[ 'class' => 'sw-ui-select', 'id' => 'stonewright-diag-symptom', 'data-sw-diag-symptom' => true, 'aria-describedby' => 'stonewright-diag-help' ],
			$option( '', __( 'Optional — pick a symptom', 'stonewright' ) )
				. $option( 'tools', __( 'Stonewright tools never appear', 'stonewright' ) )
				. $option( 'auth', __( 'Authorization or login fails', 'stonewright' ) )
				. $option( 'unreachable', __( 'The client cannot reach this site', 'stonewright' ) )
				. $option( 'other', __( 'Something else', 'stonewright' ) )
		);
		$help           = Html::element( 'span', [ 'class' => 'sw-ui-field__help', 'id' => 'stonewright-diag-help', 'data-sw-diag-help' => true, 'hidden' => true ], '' );

		$form = Html::element(
			'form',
			[ 'id' => 'stonewright-diagnostics-form', 'method' => 'post', 'action' => admin_url( 'admin-post.php' ), 'class' => 'sw-troubleshoot-form' ],
			Html::void( 'input', [ 'type' => 'hidden', 'name' => 'action', 'value' => 'stonewright_run_diagnostics' ] )
				. Html::void( 'input', [ 'type' => 'hidden', 'name' => '_wpnonce', 'value' => wp_create_nonce( 'stonewright_run_diagnostics' ) ] )
				. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'stonewright_diagnostics_return', 'value' => $return_page ] )
				. $field( 'stonewright-diag-mode', __( 'How do you connect?', 'stonewright' ), $mode_select )
				. $field( 'stonewright-diag-symptom', __( 'What do you see in your AI client?', 'stonewright' ), $symptom_select, $help )
		);

		$summary = Html::element( 'div', [ 'class' => 'sw-troubleshoot-summary', 'role' => 'status', 'data-sw-diag-summary' => true ], self::summary_html( $counts, $unfinished ) );
		$results = Html::element( 'div', [ 'data-sw-diag-results' => true, 'aria-busy' => 'false' ], self::results_html( $grouped ) );

		$actions = Button::render( __( 'Run diagnostics', 'stonewright' ), [ 'variant' => 'primary', 'type' => 'submit', 'form' => 'stonewright-diagnostics-form', 'attrs' => [ 'data-sw-diag-run' => true ] ] )
			. self::copy_button( __( 'Copy report for support', 'stonewright' ), '#stonewright-diagnostics-copy', 'stonewright-diagnostics-copy-status', false )
			. Html::element( 'span', [ 'class' => 'sw-ui-copy__status', 'role' => 'status', 'id' => 'stonewright-diagnostics-copy-status' ], '' );

		$body = Html::element( 'p', [ 'class' => 'sw-ui-field__help' ], Html::text( __( 'Run these checks when an AI client cannot connect. They probe this site the way a client does and point at what to fix.', 'stonewright' ) ) )
			. $form
			. $summary
			. $results
			. Html::element( 'pre', [ 'id' => 'stonewright-diagnostics-copy', 'hidden' => true, 'data-sw-diag-copy' => true ], Html::text( self::plaintext_report( $report ) ) );

		$footer = Html::text(
			ConfigurationPage::diagnostics_version_copy(
				(string) ( $versions['plugin'] ?? '' ),
				(string) ( $versions['companion_contract'] ?? '' )
			)
		);

		return Html::element(
			'div',
			[ 'data-sw-diagnostics' => true ],
			Card::render( $heading, $body, [ 'actions_html' => $actions, 'footer_html' => $footer, 'id' => 'sw-troubleshoot-checks' ] )
		);
	}

	/**
	 * What the report adds up to, in words: problems and warnings first, or that there are none. "So far" is said
	 * only while checks have really not run; information rows that every run contains do not make a finished run
	 * look unfinished.
	 *
	 * @param array{problem: int, warning: int, info: int, ok: int, skipped: int} $counts
	 * @param bool $unfinished Whether some check has not run yet.
	 */
	public static function summary_text( array $counts, bool $unfinished = false ): string {
		$problems = sprintf( /* translators: %d: number of problems */ _n( '%d problem', '%d problems', $counts['problem'], 'stonewright' ), $counts['problem'] );
		$warnings = sprintf( /* translators: %d: number of warnings */ _n( '%d warning', '%d warnings', $counts['warning'], 'stonewright' ), $counts['warning'] );
		if ( $counts['problem'] > 0 && $counts['warning'] > 0 ) {
			/* translators: 1: number of problems with the word, 2: number of warnings with the word */
			return sprintf( __( '%1$s and %2$s to look at.', 'stonewright' ), $problems, $warnings );
		}
		if ( $counts['problem'] > 0 ) {
			/* translators: %s: number of problems with the word */
			return sprintf( __( '%s to look at.', 'stonewright' ), $problems );
		}
		if ( $counts['warning'] > 0 ) {
			/* translators: %s: number of warnings with the word */
			return sprintf( __( '%s to look at.', 'stonewright' ), $warnings );
		}

		if ( $unfinished ) {
			return __( 'No problems or warnings so far. Run the diagnostics to complete the checks that have not run.', 'stonewright' );
		}

		return __( 'No problems or warnings.', 'stonewright' );
	}

	/** @param array{problem: int, warning: int, info: int, ok: int, skipped: int} $counts */
	private static function summary_html( array $counts, bool $unfinished ): string {
		$text = Html::text( self::summary_text( $counts, $unfinished ) );

		return $counts['problem'] + $counts['warning'] > 0 ? Html::element( 'strong', [], $text ) : $text;
	}

	/**
	 * The checks that need attention in one table, then two folded groups: the ones that did not run and the ones
	 * that passed.
	 *
	 * @param array{problem: list<array<string, mixed>>, warning: list<array<string, mixed>>, skipped: list<array<string, mixed>>, ok: list<array<string, mixed>>, info: list<array<string, mixed>>} $grouped
	 */
	private static function results_html( array $grouped ): string {
		$html      = '';
		$attention = array_merge( $grouped['problem'], $grouped['warning'] );
		if ( [] !== $attention ) {
			$html .= self::table_html( $attention, __( 'Checks that need attention', 'stonewright' ) );
		}
		$other = array_merge( $grouped['skipped'], $grouped['info'] );
		if ( [] !== $other ) {
			$html .= self::folded_html(
				sprintf( /* translators: %d: number of checks */ _n( '%d other check', '%d other checks', count( $other ), 'stonewright' ), count( $other ) ),
				self::table_html( $other, __( 'Other checks', 'stonewright' ) )
			);
		}
		if ( [] !== $grouped['ok'] ) {
			$html .= self::folded_html(
				sprintf( /* translators: %d: number of checks */ _n( '%d check passed', '%d checks passed', count( $grouped['ok'] ), 'stonewright' ), count( $grouped['ok'] ) ),
				self::table_html( $grouped['ok'], __( 'Checks that passed', 'stonewright' ) )
			);
		}

		return $html;
	}

	/**
	 * Whether some check is still waiting for a run: those carry the not_run marker.
	 *
	 * @param list<mixed> $checks
	 */
	private static function has_unrun_checks( array $checks ): bool {
		foreach ( $checks as $check ) {
			if ( is_array( $check ) && is_array( $check['evidence'] ?? null ) && 'not_run' === ( $check['evidence']['state'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A copy button that swaps its icon for a check when the text was copied, the way the other copy buttons do.
	 */
	private static function copy_button( string $label, string $source, string $status_id, bool $small ): string {
		return Html::element(
			'button',
			[
				'type'                         => 'button',
				'class'                        => $small ? 'sw-ui-btn sw-ui-btn--sm' : 'sw-ui-btn',
				'data-sw-ui-copy'              => $source,
				'data-sw-ui-copy-status'       => '#' . $status_id,
				'data-sw-ui-copied-label'      => __( 'Copied', 'stonewright' ),
				'data-sw-ui-copy-failed-label' => __( 'Press Ctrl+C', 'stonewright' ),
			],
			Icon::render( 'copy', [ 'class' => 'sw-ui-copy__icon-copy' ] ) . Icon::render( 'check', [ 'class' => 'sw-ui-copy__icon-done' ] ) . Html::element( 'span', [], Html::text( $label ) )
		);
	}

	private static function folded_html( string $summary, string $body ): string {
		return Html::element(
			'details',
			[ 'class' => 'sw-ui-disclosure' ],
			Html::element( 'summary', [], Icon::render( 'chev-r' ) . Html::text( $summary ) ) . Html::element( 'div', [ 'class' => 'sw-ui-disclosure__body' ], $body )
		);
	}

	/**
	 * @param list<array<string, mixed>> $checks
	 */
	private static function table_html( array $checks, string $caption ): string {
		$rows = [];
		foreach ( $checks as $check ) {
			$status   = self::normalize_status( (string) ( $check['status'] ?? 'problem' ) );
			$summary  = (string) ( $check['summary'] ?? $check['detail'] ?? '' );
			$remedy   = (string) ( $check['remedy'] ?? '' );
			$copy     = (string) ( $check['copy'] ?? $check['ticket'] ?? '' );
			$check_id = sanitize_key( (string) ( $check['id'] ?? '' ) );
			$copy_id  = '' !== $check_id ? 'stonewright-diag-ticket-' . $check_id : '';
			$action   = self::safe_action( $check['action'] ?? null, $copy_id, $copy );
			if ( is_array( $action ) && 'copy' === $action['type'] && '' !== $action['target'] ) {
				$copy_id = $action['target'];
			}
			$primary = Html::element( 'span', [ 'class' => 'sw-ui-table__primary' ], Html::text( (string) ( $check['label'] ?? '' ) ) )
				. ( '' !== $summary ? Html::element( 'span', [ 'class' => 'sw-ui-table__meta' ], Html::text( $summary ) ) : '' )
				. ( '' !== $remedy && $remedy !== $summary && in_array( $status, [ 'problem', 'warning' ], true ) ? Html::element( 'span', [ 'class' => 'sw-ui-table__meta' ], Html::text( $remedy ) ) : '' );

			$button = '';
			if ( null !== $action ) {
				if ( 'link' === $action['type'] ) {
					$button = Button::render( $action['label'], [ 'size' => 'sm', 'href' => $action['target'] ] );
				} elseif ( 'retry' === $action['type'] ) {
					$button = Button::render( $action['label'], [ 'size' => 'sm', 'attrs' => [ 'data-sw-diag-run' => true ] ] );
				} else {
					$status_id = 'stonewright-diag-copy-status-' . ( '' !== $check_id ? $check_id : 'check' );
					$button    = self::copy_button( $action['label'], '#' . $action['target'], $status_id, true )
						. Html::element( 'span', [ 'class' => 'sw-ui-visually-hidden', 'role' => 'status', 'id' => $status_id ], '' );
				}
			}
			if ( '' !== $copy && '' !== $check_id ) {
				$button .= Html::element( 'pre', [ 'id' => $copy_id, 'hidden' => true ], Html::text( $copy ) );
			}
			$rows[] = [
				'check'  => [ 'html' => $primary ],
				'status' => [ 'html' => self::badge_for( $status ) ],
				'action' => [ 'html' => $button ],
			];
		}

		return Table::render(
			[
				[ 'key' => 'check', 'label' => __( 'Check', 'stonewright' ), 'primary' => true ],
				[ 'key' => 'status', 'label' => __( 'Result', 'stonewright' ) ],
				[ 'key' => 'action', 'label' => __( 'Action', 'stonewright' ), 'actions' => true ],
			],
			$rows,
			[ 'caption' => $caption ]
		);
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
