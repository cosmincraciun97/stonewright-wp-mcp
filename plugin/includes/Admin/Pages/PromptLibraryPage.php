<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Pages;

use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\EmptyState;
use Stonewright\WpMcp\Admin\Ui\FormField;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Icon;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Admin\Ui\Scope;
use Stonewright\WpMcp\Support\PromptCatalog;

/**
 * Dedicated Prompt Library admin tab.
 *
 * A search-first catalog: the field filters the cards as you type (the layer's list filter), the cards are grouped
 * by outcome, and each card copies its own prompt and says so next to the button.
 */
final class PromptLibraryPage {

	public const SLUG       = 'stonewright-prompts';
	public const CAPABILITY = 'manage_options';

	private const LIST_ID = 'sw-prompts-list';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			'stonewright',
			__( 'Prompt library', 'stonewright' ),
			__( 'Prompt library', 'stonewright' ),
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

		$prompts    = PromptCatalog::all();
		$by_outcome = [];
		foreach ( $prompts as $prompt ) {
			$outcome = (string) ( $prompt['outcome'] ?? 'general' );
			if ( ! isset( $by_outcome[ $outcome ] ) ) {
				$by_outcome[ $outcome ] = [];
			}
			$by_outcome[ $outcome ][] = $prompt;
		}
		ksort( $by_outcome );

		$html = Notice::callout(
			'info',
			__( 'Every prompt starts with stonewright-task-start.', 'stonewright' ),
			__( 'Mode tags show where a prompt works. Prompts contain no site URL, username, Application Password, token, memory entry, or audit payload.', 'stonewright' )
		);

		if ( [] === $prompts ) {
			$html .= EmptyState::render(
				__( 'No prompts in the catalog yet', 'stonewright' ),
				__( 'Prompts are task starters grouped by outcome. They appear here when the catalog has entries.', 'stonewright' ),
				[ 'variant' => 'first-run' ]
			);
		} else {
			$html .= self::toolbar( count( $prompts ) );
			$groups = '';
			$serial = 0;
			foreach ( $by_outcome as $outcome => $group ) {
				$groups .= self::group( (string) $outcome, $group, $serial );
			}
			$html .= Html::element(
				'div',
				[ 'id' => self::LIST_ID, 'class' => 'sw-prompts__groups' ],
				$groups
				. Html::element(
					'div',
					[ 'data-sw-ui-filter-empty' => true, 'hidden' => true ],
					EmptyState::render( __( 'No prompt matches', 'stonewright' ), __( 'Try a shorter search, or search by an outcome or a tool name.', 'stonewright' ), [ 'variant' => 'no-results' ] )
				)
			);
		}

		AdminShell::open( self::SLUG );
		echo Scope::wrap( Html::element( 'div', [ 'class' => 'sw-ui-stack' ], $html ), [ 'page' => true, 'class' => 'sw-prompts' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
		AdminShell::close();
	}

	private static function toolbar( int $total ): string {
		$search = FormField::input(
			__( 'Search prompts', 'stonewright' ),
			'prompt_search',
			[
				'id'           => 'sw-prompts-search',
				'type'         => 'search',
				'placeholder'  => __( 'Filter by title, outcome, or tool…', 'stonewright' ),
				'autocomplete' => 'off',
				'attrs'        => [ 'data-sw-ui-filter' => '#' . self::LIST_ID, 'data-sw-ui-search' => true ],
			]
		);

		return Html::element(
			'div',
			[ 'class' => 'sw-ui-toolbar' ],
			Html::element( 'div', [ 'class' => 'sw-ui-toolbar__search' ], $search )
			. Html::element(
				'span',
				[
					'class'                    => 'sw-ui-toolbar__meta',
					'role'                     => 'status',
					'data-sw-ui-filter-count'  => true,
					'data-sw-ui-filter-label'  => __( 'Showing %1$s of %2$s prompts', 'stonewright' ),
				],
				Html::text( sprintf( /* translators: 1: prompts shown, 2: prompts in the catalog */ __( 'Showing %1$s of %2$s prompts', 'stonewright' ), (string) $total, (string) $total ) )
			)
		);
	}

	/**
	 * One outcome: a heading and the cards under it.
	 *
	 * @param list<array<string, mixed>> $group
	 */
	private static function group( string $outcome, array $group, int &$serial ): string {
		$id    = 'sw-prompts-group-' . sanitize_html_class( $outcome );
		$cards = '';
		foreach ( $group as $prompt ) {
			++$serial;
			$cards .= self::card( $prompt, $outcome, $serial );
		}

		return Html::element(
			'section',
			[ 'data-sw-prompt-outcome' => $outcome, 'data-sw-ui-filter-group' => true, 'aria-labelledby' => $id ],
			Html::element( 'div', [ 'class' => 'sw-prompts__head' ], Html::element( 'h2', [ 'id' => $id ], Html::text( ucwords( str_replace( '-', ' ', $outcome ) ) ) ) )
			. Html::element( 'div', [ 'class' => 'sw-prompts__grid' ], $cards )
		);
	}

	/**
	 * @param array<string, mixed> $prompt
	 */
	private static function card( array $prompt, string $outcome, int $serial ): string {
		$id            = (string) ( $prompt['id'] ?? '' );
		$title         = (string) ( $prompt['title'] ?? $id );
		$summary       = (string) ( $prompt['summary'] ?? '' );
		$body          = (string) ( $prompt['prompt'] ?? '' );
		$tools         = is_array( $prompt['tools'] ?? null ) ? $prompt['tools'] : [];
		$modes         = is_array( $prompt['modes'] ?? null ) ? $prompt['modes'] : [ 'plugin' ];
		$prerequisites = is_array( $prompt['prerequisites'] ?? null ) ? $prompt['prerequisites'] : [];
		$verification  = (string) ( $prompt['verification'] ?? '' );
		$search        = strtolower( trim( $title . ' ' . $outcome . ' ' . $summary . ' ' . $id . ' ' . implode( ' ', array_map( 'strval', $tools ) ) ) );
		$title_id      = 'sw-prompts-title-' . $serial;
		$status_id     = 'sw-prompts-status-' . $serial;

		$mode_tags = '';
		foreach ( $modes as $mode ) {
			$mode_tags .= Badge::tag( 'direct' === $mode ? __( 'Direct', 'stonewright' ) : __( 'Plugin', 'stonewright' ) );
		}
		$header = Html::element(
			'div',
			[ 'class' => 'sw-ui-card__header' ],
			Html::element( 'h3', [ 'class' => 'sw-ui-card__title', 'id' => $title_id ], Html::text( $title ) )
			. ( '' !== $summary ? Html::element( 'p', [ 'class' => 'sw-ui-card__desc' ], Html::text( $summary ) ) : '' )
		);

		$details = '';
		if ( [] !== $prerequisites ) {
			$list = '';
			foreach ( $prerequisites as $prerequisite ) {
				$list .= Html::element( 'li', [], Html::text( (string) $prerequisite ) );
			}
			$details .= Html::element( 'p', [], Html::element( 'strong', [], Html::text( __( 'Requires', 'stonewright' ) ) ) ) . Html::element( 'ul', [ 'class' => 'sw-prompts__list' ], $list );
		}
		if ( '' !== $verification ) {
			$details .= Html::element( 'p', [], Html::element( 'strong', [], Html::text( __( 'Done when', 'stonewright' ) ) ) ) . Html::element( 'p', [], Html::text( $verification ) );
		}
		$body_html = '' !== $details
			? Html::element(
				'details',
				[ 'class' => 'sw-ui-disclosure' ],
				Html::element( 'summary', [], Icon::render( 'chev-r' ) . Html::text( __( 'Requirements and verification', 'stonewright' ) ) )
				. Html::element( 'div', [ 'class' => 'sw-ui-disclosure__body' ], $details )
			)
			: '';
		if ( [] !== $tools ) {
			$codes = '';
			foreach ( array_slice( $tools, 0, 4 ) as $tool ) {
				$codes .= Html::element( 'code', [], Html::text( (string) $tool ) );
			}
			$body_html .= Html::element( 'p', [ 'class' => 'sw-prompts__tools' ], $codes );
		}

		$footer = Html::element(
			'div',
			[ 'class' => 'sw-ui-card__footer' ],
			Html::element(
				'div',
				[ 'class' => 'sw-ui-actions' ],
				Button::render(
					__( 'Copy prompt', 'stonewright' ),
					[
						'icon'    => 'copy',
						'size'    => 'sm',
						'context' => $title,
						'attrs'   => [
							'data-sw-ui-copy-text'         => $body,
							'data-sw-ui-copy-status'       => '#' . $status_id,
							'data-sw-ui-copied-label'      => __( 'Copied', 'stonewright' ),
							'data-sw-ui-copy-failed-label' => __( 'Press Ctrl+C', 'stonewright' ),
						],
					]
				)
				. Html::element( 'span', [ 'class' => 'sw-ui-copy__status', 'id' => $status_id, 'role' => 'status' ], '' )
			)
			. Html::element( 'div', [ 'class' => 'sw-ui-actions', 'role' => 'group', 'aria-label' => __( 'Available modes', 'stonewright' ) ], $mode_tags )
		);

		return Html::element(
			'article',
			[
				'class'                    => 'sw-ui-card',
				'aria-labelledby'          => $title_id,
				'data-sw-prompt-card'      => true,
				'data-sw-ui-filter-item'   => true,
				'data-sw-ui-filter-text'   => $search,
				'data-outcome'             => $outcome,
			],
			$header . Html::element( 'div', [ 'class' => 'sw-ui-card__body' ], $body_html ) . $footer
		);
	}
}
