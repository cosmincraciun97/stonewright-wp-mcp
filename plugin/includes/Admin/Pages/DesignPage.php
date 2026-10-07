<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Pages;

use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Card;
use Stonewright\WpMcp\Admin\Ui\EmptyState;
use Stonewright\WpMcp\Admin\Ui\FormField;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\KvList;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Admin\Ui\Scope;
use Stonewright\WpMcp\Admin\Ui\Table;
use Stonewright\WpMcp\Admin\Ui\UtcTime;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Direction\DirectionImportSanitizer;
use Stonewright\WpMcp\Design\Direction\DirectionSummary;
use Stonewright\WpMcp\Design\Quality\QualityRuleRegistry;
use Stonewright\WpMcp\Security\Permissions;
use WP_Error;

/**
 * Design Direction admin tab. Writes go through the existing direction store.
 *
 * The page lists every stored direction, not only the active one, so a direction that was switched off or
 * imported as a draft is visible, says why it is not active, and can be switched on again when it is ready.
 */
final class DesignPage {

	public const SLUG       = 'stonewright-design';
	public const CAPABILITY = 'manage_options';

	private const IMPORT_NONCE   = 'stonewright_design_import';
	private const ACTIVATE_NONCE = 'stonewright_design_activate';
	private const IMPORT_ID      = 'sw-design-import';

	private static ?DesignDirectionService $service = null;

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_post_stonewright_design_import', [ self::class, 'handle_import' ] );
		add_action( 'admin_post_stonewright_design_activate', [ self::class, 'handle_activate' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			'stonewright',
			__( 'Design', 'stonewright' ),
			__( 'Design', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function service(): DesignDirectionService {
		return self::$service ??= new DesignDirectionService();
	}

	public static function set_service_for_tests( ?DesignDirectionService $service ): void {
		self::$service = $service;
	}

	public static function reset_for_tests(): void {
		self::$service = null;
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function import_document( string $markdown, int $actor_id ) {
		$sanitized = DirectionImportSanitizer::sanitize( $markdown, 'import' );
		if ( $sanitized instanceof WP_Error ) {
			return $sanitized;
		}

		$ready  = true === ( $sanitized['contract']['readiness']['ready'] ?? false );
		$result = self::service()->save(
			[
				'contract'    => $sanitized['contract'],
				'source_type' => 'import',
				'status'      => $ready ? 'ready' : 'draft',
				'source_refs' => [
					'rationale' => (string) $sanitized['sanitized_rationale'],
				],
			],
			$actor_id
		);

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		if ( $ready ) {
			self::service()->activate( (int) $result['id'], $actor_id );
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_active( bool $on, int $id, int $actor_id ) {
		if ( ! $on ) {
			$result = self::service()->deactivate( $actor_id );
			if ( $result instanceof WP_Error ) {
				return $result;
			}

			return [
				'ok'     => true,
				'active' => false,
				'id'     => 0,
			];
		}

		return self::service()->activate( $id, $actor_id );
	}

	/**
	 * Maps a set_active result to the admin notice query key.
	 *
	 * @param array<string, mixed>|WP_Error $result
	 */
	public static function notice_for_set_active( bool $on, array|WP_Error $result ): string {
		if ( $result instanceof WP_Error ) {
			return $on ? 'activate-error' : 'deactivate-error';
		}

		return $on ? 'activated' : 'deactivated';
	}

	/**
	 * Maps an import result to the notice query key: what was stored, and whether it is active.
	 *
	 * @param array<string, mixed>|WP_Error $result
	 */
	public static function notice_for_import( array|WP_Error $result ): string {
		if ( $result instanceof WP_Error ) {
			return 'import-error';
		}
		$id = (int) ( $result['id'] ?? 0 );
		if ( $id > 0 && $id === (int) get_option( DesignDirectionService::ACTIVE_OPTION, 0 ) ) {
			return 'imported-active';
		}

		return 'ready' === (string) ( $result['status'] ?? '' ) ? 'imported-ready' : 'imported-draft';
	}

	public static function handle_import(): void {
		if ( ! Permissions::manage_options() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'stonewright' ) );
		}
		check_admin_referer( self::IMPORT_NONCE );

		$markdown = isset( $_POST['design_markdown'] )
			? (string) wp_unslash( $_POST['design_markdown'] )
			: '';
		$result   = self::import_document( $markdown, get_current_user_id() );
		$notice   = self::notice_for_import( $result );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'                      => self::SLUG,
					'stonewright_design_notice' => $notice,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_activate(): void {
		if ( ! Permissions::manage_options() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'stonewright' ) );
		}
		check_admin_referer( self::ACTIVATE_NONCE );

		$id     = isset( $_POST['direction_id'] ) ? absint( $_POST['direction_id'] ) : 0;
		$on     = isset( $_POST['direction_enabled'] ) && '1' === (string) $_POST['direction_enabled'];
		$result = self::set_active( $on, $id, get_current_user_id() );
		$notice = self::notice_for_set_active( $on, $result );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'                      => self::SLUG,
					'stonewright_design_notice' => $notice,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function render(): void {
		if ( ! Permissions::manage_options() ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'stonewright' ),
				esc_html__( 'Forbidden', 'stonewright' ),
				[ 'response' => 403 ]
			);
		}

		$active  = self::service()->active();
		$records = self::service()->list();
		$notice  = isset( $_GET['stonewright_design_notice'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( (string) wp_unslash( $_GET['stonewright_design_notice'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

		$html = self::notice_html( $notice );
		if ( [] === $records ) {
			$html .= Card::render(
				__( 'Active direction', 'stonewright' ),
				EmptyState::render(
					__( 'No design direction yet', 'stonewright' ),
					__( 'A design direction is the tokens, dials and rules agents follow when they build pages. Import a DESIGN.md file to create one.', 'stonewright' ),
					[
						'variant'      => 'first-run',
						'actions_html' => Button::render( __( 'Go to the import', 'stonewright' ), [ 'href' => '#' . self::IMPORT_ID ] ),
					]
				)
			);
		} else {
			$html .= is_array( $active ) ? self::active_card( $active ) : self::no_active_card();
			$html .= self::directions_card( $records, is_array( $active ) ? (int) $active['id'] : 0 );
		}
		$html .= self::import_card();
		$html .= self::quality_card();

		AdminShell::open( self::SLUG );
		echo Scope::wrap( Html::element( 'div', [ 'class' => 'sw-ui-stack' ], $html ), [ 'page' => true, 'class' => 'sw-design' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
		AdminShell::close();
	}

	/**
	 * What the last action did. Each outcome has its own message; errors are alerts and never go away.
	 */
	private static function notice_html( string $key ): string {
		return match ( $key ) {
			'imported-active'  => Notice::render( 'ok', __( 'Design direction imported and activated.', 'stonewright' ), __( 'Agents now follow it. It is listed under Directions.', 'stonewright' ) ),
			'imported-draft'   => Notice::render( 'warn', __( 'Design direction imported, stored as a draft.', 'stonewright' ), __( 'It is not active because its readiness checks are not met. The reasons are listed under Directions.', 'stonewright' ) ),
			'imported-ready'   => Notice::render( 'warn', __( 'Design direction imported, but not activated.', 'stonewright' ), __( 'It passes its readiness checks. Activate it from the list below.', 'stonewright' ) ),
			'activated'        => Notice::render( 'ok', __( 'Design direction activated.', 'stonewright' ), __( 'Agents now follow it.', 'stonewright' ) ),
			'deactivated'      => Notice::render( 'ok', __( 'Design direction deactivated.', 'stonewright' ), __( 'Agents no longer follow a design direction. Activate it again from the list below.', 'stonewright' ) ),
			'import-error'     => Notice::render( 'danger', __( 'The DESIGN.md import was rejected.', 'stonewright' ), __( 'Check the front matter, the tokens and any secret-like prose, then import again.', 'stonewright' ) ),
			'activate-error'   => Notice::render( 'danger', __( 'The design direction could not be activated.', 'stonewright' ), __( 'Only a direction whose readiness checks pass can be activated.', 'stonewright' ) ),
			'deactivate-error' => Notice::render( 'danger', __( 'The active design direction could not be cleared.', 'stonewright' ), __( 'Try again. If it keeps failing, check that the site can write its options.', 'stonewright' ) ),
			default            => '',
		};
	}

	/**
	 * @param array<string, mixed> $record
	 */
	private static function active_card( array $record ): string {
		$contract = is_array( $record['contract'] ?? null ) ? $record['contract'] : [];
		$identity = is_array( $contract['identity'] ?? null ) ? $contract['identity'] : [];
		$tokens   = is_array( $contract['tokens'] ?? null ) ? $contract['tokens'] : [];
		$dials    = is_array( $contract['dials'] ?? null ) ? $contract['dials'] : [];
		$guidance = is_array( $contract['guidance'] ?? null ) ? $contract['guidance'] : [];
		$name     = (string) ( $identity['name'] ?? $record['slug'] ?? '' );

		$body = Html::element( 'p', [ 'class' => 'sw-design__name' ], Html::element( 'strong', [], Html::text( $name ) ) . ( '' !== (string) ( $identity['summary'] ?? '' ) ? ' ' . Html::text( (string) $identity['summary'] ) : '' ) );

		if ( [] !== $dials ) {
			$body .= KvList::render(
				[
					[ 'label' => __( 'Variance', 'stonewright' ), 'value' => (string) (int) ( $dials['variance'] ?? 0 ) ],
					[ 'label' => __( 'Density', 'stonewright' ), 'value' => (string) (int) ( $dials['density'] ?? 0 ) ],
					[ 'label' => __( 'Motion', 'stonewright' ), 'value' => (string) (int) ( $dials['motion'] ?? 0 ) ],
				],
				[ 'inline' => true, 'label' => __( 'Dials', 'stonewright' ) ]
			);
		}

		$columns = '';
		$colors  = is_array( $tokens['colors'] ?? null ) ? $tokens['colors'] : [];
		if ( [] !== $colors ) {
			$items = [];
			foreach ( $colors as $token => $value ) {
				$items[] = [ 'label' => (string) $token, 'value_html' => Html::element( 'code', [], Html::text( (string) $value ) ) ];
			}
			$columns .= Html::element( 'div', [], Html::element( 'h3', [ 'class' => 'sw-design__label' ], Html::text( __( 'Colors', 'stonewright' ) ) ) . KvList::render( $items, [ 'label' => __( 'Colors', 'stonewright' ) ] ) );
		}
		$columns .= self::list_column( __( 'Do', 'stonewright' ), $guidance['do'] ?? [] );
		$columns .= self::list_column( __( 'Don\'t', 'stonewright' ), $guidance['avoid'] ?? [] );
		if ( '' !== $columns ) {
			$body .= Html::element( 'div', [ 'class' => 'sw-design__cols' ], $columns );
		}

		return Card::render(
			__( 'Active direction', 'stonewright' ),
			$body,
			[
				'actions_html' => Badge::render( __( 'Active', 'stonewright' ), [ 'variant' => 'ok', 'dot' => true ] ),
			]
		);
	}

	private static function no_active_card(): string {
		return Card::render(
			__( 'Active direction', 'stonewright' ),
			EmptyState::render(
				__( 'No active design direction.', 'stonewright' ),
				__( 'Agents follow no design direction right now. Activate a ready direction from the list below, or import one.', 'stonewright' ),
				[ 'variant' => 'inline' ]
			)
		);
	}

	/**
	 * @param mixed $items
	 */
	private static function list_column( string $title, mixed $items ): string {
		if ( ! is_array( $items ) || [] === $items ) {
			return '';
		}
		$list = '';
		foreach ( $items as $item ) {
			$list .= Html::element( 'li', [], Html::text( (string) $item ) );
		}

		return Html::element( 'div', [], Html::element( 'h3', [ 'class' => 'sw-design__label' ], Html::text( $title ) ) . Html::element( 'ul', [ 'class' => 'sw-design__list' ], $list ) );
	}

	/**
	 * The one form for turning a direction on or off. Same action, nonce and field names as before: "direction_id"
	 * and, to switch on, "direction_enabled" = 1.
	 */
	private static function activation_form( int $id, bool $on, string $name ): string {
		$button = Button::render(
			$on ? __( 'Activate', 'stonewright' ) : __( 'Deactivate', 'stonewright' ),
			[
				'type'    => 'submit',
				'size'    => 'sm',
				'context' => $name,
				'name'    => $on ? 'direction_enabled' : null,
				'value'   => $on ? '1' : null,
			]
		);

		return FormField::post_form( 'stonewright_design_activate', self::ACTIVATE_NONCE, '_wpnonce', $button, [ 'hidden' => [ 'direction_id' => (string) $id ], 'class' => 'sw-design__form' ] );
	}

	/**
	 * Every stored direction with its state in words, so a deactivated or draft direction is never invisible.
	 *
	 * @param list<array<string, mixed>> $records
	 */
	private static function directions_card( array $records, int $active_id ): string {
		$rows = [];
		foreach ( $records as $record ) {
			$summary  = DirectionSummary::row( $record, $active_id );
			$contract = is_array( $record['contract'] ?? null ) ? $record['contract'] : [];
			$issues   = is_array( $contract['readiness']['issues'] ?? null ) ? array_values( array_filter( array_map( 'strval', $contract['readiness']['issues'] ), static fn ( string $issue ): bool => '' !== trim( $issue ) ) ) : [];
			$label    = '' !== $summary['name'] ? (string) $summary['name'] : (string) $summary['slug'];
			$status   = (string) $summary['status'];
			$meta     = (string) $summary['slug'];
			if ( 'ready' !== $status && 'archived' !== $status && [] !== $issues ) {
				$more  = count( $issues ) - 1;
				$meta .= ' · ' . sprintf( /* translators: %s: the first reason a direction is not ready */ __( 'Not ready: %s', 'stonewright' ), $issues[0] )
					. ( $more > 0 ? ' ' . sprintf( /* translators: %d: number of further reasons */ _n( 'and %d more issue.', 'and %d more issues.', $more, 'stonewright' ), $more ) : '' );
			}

			if ( $summary['active'] ) {
				$state  = Badge::render( __( 'Active', 'stonewright' ), [ 'variant' => 'ok', 'dot' => true ] );
				$action = self::activation_form( (int) $summary['id'], false, $label );
			} elseif ( 'ready' === $status && true === $summary['ready'] ) {
				$state  = Badge::render( __( 'Ready', 'stonewright' ), [ 'variant' => 'info' ] );
				$action = self::activation_form( (int) $summary['id'], true, $label );
			} else {
				$state  = 'draft' === $status || 'stale' === $status
					? Badge::render( 'draft' === $status ? __( 'Draft', 'stonewright' ) : __( 'Stale', 'stonewright' ), [ 'variant' => 'warn' ] )
					: Badge::render( __( 'Archived', 'stonewright' ) );
				$action = '';
			}

			$rows[] = [
				'direction' => [ 'text' => $label, 'meta' => $meta ],
				'state'     => [ 'html' => $state ],
				'revision'  => [ 'text' => (string) $summary['revision'] ],
				'updated'   => [ 'html' => UtcTime::render( (string) $summary['updated_at'] ) ],
				'action'    => [ 'html' => $action ],
			];
		}

		return Card::render(
			__( 'Directions', 'stonewright' ),
			Table::render(
				[
					[ 'key' => 'direction', 'label' => __( 'Direction', 'stonewright' ), 'primary' => true ],
					[ 'key' => 'state', 'label' => __( 'State', 'stonewright' ) ],
					[ 'key' => 'revision', 'label' => __( 'Revision', 'stonewright' ), 'numeric' => true, 'secondary' => true ],
					[ 'key' => 'updated', 'label' => __( 'Updated', 'stonewright' ), 'secondary' => true ],
					[ 'key' => 'action', 'label' => __( 'Action', 'stonewright' ), 'actions' => true ],
				],
				$rows,
				[ 'caption' => __( 'Stored design directions', 'stonewright' ) ]
			),
			[
				'flush' => true,
				'desc'  => __( 'Only a direction that passes its readiness checks can be active. A draft stays here until it does.', 'stonewright' ),
			]
		);
	}

	private static function import_card(): string {
		$form = FormField::post_form(
			'stonewright_design_import',
			self::IMPORT_NONCE,
			'_wpnonce',
			FormField::textarea(
				__( 'DESIGN.md', 'stonewright' ),
				'design_markdown',
				[
					'id'       => 'design_markdown',
					'rows'     => 12,
					'code'     => true,
					'required' => true,
					'help'     => __( 'Paste a direction document with JSON front matter (tokens, dials, Do and Don\'t). Secrets and tool instructions are stripped before storage.', 'stonewright' ),
				]
			) . Button::group( [ Button::render( __( 'Import direction', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'primary' ] ) ] ),
			[ 'class' => 'sw-ui-stack' ]
		);

		return Card::render( __( 'Import DESIGN.md', 'stonewright' ), $form, [ 'id' => self::IMPORT_ID ] );
	}

	private static function quality_card(): string {
		$rows = [];
		foreach ( QualityRuleRegistry::floor() as $rule ) {
			$severity = (string) $rule['severity'];
			$rows[]   = [
				'rule'     => [ 'html' => Html::element( 'code', [], Html::text( (string) $rule['id'] ) ) ],
				'check'    => [ 'text' => (string) $rule['summary'] ],
				'severity' => [ 'html' => 'error' === $severity ? Badge::render( __( 'Error', 'stonewright' ), [ 'variant' => 'danger', 'icon' => 'x' ] ) : Badge::render( __( 'Warning', 'stonewright' ), [ 'variant' => 'warn', 'icon' => 'alert' ] ) ],
			];
		}

		return Card::render(
			__( 'Quality floor', 'stonewright' ),
			Table::render(
				[
					[ 'key' => 'rule', 'label' => __( 'Rule', 'stonewright' ), 'primary' => true ],
					[ 'key' => 'check', 'label' => __( 'What it checks', 'stonewright' ) ],
					[ 'key' => 'severity', 'label' => __( 'Severity', 'stonewright' ) ],
				],
				$rows,
				[ 'caption' => __( 'Quality floor rules', 'stonewright' ) ]
			),
			[
				'flush' => true,
				'desc'  => __( 'Generated pages are checked against these measurable rules. Missing evidence is not a pass.', 'stonewright' ),
			]
		);
	}
}
