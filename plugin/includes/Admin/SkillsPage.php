<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Skills\Skills;

/**
 * Admin page: Skills (slug: stonewright-skills).
 *
 * @stonewright-status stable
 */
final class SkillsPage {

	public const SLUG = 'stonewright-skills';
	public const CAP  = 'manage_options';

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

	public static function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'stonewright' ) );
		}
		check_admin_referer( 'stonewright_skill_save' );

		$slug           = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
		$title          = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$description    = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$content        = wp_unslash( $_POST['content'] ?? '' );
		$enabled        = ! empty( $_POST['enabled'] );
		$enable_agentic = ! empty( $_POST['enable_agentic'] );
		$enable_prompt  = ! empty( $_POST['enable_prompt'] );

		if ( '' === $slug || '' === $title || '' === $content ) {
			wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'error' => 'missing_fields' ], admin_url( 'admin.php' ) ) );
			exit;
		}

		Skills::save(
			compact( 'slug', 'title', 'description', 'content', 'enabled', 'enable_agentic', 'enable_prompt' )
			+ [ 'source' => 'user' ]
		);

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

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'stonewright' ) );
		}

		$skills = Skills::list();
		?>
		<?php AdminShell::open( self::SLUG ); ?>
		<div class="stonewright-skills-page">
			<div class="stonewright-page-header">
				<div>
					<h1><?php esc_html_e( 'Skills', 'stonewright' ); ?></h1>
					<p><?php esc_html_e( 'Site-owned Markdown playbooks for repeatable WordPress work. Agents skim descriptions first, then load full bodies only when a task matches.', 'stonewright' ); ?></p>
				</div>
				<button
					type="button"
					class="button button-primary"
					id="sw-new-skill-btn"
					data-stonewright-toggle-target="sw-new-skill-form"
					data-stonewright-focus-target="sw-new-title"
				>
					<?php esc_html_e( 'New Skill', 'stonewright' ); ?>
				</button>
			</div>

			<?php self::render_notices(); ?>

			<div class="stonewright-guidance-grid">
				<div class="stonewright-guidance-card">
					<h2><?php esc_html_e( 'How skills reach agents', 'stonewright' ); ?></h2>
					<p><?php esc_html_e( 'Keep descriptions short and specific. They are the trigger text agents read during discovery; long Markdown bodies stay out of context until needed.', 'stonewright' ); ?></p>
				</div>
				<div class="stonewright-guidance-card">
					<h2><?php esc_html_e( 'Best fit', 'stonewright' ); ?></h2>
					<p><?php esc_html_e( 'Use skills for site conventions, builder rules, QA checklists, naming standards, and fragile workflows that should repeat the same way.', 'stonewright' ); ?></p>
				</div>
				<div class="stonewright-guidance-card">
					<h2><?php esc_html_e( 'Trust boundary', 'stonewright' ); ?></h2>
					<p><?php esc_html_e( 'Review every skill before enabling it. A skill is guidance for a powerful connected operator, so it should be concise, intentional, and reviewed.', 'stonewright' ); ?></p>
				</div>
			</div>

			<div id="sw-new-skill-form" class="stonewright-panel stonewright-new-skill-form" hidden>
				<h2><?php esc_html_e( 'Create New Skill', 'stonewright' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'stonewright_skill_save' ); ?>
					<input type="hidden" name="action" value="stonewright_skill_save">
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="sw-new-title"><?php esc_html_e( 'Title', 'stonewright' ); ?> *</label></th>
								<td><input type="text" id="sw-new-title" name="title" class="regular-text" required></td>
							</tr>
							<tr>
								<th scope="row"><label for="sw-new-slug"><?php esc_html_e( 'Slug', 'stonewright' ); ?> *</label></th>
								<td><input type="text" id="sw-new-slug" name="slug" class="regular-text" required pattern="[a-z0-9\-]+" placeholder="my-skill-slug"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sw-new-desc"><?php esc_html_e( 'Description', 'stonewright' ); ?></label></th>
								<td><input type="text" id="sw-new-desc" name="description" class="regular-text" placeholder="<?php esc_attr_e( 'When to use this skill', 'stonewright' ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="sw-new-content"><?php esc_html_e( 'Content (Markdown)', 'stonewright' ); ?> *</label></th>
								<td><textarea id="sw-new-content" name="content" rows="12" class="large-text code" required placeholder="# My Skill&#10;&#10;Step 1: ..."></textarea></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Enabled', 'stonewright' ); ?></th>
								<td>
									<label><input type="checkbox" name="enabled" value="1" checked> <?php esc_html_e( 'Skill is active', 'stonewright' ); ?></label>
									<p class="description"><?php esc_html_e( 'Disabled skills stay saved for review but are hidden from connected agents.', 'stonewright' ); ?></p>
									<label><input type="checkbox" name="enable_agentic" value="1" checked> <?php esc_html_e( 'Auto-match from task descriptions', 'stonewright' ); ?></label><br>
									<label><input type="checkbox" name="enable_prompt" value="1" checked> <?php esc_html_e( 'Show as a prompt or command', 'stonewright' ); ?></label>
									<p class="description"><?php esc_html_e( 'Use auto-match for concise, broadly useful rules. Use prompt mode for larger playbooks agents should open only when explicitly requested.', 'stonewright' ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>
					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Skill', 'stonewright' ); ?></button>
						<button
							type="button"
							class="button"
							id="sw-cancel-skill-btn"
							data-stonewright-hide-target="sw-new-skill-form"
						><?php esc_html_e( 'Cancel', 'stonewright' ); ?></button>
					</p>
				</form>
			</div>

			<?php if ( empty( $skills ) ) : ?>
				<div class="stonewright-empty-state">
					<p><?php esc_html_e( 'No skills yet. Create your first skill above.', 'stonewright' ); ?></p>
				</div>
			<?php else : ?>
				<div class="stonewright-card-grid stonewright-skills-grid">
					<?php foreach ( $skills as $skill ) : ?>
						<?php self::render_skill_card( $skill ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php AdminShell::close(); ?>
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
		$source         = (string) $skill['source'];
		$enable_agentic = (bool) ( $skill['enable_agentic'] ?? $enabled );
		$enable_prompt  = (bool) ( $skill['enable_prompt'] ?? $enabled );
		$is_builtin     = 'builtin' === $source;
		?>
		<div class="stonewright-card stonewright-skill-card <?php echo $enabled ? 'stonewright-card--enabled' : 'stonewright-card--disabled'; ?>">
			<div class="stonewright-card__header">
				<div class="stonewright-card__title-row">
					<strong class="stonewright-card__title"><?php echo esc_html( $title ); ?></strong>
					<span class="sw-badge sw-badge--<?php echo esc_attr( $source ); ?>"><?php echo esc_html( ucfirst( $source ) ); ?></span>
					<?php if ( $enabled ) : ?>
						<span class="sw-badge sw-badge--active"><?php esc_html_e( 'Active', 'stonewright' ); ?></span>
						<?php if ( $enable_agentic ) : ?>
							<span class="sw-badge sw-badge--agentic"><?php esc_html_e( 'Auto', 'stonewright' ); ?></span>
						<?php endif; ?>
						<?php if ( $enable_prompt ) : ?>
							<span class="sw-badge sw-badge--prompt"><?php esc_html_e( 'Command', 'stonewright' ); ?></span>
						<?php endif; ?>
					<?php else : ?>
						<span class="sw-badge sw-badge--disabled"><?php esc_html_e( 'Disabled', 'stonewright' ); ?></span>
					<?php endif; ?>
				</div>
				<code class="stonewright-card__slug"><?php echo esc_html( $slug ); ?></code>
			</div>

			<div class="stonewright-card__body">
				<?php if ( $description ) : ?>
					<p class="stonewright-card__description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
				<p class="stonewright-card__preview"><?php echo esc_html( mb_substr( $content, 0, 200 ) . ( mb_strlen( $content ) > 200 ? '...' : '' ) ); ?></p>
			</div>

			<div class="stonewright-card__footer">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="stonewright-inline-form">
					<?php wp_nonce_field( 'stonewright_skill_toggle' ); ?>
					<input type="hidden" name="action" value="stonewright_skill_toggle">
					<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
					<input type="hidden" name="enabled" value="<?php echo $enabled ? '0' : '1'; ?>">
					<button type="submit" class="button button-small">
						<?php echo $enabled ? esc_html__( 'Disable', 'stonewright' ) : esc_html__( 'Enable', 'stonewright' ); ?>
					</button>
				</form>

				<button
					type="button"
					class="button button-small"
					data-stonewright-skill-toggle="sw-edit-<?php echo esc_attr( (string) $id ); ?>"
					aria-expanded="false"
				>
					<?php esc_html_e( 'View / Edit', 'stonewright' ); ?>
				</button>

				<?php if ( ! $is_builtin ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="stonewright-inline-form">
						<?php wp_nonce_field( 'stonewright_skill_delete' ); ?>
						<input type="hidden" name="action" value="stonewright_skill_delete">
						<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>">
						<button
							type="submit"
							class="button button-small sw-btn--danger"
							data-confirm="<?php esc_attr_e( 'Delete this skill?', 'stonewright' ); ?>"
						><?php esc_html_e( 'Delete', 'stonewright' ); ?></button>
					</form>
				<?php endif; ?>
			</div>

			<div id="sw-edit-<?php echo esc_attr( (string) $id ); ?>" class="stonewright-card__edit-panel" hidden>
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
						<?php esc_html_e( 'Skill is active', 'stonewright' ); ?></label>
					</p>
					<p>
						<label><input type="checkbox" name="enable_agentic" value="1" <?php checked( $enable_agentic ); ?>>
						<?php esc_html_e( 'Auto-match from task descriptions', 'stonewright' ); ?></label><br>
						<label><input type="checkbox" name="enable_prompt" value="1" <?php checked( $enable_prompt ); ?>>
						<?php esc_html_e( 'Show as a prompt or command', 'stonewright' ); ?></label>
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
}
