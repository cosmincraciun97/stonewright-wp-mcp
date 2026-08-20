<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Pages;

use Stonewright\WpMcp\Admin\AdminShell;
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
		$stored   = (string) get_option( UserContext::OPTION, '' );
		$enabled  = (bool) get_option( UserContext::ENABLED_OPTION, false );
		$notice   = isset( $_GET['stonewright_context_notice'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( (string) wp_unslash( $_GET['stonewright_context_notice'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';
		$plugins  = is_array( $snapshot['plugins'] ?? null ) ? $snapshot['plugins'] : [];
		$plugin_labels = [];
		foreach ( $plugins as $plugin ) {
			if ( ! is_array( $plugin ) ) {
				continue;
			}
			$plugin_labels[] = trim( (string) ( $plugin['name'] ?? '' ) . ' ' . (string) ( $plugin['version'] ?? '' ) );
		}
		$site_url     = (string) ( is_array( $snapshot['target_context'] ?? null ) ? ( $snapshot['target_context']['normalized_url'] ?? '' ) : '' );
		$instructions = (string) ( $snapshot['instructions'] ?? '' );
		$facts = array_filter(
			[
				'PHP ' . (string) ( $snapshot['php_version'] ?? '' ),
				'' !== (string) ( $snapshot['wordpress_version'] ?? '' ) ? 'WordPress ' . (string) $snapshot['wordpress_version'] : '',
				(string) ( $snapshot['mode'] ?? '' ),
				(string) ( $snapshot['tool_profile'] ?? '' ),
			]
		);

		AdminShell::open( self::SLUG );
		?>
		<div class="sw-context-page stonewright-context-page">
			<header class="stonewright-page-header">
				<div>
					<h1><?php esc_html_e( 'Context', 'stonewright' ); ?></h1>
					<p><?php esc_html_e( 'Review the generated system instructions first, then add site-specific context. Task-start injects enabled user context into bootstrap.', 'stonewright' ); ?></p>
				</div>
			</header>

			<?php if ( 'saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible sw-notice"><p><?php esc_html_e( 'User context saved.', 'stonewright' ); ?></p></div>
			<?php endif; ?>

			<div class="sw-context-stack">
				<section class="sw-card" aria-labelledby="stonewright-system-context">
					<h2 id="stonewright-system-context"><?php esc_html_e( 'System context', 'stonewright' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Generated agent instructions for this site. URLs, emails, and post IDs are redacted in this admin view.', 'stonewright' ); ?></p>
					<p class="sw-context-facts"><?php echo esc_html( implode( ' · ', $facts ) ); ?></p>
					<p class="sw-context-facts">
						<?php esc_html_e( 'Site URL', 'stonewright' ); ?>:
						<?php echo esc_html( $site_url ); ?>
					</p>
					<?php if ( [] !== $plugin_labels ) : ?>
						<p class="sw-context-facts">
							<?php esc_html_e( 'Plugins', 'stonewright' ); ?>:
							<?php echo esc_html( implode( ', ', $plugin_labels ) ); ?>
						</p>
					<?php endif; ?>
					<details class="sw-callout sw-context-preview-wrap">
						<summary><?php esc_html_e( 'Show full system context', 'stonewright' ); ?></summary>
						<pre class="sw-context-preview"><?php echo esc_html( $instructions ); ?></pre>
					</details>
				</section>

				<section class="sw-card" aria-labelledby="stonewright-user-context">
					<div class="sw-context-toolbar">
						<h2 id="stonewright-user-context"><?php esc_html_e( 'User context', 'stonewright' ); ?></h2>
						<span
							class="sw-context-state"
							data-sw-context-state
							data-context-on="<?php esc_attr_e( 'On', 'stonewright' ); ?>"
							data-context-off="<?php esc_attr_e( 'Off', 'stonewright' ); ?>"
						><?php echo esc_html( $enabled ? __( 'On', 'stonewright' ) : __( 'Off', 'stonewright' ) ); ?></span>
					</div>
					<p><?php esc_html_e( 'When enabled, this text is prepended into task-start and context-bootstrap custom instructions.', 'stonewright' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="stonewright_user_context_save"/>
						<?php wp_nonce_field( self::SAVE_NONCE ); ?>
						<p class="sw-context-enable">
							<label class="sw-toggle" for="stonewright_user_context_enabled">
								<input
									id="stonewright_user_context_enabled"
									type="checkbox"
									name="stonewright_user_context_enabled"
									value="1"
									<?php checked( $enabled ); ?>
								/>
								<span class="sw-toggle__track" aria-hidden="true"></span>
							</label>
							<label for="stonewright_user_context_enabled">
								<?php esc_html_e( 'Inject user context into bootstrap', 'stonewright' ); ?>
							</label>
						</p>
						<p>
							<label for="stonewright_user_context"><?php esc_html_e( 'Persisted user context', 'stonewright' ); ?></label>
							<textarea
								id="stonewright_user_context"
								name="stonewright_user_context"
								class="large-text sw-context-editor"
								rows="12"
							><?php echo esc_textarea( $stored ); ?></textarea>
						</p>
						<p class="sw-actions">
							<button type="submit" class="sw-btn sw-btn--primary"><?php esc_html_e( 'Save user context', 'stonewright' ); ?></button>
						</p>
					</form>
				</section>
			</div>
		</div>
		<?php
		AdminShell::close();
	}
}
