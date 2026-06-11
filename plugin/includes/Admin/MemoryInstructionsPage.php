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
		add_action( 'admin_post_stonewright_knowledge_export', [ self::class, 'handle_export' ] );
		add_action( 'admin_post_stonewright_knowledge_import', [ self::class, 'handle_import' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			'stonewright',
			'Memory & Instructions',
			'Memory & Instructions',
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
		<div class="wrap stonewright-admin-shell stonewright-memory-page">
			<div class="stonewright-page-header">
				<div>
					<h1>Memory &amp; Instructions</h1>
					<p>Durable site knowledge for connected Stonewright sessions. Memory is saved only when an operator or ability writes it, and every entry stays auditable here.</p>
				</div>
			</div>

			<div class="stonewright-guidance-grid">
				<div class="stonewright-guidance-card">
					<h2>What belongs here</h2>
					<p>Store project conventions, builder rules, recurring user feedback, naming standards, and hard-won pitfalls that should survive across sessions.</p>
				</div>
				<div class="stonewright-guidance-card">
					<h2>What stays out</h2>
					<p>Do not store passwords, API keys, personal notes, or temporary task chatter. Memory is site-wide and visible to administrators.</p>
				</div>
				<div class="stonewright-guidance-card">
					<h2>Token efficiency</h2>
					<p>Prefer short, scoped entries. Discovery can find the right memory by type, scope, and key without loading a long briefing every time.</p>
				</div>
			</div>

			<!-- Section 1: Custom Instructions -->
			<div class="stonewright-panel">
				<h2>Custom Instructions</h2>
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
							Enable custom instructions
						</label>
					</p>
					<p class="description">Use for a short baseline rule set that should always be visible to connected agents. Keep larger procedures as skills instead.</p>
					<p>
						<textarea
							name="stonewright_custom_instructions"
							rows="10"
							class="large-text code"
							maxlength="4000"
						><?php echo esc_textarea( $instructions ); ?></textarea>
					</p>
					<p class="description">Up to 4000 characters. Shorter instructions keep discovery faster and cheaper.</p>
					<?php submit_button( 'Save instructions' ); ?>
				</form>
			</div>

			<div class="stonewright-panel">
				<h2>Import / Export</h2>
				<p class="description">Export or import custom instructions, memory entries, and skills as a portable Stonewright JSON bundle.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="stonewright-inline-form stonewright-inline-form--spaced">
					<input type="hidden" name="action" value="stonewright_knowledge_export">
					<?php wp_nonce_field( 'stonewright_knowledge_bundle', '_stonewright_nonce' ); ?>
					<button type="submit" class="button button-secondary">Export JSON</button>
				</form>
				<button
					type="button"
					class="button button-secondary"
					data-stonewright-toggle-target="stonewright-knowledge-import"
				>Import JSON</button>
				<div id="stonewright-knowledge-import" class="stonewright-subpanel" hidden>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="stonewright_knowledge_import">
						<?php wp_nonce_field( 'stonewright_knowledge_bundle', '_stonewright_nonce' ); ?>
						<p>
							<label>
								Bundle JSON<br>
								<textarea name="bundle_json" rows="10" class="large-text code" required></textarea>
							</label>
						</p>
						<?php submit_button( 'Import JSON' ); ?>
					</form>
				</div>
			</div>

			<!-- Section 2: Memory entries -->
			<div class="stonewright-panel">
				<h2>
					Memory
					<button
						type="button"
						class="button button-secondary"
						data-stonewright-toggle-target="stonewright-new-memory"
						data-stonewright-focus-target="stonewright_memory_name"
					>Add new</button>
				</h2>

				<form method="post" action="options.php" class="stonewright-compact-form">
					<?php settings_fields( self::OPT_GROUP ); ?>
					<label>
						<input
							type="checkbox"
							name="stonewright_memory_enabled"
							value="1"
							<?php checked( $mem_enabled ); ?>
						>
						Enable memory abilities
					</label>
					<?php submit_button( 'Save', 'small', 'submit', false ); ?>
				</form>
				<p class="description stonewright-memory-note">Memory abilities let connected agents list, read, create, update, and delete these entries through guarded Stonewright tools.</p>

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
