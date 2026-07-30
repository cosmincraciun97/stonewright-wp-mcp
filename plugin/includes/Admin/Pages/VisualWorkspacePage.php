<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Pages;

use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\DesignStudioRestApi;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Design\Direction\DirectionSummary;
use Stonewright\WpMcp\Security\Permissions;

/**
 * The admin host for the Stonewright Visual workspace.
 *
 * The page owns no editor knowledge. It answers three questions before the
 * browser bundle boots — which post is being worked on, whether the current
 * user may edit it, and which builder the post uses — then renders the three
 * regions the bundle mounts into. Everything after that is the bundle's job:
 * it resolves the live editor adapter, proposes operations, asks for explicit
 * confirmation, and reads verification evidence back from the quality route.
 *
 * The active design direction reaches the bundle as a summary that carries the
 * contract hash. The contract itself stays on the server: the workspace needs
 * to prove which direction it is working under, not to re-read its source.
 */
final class VisualWorkspacePage {

	public const SLUG = 'stonewright-visual-workspace';

	/**
	 * Menu capability. Per-post authority is checked separately, because a
	 * user who may edit some posts may not edit the one in the URL.
	 */
	public const CAPABILITY = 'edit_posts';

	/**
	 * Editor kinds a caller may pin through the URL. `auto` means the bundle
	 * detects the live editor itself, which is the honest default: only the
	 * browser can see which editor actually loaded.
	 */
	public const EDITOR_KINDS = [ 'auto', 'elementor-v3', 'elementor-v4', 'gutenberg' ];

	private const BUNDLE_RELATIVE = 'assets/visual/workspace-browser.js';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			'stonewright',
			__( 'Visual Workspace', 'stonewright' ),
			__( 'Visual Workspace', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	/**
	 * The post the workspace targets, or 0 when the URL names none.
	 *
	 * Anything that is not a plain positive integer is treated as absent
	 * rather than coerced, so `9 OR 1=1` and `4.5` both land on the picker.
	 */
	public static function current_post_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only target selector.
		$raw = isset( $_GET['post_id'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['post_id'] ) ) : '';

		if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $raw ) ) {
			return 0;
		}

		return (int) $raw;
	}

	/**
	 * The editor kind requested through the URL, falling back to detection.
	 */
	public static function requested_editor(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor hint.
		$raw = isset( $_GET['editor'] ) ? sanitize_key( (string) wp_unslash( $_GET['editor'] ) ) : '';

		return in_array( $raw, self::EDITOR_KINDS, true ) ? $raw : 'auto';
	}

	/**
	 * Admin URL for the workspace, optionally pinned to a post and an editor.
	 */
	public static function url( int $post_id = 0, string $editor = 'auto' ): string {
		$url = admin_url( 'admin.php?page=' . rawurlencode( self::SLUG ) );

		if ( $post_id > 0 ) {
			$url .= '&post_id=' . rawurlencode( (string) $post_id );
		}

		if ( in_array( $editor, self::EDITOR_KINDS, true ) && 'auto' !== $editor ) {
			$url .= '&editor=' . rawurlencode( $editor );
		}

		return $url;
	}

	/**
	 * Everything the browser bundle needs to boot.
	 *
	 * @param int                        $post_id Target post.
	 * @param string                     $editor  Requested editor kind.
	 * @param DesignDirectionService|null $service Injected for tests.
	 * @return array<string, mixed>
	 */
	public static function boot_payload( int $post_id, string $editor = 'auto', ?DesignDirectionService $service = null ): array {
		$service = $service ?? new DesignDirectionService();
		$active  = $service->active();

		return [
			'restBase'   => rest_url( DesignStudioRestApi::REST_NAMESPACE ),
			'nonce'      => wp_create_nonce( DesignStudioRestApi::NONCE_ACTION ),
			'postId'     => $post_id,
			'editorKind' => in_array( $editor, self::EDITOR_KINDS, true ) ? $editor : 'auto',
			'editorUrl'  => self::editor_url( $post_id, $editor ),
			'direction'  => self::direction_summary( is_array( $active ) ? $active : null ),
			'can'        => [
				'editPost'     => Permissions::can_edit_post( $post_id ),
				'manageDesign' => Permissions::can_manage_design(),
			],
		];
	}

	/**
	 * URL of the real editor window the workspace attaches to.
	 *
	 * The workspace host is deliberately separate from Elementor/Gutenberg.
	 * A user gesture opens this same-origin URL, then the browser bundle reads
	 * the actual editor globals from that window instead of pretending the
	 * host page is an editor.
	 */
	public static function editor_url( int $post_id, string $editor = 'auto' ): string {
		if ( $post_id <= 0 || ! Permissions::can_edit_post( $post_id ) ) {
			return '';
		}

		$kind = in_array( $editor, self::EDITOR_KINDS, true ) ? $editor : 'auto';
		if ( 'auto' === $kind ) {
			$kind = 'elementor' === self::editor_context( $post_id )['builder'] ? 'elementor-v3' : 'gutenberg';
		}

		$action = str_starts_with( $kind, 'elementor-' ) ? 'elementor' : 'edit';

		return admin_url( 'post.php?post=' . rawurlencode( (string) $post_id ) . '&action=' . $action );
	}

	/**
	 * Projection of the active direction record for the workspace.
	 *
	 * `DirectionSummary::row()` returns identity, status, and the contract
	 * hash — never the contract. The workspace shows which direction is in
	 * force and can prove the revision; it does not re-derive design rules in
	 * the browser.
	 *
	 * @param array<string, mixed>|null $record Stored direction record.
	 * @return array<string, mixed>|null
	 */
	public static function direction_summary( ?array $record ): ?array {
		if ( null === $record ) {
			return null;
		}

		return DirectionSummary::row( $record, (int) ( $record['id'] ?? 0 ) );
	}

	/**
	 * Which builder owns the post, resolved on the server.
	 *
	 * This is context for the header, not a decision: the bundle still detects
	 * the adapter from the live editor, because a post can be opened in either
	 * editor regardless of what its meta says.
	 *
	 * @return array{builder: string, label: string}
	 */
	public static function editor_context( int $post_id ): array {
		if ( $post_id <= 0 || null === get_post( $post_id ) ) {
			return [
				'builder' => 'unknown',
				'label'   => __( 'No post selected', 'stonewright' ),
			];
		}

		$mode = (string) get_post_meta( $post_id, '_elementor_edit_mode', true );

		if ( 'builder' === $mode ) {
			return [
				'builder' => 'elementor',
				'label'   => __( 'Elementor', 'stonewright' ),
			];
		}

		return [
			'builder' => 'block-editor',
			'label'   => __( 'Block editor', 'stonewright' ),
		];
	}

	/**
	 * Absolute path of the packaged browser bundle.
	 *
	 * The bundle is built from `visual/` and staged into the plugin during
	 * packaging, so it is absent from a plain source checkout.
	 */
	public static function bundle_path(): string {
		$base = defined( 'STONEWRIGHT_PATH' ) ? (string) constant( 'STONEWRIGHT_PATH' ) : dirname( __DIR__, 3 ) . '/';

		return rtrim( $base, '/' ) . '/' . self::BUNDLE_RELATIVE;
	}

	/**
	 * True when a non-empty, readable bundle is on disk.
	 */
	public static function bundle_ready( string $path ): bool {
		return is_file( $path ) && is_readable( $path ) && filesize( $path ) > 0;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'stonewright' ),
				esc_html__( 'Forbidden', 'stonewright' ),
				[ 'response' => 403 ]
			);
		}

		$post_id = self::current_post_id();

		if ( $post_id > 0 && ! Permissions::can_edit_post( $post_id ) ) {
			wp_die(
				esc_html__( 'You do not have permission to edit this post.', 'stonewright' ),
				esc_html__( 'Forbidden', 'stonewright' ),
				[ 'response' => 403 ]
			);
		}

		AdminShell::open( self::SLUG );

		if ( 0 === $post_id ) {
			self::render_picker();
			AdminShell::close();

			return;
		}

		self::render_workspace( $post_id );
		AdminShell::close();
	}

	/**
	 * Shown when the URL names no usable post.
	 */
	private static function render_picker(): void {
		?>
		<div class="sw-visual-page" data-sw-visual-picker>
			<div class="stonewright-page-header">
				<div>
					<h1><?php esc_html_e( 'Visual Workspace', 'stonewright' ); ?></h1>
					<p><?php esc_html_e( 'Open a post in its editor, then work on it here with the active design direction, an explicit confirmation step, and evidence read back from the quality reports.', 'stonewright' ); ?></p>
				</div>
			</div>

			<?php self::render_guide(); ?>

			<form class="sw-visual-picker" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<label for="sw-visual-post-id"><?php esc_html_e( 'Post id', 'stonewright' ); ?></label>
				<input
					id="sw-visual-post-id"
					class="sw-visual-picker__input"
					type="number"
					name="post_id"
					min="1"
					step="1"
					required
					inputmode="numeric"
				/>
				<button type="submit" class="sw-button sw-button--primary"><?php esc_html_e( 'Open workspace', 'stonewright' ); ?></button>
			</form>

			<p class="sw-visual-picker__hint">
				<?php esc_html_e( 'The Design Studio quality view links straight here once you load reports for a post.', 'stonewright' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * The three regions the browser bundle mounts into.
	 *
	 * Server-rendered content stays outside the bundle's slots, so the page
	 * still states what it is about when the bundle is missing or JavaScript
	 * is off.
	 */
	private static function render_workspace( int $post_id ): void {
		$post    = get_post( $post_id );
		$title   = is_object( $post ) && property_exists( $post, 'post_title' ) ? (string) $post->post_title : '';
		$context = self::editor_context( $post_id );
		$ready   = self::bundle_ready( self::bundle_path() );
		?>
		<div
			class="sw-visual-page"
			data-sw-visual-workspace
			data-sw-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
			data-sw-visual-editor="<?php echo esc_attr( $context['builder'] ); ?>"
		>
			<header class="sw-visual-page__header" data-sw-visual-header>
				<div class="sw-visual-page__identity">
					<h1><?php esc_html_e( 'Visual Workspace', 'stonewright' ); ?></h1>
					<p class="sw-visual-page__post">
						<?php if ( '' !== $title ) : ?>
							<span class="sw-visual-page__post-title"><?php echo esc_html( $title ); ?></span>
						<?php endif; ?>
						<span class="sw-visual-page__post-id">
							<?php
							printf(
								/* translators: %d: post id */
								esc_html__( 'Post %d', 'stonewright' ),
								(int) $post_id
							);
							?>
						</span>
						<span class="sw-visual-page__editor"><?php echo esc_html( $context['label'] ); ?></span>
					</p>
				</div>

				<div class="sw-visual-page__header-actions">
					<div class="sw-visual-page__adapter" data-sw-visual-adapter></div>
					<?php if ( '' !== self::editor_url( $post_id, self::requested_editor() ) ) : ?>
						<button
							type="button"
							class="sw-button sw-button--primary"
							data-sw-visual-connect
							data-editor-url="<?php echo esc_url( self::editor_url( $post_id, self::requested_editor() ) ); ?>"
							data-sw-tooltip="<?php esc_attr_e( 'Opens the real editor in a companion window and connects this workspace to its live runtime. Nothing is written until you confirm a proposed change.', 'stonewright' ); ?>"
						><?php esc_html_e( 'Connect editor', 'stonewright' ); ?></button>
					<?php endif; ?>
					<button
						type="button"
						class="sw-button sw-visual-page__inspector-toggle"
						data-sw-visual-inspector-toggle
						aria-controls="sw-visual-inspector"
						aria-expanded="false"
					><?php esc_html_e( 'Inspector', 'stonewright' ); ?></button>
				</div>
			</header>

			<?php self::render_guide(); ?>

			<p class="sw-visual-page__status" data-sw-visual-status role="status" aria-live="polite"></p>

			<div class="sw-visual-page__body">
				<section class="sw-visual-page__canvas" data-sw-visual-canvas aria-label="<?php esc_attr_e( 'Evidence canvas', 'stonewright' ); ?>">
					<div data-sw-visual-workspace-canvas>
						<div class="sw-visual-connect-card">
							<p class="sw-visual-connect-card__eyebrow"><?php esc_html_e( 'Step 1 of 4', 'stonewright' ); ?></p>
							<h2><?php esc_html_e( 'Connect the real editor', 'stonewright' ); ?></h2>
							<p><?php esc_html_e( 'Use Connect editor above. Keep the editor window open while this workspace reads structure, previews a proposed diff, asks for confirmation, and verifies the result.', 'stonewright' ); ?></p>
						</div>
					</div>
				</section>

				<aside
					id="sw-visual-inspector"
					class="sw-visual-page__inspector"
					data-sw-visual-inspector
					aria-label="<?php esc_attr_e( 'Inspector', 'stonewright' ); ?>"
				>
					<h2 class="sw-visual-page__inspector-heading"><?php esc_html_e( 'Inspector', 'stonewright' ); ?></h2>
					<div data-sw-visual-workspace-inspector>
						<p class="sw-visual-page__placeholder"><?php esc_html_e( 'The active direction, the proposed changes, and the verification receipt appear here.', 'stonewright' ); ?></p>
					</div>
				</aside>
			</div>

			<?php if ( ! $ready ) : ?>
				<div class="sw-empty-state" data-sw-visual-missing>
					<p><?php esc_html_e( 'The Stonewright Visual browser bundle is not present in this install. It is built from the visual package and staged during packaging; a source checkout does not carry it.', 'stonewright' ); ?></p>
					<p><code>npm ci &amp;&amp; npm run build</code></p>
				</div>
			<?php endif; ?>

			<noscript>
				<div class="sw-empty-state">
					<p><?php esc_html_e( 'The Visual Workspace needs JavaScript. Design directions, quality reports, and Elementor writes stay available through the Stonewright MCP abilities.', 'stonewright' ); ?></p>
				</div>
			</noscript>
		</div>
		<?php
	}

	private static function render_guide(): void {
		?>
		<details class="sw-visual-guide">
			<summary><?php esc_html_e( 'How this workspace works', 'stonewright' ); ?></summary>
			<ol>
				<li><strong><?php esc_html_e( 'Connect', 'stonewright' ); ?></strong> — <?php esc_html_e( 'opens the real Elementor or block editor and detects its live adapter.', 'stonewright' ); ?></li>
				<li><strong><?php esc_html_e( 'Read', 'stonewright' ); ?></strong> — <?php esc_html_e( 'loads structure without changing the page.', 'stonewright' ); ?></li>
				<li><strong><?php esc_html_e( 'Preview and confirm', 'stonewright' ); ?></strong> — <?php esc_html_e( 'shows the exact proposed operations; writes remain blocked until approval.', 'stonewright' ); ?></li>
				<li><strong><?php esc_html_e( 'Verify', 'stonewright' ); ?></strong> — <?php esc_html_e( 'reads the latest quality evidence and labels unchecked work honestly.', 'stonewright' ); ?></li>
			</ol>
		</details>
		<?php
	}
}
