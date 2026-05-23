<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Skills\Skills;

/**
 * Admin page: Skills (slug: stonewright-skills).
 * Displays skills as a modern card grid with enable/disable toggles.
 *
 * @stonewright-status stable
 */
final class SkillsPage {

	public const SLUG = 'stonewright-skills';
	public const CAP  = 'manage_options';

	// ------------------------------------------------------------------
	// Registration
	// ------------------------------------------------------------------

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_post_stonewright_skill_save', [ self::class, 'handle_save' ] );
		add_action( 'admin_post_stonewright_skill_toggle', [ self::class, 'handle_toggle' ] );
		add_action( 'admin_post_stonewright_skill_delete', [ self::class, 'handle_delete' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			'stonewright',
			__( 'Skills', 'stonewright' ),
			__( 'Skills', 'stonewright' ),
			self::CAP,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	// ------------------------------------------------------------------
	// Form handlers
	// ------------------------------------------------------------------

	public static function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'stonewright' ) );
		}
		check_admin_referer( 'stonewright_skill_save' );

		$slug        = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
		$title       = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$content     = wp_unslash( $_POST['content'] ?? '' );
		$enabled     = ! empty( $_POST['enabled'] );

		if ( '' === $slug || '' === $title || '' === $content ) {
			wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'error' => 'missing_fields' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		Skills::save( compact( 'slug', 'title', 'description', 'content', 'enabled' ) + [ 'source' => 'user' ] );

		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'saved' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_toggle(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'stonewright' ) );
		}
		check_admin_referer( 'stonewright_skill_toggle' );

		$id      = absint( $_POST['id'] ?? 0 );
		$enabled = ! empty( $_POST['enabled'] );

		if ( $id > 0 ) {
			Skills::toggle( $id, $enabled );
		}

		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'toggled' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_delete(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'stonewright' ) );
		}
		check_admin_referer( 'stonewright_skill_delete' );

		$id = absint( $_POST['id'] ?? 0 );

		if ( $id > 0 ) {
			Skills::delete( $id );
		}

		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'deleted' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ------------------------------------------------------------------
	// Render
	// ------------------------------------------------------------------

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'stonewright' ) );
		}

		$skills = Skills::list();
		?>
		<div class="wrap stonewright-wrap">
			<div class="sw-page-header">
				<h1 class="sw-page-title">
					<span class="sw-page-title__icon">📚</span>
					<?php esc_html_e( 'Skills', 'stonewright' ); ?>
				</h1>
				<p class="sw-page-description">
					<?php esc_html_e( 'Skills are Markdown playbooks your AI follows automatically. Enabled skills are injected into every MCP session.', 'stonewright' ); ?>
				</p>
				<button type="button" class="button button-primary" id="sw-new-skill-btn">
					+ <?php esc_html_e( 'New Skill', 'stonewright' ); ?>
				</button>
			</div>

			<?php self::render_notices(); ?>

			<!-- New Skill Form (hidden by default) -->
			<div id="sw-new-skill-form" class="sw-new-skill-form" style="display:none;">
				<h2><?php esc_html_e( 'Create New Skill', 'stonewright' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'stonewright_skill_save' ); ?>
					<input type="hidden" name="action" value="stonewright_skill_save">
					<table class="form-table">
						<tr>
							<th><label for="sw-new-title"><?php esc_html_e( 'Title', 'stonewright' ); ?> *</label></th>
							<td><input type="text" id="sw-new-title" name="title" class="regular-text" required></td>
						</tr>
						<tr>
							<th><label for="sw-new-slug"><?php esc_html_e( 'Slug', 'stonewright' ); ?> *</label></th>
							<td><input type="text" id="sw-new-slug" name="slug" class="regular-text" required pattern="[a-z0-9\-]+" placeholder="my-skill-slug"></td>
						</tr>
						<tr>
							<th><label for="sw-new-desc"><?php esc_html_e( 'Description', 'stonewright' ); ?></label></th>
							<td><input type="text" id="sw-new-desc" name="description" class="regular-text" placeholder="<?php esc_attr_e( 'When to use this skill', 'stonewright' ); ?>"></td>
						</tr>
						<tr>
							<th><label for="sw-new-content"><?php esc_html_e( 'Content (Markdown)', 'stonewright' ); ?> *</label></th>
							<td><textarea id="sw-new-content" name="content" rows="12" class="large-text code" required placeholder="# My Skill&#10;&#10;Step 1: ..."></textarea></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Enabled', 'stonewright' ); ?></th>
							<td><label><input type="checkbox" name="enabled" value="1" checked> <?php esc_html_e( 'Inject into MCP sessions', 'stonewright' ); ?></label></td>
						</tr>
					</table>
					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Skill', 'stonewright' ); ?></button>
						<button type="button" class="button" id="sw-cancel-skill-btn"><?php esc_html_e( 'Cancel', 'stonewright' ); ?></button>
					</p>
				</form>
			</div>

			<!-- Skills Grid -->
			<?php if ( empty( $skills ) ) : ?>
				<div class="sw-empty-state">
					<p><?php esc_html_e( 'No skills yet. Create your first skill above.', 'stonewright' ); ?></p>
				</div>
			<?php else : ?>
				<div class="sw-card-grid">
					<?php foreach ( $skills as $skill ) : ?>
						<?php self::render_skill_card( $skill ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<?php self::render_styles(); ?>
		<?php self::render_scripts(); ?>
		<?php
	}

	/**
	 * @param array<string, mixed> $skill
	 */
	private static function render_skill_card( array $skill ): void {
		$id          = (int) $skill['id'];
		$slug        = (string) $skill['slug'];
		$title       = (string) $skill['title'];
		$description = (string) $skill['description'];
		$content     = (string) $skill['content'];
		$enabled     = (bool) $skill['enabled'];
		$source      = (string) $skill['source'];
		$is_builtin  = 'builtin' === $source;
		?>
		<div class="sw-card <?php echo $enabled ? 'sw-card--enabled' : 'sw-card--disabled'; ?>">
			<div class="sw-card__header">
				<div class="sw-card__title-row">
					<strong class="sw-card__title"><?php echo esc_html( $title ); ?></strong>
					<span class="sw-badge sw-badge--<?php echo esc_attr( $source ); ?>"><?php echo esc_html( ucfirst( $source ) ); ?></span>
					<?php if ( $enabled ) : ?>
						<span class="sw-badge sw-badge--active"><?php esc_html_e( 'Active', 'stonewright' ); ?></span>
					<?php else : ?>
						<span class="sw-badge sw-badge--disabled"><?php esc_html_e( 'Disabled', 'stonewright' ); ?></span>
					<?php endif; ?>
				</div>
				<code class="sw-card__slug"><?php echo esc_html( $slug ); ?></code>
			</div>

			<div class="sw-card__body">
				<?php if ( $description ) : ?>
					<p class="sw-card__description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
				<p class="sw-card__preview"><?php echo esc_html( mb_substr( $content, 0, 200 ) . ( mb_strlen( $content ) > 200 ? '…' : '' ) ); ?></p>
			</div>

			<div class="sw-card__footer">
				<!-- Toggle Enable/Disable -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
					<?php wp_nonce_field( 'stonewright_skill_toggle' ); ?>
					<input type="hidden" name="action" value="stonewright_skill_toggle">
					<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
					<input type="hidden" name="enabled" value="<?php echo $enabled ? '0' : '1'; ?>">
					<button type="submit" class="button button-small">
						<?php echo $enabled ? esc_html__( 'Disable', 'stonewright' ) : esc_html__( 'Enable', 'stonewright' ); ?>
					</button>
				</form>

				<!-- Expand/Edit button -->
				<button type="button" class="button button-small sw-expand-btn" data-target="sw-edit-<?php echo esc_attr( (string) $id ); ?>">
					<?php esc_html_e( 'View / Edit', 'stonewright' ); ?>
				</button>

				<!-- Delete (non-builtin only) -->
				<?php if ( ! $is_builtin ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e( 'Delete this skill?', 'stonewright' ); ?>');">
						<?php wp_nonce_field( 'stonewright_skill_delete' ); ?>
						<input type="hidden" name="action" value="stonewright_skill_delete">
						<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
						<button type="submit" class="button button-small sw-btn--danger"><?php esc_html_e( 'Delete', 'stonewright' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<!-- Expandable edit panel -->
			<div id="sw-edit-<?php echo esc_attr( (string) $id ); ?>" class="sw-card__edit-panel" style="display:none;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'stonewright_skill_save' ); ?>
					<input type="hidden" name="action" value="stonewright_skill_save">
					<input type="hidden" name="slug" value="<?php echo esc_attr( $slug ); ?>">
					<p>
						<label><strong><?php esc_html_e( 'Title', 'stonewright' ); ?></strong><br>
						<input type="text" name="title" value="<?php echo esc_attr( $title ); ?>" class="regular-text"></label>
					</p>
					<p>
						<label><strong><?php esc_html_e( 'Description', 'stonewright' ); ?></strong><br>
						<input type="text" name="description" value="<?php echo esc_attr( $description ); ?>" class="regular-text"></label>
					</p>
					<p>
						<label><strong><?php esc_html_e( 'Content (Markdown)', 'stonewright' ); ?></strong><br>
						<textarea name="content" rows="16" class="large-text code"><?php echo esc_textarea( $content ); ?></textarea></label>
					</p>
					<p>
						<label><input type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?>>
						<?php esc_html_e( 'Enabled', 'stonewright' ); ?></label>
					</p>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'stonewright' ); ?></button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	private static function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Skill saved.', 'stonewright' ) . '</p></div>';
		}
		if ( ! empty( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Skill deleted.', 'stonewright' ) . '</p></div>';
		}
		if ( ! empty( $_GET['toggled'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Skill updated.', 'stonewright' ) . '</p></div>';
		}
		if ( ! empty( $_GET['error'] ) && 'missing_fields' === $_GET['error'] ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please fill in all required fields (title, slug, content).', 'stonewright' ) . '</p></div>';
		}
		// phpcs:enable
	}

	private static function render_styles(): void {
		?>
		<style>
		.stonewright-wrap .sw-page-header {
			display: flex;
			align-items: flex-start;
			flex-wrap: wrap;
			gap: 12px;
			margin: 20px 0;
			padding: 20px 24px;
			background: #fff;
			border: 1px solid #e0e0e0;
			border-radius: 8px;
		}
		.stonewright-wrap .sw-page-title {
			flex: 1 1 100%;
			font-size: 1.5rem;
			margin: 0 0 4px;
			display: flex;
			align-items: center;
			gap: 8px;
		}
		.stonewright-wrap .sw-page-description {
			flex: 1 1 100%;
			color: #666;
			margin: 0 0 12px;
		}
		.stonewright-wrap .sw-new-skill-form {
			background: #fff;
			border: 1px solid #e0e0e0;
			border-radius: 8px;
			padding: 20px 24px;
			margin-bottom: 20px;
		}
		.stonewright-wrap .sw-card-grid {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
			gap: 16px;
			margin-top: 16px;
		}
		.stonewright-wrap .sw-card {
			background: #fff;
			border: 1px solid #e0e0e0;
			border-radius: 8px;
			overflow: hidden;
			transition: box-shadow 0.15s ease;
		}
		.stonewright-wrap .sw-card:hover {
			box-shadow: 0 2px 8px rgba(0,0,0,.10);
		}
		.stonewright-wrap .sw-card--disabled {
			opacity: 0.65;
		}
		.stonewright-wrap .sw-card__header {
			padding: 14px 16px 10px;
			border-bottom: 1px solid #f0f0f0;
		}
		.stonewright-wrap .sw-card__title-row {
			display: flex;
			align-items: center;
			flex-wrap: wrap;
			gap: 6px;
			margin-bottom: 4px;
		}
		.stonewright-wrap .sw-card__title {
			font-size: 14px;
			flex: 1;
		}
		.stonewright-wrap .sw-card__slug {
			font-size: 11px;
			color: #666;
			background: #f5f5f5;
			padding: 1px 6px;
			border-radius: 4px;
		}
		.stonewright-wrap .sw-card__body {
			padding: 12px 16px;
		}
		.stonewright-wrap .sw-card__description {
			margin: 0 0 6px;
			color: #444;
			font-style: italic;
			font-size: 13px;
		}
		.stonewright-wrap .sw-card__preview {
			margin: 0;
			font-size: 12px;
			color: #888;
			font-family: monospace;
			white-space: pre-wrap;
			background: #f9f9f9;
			border-radius: 4px;
			padding: 8px;
			max-height: 80px;
			overflow: hidden;
		}
		.stonewright-wrap .sw-card__footer {
			padding: 10px 16px;
			border-top: 1px solid #f0f0f0;
			display: flex;
			gap: 8px;
			align-items: center;
			flex-wrap: wrap;
		}
		.stonewright-wrap .sw-card__edit-panel {
			padding: 16px;
			border-top: 1px solid #e0e0e0;
			background: #fafafa;
		}
		.stonewright-wrap .sw-badge {
			font-size: 10px;
			font-weight: 600;
			text-transform: uppercase;
			letter-spacing: .04em;
			padding: 2px 8px;
			border-radius: 999px;
			white-space: nowrap;
		}
		.stonewright-wrap .sw-badge--builtin  { background: #e8f4fd; color: #0073aa; }
		.stonewright-wrap .sw-badge--user      { background: #e9f5e9; color: #2e7d32; }
		.stonewright-wrap .sw-badge--uploaded  { background: #fff8e1; color: #f57c00; }
		.stonewright-wrap .sw-badge--active    { background: #d4edda; color: #155724; }
		.stonewright-wrap .sw-badge--disabled  { background: #f5f5f5; color: #777; }
		.stonewright-wrap .sw-btn--danger      { color: #cc1818 !important; border-color: #cc1818 !important; }
		.stonewright-wrap .sw-btn--danger:hover { background: #fef2f2 !important; }
		.stonewright-wrap .sw-empty-state {
			background: #fff;
			border: 1px dashed #ccc;
			border-radius: 8px;
			text-align: center;
			padding: 40px;
			color: #888;
		}
		</style>
		<?php
	}

	private static function render_scripts(): void {
		?>
		<script>
		(function() {
			// New skill form toggle.
			var newBtn    = document.getElementById('sw-new-skill-btn');
			var newForm   = document.getElementById('sw-new-skill-form');
			var cancelBtn = document.getElementById('sw-cancel-skill-btn');
			var titleInp  = document.getElementById('sw-new-title');
			var slugInp   = document.getElementById('sw-new-slug');

			if (newBtn && newForm) {
				newBtn.addEventListener('click', function() {
					newForm.style.display = newForm.style.display === 'none' ? '' : 'none';
				});
			}
			if (cancelBtn && newForm) {
				cancelBtn.addEventListener('click', function() { newForm.style.display = 'none'; });
			}

			// Auto-generate slug from title.
			if (titleInp && slugInp) {
				titleInp.addEventListener('input', function() {
					if (!slugInp.dataset.userEdited) {
						slugInp.value = titleInp.value
							.toLowerCase()
							.replace(/[^a-z0-9]+/g, '-')
							.replace(/^-+|-+$/g, '');
					}
				});
				slugInp.addEventListener('input', function() { slugInp.dataset.userEdited = '1'; });
			}

			// Expand/collapse edit panels.
			document.querySelectorAll('.sw-expand-btn').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var target = document.getElementById(btn.dataset.target);
					if (target) {
						target.style.display = target.style.display === 'none' ? '' : 'none';
						btn.textContent = target.style.display === 'none' ? 'View / Edit' : 'Close';
					}
				});
			});
		})();
		</script>
		<?php
	}
}
