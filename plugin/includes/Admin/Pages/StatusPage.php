<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Pages;

use Stonewright\WpMcp\Abilities\Site\SitePulse;
use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\OverviewData;
use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Card;
use Stonewright\WpMcp\Admin\Ui\EmptyState;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\KvList;
use Stonewright\WpMcp\Admin\Ui\Table;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Core\LiveAbilities;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\PluginEffectiveState;
use Stonewright\WpMcp\SkillLibrary\Site\SkillLibraryService;
use Stonewright\WpMcp\SkillLibrary\Site\WordPressBoundary;

/**
 * Stonewright Overview: the landing page. Read-only.
 *
 * It answers three questions in this order: is it working (the status band), what needs me (items with a state
 * word and one action), what happened (recent activity). While setup is unfinished the next step is the one
 * primary action on the page.
 *
 * The page keeps its original slug, `stonewright-status`, so every bookmark and link still opens it.
 *
 * @phpstan-import-type Facts from OverviewData
 * @phpstan-type View array{facts: Facts, effective_state: string, mode: string, tool_count: int, surface: string, last_activity: string, calls_14d: int, daily_counts: array<string, int>, last_client_use: int|null, companion: array{state: string, host: string, detail: string}, recent: list<array<string, mixed>>, pulse: array{score: int, grade: string}|null, elementor: array{version: string, pro: bool}, skills: int, memory: int, version: string}
 */
final class StatusPage {

	public const SLUG       = 'stonewright-status';
	public const CAPABILITY = 'manage_options';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
	}

	public static function add_submenu(): void {
		// Hub: Overview. The slug stays stonewright-status; MenuOrder puts the entry first.
		add_submenu_page(
			'stonewright',
			__( 'Overview', 'stonewright' ),
			__( 'Overview', 'stonewright' ),
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

		$view = self::view();

		AdminShell::open( self::SLUG, [ 'actions' => self::header_status_html( $view ) ] );
		echo self::overview_html( $view ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
		AdminShell::close();
	}

	/**
	 * Read the site once.
	 *
	 * @return View
	 */
	private static function view(): array {
		$recent_entries = AuditLog::recent( 8, 1 );
		$daily_counts   = AuditLog::daily_counts( 14 );
		$facts          = OverviewData::facts();

		$pulse = null;
		try {
			$result = ( new SitePulse() )->execute( [] );
			if ( is_array( $result ) ) {
				$pulse = [ 'score' => (int) ( $result['score'] ?? 0 ), 'grade' => (string) ( $result['grade'] ?? '' ) ];
			}
		} catch ( \Throwable $failure ) {
			$pulse = null;
		}

		return [
			'facts'           => $facts,
			'effective_state' => PluginEffectiveState::effective_state(),
			'mode'            => (string) get_option( 'stonewright_mode', 'development' ),
			'tool_count'      => LiveAbilities::exposed_count(),
			'surface'         => AbilityRegistry::mcp_surface(),
			'last_activity'   => (string) ( $recent_entries[0]['created_at'] ?? '' ),
			'calls_14d'       => (int) array_sum( $daily_counts ),
			'daily_counts'    => $daily_counts,
			'last_client_use' => $facts['last_client_use'],
			'companion'       => OverviewData::companion( (string) get_option( 'stonewright_companion_url', 'http://127.0.0.1:8765' ) ),
			'recent'          => $recent_entries,
			'pulse'           => $pulse,
			'elementor'       => [
				'version' => defined( 'ELEMENTOR_VERSION' ) ? (string) constant( 'ELEMENTOR_VERSION' ) : '',
				'pro'     => class_exists( 'ElementorPro\Plugin' ),
			],
			'skills'          => count( SkillLibraryService::open( WordPressBoundary::ADMIN )->records() ),
			'memory'          => count( Memory::list_all( 10000 ) ),
			'version'         => defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : '',
		];
	}

	/**
	 * The status badges in the page header: whether abilities are on, the mode and the version.
	 *
	 * @param View $view
	 */
	public static function header_status_html( array $view ): string {
		$state = match ( $view['effective_state'] ) {
			PluginEffectiveState::STATE_ENABLED              => Badge::render( __( 'AI abilities on', 'stonewright' ), [ 'variant' => 'ok', 'dot' => true ] ),
			PluginEffectiveState::STATE_DISABLED_BY_OPERATOR => Badge::render( __( 'AI abilities off', 'stonewright' ), [ 'dot' => true ] ),
			default                                          => Badge::render( __( 'AI abilities blocked', 'stonewright' ), [ 'variant' => 'danger', 'icon' => 'alert' ] ),
		};
		$html  = $state . Badge::render( OverviewData::mode( $view['mode'] )['label'] );
		if ( '' !== $view['version'] ) {
			$html .= Badge::tag( 'v' . $view['version'] );
		}

		return $html;
	}

	/**
	 * The page content.
	 *
	 * @param View $view
	 */
	public static function overview_html( array $view ): string {
		$facts     = $view['facts'];
		$attention = OverviewData::attention( $facts );
		$setup     = OverviewData::setup_steps( $facts );

		$left  = Card::render(
			__( 'Needs attention', 'stonewright' ),
			self::attention_body( $attention ),
			[
				'flush'        => [] !== $attention,
				'actions_html' => [] !== $attention ? Badge::count( count( $attention ) ) : '',
			]
		);
		$left .= self::recent_card( $view, $setup['done'] === $setup['total'] );

		$right = '';
		if ( $setup['done'] < $setup['total'] ) {
			$right .= self::setup_card( $setup );
		}
		$right .= self::site_card( $view );

		return Html::element(
			'div',
			[ 'class' => 'sw-ui sw-ui-page sw-overview' ],
			self::stats( $view )
				. Html::element(
					'div',
					[ 'class' => 'sw-ui-grid sw-ui-grid--2-1' ],
					Html::element( 'div', [ 'class' => 'sw-ui-stack' ], $left ) . Html::element( 'div', [ 'class' => 'sw-ui-stack' ], $right )
				)
		);
	}

	/**
	 * Is it working: connection, mode, tool surface, last activity and the bridge.
	 *
	 * @param View $view
	 */
	private static function stats( array $view ): string {
		$facts   = $view['facts'];
		$clients = $facts['client_count'] + $facts['password_count'];
		$mode    = OverviewData::mode( $view['mode'] );

		if ( $clients > 0 ) {
			$connection_value = sprintf(
				/* translators: %d: number of connected clients */
				_n( '%d client', '%d clients', $clients, 'stonewright' ),
				$clients
			);
			$connection_meta  = null !== $view['last_client_use']
				? sprintf(
					/* translators: %s: how long ago, for example 4 mins ago */
					__( 'Last sign-in %s', 'stonewright' ),
					OverviewData::relative_since( $view['last_client_use'] )
				)
				: __( 'Not used yet', 'stonewright' );
		} else {
			$connection_value = __( 'None yet', 'stonewright' );
			$connection_meta  = $facts['enabled'] ? __( 'Waiting for the first client', 'stonewright' ) : __( 'AI abilities are off', 'stonewright' );
		}

		$activity_value = '' !== $view['last_activity'] ? OverviewData::relative_time( $view['last_activity'] ) : __( 'No activity yet', 'stonewright' );
		$activity_meta  = $view['calls_14d'] > 0
			? sprintf(
				/* translators: %d: number of recorded changes */
				_n( '%d change in 14 days', '%d changes in 14 days', $view['calls_14d'], 'stonewright' ),
				$view['calls_14d']
			)
			: __( 'Nothing recorded in 14 days', 'stonewright' );

		$companion      = $view['companion'];
		$companion_meta = '' !== $companion['host'] ? $companion['host'] : $companion['detail'];

		return Html::element(
			'div',
			[ 'class' => 'sw-ui-stats', 'role' => 'group', 'aria-label' => __( 'Status summary', 'stonewright' ) ],
			self::stat( __( 'Connection', 'stonewright' ), $connection_value, $connection_meta )
				. self::stat( __( 'Mode', 'stonewright' ), $mode['label'], $mode['meta'] )
				. self::stat( __( 'Tool surface', 'stonewright' ), (string) $view['tool_count'], OverviewData::surface_label( $view['surface'] ) )
				. self::stat( __( 'Last activity', 'stonewright' ), $activity_value, $activity_meta )
				. self::stat( __( 'Companion', 'stonewright' ), $companion['state'], $companion_meta )
		);
	}

	private static function stat( string $label, string $value, string $meta ): string {
		return Html::element(
			'div',
			[ 'class' => 'sw-ui-stat' ],
			Html::element( 'span', [ 'class' => 'sw-ui-stat__label' ], Html::text( $label ) )
				. Html::element( 'span', [ 'class' => 'sw-ui-stat__value' ], Html::text( $value ) )
				. ( '' !== $meta ? Html::element( 'span', [ 'class' => 'sw-ui-stat__meta' ], Html::text( $meta ) ) : '' )
		);
	}

	/**
	 * What needs me: a table of items, or a line saying nothing does.
	 *
	 * @param list<array{id: string, title: string, detail: string, badge: array{label: string, variant: string, icon: string}, action: array{label: string, url: string, context: string}}> $items
	 */
	private static function attention_body( array $items ): string {
		if ( [] === $items ) {
			return EmptyState::render(
				__( 'Nothing needs you right now.', 'stonewright' ),
				__( 'Open incidents, changes to roll back, queued block changes and an unverified connection appear here.', 'stonewright' ),
				[ 'variant' => 'inline', 'icon' => 'check' ]
			);
		}

		$rows = [];
		foreach ( $items as $item ) {
			$rows[] = [
				'item'   => [ 'text' => $item['title'], 'meta' => $item['detail'] ],
				'status' => [ 'html' => Badge::render( $item['badge']['label'], [ 'variant' => $item['badge']['variant'], 'icon' => $item['badge']['icon'] ] ) ],
				'action' => [ 'html' => Button::render( $item['action']['label'], [ 'href' => $item['action']['url'], 'size' => 'sm', 'context' => $item['action']['context'] ] ) ],
			];
		}

		return Table::render(
			[
				[ 'key' => 'item', 'label' => __( 'Item', 'stonewright' ), 'primary' => true ],
				[ 'key' => 'status', 'label' => __( 'Status', 'stonewright' ) ],
				[ 'key' => 'action', 'label' => __( 'Action', 'stonewright' ), 'actions' => true ],
			],
			$rows,
			[ 'caption' => __( 'Items that need attention', 'stonewright' ) ]
		);
	}

	/**
	 * Finish setup: the steps and the next one as the page's primary action.
	 *
	 * @param array{done: int, total: int, steps: list<array{id: string, label: string, cta: string, state: string, url: string}>, next: array{id: string, label: string, cta: string, state: string, url: string}|null} $setup
	 */
	private static function setup_card( array $setup ): string {
		$nodes = '';
		foreach ( $setup['steps'] as $step ) {
			$badge  = match ( $step['state'] ) {
				'done'  => Badge::render( __( 'Done', 'stonewright' ), [ 'variant' => 'ok', 'icon' => 'check' ] ),
				'next'  => Badge::render( __( 'Next', 'stonewright' ), [ 'variant' => 'accent' ] ),
				default => Badge::render( __( 'To do', 'stonewright' ) ),
			};
			$nodes .= Html::element(
				'li',
				[ 'class' => 'sw-ui-lineage__node', 'aria-current' => 'next' === $step['state'] ? 'step' : null ],
				$badge . ' ' . Html::text( $step['label'] )
			);
		}

		return Card::render(
			__( 'Finish setup', 'stonewright' ),
			Html::element( 'ol', [ 'class' => 'sw-ui-lineage', 'aria-label' => __( 'Setup steps', 'stonewright' ) ], $nodes ),
			[
				'actions_html' => Badge::render(
					sprintf(
						/* translators: 1: steps done, 2: number of steps */
						__( '%1$d of %2$d', 'stonewright' ),
						$setup['done'],
						$setup['total']
					),
					[ 'variant' => 'accent' ]
				),
				'footer_html'  => null !== $setup['next'] ? Button::render( $setup['next']['cta'], [ 'variant' => 'primary', 'href' => $setup['next']['url'] ] ) : '',
			]
		);
	}

	/**
	 * What happened: the last changes agents made, with the outcome in words.
	 *
	 * @param View $view
	 * @param bool $set_up Whether setup is finished. While it is not, the Finish setup card holds the one primary
	 *                     action and this card offers none, so the same step is not shown twice.
	 */
	private static function recent_card( array $view, bool $set_up ): string {
		$actions = Html::element( 'a', [ 'class' => 'sw-ui-link', 'href' => add_query_arg( [ 'page' => 'stonewright-audit-log' ], admin_url( 'admin.php' ) ) ], Html::text( __( 'Open audit log', 'stonewright' ) ) );

		if ( [] === $view['recent'] ) {
			return Card::render(
				__( 'Recent activity', 'stonewright' ),
				EmptyState::render(
					__( 'No activity yet', 'stonewright' ),
					__( 'Every change an agent makes appears here, with secrets removed.', 'stonewright' ),
					$set_up ? [ 'actions_html' => Button::render( __( 'Browse the prompt library', 'stonewright' ), [ 'href' => add_query_arg( [ 'page' => 'stonewright-prompts' ], admin_url( 'admin.php' ) ) ] ) ] : []
				),
				[ 'actions_html' => $actions ]
			);
		}

		$rows = [];
		foreach ( $view['recent'] as $row ) {
			$ability = (string) ( $row['ability_name'] ?? '' );
			$created = (string) ( $row['created_at'] ?? '' );
			$rows[]  = [
				'ability' => [
					'html' => Html::element(
						'a',
						[ 'class' => 'sw-ui-link', 'href' => add_query_arg( [ 'page' => 'stonewright-audit-log', 'ability' => $ability ], admin_url( 'admin.php' ) ) ],
						Html::element( 'code', [], Html::text( $ability ) )
					),
				],
				'result'  => [ 'html' => self::result_badge( strtolower( (string) ( $row['result_status'] ?? '' ) ) ) ],
				'when'    => [ 'html' => Html::element( 'time', [ 'datetime' => $created ], Html::text( OverviewData::relative_time( $created ) ) ) ],
			];
		}

		$table = Table::render(
			[
				[ 'key' => 'ability', 'label' => __( 'Ability', 'stonewright' ), 'primary' => true ],
				[ 'key' => 'result', 'label' => __( 'Result', 'stonewright' ) ],
				[ 'key' => 'when', 'label' => __( 'When', 'stonewright' ) ],
			],
			$rows,
			[ 'caption' => __( 'Recent changes by agents', 'stonewright' ) ]
		);

		return Card::render( __( 'Recent activity', 'stonewright' ), self::sparkline( $view['daily_counts'] ) . $table, [ 'flush' => true, 'actions_html' => $actions ] );
	}

	private static function result_badge( string $status ): string {
		return match ( $status ) {
			'ok'      => Badge::render( __( 'OK', 'stonewright' ), [ 'variant' => 'ok', 'icon' => 'check' ] ),
			'blocked' => Badge::render( __( 'Blocked', 'stonewright' ), [ 'variant' => 'warn', 'icon' => 'alert' ] ),
			'auth'    => Badge::render( __( 'Sign-in', 'stonewright' ), [ 'variant' => 'info', 'icon' => 'info' ] ),
			default   => Badge::render( __( 'Error', 'stonewright' ), [ 'variant' => 'danger', 'icon' => 'x' ] ),
		};
	}

	/**
	 * A few facts about the site that were on the Dashboard: pulse, Elementor, skills and memory.
	 *
	 * @param View $view
	 */
	private static function site_card( array $view ): string {
		$items = [];
		if ( null !== $view['pulse'] ) {
			$items[] = [
				'label' => __( 'Site pulse', 'stonewright' ),
				'value' => '' !== $view['pulse']['grade'] ? sprintf( '%d (%s)', $view['pulse']['score'], $view['pulse']['grade'] ) : (string) $view['pulse']['score'],
			];
		}
		$items[] = [ 'label' => __( 'Elementor', 'stonewright' ), 'value' => '' !== $view['elementor']['version'] ? $view['elementor']['version'] : __( 'Not detected', 'stonewright' ) ];
		$items[] = [
			'label'      => __( 'Skills', 'stonewright' ),
			'value_html' => Html::element( 'a', [ 'class' => 'sw-ui-link', 'href' => add_query_arg( [ 'page' => 'stonewright-skills' ], admin_url( 'admin.php' ) ) ], Html::text( (string) $view['skills'] ) ),
		];
		$items[] = [
			'label'      => __( 'Memory', 'stonewright' ),
			'value_html' => Html::element( 'a', [ 'class' => 'sw-ui-link', 'href' => add_query_arg( [ 'page' => 'stonewright-memory' ], admin_url( 'admin.php' ) ) ], Html::text( (string) $view['memory'] ) ),
		];

		return Card::render( __( 'This site', 'stonewright' ), KvList::render( $items ) );
	}

	/**
	 * @param array<string, int> $daily_counts
	 */
	private static function sparkline( array $daily_counts ): string {
		$values = array_values( $daily_counts );
		if ( [] === $values ) {
			return '';
		}
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

		$label = sprintf(
			/* translators: %d: total recorded changes in the window */
			__( 'Changes over the last 14 days: %d in total', 'stonewright' ),
			(int) array_sum( $values )
		);

		return Html::element(
			'div',
			[ 'class' => 'sw-overview__spark', 'role' => 'img', 'aria-label' => $label ],
			'<svg viewBox="0 0 ' . (int) $width . ' ' . (int) $height . '" width="100%" height="' . (int) $height . '" preserveAspectRatio="none" aria-hidden="true" focusable="false"><polyline fill="none" stroke="currentColor" stroke-width="2" points="' . esc_attr( implode( ' ', $points ) ) . '"/></svg>'
		);
	}
}
