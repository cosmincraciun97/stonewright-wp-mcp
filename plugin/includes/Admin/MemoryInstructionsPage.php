<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Knowledge\KnowledgeBundle;
use Stonewright\WpMcp\Memory\Memory;

/**
 * Admin page: Memory & Instructions (slug: stonewright-memory).
 */
final class MemoryInstructionsPage {

	public const SLUG       = 'stonewright-memory';
	public const CAP        = 'manage_options';
	public const OPT_GROUP  = 'stonewright_memory_settings';

	// ------------------------------------------------------------------
	// Registration
	// ------------------------------------------------------------------

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_init', [ self::class, 'register_settings' ] );
		add_action( 'admin_post_stonewright_memory_create', [ self::class, 'handle_create' ] );
		add_action( 'admin_post_stonewright_memory_update', [ self::class, 'handle_update' ] );
		add_action( 'admin_post_stonewright_memory_delete', [ self::class, 'handle_delete' ] );
		add_action( 'admin_post_stonewright_learning_disable', [ self::class, 'handle_learning_disable' ] );
		add_action( 'admin_post_stonewright_knowledge_export', [ self::class, 'handle_export' ] );
		add_action( 'admin_post_stonewright_knowledge_import', [ self::class, 'handle_import' ] );
	}

	public static function add_submenu(): void {
		// IA group: Safety & Diagnostics — slug stonewright-memory unchanged.
		add_submenu_page(
			'stonewright',
			__( 'Memory & Instructions', 'stonewright' ),
			__( 'Safety: Memory', 'stonewright' ),
			self::CAP,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function register_settings(): void {
		register_setting(
			self::OPT_GROUP,
			'stonewright_custom_instructions',
			[
				'type'              => 'string',
				'sanitize_callback' => static function ( $v ): string {
					// Allow newlines; just cap length to 4000 chars.
					return mb_substr( (string) $v, 0, 4000 );
				},
				'default'           => '',
			]
		);

		register_setting(
			self::OPT_GROUP,
			'stonewright_custom_instructions_enabled',
			[
				'type'              => 'boolean',
				'sanitize_callback' => static fn( $v ): bool => (bool) $v,
				'default'           => true,
			]
		);

		register_setting(
			self::OPT_GROUP,
			'stonewright_memory_enabled',
			[
				'type'              => 'boolean',
				'sanitize_callback' => static fn( $v ): bool => (bool) $v,
				'default'           => true,
			]
		);
	}

	// ------------------------------------------------------------------
	// Render
	// ------------------------------------------------------------------

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'stonewright' ) );
		}

		$instructions  = (string) get_option( 'stonewright_custom_instructions', '' );
		$enabled       = (bool) get_option( 'stonewright_custom_instructions_enabled', true );
		$mem_enabled   = (bool) get_option( 'stonewright_memory_enabled', true );

		// Determine active tab / type filter.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw_type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( (string) $_GET['type'] ) ) : '';
		$valid    = Memory::valid_types();
		$active   = ( '' === $raw_type || 'all' === $raw_type ) ? 'all' : ( in_array( $raw_type, $valid, true ) ? $raw_type : 'all' );

		// Fetch entries.
		if ( 'all' === $active ) {
			$entries = Memory::list_all( 200 );
		} else {
			$entries = Memory::list_by_type( $active, 200 );
		}

		// Build type counts.
		$all_entries = Memory::list_all( 10000 );
		$counts      = [ 'all' => count( $all_entries ) ];
		foreach ( $valid as $t ) {
			$counts[ $t ] = 0;
		}
		foreach ( $all_entries as $e ) {
			if ( isset( $counts[ $e['type'] ] ) ) {
				++$counts[ $e['type'] ];
			} else {
				$counts['generic'] = ( $counts['generic'] ?? 0 ) + 1;
			}
		}

		?>
		<?php AdminShell::open( self::SLUG ); ?>
		<div class="sw-memory-page stonewright-memory-page">
			<div class="stonewright-page-header">
				<div>
					<h1><?php esc_html_e( 'Memory & Instructions', 'stonewright' ); ?></h1>
					<p><?php esc_html_e( 'Durable site knowledge for connected Stonewright sessions. Memory is saved only when an operator or ability writes it, and every entry stays auditable here.', 'stonewright' ); ?></p>
				</div>
			</div>

			<?php if ( ! Memory::table_schema_ok() ) : ?>
				<div class="notice notice-error"><p>
					<?php esc_html_e( 'Stonewright memory table is missing or outdated. Learning promotion and memory abilities cannot store entries. Deactivate and reactivate the plugin, or check database ALTER/CREATE permissions, then reload this page.', 'stonewright' ); ?>
				</p></div>
			<?php endif; ?>

			<details class="sw-callout">
				<summary><?php esc_html_e( 'Guidance', 'stonewright' ); ?></summary>
				<div class="sw-callout__body">
					<p><strong><?php esc_html_e( 'What belongs here', 'stonewright' ); ?></strong> — <?php esc_html_e( 'Store project conventions, builder rules, recurring user feedback, naming standards, and hard-won pitfalls that should survive across sessions.', 'stonewright' ); ?></p>
					<p><strong><?php esc_html_e( 'What stays out', 'stonewright' ); ?></strong> — <?php esc_html_e( 'Do not store passwords, API keys, personal notes, or temporary task chatter. Memory is site-wide and visible to administrators.', 'stonewright' ); ?></p>
					<p><strong><?php esc_html_e( 'Token efficiency', 'stonewright' ); ?></strong> — <?php esc_html_e( 'Prefer short, scoped entries. Discovery can find the right memory by type, scope, and key without loading a long briefing every time.', 'stonewright' ); ?></p>
				</div>
			</details>

			<!-- Section 1: Custom Instructions -->
			<div class="sw-card stonewright-panel">
				<h2><?php esc_html_e( 'Custom Instructions', 'stonewright' ); ?></h2>
				<form method="post" action="options.php">
					<?php settings_fields( self::OPT_GROUP ); ?>
					<p>
						<label>
							<input
								type="checkbox"
								name="stonewright_custom_instructions_enabled"
								value="1"
								<?php checked( $enabled ); ?>
							>
							<?php esc_html_e( 'Enable custom instructions', 'stonewright' ); ?>
						</label>
					</p>
					<p class="description"><?php esc_html_e( 'Use for a short baseline rule set that should always be visible to connected agents. Keep larger procedures as skills instead.', 'stonewright' ); ?></p>
					<p>
						<textarea
							name="stonewright_custom_instructions"
							rows="10"
							class="large-text code"
							maxlength="4000"
						><?php echo esc_textarea( $instructions ); ?></textarea>
					</p>
					<p class="description"><?php esc_html_e( 'Up to 4000 characters. Shorter instructions keep discovery faster and cheaper.', 'stonewright' ); ?></p>
					<div class="sw-actions">
						<?php submit_button( __( 'Save instructions', 'stonewright' ), 'primary', 'submit', false ); ?>
					</div>
				</form>
			</div>

			<div class="sw-card stonewright-panel">
				<header class="sw-card__header">
					<div>
						<h2><?php esc_html_e( 'Import / Export', 'stonewright' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Export or import custom instructions, memory entries, and skills as a portable Stonewright JSON bundle.', 'stonewright' ); ?></p>
					</div>
					<div class="sw-actions">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="stonewright-inline-form">
							<input type="hidden" name="action" value="stonewright_knowledge_export">
							<?php wp_nonce_field( 'stonewright_knowledge_bundle', '_stonewright_nonce' ); ?>
							<button type="submit" class="sw-btn sw-btn--secondary sw-btn--sm"><?php esc_html_e( 'Export JSON', 'stonewright' ); ?></button>
						</form>
						<button
							type="button"
							class="sw-btn sw-btn--secondary sw-btn--sm"
							data-stonewright-toggle-target="stonewright-knowledge-import"
						><?php esc_html_e( 'Import JSON', 'stonewright' ); ?></button>
					</div>
				</header>
				<div id="stonewright-knowledge-import" class="stonewright-subpanel" hidden>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="stonewright_knowledge_import">
						<?php wp_nonce_field( 'stonewright_knowledge_bundle', '_stonewright_nonce' ); ?>
						<p>
							<label>
								<?php esc_html_e( 'Bundle JSON', 'stonewright' ); ?><br>
								<textarea name="bundle_json" rows="10" class="large-text code" required></textarea>
							</label>
						</p>
						<div class="sw-actions">
							<?php submit_button( __( 'Import JSON', 'stonewright' ), 'primary', 'submit', false ); ?>
						</div>
					</form>
				</div>
			</div>

			<?php self::render_learned_rules( $all_entries ); ?>

			<!-- Section 2: Memory entries -->
			<div class="sw-card stonewright-panel">
				<header class="sw-card__header sw-memory-section-header">
					<div>
						<h2><?php esc_html_e( 'Memory', 'stonewright' ); ?></h2>
						<p class="description stonewright-memory-note"><?php esc_html_e( 'Memory abilities let connected agents list, read, create, update, and delete these entries through Stonewright tools.', 'stonewright' ); ?></p>
					</div>
					<div class="sw-actions">
						<form method="post" action="options.php" class="stonewright-compact-form sw-actions">
							<?php settings_fields( self::OPT_GROUP ); ?>
							<label class="sw-check">
								<input
									type="checkbox"
									name="stonewright_memory_enabled"
									value="1"
									<?php checked( $mem_enabled ); ?>
								>
								<?php esc_html_e( 'Enable memory abilities', 'stonewright' ); ?>
							</label>
							<?php submit_button( __( 'Save', 'stonewright' ), 'secondary', 'submit', false ); ?>
						</form>
						<button
							type="button"
							class="sw-btn sw-btn--primary sw-btn--sm"
							data-stonewright-toggle-target="stonewright-new-memory"
							data-stonewright-focus-target="stonewright_memory_name"
						><?php esc_html_e( 'Add new', 'stonewright' ); ?></button>
					</div>
				</header>

				<!-- Tabs: All | User | Feedback | Project | Reference -->
				<ul class="subsubsub">
					<li>
						<a href="?page=<?php echo esc_attr( self::SLUG ); ?>" class="<?php echo 'all' === $active ? 'current' : ''; ?>">
							All (<?php echo (int) $counts['all']; ?>)
						</a> |
					</li>
					<li>
						<a href="?page=<?php echo esc_attr( self::SLUG ); ?>&amp;type=user" class="<?php echo 'user' === $active ? 'current' : ''; ?>">
							User (<?php echo (int) ( $counts['user'] ?? 0 ); ?>)
						</a> |
					</li>
					<li>
						<a href="?page=<?php echo esc_attr( self::SLUG ); ?>&amp;type=feedback" class="<?php echo 'feedback' === $active ? 'current' : ''; ?>">
							Feedback (<?php echo (int) ( $counts['feedback'] ?? 0 ); ?>)
						</a> |
					</li>
					<li>
						<a href="?page=<?php echo esc_attr( self::SLUG ); ?>&amp;type=project" class="<?php echo 'project' === $active ? 'current' : ''; ?>">
							Project (<?php echo (int) ( $counts['project'] ?? 0 ); ?>)
						</a> |
					</li>
					<li>
						<a href="?page=<?php echo esc_attr( self::SLUG ); ?>&amp;type=reference" class="<?php echo 'reference' === $active ? 'current' : ''; ?>">
							Reference (<?php echo (int) ( $counts['reference'] ?? 0 ); ?>)
						</a>
					</li>
				</ul>

				<!-- Add new form (hidden by default) -->
				<div id="stonewright-new-memory" class="stonewright-subpanel" hidden>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="stonewright_memory_create">
						<?php wp_nonce_field( 'stonewright_memory', '_stonewright_nonce' ); ?>
						<p>
							<label>
								Name
								<input type="text" id="stonewright_memory_name" name="name" required class="regular-text">
							</label>
						</p>
						<p>
							<label>
								Scope
								<input type="text" name="scope" value="default" class="regular-text">
							</label>
						</p>
						<p>
							<label>
								Key
								<input type="text" name="memory_key" required class="regular-text">
							</label>
						</p>
						<p>
							<label>
								Type
								<select name="type">
									<option value="user">User</option>
									<option value="feedback">Feedback</option>
									<option value="project">Project</option>
									<option value="reference">Reference</option>
									<option value="generic">Generic</option>
								</select>
							</label>
						</p>
						<p>
							<label>
								Value (JSON or text)<br>
								<textarea name="value" rows="6" class="large-text code"></textarea>
							</label>
						</p>
						<?php submit_button( 'Create memory entry' ); ?>
					</form>
				</div>

				<!-- Memory table -->
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Name</th>
							<th>Type</th>
							<th>Scope</th>
							<th>Key</th>
							<th>Updated</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $e ) : ?>
						<tr>
							<td><?php echo esc_html( $e['name'] ); ?></td>
							<td>
								<span class="stonewright-type-badge stonewright-type-<?php echo esc_attr( $e['type'] ); ?>">
									<?php echo esc_html( $e['type'] ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $e['scope'] ); ?></td>
							<td><code><?php echo esc_html( $e['memory_key'] ); ?></code></td>
							<td><?php echo esc_html( $e['updated_at'] ); ?></td>
							<td class="stonewright-action-cell">
								<button
									type="button"
									class="button button-secondary"
									data-stonewright-row-toggle="stonewright-memory-edit-<?php echo (int) $e['id']; ?>"
								>Edit</button>
								<form
									method="post"
									action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
									class="stonewright-inline-form"
								>
									<input type="hidden" name="action" value="stonewright_memory_delete">
									<input type="hidden" name="id" value="<?php echo (int) $e['id']; ?>">
									<?php wp_nonce_field( 'stonewright_memory', '_stonewright_nonce' ); ?>
									<button type="submit" class="button button-link-delete" data-confirm="Delete this memory?">Delete</button>
								</form>
							</td>
						</tr>
						<tr id="stonewright-memory-edit-<?php echo (int) $e['id']; ?>" hidden>
							<td colspan="6">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="stonewright_memory_update">
									<input type="hidden" name="id" value="<?php echo (int) $e['id']; ?>">
									<?php wp_nonce_field( 'stonewright_memory', '_stonewright_nonce' ); ?>
									<div class="stonewright-memory-edit-grid">
										<label>
											Name
											<input type="text" name="name" value="<?php echo esc_attr( (string) $e['name'] ); ?>" class="regular-text">
										</label>
										<label>
											Scope
											<input type="text" name="scope" value="<?php echo esc_attr( (string) $e['scope'] ); ?>" class="regular-text">
										</label>
										<label>
											Key
											<input type="text" name="memory_key" value="<?php echo esc_attr( (string) $e['memory_key'] ); ?>" class="regular-text">
										</label>
										<label>
											Type
											<select name="type">
												<?php foreach ( $valid as $type ) : ?>
													<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $e['type'], $type ); ?>><?php echo esc_html( ucfirst( $type ) ); ?></option>
												<?php endforeach; ?>
											</select>
										</label>
									</div>
									<p>
										<label>
											Value (JSON or text)<br>
											<textarea name="value" rows="8" class="large-text code"><?php echo esc_textarea( self::value_to_text( $e['value'] ?? null ) ); ?></textarea>
										</label>
									</p>
									<?php submit_button( 'Save memory entry' ); ?>
								</form>
							</td>
						</tr>
						<?php endforeach; ?>
						<?php if ( empty( $entries ) ) : ?>
						<tr>
							<td colspan="6">
								<div class="stonewright-empty-state">No memory entries.</div>
							</td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php AdminShell::close(); ?>
		<?php
	}

	private static function value_to_text( mixed $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}

		$encoded = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $encoded ? '' : $encoded;
	}

	// ------------------------------------------------------------------
	// Handlers
	// ------------------------------------------------------------------

	public static function handle_create(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stonewright' ) );
		}

		check_admin_referer( 'stonewright_memory', '_stonewright_nonce' );

		$type       = sanitize_key( wp_unslash( (string) ( $_POST['type'] ?? 'generic' ) ) );
		$scope      = sanitize_text_field( wp_unslash( (string) ( $_POST['scope'] ?? 'default' ) ) );
		$memory_key = sanitize_text_field( wp_unslash( (string) ( $_POST['memory_key'] ?? '' ) ) );
		$name       = sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) );
		$value      = wp_unslash( (string) ( $_POST['value'] ?? '' ) );

		Memory::put_typed( $type, $scope, $memory_key, $name, $value );

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => self::SLUG, 'created' => '1' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_update(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stonewright' ) );
		}

		check_admin_referer( 'stonewright_memory', '_stonewright_nonce' );

		$id = (int) ( $_POST['id'] ?? 0 );
		if ( $id <= 0 ) {
			wp_safe_redirect(
				add_query_arg(
					[ 'page' => self::SLUG, 'error' => '1' ],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$changes = [];

		if ( isset( $_POST['type'] ) ) {
			$changes['type'] = sanitize_key( wp_unslash( (string) $_POST['type'] ) );
		}
		if ( isset( $_POST['scope'] ) ) {
			$changes['scope'] = sanitize_text_field( wp_unslash( (string) $_POST['scope'] ) );
		}
		if ( isset( $_POST['memory_key'] ) ) {
			$changes['memory_key'] = sanitize_text_field( wp_unslash( (string) $_POST['memory_key'] ) );
		}
		if ( isset( $_POST['name'] ) ) {
			$changes['name'] = sanitize_text_field( wp_unslash( (string) $_POST['name'] ) );
		}
		if ( isset( $_POST['value'] ) ) {
			$changes['value'] = wp_unslash( (string) $_POST['value'] );
		}

		Memory::update_by_id( $id, $changes );

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => self::SLUG, 'updated' => '1' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_delete(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stonewright' ) );
		}

		check_admin_referer( 'stonewright_memory', '_stonewright_nonce' );

		$id = (int) ( $_POST['id'] ?? 0 );
		if ( $id > 0 ) {
			Memory::delete_by_id( $id );
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => self::SLUG, 'deleted' => '1' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Disable a learned rule (sets status=stale) without hard-deleting history.
	 */
	public static function handle_learning_disable(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stonewright' ) );
		}

		check_admin_referer( 'stonewright_learning_disable', '_stonewright_nonce' );

		$id = (int) ( $_POST['id'] ?? 0 );
		if ( $id > 0 ) {
			Memory::update_by_id( $id, [ 'status' => 'stale' ] );
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => self::SLUG, 'updated' => '1' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @param array<int, array<string, mixed>> $all_entries
	 */
	private static function render_learned_rules( array $all_entries ): void {
		$rules = [];
		foreach ( $all_entries as $entry ) {
			if ( 'feedback' !== (string) ( $entry['type'] ?? '' ) ) {
				continue;
			}
			$key = (string) ( $entry['memory_key'] ?? '' );
			if ( ! str_starts_with( $key, 'learning-' ) ) {
				continue;
			}
			if ( 'active' !== (string) ( $entry['status'] ?? 'active' ) ) {
				continue;
			}
			$rules[] = $entry;
		}

		if ( [] === $rules ) {
			return;
		}

		?>
		<div class="sw-card stonewright-panel sw-learned-rules">
			<header class="sw-card__header">
				<div>
					<h2><?php esc_html_e( 'Learned rules', 'stonewright' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Corrections and recurring audit errors that agents load at task-start.', 'stonewright' ); ?></p>
				</div>
			</header>
			<ul class="sw-learned-rules__list">
				<?php foreach ( $rules as $rule ) : ?>
					<?php
					$value  = is_array( $rule['value'] ?? null ) ? $rule['value'] : [];
					$source = (string) ( $value['source'] ?? 'manual' );
					$sev    = (string) ( $value['severity'] ?? 'medium' );
					$corr   = (string) ( $value['correction'] ?? '' );
					?>
					<li class="sw-learned-rules__item">
						<div class="sw-learned-rules__main">
							<strong><?php echo esc_html( (string) ( $rule['name'] ?? $rule['topic'] ?? '' ) ); ?></strong>
							<span class="sw-badge sw-badge--neutral"><?php echo esc_html( $source ); ?></span>
							<span class="sw-badge sw-badge--info"><?php echo esc_html( $sev ); ?></span>
							<p class="sw-learned-rules__text"><?php echo esc_html( $corr ); ?></p>
						</div>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="stonewright-inline-form">
							<input type="hidden" name="action" value="stonewright_learning_disable" />
							<input type="hidden" name="id" value="<?php echo (int) ( $rule['id'] ?? 0 ); ?>" />
							<?php wp_nonce_field( 'stonewright_learning_disable', '_stonewright_nonce' ); ?>
							<button type="submit" class="sw-btn sw-btn--ghost sw-btn--sm"><?php esc_html_e( 'Disable', 'stonewright' ); ?></button>
						</form>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	public static function handle_export(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stonewright' ) );
		}

		check_admin_referer( 'stonewright_knowledge_bundle', '_stonewright_nonce' );

		$json = wp_json_encode( KnowledgeBundle::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			wp_die( esc_html__( 'Failed to encode knowledge bundle.', 'stonewright' ) );
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="stonewright-knowledge-bundle.json"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function handle_import(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stonewright' ) );
		}

		check_admin_referer( 'stonewright_knowledge_bundle', '_stonewright_nonce' );

		$raw = wp_unslash( (string) ( $_POST['bundle_json'] ?? '' ) );
		try {
			$bundle = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
			if ( ! is_array( $bundle ) ) {
				throw new \JsonException( 'Bundle must decode to an object.' );
			}
			KnowledgeBundle::import( $bundle );
			$args = [ 'page' => self::SLUG, 'imported' => '1' ];
		} catch ( \Throwable ) {
			$args = [ 'page' => self::SLUG, 'import_error' => '1' ];
		}

		wp_safe_redirect(
			add_query_arg(
				$args,
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
