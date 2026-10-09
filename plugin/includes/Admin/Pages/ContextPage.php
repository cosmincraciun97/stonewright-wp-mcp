<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Pages;

use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Card;
use Stonewright\WpMcp\Admin\Ui\FormField;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Icon;
use Stonewright\WpMcp\Admin\Ui\KvList;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Admin\Ui\Scope;
use Stonewright\WpMcp\Context\ContextSnapshot;
use Stonewright\WpMcp\Context\UserContext;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Read-only task-start snapshot plus persisted user context.
 */
final class ContextPage {

	public const SLUG       = 'stonewright-context';
	public const CAPABILITY = 'manage_options';

	private const SAVE_NONCE = 'stonewright_user_context_save';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_post_stonewright_user_context_save', [ self::class, 'handle_save' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			'stonewright',
			__( 'Context', 'stonewright' ),
			__( 'Context', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function handle_save(): void {
		if ( ! Permissions::manage_options() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'stonewright' ) );
		}
		check_admin_referer( self::SAVE_NONCE );

		$text    = isset( $_POST['stonewright_user_context'] )
			? (string) wp_unslash( $_POST['stonewright_user_context'] )
			: '';
		$enabled = isset( $_POST['stonewright_user_context_enabled'] );
		UserContext::save( $text, $enabled );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'                       => self::SLUG,
					'stonewright_context_notice' => 'saved',
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

		$snapshot = ContextSnapshot::for_admin();
		$stored   = UserContext::stored();
		$enabled  = (bool) get_option( UserContext::ENABLED_OPTION, false );
		$notice   = isset( $_GET['stonewright_context_notice'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( (string) wp_unslash( $_GET['stonewright_context_notice'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';

		$html = '';
		if ( 'saved' === $notice ) {
			$html .= Notice::render(
				'ok',
				__( 'User context saved.', 'stonewright' ),
				self::reach_sentence( mb_strlen( $stored ) ) . ' ' . (
					$enabled
						? __( 'Agents receive it at task start.', 'stonewright' )
						: __( 'It is off, so agents do not receive it. Turn on "Include user context in task start" to use it.', 'stonewright' )
				)
			);
		}
		$html .= Html::element( 'div', [ 'class' => 'sw-context__cols' ], self::system_card( $snapshot ) . self::user_card( $stored, $enabled ) );

		AdminShell::open( self::SLUG );
		echo Scope::wrap( Html::element( 'div', [ 'class' => 'sw-ui-stack' ], $html ), [ 'page' => true, 'class' => 'sw-context' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
		AdminShell::close();
	}

	/**
	 * The generated instructions agents get for this site, with URLs, emails and post ids redacted.
	 *
	 * @param array<string, mixed> $snapshot
	 */
	private static function system_card( array $snapshot ): string {
		$plugin_labels = [];
		foreach ( is_array( $snapshot['plugins'] ?? null ) ? $snapshot['plugins'] : [] as $plugin ) {
			if ( is_array( $plugin ) ) {
				$plugin_labels[] = trim( (string) ( $plugin['name'] ?? '' ) . ' ' . (string) ( $plugin['version'] ?? '' ) );
			}
		}
		$site_url     = (string) ( is_array( $snapshot['target_context'] ?? null ) ? ( $snapshot['target_context']['normalized_url'] ?? '' ) : '' );
		$instructions = (string) ( $snapshot['instructions'] ?? '' );

		$facts = [
			[ 'label' => __( 'PHP', 'stonewright' ), 'value' => (string) ( $snapshot['php_version'] ?? '' ) ],
			[ 'label' => __( 'WordPress', 'stonewright' ), 'value' => (string) ( $snapshot['wordpress_version'] ?? '' ) ],
			[ 'label' => __( 'Mode', 'stonewright' ), 'value' => (string) ( $snapshot['mode'] ?? '' ) ],
			[ 'label' => __( 'Tool profile', 'stonewright' ), 'value' => (string) ( $snapshot['tool_profile'] ?? '' ) ],
			[ 'label' => __( 'Site URL', 'stonewright' ), 'value' => $site_url ],
		];
		if ( [] !== $plugin_labels ) {
			$facts[] = [ 'label' => __( 'Plugins', 'stonewright' ), 'value' => implode( ', ', $plugin_labels ) ];
		}
		$facts = array_values( array_filter( $facts, static fn ( array $fact ): bool => '' !== trim( $fact['value'] ) ) );

		$code_id = Html::unique_id( 'context-code' );
		$code    = Html::element(
			'div',
			[ 'class' => 'sw-ui-code' ],
			Html::element(
				'div',
				[ 'class' => 'sw-ui-code__head' ],
				Html::element( 'span', [], Html::text( __( 'Generated instructions', 'stonewright' ) ) )
				. Html::element(
					'button',
					[
						'type'                    => 'button',
						'class'                   => 'sw-ui-btn sw-ui-btn--sm',
						'data-sw-ui-copy'         => '#' . $code_id,
						'data-sw-ui-copy-status'  => '#' . $code_id . '-status',
						'data-sw-ui-copied-label' => __( 'Copied', 'stonewright' ),
						'aria-label'              => __( 'Copy system instructions', 'stonewright' ),
					],
					Icon::render( 'copy', [ 'class' => 'sw-ui-copy__icon-copy' ] ) . Icon::render( 'check', [ 'class' => 'sw-ui-copy__icon-done' ] ) . Html::element( 'span', [], Html::text( __( 'Copy', 'stonewright' ) ) )
				)
				. Html::element( 'span', [ 'class' => 'sw-ui-visually-hidden', 'id' => $code_id . '-status', 'role' => 'status' ], '' )
			)
			. Html::element( 'pre', [ 'class' => 'sw-ui-code__body', 'id' => $code_id, 'tabindex' => '0', 'aria-label' => __( 'System instructions', 'stonewright' ) ], Html::element( 'code', [], Html::text( $instructions ) ) )
		);
		$preview = Html::element(
			'details',
			[ 'class' => 'sw-ui-disclosure', 'data-sw-ui-remember' => 'context-system' ],
			Html::element( 'summary', [], Icon::render( 'chev-r' ) . Html::text( __( 'Show full system context', 'stonewright' ) ) )
			. Html::element( 'div', [ 'class' => 'sw-ui-disclosure__body' ], $code )
		);

		return Card::render(
			__( 'System context', 'stonewright' ),
			Html::element( 'div', [ 'class' => 'sw-ui-stack' ], KvList::render( $facts, [ 'label' => __( 'System facts', 'stonewright' ) ] ) . $preview ),
			[ 'desc' => __( 'Generated agent instructions for this site. URLs, emails, and post IDs are redacted in this admin view.', 'stonewright' ) ]
		);
	}

	/**
	 * How many characters are stored and how many of them each task-start mode carries.
	 */
	private static function reach_sentence( int $stored ): string {
		$reach = UserContext::reach( $stored );
		if ( 0 === $reach['stored'] ) {
			return __( 'Nothing is stored, so there is no user context to send.', 'stonewright' );
		}

		return sprintf(
			/* translators: 1: characters stored, 2: characters compact task start receives, 3: characters full task start receives */
			__( '%1$d characters stored. Compact task start receives %2$d of them; full task start and context-bootstrap receive %3$d.', 'stonewright' ),
			$reach['stored'],
			$reach['compact'],
			$reach['full']
		);
	}

	private static function user_card( string $stored, bool $enabled ): string {
		$fields = FormField::switch(
			__( 'Include user context in task start', 'stonewright' ),
			'stonewright_user_context_enabled',
			[
				'id'      => 'stonewright_user_context_enabled',
				'checked' => $enabled,
				'help'    => __( 'When on, this text is prepended to the custom instructions that task-start and context-bootstrap return.', 'stonewright' ),
			]
		)
			. FormField::textarea(
				__( 'Persisted user context', 'stonewright' ),
				'stonewright_user_context',
				[
					'id'        => 'stonewright_user_context',
					'rows'      => 12,
					'code'      => true,
					'value'     => $stored,
					'maxlength' => UserContext::MAX_STORED,
					'help'      => sprintf(
						/* translators: 1: characters stored, 2: characters compact task start carries, 3: characters full task start carries */
						__( 'Up to %1$d characters are stored as plain text; tags are removed. Compact task start (the default) carries the first %2$d characters, and full task start and context-bootstrap the first %3$d characters, so put the most important lines first.', 'stonewright' ),
						UserContext::MAX_STORED,
						UserContext::MAX_COMPACT,
						UserContext::MAX_INJECTED
					),
				]
			)
			. Button::group( [ Button::render( __( 'Save user context', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'primary' ] ) ] );

		return Card::render(
			__( 'User context', 'stonewright' ),
			FormField::post_form( 'stonewright_user_context_save', self::SAVE_NONCE, '_wpnonce', $fields, [ 'class' => 'sw-ui-stack' ] ),
			[
				'actions_html' => $enabled
					? Badge::render( __( 'On', 'stonewright' ), [ 'variant' => 'ok', 'dot' => true ] )
					: Badge::render( __( 'Off', 'stonewright' ), [ 'dot' => true ] ),
			]
		);
	}
}
