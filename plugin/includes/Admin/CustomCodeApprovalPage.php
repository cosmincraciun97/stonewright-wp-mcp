<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Card;
use Stonewright\WpMcp\Admin\Ui\CopyField;
use Stonewright\WpMcp\Admin\Ui\EmptyState;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\KvList;
use Stonewright\WpMcp\Admin\Ui\Nonce;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Admin\Ui\Scope;
use Stonewright\WpMcp\Security\CustomCodeGrant;

/**
 * Human approval boundary for exact custom-code dry-run candidates.
 *
 * Built from the shared UI layer. The warning that says only a person approves is content on every view, and
 * the page prints exactly what it printed before as far as safety goes: the exact candidate, one form with a
 * nonce that issues one grant, and a token shown once, never masked.
 */
final class CustomCodeApprovalPage {

	public const SLUG       = 'stonewright-custom-code-approval';
	public const CAPABILITY = 'manage_options';

	/** The page of the documentation that explains the approval step. */
	private const DOCS_URL = 'https://github.com/cosmincraciun97/stonewright-wp-mcp/tree/main/docs/admin/sandbox.md';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_post_stonewright_custom_code_approve', [ self::class, 'handle_approve' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			ConfigurationPage::SLUG,
			__( 'Code approval', 'stonewright' ),
			__( 'Code approval', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to approve custom code.', 'stonewright' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only proposal/result lookup.
		$proposal_id = isset( $_GET['proposal_id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['proposal_id'] ) ) : '';
		$result_id   = isset( $_GET['result_id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['result_id'] ) ) : '';
		// phpcs:enable

		$content = Notice::callout(
			'warn',
			__( 'Human approval only.', 'stonewright' ),
			__( 'Agents must show you the proposal and stop. They may open or submit this page only when you explicitly ask them to perform the approval step.', 'stonewright' )
		);

		if ( '' !== $result_id ) {
			$content .= self::grant_result_html( $result_id );
		} elseif ( '' !== $proposal_id ) {
			$content .= self::proposal_html( $proposal_id );
		} else {
			$content .= self::nothing_selected_html();
		}

		AdminShell::open( self::SLUG );
		echo Scope::wrap( Html::element( 'div', [ 'class' => 'sw-code' ], $content ), [ 'page' => true ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
		AdminShell::close();
	}

	public static function handle_approve(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden', 'stonewright' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'stonewright_custom_code_approve', '_stonewright_nonce' );
		$proposal_id = isset( $_POST['proposal_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['proposal_id'] ) ) : '';
		$result      = CustomCodeGrant::approve_proposal( $proposal_id );
		$result_id   = wp_generate_uuid4();
		set_transient(
			'sw_cc_result_' . $result_id,
			$result instanceof \WP_Error
				? [
					'ok'      => false,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				]
				: array_merge( [ 'ok' => true ], $result ),
			300
		);
		wp_safe_redirect(
			add_query_arg(
				[
					'page'      => self::SLUG,
					'result_id' => $result_id,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** The way back to the Custom code hub. */
	private static function back_button( string $label ): string {
		return Button::render( $label, [ 'href' => admin_url( 'admin.php?page=' . SandboxPage::SLUG ), 'variant' => 'tertiary', 'size' => 'sm' ] );
	}

	/** No proposal and no result: say what this page is for and how a proposal arrives. */
	private static function nothing_selected_html(): string {
		return Card::render(
			__( 'Pending approval', 'stonewright' ),
			EmptyState::render(
				__( 'Nothing to approve', 'stonewright' ),
				__( 'When an agent proposes custom code it stops here for you. Ask it to run the custom-code tool with a dry run, then open the link it returns.', 'stonewright' ),
				[
					'icon'         => 'shield',
					'actions_html' => Button::render( __( 'Open Custom code', 'stonewright' ), [ 'variant' => 'primary', 'href' => admin_url( 'admin.php?page=' . SandboxPage::SLUG ) ] )
						. Html::element(
							'a',
							[ 'class' => 'sw-ui-link sw-ui-link--external', 'href' => self::DOCS_URL, 'target' => '_blank', 'rel' => 'noopener noreferrer' ],
							Html::text( __( 'How approvals work', 'stonewright' ) )
						),
				]
			)
		);
	}

	private static function proposal_html( string $proposal_id ): string {
		$proposal = CustomCodeGrant::proposal( $proposal_id );
		if ( $proposal instanceof \WP_Error ) {
			return Notice::render( 'danger', __( 'This proposal cannot be approved', 'stonewright' ), $proposal->get_error_message(), [ 'actions_html' => self::back_button( __( 'Back to Custom code', 'stonewright' ) ) ] );
		}

		$gap  = is_array( $proposal['native_gap'] ?? null ) ? $proposal['native_gap'] : [];
		$diff = is_array( $proposal['diff_preview'] ?? null ) ? $proposal['diff_preview'] : [];

		$risk_class = (string) $proposal['risk_class'];
		$facts      = KvList::render(
			[
				[ 'label' => __( 'Path', 'stonewright' ), 'value_html' => Html::element( 'code', [], Html::text( (string) $proposal['path'] ) ) ],
				[ 'label' => __( 'Language', 'stonewright' ), 'value' => strtoupper( (string) $proposal['language'] ) ],
				[
					'label'      => __( 'Risk', 'stonewright' ),
					'value_html' => self::risk_badge( $risk_class ) . ' ' . Html::element( 'code', [], Html::text( $risk_class ) ),
				],
				[ 'label' => __( 'Changed bytes', 'stonewright' ), 'value_html' => Html::element( 'span', [ 'class' => 'sw-ui-num' ], Html::text( (string) $proposal['changed_bytes'] ) ) ],
				[ 'label' => __( 'Candidate SHA-256', 'stonewright' ), 'value_html' => Html::element( 'code', [], Html::text( (string) $proposal['after_sha256'] ) ) ],
				[ 'label' => __( 'Native gap', 'stonewright' ), 'value' => (string) ( $gap['reason'] ?? '' ) ],
			],
			[ 'label' => __( 'Exact candidate', 'stonewright' ) ]
		);

		$preview = Html::element(
			'div',
			[ 'class' => 'sw-ui-code' ],
			Html::element( 'div', [ 'class' => 'sw-ui-code__head' ], Html::element( 'span', [], Html::text( __( 'Bounded diff preview', 'stonewright' ) ) ) )
				. Html::element(
					'pre',
					[ 'class' => 'sw-ui-code__body', 'tabindex' => '0', 'aria-label' => __( 'Bounded diff preview of the candidate', 'stonewright' ) ],
					Html::element( 'code', [], Html::text( (string) ( $diff['preview'] ?? '' ) ) )
				)
		);

		$form = Html::element(
			'form',
			[ 'method' => 'post', 'action' => admin_url( 'admin-post.php' ), 'class' => 'sw-code__form' ],
			Html::void( 'input', [ 'type' => 'hidden', 'name' => 'action', 'value' => 'stonewright_custom_code_approve' ] )
				. Html::void( 'input', [ 'type' => 'hidden', 'name' => 'proposal_id', 'value' => $proposal_id ] )
				. Nonce::field( 'stonewright_custom_code_approve', '_stonewright_nonce' )
				. Html::element(
					'div',
					[ 'class' => 'sw-ui-actions' ],
					Button::render( __( 'Issue one-time grant', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'primary' ] )
					. self::back_button( __( 'Back to Custom code', 'stonewright' ) )
				)
		);

		return Card::render(
			__( 'Exact candidate', 'stonewright' ),
			Html::element( 'div', [ 'class' => 'sw-ui-stack' ], $facts . $preview . $form )
		);
	}

	/** The risk class as a badge with words: high risk is danger, elevated is a warning, the rest is review. */
	private static function risk_badge( string $risk_class ): string {
		if ( str_contains( $risk_class, 'high_risk' ) ) {
			return Badge::render( __( 'High risk', 'stonewright' ), [ 'variant' => 'danger', 'icon' => 'alert' ] );
		}
		if ( 'elevated' === $risk_class ) {
			return Badge::render( __( 'Elevated risk', 'stonewright' ), [ 'variant' => 'warn', 'icon' => 'alert' ] );
		}

		return Badge::render( __( 'Standard review', 'stonewright' ), [ 'variant' => 'info', 'icon' => 'info' ] );
	}

	private static function grant_result_html( string $result_id ): string {
		$back   = self::back_button( __( 'Back to Custom code', 'stonewright' ) );
		$result = get_transient( 'sw_cc_result_' . $result_id );
		if ( ! is_array( $result ) ) {
			return Notice::render( 'danger', __( 'The grant is no longer available', 'stonewright' ), __( 'Grant result expired. Run dry_run again.', 'stonewright' ), [ 'actions_html' => $back ] );
		}
		delete_transient( 'sw_cc_result_' . $result_id );
		if ( empty( $result['ok'] ) ) {
			return Notice::render( 'danger', __( 'Approval failed', 'stonewright' ), (string) ( $result['message'] ?? __( 'Approval failed.', 'stonewright' ) ), [ 'actions_html' => $back ] );
		}

		$token = CopyField::render( (string) $result['token'], [ 'id' => 'stonewright-custom-code-grant', 'label' => __( 'approval token', 'stonewright' ) ] );
		$facts = KvList::render(
			[
				[ 'label' => __( 'Path', 'stonewright' ), 'value_html' => Html::element( 'code', [], Html::text( (string) $result['path'] ) ) ],
				[ 'label' => __( 'Candidate SHA-256', 'stonewright' ), 'value_html' => Html::element( 'code', [], Html::text( (string) $result['after_sha256'] ) ) ],
			],
			[ 'label' => __( 'What the grant is bound to', 'stonewright' ) ]
		);

		return Notice::render( 'ok', __( 'Grant issued.', 'stonewright' ), __( 'Copy it now; this screen will not show it again.', 'stonewright' ) )
			. Card::render(
				__( 'One-time approval token', 'stonewright' ),
				Html::element(
					'div',
					[ 'class' => 'sw-ui-stack' ],
					$token
						. Html::element( 'p', [ 'class' => 'sw-ui-hint' ], Html::text( __( 'Expires quickly and works once for this exact path and hash.', 'stonewright' ) ) )
						. $facts
				),
				[ 'actions_html' => $back ]
			);
	}
}
