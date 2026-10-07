<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Button;
use Stonewright\WpMcp\Admin\Ui\Card;
use Stonewright\WpMcp\Admin\Ui\EmptyState;
use Stonewright\WpMcp\Admin\Ui\FormField;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Icon;
use Stonewright\WpMcp\Admin\Ui\Notice;
use Stonewright\WpMcp\Admin\Ui\Scope;
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

		$tabs = '';
		foreach ( self::VIEWS as $view ) {
			$is_current = ( $view === $current );
			$tabs      .= Html::element(
				'a',
				[
					'class'         => 'sw-ui-tabs__tab',
					'role'          => 'tab',
					'id'            => 'sw-skills-tab-' . $view,
					'href'          => self::view_url( $view ),
					'data-sw-view'  => $view,
					'aria-selected' => $is_current ? 'true' : 'false',
					'aria-controls' => 'sw-skills-panel-' . $view,
					'tabindex'      => $is_current ? '0' : '-1',
				],
				Html::text( $labels[ $view ] )
			);
		}

		$panels = '';
		foreach ( self::VIEWS as $view ) {
			if ( 'editor' === $view ) {
				$inner = self::editor_html();
			} elseif ( 'catalog' === $view ) {
				$inner = self::catalog_panel_html();
			} else {
				$inner = self::skeleton_html( $labels[ $view ] );
			}
			$panels .= Html::element(
				'section',
				[
					'class'           => 'sw-ui-tabs__panel',
					'role'            => 'tabpanel',
					'id'              => 'sw-skills-panel-' . $view,
					'aria-labelledby' => 'sw-skills-tab-' . $view,
					'data-sw-panel'   => $view,
					'hidden'          => $view === $current ? null : true,
				],
				$inner
			);
		}

		$noscript = '<noscript>' . Notice::callout(
			'info',
			__( 'Some views need JavaScript.', 'stonewright' ),
			__( 'The catalog, import review, and trash need JavaScript. The editor below still saves without it, and every skill stays readable and writable through the Stonewright MCP abilities.', 'stonewright' )
		) . '</noscript>';

		$inner = self::notices_html()
			. self::how_it_works_html()
			. Html::element( 'div', [ 'class' => 'sw-ui-tabs', 'role' => 'tablist', 'aria-label' => __( 'Skill views', 'stonewright' ) ], $tabs )
			. Html::element( 'div', [ 'class' => 'sw-skills__status', 'data-sw-skills-status' => true ], '' )
			. $panels
			. $noscript;

		$page = Scope::wrap(
			Html::element(
				'div',
				[ 'class' => 'sw-ui-stack', 'data-sw-skills' => true, 'data-sw-current-view' => $current ],
				$inner
			),
			[ 'page' => true, 'class' => 'sw-skills stonewright-skills-page' ]
		);

		AdminShell::open( self::SLUG );
		echo $page; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built by the Ui helpers, which escape every value.
		AdminShell::close();
	}

	private static function how_it_works_html(): string {
		$rows = [
			[ __( 'How skills reach agents', 'stonewright' ), __( 'Keep descriptions short and specific. They are the trigger text agents read during discovery; long Markdown bodies stay out of context until needed.', 'stonewright' ) ],
			[ __( 'Provenance', 'stonewright' ), __( 'Built-in skills ship with Stonewright and can be disabled but not removed. Local skills are yours. External skills come from another plugin and always carry their source.', 'stonewright' ) ],
			[ __( 'Trust boundary', 'stonewright' ), __( 'Review every skill before enabling it. An imported file lands disabled, as a draft, and is re-checked on the server no matter what the file claims about itself.', 'stonewright' ) ],
		];
		$body = '';
		foreach ( $rows as [ $title, $text ] ) {
			$body .= Html::element( 'p', [], Html::element( 'strong', [], Html::text( $title ) ) . ' ' . Html::text( $text ) );
		}

		return Html::element(
			'details',
			[ 'class' => 'sw-ui-disclosure', 'data-sw-ui-remember' => 'skills-how' ],
			Html::element( 'summary', [], Icon::render( 'chev-r' ) . Html::text( __( 'How skills work', 'stonewright' ) ) )
			. Html::element( 'div', [ 'class' => 'sw-ui-disclosure__body' ], $body )
		);
	}

	/**
	 * The placeholder a panel shows until the script has read the catalog: it reserves the height of the content.
	 */
	private static function skeleton_html( string $label ): string {
		return Html::element(
			'div',
			[ 'class' => 'sw-ui-stack', 'aria-busy' => 'true', 'data-sw-skills-loading' => true ],
			Html::element( 'span', [ 'class' => 'sw-ui-visually-hidden', 'role' => 'status' ], Html::text( $label ) )
			. Html::element( 'div', [ 'class' => 'sw-ui-skeleton sw-ui-skeleton--card' ], '' )
			. Html::element( 'div', [ 'class' => 'sw-ui-skeleton sw-ui-skeleton--card' ], '' )
		);
	}

	private static function catalog_panel_html(): string {
		$library = SkillLibraryService::open( WordPressBoundary::ADMIN );
		$catalog = $library->catalog_view();
		$skills  = $catalog['skills'];
		$sources = $catalog['sources'];
		$trashed = $library->trashed();

		$search = FormField::input(
			__( 'Search skills', 'stonewright' ),
			'sw_skills_search',
			[
				'id'    => 'sw-skills-search',
				'type'  => 'search',
				'attrs' => [ 'data-sw-skills-search' => true, 'data-sw-ui-search' => true, 'disabled' => true, 'aria-busy' => 'true' ],
			]
		);
		$toolbar = Html::element(
			'div',
			[ 'class' => 'sw-ui-toolbar' ],
			Html::element( 'div', [ 'class' => 'sw-ui-toolbar__search' ], $search )
			. Html::element( 'div', [ 'class' => 'sw-ui-actions' ], Button::render( __( 'New skill', 'stonewright' ), [ 'variant' => 'primary', 'icon' => 'plus', 'href' => self::view_url( 'editor' ) ] ) )
		);
		$summary = Html::element(
			'p',
			[ 'class' => 'sw-ui-field__help' ],
			Html::text(
				sprintf(
					/* translators: 1: skill count, 2: source count, 3: trashed count */
					__( '%1$d skill(s) from %2$d source(s). %3$d in trash.', 'stonewright' ),
					count( $skills ),
					count( $sources ),
					count( $trashed )
				)
			)
		);

		if ( [] === $skills ) {
			$list = EmptyState::render(
				__( 'No skills yet', 'stonewright' ),
				__( 'Write one in the editor, or import a reviewed Markdown file.', 'stonewright' ),
				[ 'variant' => 'first-run' ]
			);
		} else {
			$items = '';
			foreach ( $skills as $skill ) {
				$items .= self::catalog_skill_row_html( is_array( $skill ) ? $skill : [] );
			}
			$list = Html::element( 'ul', [ 'class' => 'sw-skills__list' ], $items );
		}

		return Html::element(
			'div',
			[ 'class' => 'sw-ui-stack', 'data-sw-skills-ssr' => 'catalog' ],
			$toolbar . $summary . Html::element( 'div', [ 'data-sw-skills-list' => true ], $list )
		);
	}

	/**
	 * @param array<string, mixed> $skill
	 */
	private static function catalog_skill_row_html( array $skill ): string {
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
			: ( $protected ? __( 'Built-in', 'stonewright' ) : ucfirst( $source ) );
		$title       = '' !== $title ? $title : $slug;

		// One status badge, then at most two tags: where the skill comes from and how agents reach it.
		if ( 'active' !== $status ) {
			$state = Badge::render( ucfirst( $status ), [ 'variant' => 'warn' ] );
		} else {
			$state = $enabled ? Badge::render( __( 'Active', 'stonewright' ), [ 'variant' => 'ok' ] ) : Badge::render( __( 'Disabled', 'stonewright' ) );
		}
		$tags = Badge::tag( $origin );
		if ( $enabled ) {
			$auto    = ! empty( $skill['enable_agentic'] );
			$command = ! empty( $skill['enable_prompt'] );
			if ( $auto && $command ) {
				$tags .= Badge::tag( __( 'Auto and command', 'stonewright' ) );
			} elseif ( $auto ) {
				$tags .= Badge::tag( __( 'Auto', 'stonewright' ) );
			} elseif ( $command ) {
				$tags .= Badge::tag( __( 'Command', 'stonewright' ) );
			}
		}

		$head = Html::element(
			'div',
			[ 'class' => 'sw-skill-row__head' ],
			Html::element( 'strong', [ 'class' => 'sw-skill-row__title' ], Html::text( $title ) )
			. Html::element( 'span', [ 'class' => 'sw-skill-row__badges' ], $state . $tags )
		);
		$body = $head
			. Html::element( 'code', [ 'class' => 'sw-skill-row__slug' ], Html::text( '' !== $slug ? $slug : 'unknown' ) )
			. ( '' !== $description ? Html::element( 'p', [ 'class' => 'sw-skill-row__description' ], Html::text( $description ) ) : '' )
			. Html::element( 'div', [ 'class' => 'sw-ui-actions' ], Button::render( __( 'Edit', 'stonewright' ), [ 'size' => 'sm', 'href' => self::editor_url( $slug ), 'context' => $title ] ) );

		return Html::element( 'li', [ 'class' => 'sw-skill-row sw-ui-card' ], Html::element( 'div', [ 'class' => 'sw-ui-card__body' ], $body ) );
	}

	/**
	 * The one form on the page: create a skill, or edit the one named by
	 * `?skill=<slug>`.
	 */
	private static function editor_html(): string {
		$skill = self::requested_skill();

		$slug           = (string) ( $skill['slug'] ?? '' );
		$title          = (string) ( $skill['title'] ?? '' );
		$description    = (string) ( $skill['description'] ?? '' );
		$content        = (string) ( $skill['content'] ?? '' );
		$enabled        = null === $skill || (bool) ( $skill['enabled'] ?? true );
		$enable_agentic = null === $skill || (bool) ( $skill['enable_agentic'] ?? true );
		$enable_prompt  = null === $skill || (bool) ( $skill['enable_prompt'] ?? true );
		$locked_slug    = null !== $skill;

		$fields = FormField::input( __( 'Title', 'stonewright' ), 'title', [ 'id' => 'sw-skill-title', 'value' => $title, 'required' => true ] )
			. FormField::input(
				__( 'Slug', 'stonewright' ),
				'slug',
				[
					'id'          => 'sw-skill-slug',
					'value'       => $slug,
					'pattern'     => '[a-z0-9\-]+',
					'placeholder' => 'my-skill-slug',
					'required'    => true,
					'readonly'    => $locked_slug,
					'size'        => 'md',
					'help'        => $locked_slug ? __( 'The slug names the skill and cannot change once it is saved.', 'stonewright' ) : __( 'Lowercase letters, numbers and hyphens.', 'stonewright' ),
				]
			)
			. FormField::input(
				__( 'Description', 'stonewright' ),
				'description',
				[
					'id'          => 'sw-skill-description',
					'value'       => $description,
					'placeholder' => __( 'Use when …', 'stonewright' ),
					'help'        => __( 'The trigger text agents read during discovery. State when the skill applies, not what it contains.', 'stonewright' ),
				]
			)
			. FormField::textarea( __( 'Content (Markdown)', 'stonewright' ), 'content', [ 'id' => 'sw-skill-content', 'value' => $content, 'rows' => 16, 'code' => true, 'required' => true ] )
			. Html::element(
				'fieldset',
				[ 'class' => 'sw-ui-fieldset sw-ui-stack' ],
				Html::element( 'legend', [], Html::text( __( 'Availability', 'stonewright' ) ) )
				. FormField::checkbox( __( 'Skill is active', 'stonewright' ), 'enabled', [ 'id' => 'sw-skill-enabled', 'checked' => $enabled ] )
				. FormField::checkbox( __( 'Auto-match from task descriptions', 'stonewright' ), 'enable_agentic', [ 'id' => 'sw-skill-agentic', 'checked' => $enable_agentic ] )
				. FormField::checkbox( __( 'Show as a prompt or command', 'stonewright' ), 'enable_prompt', [ 'id' => 'sw-skill-prompt', 'checked' => $enable_prompt ] )
				. Html::element( 'span', [ 'class' => 'sw-ui-field__help' ], Html::text( __( 'Use auto-match for concise, broadly useful rules. Use prompt mode for larger playbooks agents should open only when explicitly requested.', 'stonewright' ) ) )
			)
			. Button::group(
				[
					Button::render( __( 'Save skill', 'stonewright' ), [ 'type' => 'submit', 'variant' => 'primary' ] ),
					Button::render( __( 'Back to catalog', 'stonewright' ), [ 'href' => self::view_url( 'catalog' ) ] ),
				]
			);

		$form = FormField::post_form(
			'stonewright_skill_save',
			'stonewright_skill_save',
			'_wpnonce',
			$fields,
			[
				'class'  => 'sw-ui-stack',
				'hidden' => null !== $skill ? [ 'revision' => (string) ( $skill['revision'] ?? '' ) ] : [],
			]
		);

		return Card::render(
			$locked_slug ? __( 'Edit skill', 'stonewright' ) : __( 'New skill', 'stonewright' ),
			$form
		);
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

	/**
	 * What the last save or toggle did. A confirmation is a status message and a refusal is an alert; neither goes
	 * away by itself.
	 */
	private static function notices_html(): string {
		// phpcs:disable WordPress.Security.NonceVerification
		$html = '';
		if ( ! empty( $_GET['saved'] ) ) {
			$html .= Notice::render( 'ok', __( 'Skill saved.', 'stonewright' ) );
		}
		if ( ! empty( $_GET['toggled'] ) ) {
			$html .= Notice::render( 'ok', __( 'Skill updated.', 'stonewright' ) );
		}
		$error = isset( $_GET['error'] ) ? sanitize_key( (string) wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable
		if ( '' !== $error ) {
			$html .= Notice::render( 'danger', self::error_message( $error ) );
		}

		return $html;
	}

	private static function editor_url( string $slug ): string {
		return self::view_url( 'editor' ) . ( '' !== $slug ? '&skill=' . rawurlencode( $slug ) : '' );
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
