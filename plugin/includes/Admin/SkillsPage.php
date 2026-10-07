<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\SkillLibrary\Site\SkillLibraryService;
use Stonewright\WpMcp\SkillLibrary\Site\WordPressBoundary;

/**
 * Admin page: Skills (slug: stonewright-skills).
 *
 * The page renders the shell, the view tabs, and the regions the skills script
 * boots into. Reading the catalog, importing, exporting, trashing, restoring,
 * and destroying all happen over `SkillsRestApi`, which delegates to the skill
 * library service — so no lifecycle rule lives here.
 *
 * The editor view stays a plain nonce-checked form post. It is the write path
 * that still works with JavaScript switched off, and it is the only form on
 * the page.
 *
 * @stonewright-status stable
 */
final class SkillsPage {

	public const SLUG = 'stonewright-skills';
	public const CAP  = 'manage_options';

	/**
	 * The views the page can show. The URL carries the current one so a reload,
	 * a bookmark, and the back button all land where the user was.
	 */
	public const VIEWS = [ 'catalog', 'editor', 'import', 'trash' ];

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_post_stonewright_skill_save', [ self::class, 'handle_save' ] );
		add_action( 'admin_post_stonewright_skill_toggle', [ self::class, 'handle_toggle' ] );
	}

	public static function add_submenu(): void {
		// IA group: Safety & Diagnostics — slug stonewright-skills unchanged.
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

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- save_submission() sanitizes each field it reads.
		wp_safe_redirect( self::save_submission( (array) wp_unslash( $_POST ) ) );
		exit;
	}

	public static function handle_toggle(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'stonewright' ) );
		}
		check_admin_referer( 'stonewright_skill_toggle' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- toggle_submission() sanitizes each field it reads.
		wp_safe_redirect( self::toggle_submission( (array) wp_unslash( $_POST ) ) );
		exit;
	}

	/**
	 * Saves the editor form and returns where the browser goes next.
	 *
	 * @param array<string, mixed> $form Unslashed form fields; the caller has checked the capability and nonce.
	 */
	public static function save_submission( array $form ): string {
		$slug           = sanitize_title( is_string( $form['slug'] ?? null ) ? $form['slug'] : '' );
		$title          = sanitize_text_field( is_string( $form['title'] ?? null ) ? $form['title'] : '' );
		$description    = sanitize_textarea_field( is_string( $form['description'] ?? null ) ? $form['description'] : '' );
		$content        = is_string( $form['content'] ?? null ) ? $form['content'] : '';
		$enabled        = ! empty( $form['enabled'] );
		$enable_agentic = ! empty( $form['enable_agentic'] );
		$enable_prompt  = ! empty( $form['enable_prompt'] );
		$revision       = absint( is_scalar( $form['revision'] ?? null ) ? $form['revision'] : 0 );

		if ( '' === $slug || '' === $title || '' === $content ) {
			return self::redirect_url( 'editor', [ 'error' => 'missing_fields' ] );
		}

		$input = compact( 'slug', 'title', 'description', 'content', 'enabled', 'enable_agentic', 'enable_prompt' );
		if ( $revision > 0 ) {
			// The editor loaded this revision of the skill; a newer one means the form is stale.
			$input['revision'] = $revision;
		}

		$result = SkillLibraryService::open( WordPressBoundary::ADMIN )->save_skill( $input );

		if ( is_wp_error( $result ) ) {
			return self::redirect_url(
				'editor',
				[
					'error' => sanitize_key( (string) $result->get_error_code() ),
					'skill' => $slug,
				]
			);
		}

		return self::redirect_url( 'catalog', [ 'saved' => '1' ] );
	}

	/**
	 * Enables or disables a skill from a form post and returns where the browser goes next.
	 *
	 * @param array<string, mixed> $form Unslashed form fields; the caller has checked the capability and nonce.
	 */
	public static function toggle_submission( array $form ): string {
		$id      = absint( is_scalar( $form['id'] ?? null ) ? $form['id'] : 0 );
		$enabled = ! empty( $form['enabled'] );
		$result  = $id > 0 ? SkillLibraryService::open( WordPressBoundary::ADMIN )->set_enabled( $id, $enabled ) : true;

		if ( is_wp_error( $result ) ) {
			return self::redirect_url( 'catalog', [ 'error' => sanitize_key( (string) $result->get_error_code() ) ] );
		}

		return self::redirect_url( 'catalog', [ 'toggled' => '1' ] );
	}

	/**
	 * The requested view, falling back to the catalog.
	 */
	public static function current_view(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selector.
		$requested = isset( $_GET['view'] ) ? sanitize_key( (string) wp_unslash( $_GET['view'] ) ) : '';

		return in_array( $requested, self::VIEWS, true ) ? $requested : 'catalog';
	}

	/**
	 * Everything the front-end needs to boot without a second round trip.
	 *
	 * `mode` travels with the payload because a hard delete needs a
	 * confirmation token in production-safe mode, and the review drawer has to
	 * say so before the user commits to it.
	 *
	 * @return array<string, mixed>
	 */
	public static function boot_payload(): array {
		return [
			'restRoot' => rest_url( SkillsRestApi::REST_NAMESPACE . SkillsRestApi::ROUTE_PREFIX ),
			'nonce'    => wp_create_nonce( SkillsRestApi::NONCE_ACTION ),
			'view'     => self::current_view(),
			'views'    => self::VIEWS,
			'mode'     => (string) get_option( 'stonewright_mode', 'development' ),
			'can'      => [
				'manageOptions' => Permissions::manage_options(),
			],
		];
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'stonewright' ) );
		}

		$current = self::current_view();
		$labels  = self::view_labels();

		AdminShell::open( self::SLUG );
		?>
		<div
			class="sw-skills-page stonewright-skills-page"
			data-sw-skills
			data-sw-current-view="<?php echo esc_attr( $current ); ?>"
		>
			<?php self::render_notices(); ?>

			<details class="sw-callout">
				<summary><?php esc_html_e( 'How skills work', 'stonewright' ); ?></summary>
				<div class="sw-callout__body">
					<p><strong><?php esc_html_e( 'How skills reach agents', 'stonewright' ); ?></strong> — <?php esc_html_e( 'Keep descriptions short and specific. They are the trigger text agents read during discovery; long Markdown bodies stay out of context until needed.', 'stonewright' ); ?></p>
					<p><strong><?php esc_html_e( 'Provenance', 'stonewright' ); ?></strong> — <?php esc_html_e( 'Built-in skills ship with Stonewright and can be disabled but not removed. Local skills are yours. External skills come from another plugin and always carry their source.', 'stonewright' ); ?></p>
					<p><strong><?php esc_html_e( 'Trust boundary', 'stonewright' ); ?></strong> — <?php esc_html_e( 'Review every skill before enabling it. An imported file lands disabled, as a draft, and is re-checked on the server no matter what the file claims about itself.', 'stonewright' ); ?></p>
				</div>
			</details>

			<div class="sw-skills-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Skill views', 'stonewright' ); ?>">
				<?php foreach ( self::VIEWS as $view ) : ?>
					<?php $is_current = ( $view === $current ); ?>
					<a
						class="sw-skills-tab<?php echo $is_current ? ' is-current' : ''; ?>"
						role="tab"
						id="sw-skills-tab-<?php echo esc_attr( $view ); ?>"
						href="<?php echo esc_url( self::view_url( $view ) ); ?>"
						data-sw-view="<?php echo esc_attr( $view ); ?>"
						aria-selected="<?php echo $is_current ? 'true' : 'false'; ?>"
						aria-controls="sw-skills-panel-<?php echo esc_attr( $view ); ?>"
						tabindex="<?php echo $is_current ? '0' : '-1'; ?>"
					><?php echo esc_html( $labels[ $view ] ); ?></a>
				<?php endforeach; ?>
			</div>

			<p class="sw-skills-status" data-sw-skills-status role="status" aria-live="polite"></p>

			<?php foreach ( self::VIEWS as $view ) : ?>
				<section
					class="sw-skills-panel"
					role="tabpanel"
					id="sw-skills-panel-<?php echo esc_attr( $view ); ?>"
					aria-labelledby="sw-skills-tab-<?php echo esc_attr( $view ); ?>"
					data-sw-panel="<?php echo esc_attr( $view ); ?>"
					<?php echo $view === $current ? '' : 'hidden'; ?>
				>
					<?php if ( 'editor' === $view ) : ?>
						<?php self::render_editor(); ?>
					<?php elseif ( 'catalog' === $view ) : ?>
						<?php self::render_catalog_panel(); ?>
					<?php else : ?>
						<div class="sw-skills-panel__loading" data-sw-skills-loading>
							<?php echo esc_html( $labels[ $view ] ); ?>
						</div>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>

			<noscript>
				<div class="sw-empty-state stonewright-empty-state">
					<p><?php esc_html_e( 'The catalog, import review, and trash need JavaScript. The editor below still saves without it, and every skill stays readable and writable through the Stonewright MCP abilities.', 'stonewright' ); ?></p>
				</div>
			</noscript>
		</div>
		<?php
		AdminShell::close();
	}

	private static function render_catalog_panel(): void {
		$library = SkillLibraryService::open( WordPressBoundary::ADMIN );
		$catalog = $library->catalog_view();
		$skills  = $catalog['skills'];
		$sources = $catalog['sources'];
		$trashed = $library->trashed();
		?>
		<div data-sw-skills-ssr="catalog">
			<div class="sw-skills-toolbar">
				<div class="sw-field sw-skills-search">
					<label for="sw-skills-search"><?php esc_html_e( 'Search skills', 'stonewright' ); ?></label>
					<input
						type="search"
						id="sw-skills-search"
						class="sw-skills-input"
						data-sw-skills-search
						disabled
						aria-busy="true"
					>
				</div>
				<div class="sw-actions">
					<a class="sw-skills-button sw-skills-button--primary" href="<?php echo esc_url( self::view_url( 'editor' ) ); ?>">
						<?php esc_html_e( 'New skill', 'stonewright' ); ?>
					</a>
				</div>
			</div>
			<p class="description">
				<?php
				printf(
					/* translators: 1: skill count, 2: source count, 3: trashed count */
					esc_html__( '%1$d skill(s) from %2$d source(s). %3$d in trash.', 'stonewright' ),
					count( $skills ),
					count( $sources ),
					count( $trashed )
				);
				?>
			</p>
			<div data-sw-skills-list>
				<?php if ( [] === $skills ) : ?>
					<div class="sw-empty-state">
						<p><?php esc_html_e( 'No skills yet. Write one in the editor, or import a reviewed Markdown file.', 'stonewright' ); ?></p>
					</div>
				<?php else : ?>
					<ul class="sw-skills-list">
						<?php foreach ( $skills as $skill ) : ?>
							<?php self::render_catalog_skill_row( is_array( $skill ) ? $skill : [] ); ?>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $skill
	 */
	private static function render_catalog_skill_row( array $skill ): void {
		$title       = (string) ( $skill['title'] ?? '' );
		$slug        = (string) ( $skill['slug'] ?? '' );
		$description = (string) ( $skill['description'] ?? '' );
		$source      = (string) ( $skill['source'] ?? 'user' );
		$source_kind = (string) ( $skill['source_kind'] ?? '' );
		$source_id   = (string) ( $skill['source_id'] ?? '' );
		$status      = (string) ( $skill['status'] ?? 'draft' );
		$enabled     = ! empty( $skill['enabled'] );
		$protected   = in_array( $source, [ 'builtin', 'playbook' ], true );
		$origin      = 'external' === $source_kind && '' !== $source_id
			? $source_id
			: ( $protected ? 'built-in' : $source );
		?>
		<li class="sw-skill-row">
			<div class="sw-skill-row__head">
				<strong class="sw-skill-row__title"><?php echo esc_html( '' !== $title ? $title : $slug ); ?></strong>
				<span class="sw-skill-row__badges">
					<span class="sw-badge sw-badge--<?php echo $protected ? 'playbook' : 'neutral'; ?>"><?php echo esc_html( $origin ); ?></span>
					<?php if ( $enabled ) : ?>
						<span class="sw-badge sw-badge--active"><?php esc_html_e( 'active', 'stonewright' ); ?></span>
					<?php else : ?>
						<span class="sw-badge sw-badge--disabled"><?php esc_html_e( 'disabled', 'stonewright' ); ?></span>
					<?php endif; ?>
					<?php if ( 'active' !== $status ) : ?>
						<span class="sw-badge sw-badge--info"><?php echo esc_html( $status ); ?></span>
					<?php endif; ?>
				</span>
			</div>
			<code class="sw-skill-row__slug"><?php echo esc_html( '' !== $slug ? $slug : 'unknown' ); ?></code>
			<?php if ( '' !== $description ) : ?>
				<p class="sw-skill-row__description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</li>
		<?php
	}

	/**
	 * The one form on the page: create a skill, or edit the one named by
	 * `?skill=<slug>`.
	 */
	private static function render_editor(): void {
		$skill = self::requested_skill();

		$slug           = (string) ( $skill['slug'] ?? '' );
		$title          = (string) ( $skill['title'] ?? '' );
		$description    = (string) ( $skill['description'] ?? '' );
		$content        = (string) ( $skill['content'] ?? '' );
		$enabled        = null === $skill || (bool) ( $skill['enabled'] ?? true );
		$enable_agentic = null === $skill || (bool) ( $skill['enable_agentic'] ?? true );
		$enable_prompt  = null === $skill || (bool) ( $skill['enable_prompt'] ?? true );
		$locked_slug    = null !== $skill;
		?>
		<div class="sw-card sw-skills-editor">
			<h2>
				<?php
				echo $locked_slug
					? esc_html__( 'Edit skill', 'stonewright' )
					: esc_html__( 'New skill', 'stonewright' );
				?>
			</h2>
			<p class="description"><?php esc_html_e( 'The description is the trigger text agents read during discovery. State when the skill applies, not what it contains.', 'stonewright' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sw-skills-form">
				<?php wp_nonce_field( 'stonewright_skill_save' ); ?>
				<input type="hidden" name="action" value="stonewright_skill_save">
				<?php if ( null !== $skill ) : ?>
					<input type="hidden" name="revision" value="<?php echo esc_attr( (string) ( $skill['revision'] ?? '' ) ); ?>">
				<?php endif; ?>

				<p class="sw-field">
					<label for="sw-skill-title"><?php esc_html_e( 'Title', 'stonewright' ); ?></label>
					<input type="text" id="sw-skill-title" name="title" value="<?php echo esc_attr( $title ); ?>" required>
				</p>

				<p class="sw-field">
					<label for="sw-skill-slug"><?php esc_html_e( 'Slug', 'stonewright' ); ?></label>
					<input
						type="text"
						id="sw-skill-slug"
						name="slug"
						value="<?php echo esc_attr( $slug ); ?>"
						pattern="[a-z0-9\-]+"
						placeholder="my-skill-slug"
						required
						<?php echo $locked_slug ? 'readonly' : ''; ?>
					>
				</p>

				<p class="sw-field">
					<label for="sw-skill-description"><?php esc_html_e( 'Description', 'stonewright' ); ?></label>
					<input
						type="text"
						id="sw-skill-description"
						name="description"
						value="<?php echo esc_attr( $description ); ?>"
						placeholder="<?php esc_attr_e( 'Use when …', 'stonewright' ); ?>"
					>
				</p>

				<p class="sw-field">
					<label for="sw-skill-content"><?php esc_html_e( 'Content (Markdown)', 'stonewright' ); ?></label>
					<textarea id="sw-skill-content" name="content" rows="16" class="code" required><?php echo esc_textarea( $content ); ?></textarea>
				</p>

				<fieldset class="sw-fieldset">
					<legend><?php esc_html_e( 'Availability', 'stonewright' ); ?></legend>
					<label class="sw-check">
						<input type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?>>
						<span><?php esc_html_e( 'Skill is active', 'stonewright' ); ?></span>
					</label>
					<label class="sw-check">
						<input type="checkbox" name="enable_agentic" value="1" <?php checked( $enable_agentic ); ?>>
						<span><?php esc_html_e( 'Auto-match from task descriptions', 'stonewright' ); ?></span>
					</label>
					<label class="sw-check">
						<input type="checkbox" name="enable_prompt" value="1" <?php checked( $enable_prompt ); ?>>
						<span><?php esc_html_e( 'Show as a prompt or command', 'stonewright' ); ?></span>
					</label>
					<p class="description"><?php esc_html_e( 'Use auto-match for concise, broadly useful rules. Use prompt mode for larger playbooks agents should open only when explicitly requested.', 'stonewright' ); ?></p>
				</fieldset>

				<div class="sw-actions">
					<button type="submit" class="sw-btn sw-btn--primary"><?php esc_html_e( 'Save skill', 'stonewright' ); ?></button>
					<a class="sw-btn sw-btn--secondary" href="<?php echo esc_url( self::view_url( 'catalog' ) ); ?>"><?php esc_html_e( 'Back to catalog', 'stonewright' ); ?></a>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * The skill named by `?skill=<slug>`, when there is one.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function requested_skill(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor selector.
		$slug = isset( $_GET['skill'] ) ? sanitize_title( (string) wp_unslash( $_GET['skill'] ) ) : '';

		return '' === $slug ? null : SkillLibraryService::open( WordPressBoundary::ADMIN )->find( $slug );
	}

	/**
	 * @return array<string, string>
	 */
	private static function view_labels(): array {
		return [
			'catalog' => __( 'Catalog', 'stonewright' ),
			'editor'  => __( 'Editor', 'stonewright' ),
			'import'  => __( 'Import', 'stonewright' ),
			'trash'   => __( 'Trash', 'stonewright' ),
		];
	}

	private static function view_url( string $view ): string {
		return admin_url( 'admin.php?page=' . rawurlencode( self::SLUG ) . '&view=' . rawurlencode( $view ) );
	}

	/**
	 * @param array<string, string> $args Extra query arguments.
	 */
	private static function redirect_url( string $view, array $args ): string {
		return add_query_arg(
			$args + [
				'page' => self::SLUG,
				'view' => $view,
			],
			admin_url( 'admin.php' )
		);
	}

	private static function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Skill saved.', 'stonewright' ) . '</p></div>';
		}
		if ( ! empty( $_GET['toggled'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Skill updated.', 'stonewright' ) . '</p></div>';
		}
		$error = isset( $_GET['error'] ) ? sanitize_key( (string) wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable
		if ( '' !== $error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( self::error_message( $error ) ) . '</p></div>';
		}
	}

	/** A notice for a refused save or toggle; the code never reaches the page as markup. */
	private static function error_message( string $code ): string {
		return match ( $code ) {
			'missing_fields'                      => __( 'Please fill in all required fields (title, slug, content).', 'stonewright' ),
			'stonewright_skill_lint_failed'       => __( 'The skill was not saved as active. Its description must say when to use it, Elementor guidance needs version constraints, and every ability it names must exist. Save it disabled to keep a draft.', 'stonewright' ),
			'stonewright_skill_protected',
			'stonewright_skill_identity_reserved',
			'stonewright_skill_authority_claim'   => __( 'That slug belongs to a skill that ships with Stonewright or comes from another source. Choose a different slug.', 'stonewright' ),
			'stonewright_skill_sensitive_content' => __( 'Remove credentials and other secrets from the skill before saving it.', 'stonewright' ),
			'stonewright_skill_toggle_invalid'    => __( 'A stale or retired skill cannot be enabled again.', 'stonewright' ),
			'stonewright_skill_write_conflict'    => __( 'The skill changed after you opened it, so nothing was saved. The editor shows the current version; apply your edits to it again.', 'stonewright' ),
			default                               => __( 'The skill was not saved. Check the fields and try again.', 'stonewright' ),
		};
	}
}
