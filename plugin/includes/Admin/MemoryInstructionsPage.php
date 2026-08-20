<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Knowledge\KnowledgeBundle;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Security\IncidentStore;

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
		add_action( 'admin_post_stonewright_memory_approve_draft', [ self::class, 'handle_approve_draft' ] );
		add_action( 'admin_post_stonewright_memory_discard_draft', [ self::class, 'handle_discard_draft' ] );
		add_action( 'admin_post_stonewright_learning_disable', [ self::class, 'handle_learning_disable' ] );
		add_action( 'admin_post_stonewright_knowledge_export', [ self::class, 'handle_export' ] );
		add_action( 'admin_post_stonewright_knowledge_import', [ self::class, 'handle_import' ] );
		add_action( 'admin_post_stonewright_memory_migrate_feedback', [ self::class, 'handle_migrate_feedback' ] );
	}

	public static function add_submenu(): void {
		// IA group: Safety & Diagnostics — slug stonewright-memory unchanged.
		add_submenu_page(
			'stonewright',
			__( 'Memory', 'stonewright' ),
			__( 'Memory', 'stonewright' ),
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
		$tabs     = [ 'all', 'user', 'project', 'verified_repairs', 'unresolved_incidents', 'incidents', 'audit_feedback', 'reference' ];
		$active   = ( '' === $raw_type || 'all' === $raw_type ) ? 'all' : ( in_array( $raw_type, $tabs, true ) ? $raw_type : 'all' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only incident filter.
		$incident_state = isset( $_GET['incident_state'] ) ? sanitize_key( wp_unslash( (string) $_GET['incident_state'] ) ) : '';
		if ( ! in_array( $incident_state, [ 'open', 'observing', 'resolved', 'suppressed' ], true ) ) {
			$incident_state = '';
		}
		$all_entries = Memory::list_all( 10000 );
		$counts      = array_fill_keys( $tabs, 0 );
		$counts['all'] = count( $all_entries );
		foreach ( $all_entries as $e ) {
			$bucket = self::entry_bucket( $e );
			if ( isset( $counts[ $bucket ] ) ) {
				++$counts[ $bucket ];
			}
		}
		$incident_counts = IncidentStore::counts();
		$counts['incidents'] = array_sum( $incident_counts );
		$counts['unresolved_incidents'] = (int) ( $incident_counts['open'] ?? 0 ) + (int) ( $incident_counts['observing'] ?? 0 );
		$legacy_unresolved_count = count( array_filter( $all_entries, static fn( array $entry ): bool => 'unresolved_incidents' === self::entry_bucket( $entry ) ) );
		$incident_rows = IncidentStore::recent( 200, '' === $incident_state ? [] : [ 'state' => $incident_state ] );
		if ( 'unresolved_incidents' === $active ) {
			$incident_rows = array_values( array_filter( IncidentStore::recent( 200 ), static fn( array $row ): bool => in_array( (string) ( $row['state'] ?? '' ), [ 'open', 'observing' ], true ) ) );
		}
		$entries = 'all' === $active
			? array_slice( $all_entries, 0, 200 )
			: array_values(
				array_slice(
					array_filter(
						$all_entries,
						static fn( array $entry ): bool => self::entry_bucket( $entry ) === $active
					),
					0,
					200
				)
			);

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
			<div class="notice notice-info"><p>
				<?php esc_html_e( 'This page shows the authoritative plugin-site store. Direct-local receipts live only on the companion machine and cannot appear here; use an explicit export/import instead of assuming synchronization.', 'stonewright' ); ?>
			</p></div>

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
							<input type="hidden" name="stonewright_custom_instructions_enabled" value="0" />
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

			<div class="sw-card stonewright-panel">
				<h2><?php esc_html_e( 'Receipt lookup and feedback migration', 'stonewright' ); ?></h2>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="sw-memory-receipt-form">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<label><?php esc_html_e( 'Plugin memory ID', 'stonewright' ); ?>
						<input type="number" name="receipt_id" min="1" value="">
					</label>
					<button type="submit" class="sw-btn sw-btn--secondary sw-btn--sm"><?php esc_html_e( 'Look up receipt', 'stonewright' ); ?></button>
				</form>
				<?php self::render_receipt_lookup(); ?>
				<hr>
				<p><?php esc_html_e( 'Legacy automatic feedback can be reclassified without deleting audit history. Export JSON first; the migration preserves historical Post-deploy smoke feedback.', 'stonewright' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sw-memory-migration-form">
					<input type="hidden" name="action" value="stonewright_memory_migrate_feedback">
					<?php wp_nonce_field( 'stonewright_memory_migrate_feedback', '_stonewright_nonce' ); ?>
					<label><input type="checkbox" name="export_confirmed" value="1" required> <?php esc_html_e( 'I exported the current JSON bundle.', 'stonewright' ); ?></label>
					<button type="submit" class="sw-btn sw-btn--secondary sw-btn--sm"><?php esc_html_e( 'Classify legacy feedback', 'stonewright' ); ?></button>
				</form>
			</div>

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
								<input type="hidden" name="stonewright_memory_enabled" value="0" />
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

				<!-- Lifecycle tabs -->
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
						<a href="?page=<?php echo esc_attr( self::SLUG ); ?>&amp;type=project" class="<?php echo 'project' === $active ? 'current' : ''; ?>">
							Project (<?php echo (int) ( $counts['project'] ?? 0 ); ?>)
						</a> |
					</li>
					<li>
						<a href="?page=<?php echo esc_attr( self::SLUG ); ?>&amp;type=verified_repairs" class="<?php echo 'verified_repairs' === $active ? 'current' : ''; ?>">
							Verified Repairs (<?php echo (int) ( $counts['verified_repairs'] ?? 0 ); ?>)
						</a> |
					</li>
					<li>
						<a href="?page=<?php echo esc_attr( self::SLUG ); ?>&amp;type=unresolved_incidents" class="<?php echo 'unresolved_incidents' === $active ? 'current' : ''; ?>">
							Unresolved Incidents (<?php echo (int) $counts['unresolved_incidents']; ?>)
						</a> |
					</li>
					<li>
						<a href="?page=<?php echo esc_attr( self::SLUG ); ?>&amp;type=incidents" class="<?php echo 'incidents' === $active ? 'current' : ''; ?>">
							Incident Lifecycle (<?php echo (int) $counts['incidents']; ?>)
						</a> |
					</li>
					<li>
						<a href="?page=<?php echo esc_attr( self::SLUG ); ?>&amp;type=audit_feedback" class="<?php echo 'audit_feedback' === $active ? 'current' : ''; ?>">
							Audit Feedback (<?php echo (int) ( $counts['audit_feedback'] ?? 0 ); ?>)
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

				<?php if ( in_array( $active, [ 'incidents', 'unresolved_incidents' ], true ) ) : ?>
					<?php if ( 'unresolved_incidents' === $active && $legacy_unresolved_count > 0 ) : ?>
						<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( /* translators: %d: legacy memory incident count */ _n( '%d legacy incident memory remains pending reconciliation.', '%d legacy incident memories remain pending reconciliation.', $legacy_unresolved_count, 'stonewright' ), $legacy_unresolved_count ) ); ?></p></div>
					<?php endif; ?>
					<?php self::render_incident_table( $incident_rows, $incident_counts, 'unresolved_incidents' === $active ? 'unresolved' : $incident_state ); ?>
				<?php endif; ?>

				<?php if ( ! in_array( $active, [ 'incidents', 'unresolved_incidents' ], true ) ) : ?>
				<!-- Memory table -->
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Name</th>
							<th>Type</th>
							<th>Scope</th>
							<th>Key</th>
							<th>Updated</th>
							<th>Backend / lifecycle</th>
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
							<td>
								<?php $meta = self::entry_meta( $e ); ?>
								<code><?php echo esc_html( $meta['backend'] ); ?></code><br>
								<span><?php echo esc_html( $meta['origin'] . ' · ' . $meta['visibility'] ); ?></span><br>
								<span><?php echo esc_html( $meta['state'] . ' · ' . $meta['verification'] ); ?></span><br>
								<span><?php echo esc_html( sprintf( 'Last retrieved: %s', $meta['last_retrieved'] ) ); ?></span>
							</td>
							<td class="stonewright-action-cell">
								<?php if ( 'draft' === (string) ( $e['status'] ?? '' ) ) : ?>
									<form
										method="post"
										action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
										class="stonewright-inline-form"
									>
										<input type="hidden" name="action" value="stonewright_memory_approve_draft">
										<input type="hidden" name="id" value="<?php echo (int) $e['id']; ?>">
										<?php wp_nonce_field( 'stonewright_memory_draft', '_stonewright_nonce' ); ?>
										<button type="submit" class="button button-primary">Approve</button>
									</form>
									<form
										method="post"
										action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
										class="stonewright-inline-form"
									>
										<input type="hidden" name="action" value="stonewright_memory_discard_draft">
										<input type="hidden" name="id" value="<?php echo (int) $e['id']; ?>">
										<?php wp_nonce_field( 'stonewright_memory_draft', '_stonewright_nonce' ); ?>
										<button type="submit" class="button">Discard</button>
									</form>
								<?php endif; ?>
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
							<td colspan="7">
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
							<td colspan="7">
								<div class="stonewright-empty-state">No memory entries.</div>
							</td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>
		</div>
		<?php AdminShell::close(); ?>
		<?php
	}

	/** @param list<array<string, mixed>> $rows @param array<string, int> $counts */
	private static function render_incident_table( array $rows, array $counts, string $active_state ): void {
		?>
		<div class="sw-card stonewright-panel">
			<h2><?php esc_html_e( 'Incident lifecycle', 'stonewright' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Observing incidents are below threshold. Open incidents require a correlated verified repair before they can resolve.', 'stonewright' ); ?></p>
			<ul class="subsubsub sw-incident-state-views">
				<?php
				$state_labels = [ 'open' => __( 'Open', 'stonewright' ), 'observing' => __( 'Observing', 'stonewright' ), 'resolved' => __( 'Resolved', 'stonewright' ), 'suppressed' => __( 'Suppressed', 'stonewright' ) ];
				foreach ( $state_labels as $state => $label ) :
					$url = add_query_arg( [ 'page' => self::SLUG, 'type' => 'incidents', 'incident_state' => $state ], admin_url( 'admin.php' ) );
				?>
				<li><a class="<?php echo esc_attr( $state === $active_state ? 'current' : '' ); ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label . ' (' . (int) ( $counts[ $state ] ?? 0 ) . ')' ); ?></a> | </li>
				<?php endforeach; ?>
				<li><a class="<?php echo '' === $active_state ? 'current' : ''; ?>" href="<?php echo esc_url( add_query_arg( [ 'page' => self::SLUG, 'type' => 'incidents' ], admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( __( 'All', 'stonewright' ) . ' (' . array_sum( $counts ) . ')' ); ?></a></li>
			</ul>
			<table class="widefat striped">
				<thead><tr><th>State</th><th>Ability</th><th>Category</th><th>Root error / path</th><th>Occurrences</th><th>Timeline</th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
				<?php
				$incident_id = (string) ( $row['incident_id'] ?? '' );
				$audit_url = add_query_arg( [ 'page' => AuditLogPage::SLUG, 'incident_id' => $incident_id ], admin_url( 'admin.php' ) );
				?>
				<tr id="stonewright-incident-<?php echo esc_attr( $incident_id ); ?>">
					<td><span class="stonewright-type-badge"><?php echo esc_html( (string) ( $row['state'] ?? '' ) ); ?></span></td>
					<td><code><?php echo esc_html( (string) ( $row['ability_name'] ?? '' ) ); ?></code></td>
					<td><?php echo esc_html( (string) ( $row['category'] ?? '' ) . ' · ' . (string) ( $row['severity'] ?? '' ) ); ?></td>
					<td>
						<code><?php echo esc_html( (string) ( $row['root_error_code'] ?? '' ) ); ?></code>
						<?php if ( '' !== (string) ( $row['normalized_path'] ?? '' ) ) : ?>
							<br><code><?php echo esc_html( (string) $row['normalized_path'] ); ?></code>
						<?php endif; ?>
					</td>
					<td><?php echo (int) ( $row['occurrence_count'] ?? 0 ); ?></td>
					<td>
						<a href="<?php echo esc_url( $audit_url ); ?>"><?php esc_html_e( 'View audit events', 'stonewright' ); ?></a><br>
						<?php echo esc_html( (string) ( $row['first_seen'] ?? '' ) . ' → ' . (string) ( $row['last_seen'] ?? '' ) ); ?>
						<?php if ( '' !== (string) ( $row['resolved_at'] ?? '' ) ) : ?>
							<br><?php echo esc_html( 'Resolved: ' . (string) $row['resolved_at'] ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
				<?php if ( [] === $rows ) : ?>
					<tr><td colspan="6"><?php echo esc_html( '' === $active_state ? __( 'No lifecycle incidents have been recorded.', 'stonewright' ) : ( 'unresolved' === $active_state ? __( 'No unresolved lifecycle incidents remain.', 'stonewright' ) : __( 'No incidents match this lifecycle state.', 'stonewright' ) ) ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
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

	/**
	 * @param array<string, mixed> $entry
	 */
	private static function entry_bucket( array $entry ): string {
		$type = (string) ( $entry['type'] ?? 'generic' );
		if ( 'feedback' !== $type ) {
			return in_array( $type, [ 'user', 'project', 'reference' ], true ) ? $type : 'all';
		}
		$value = is_array( $entry['value'] ?? null ) ? $entry['value'] : [];
		$state = sanitize_key( (string) ( $value['state'] ?? '' ) );
		if ( in_array( $state, [ 'verified_resolved', 'promoted_learning' ], true ) || 'verified-repairs' === (string) ( $entry['scope'] ?? '' ) ) {
			return 'verified_repairs';
		}
		if ( in_array( $state, [ 'unresolved_incident', 'observed', 'repeated', 'repair_attempted', 'blocked_pending_repair' ], true ) ) {
			return 'unresolved_incidents';
		}
		return 'audit_feedback';
	}

	/**
	 * @param array<string, mixed> $entry
	 * @return array{backend:string,origin:string,visibility:string,state:string,verification:string,last_retrieved:string}
	 */
	private static function entry_meta( array $entry ): array {
		$value = is_array( $entry['value'] ?? null ) ? $entry['value'] : [];
		$state = (string) ( $value['state'] ?? $entry['status'] ?? 'active' );
		$source = (string) ( $value['source'] ?? ( 'feedback' === (string) ( $entry['type'] ?? '' ) ? 'audit-feedback' : 'operator-or-agent' ) );
		$verification = (string) ( $value['verification'] ?? '' );
		if ( '' === $verification ) {
			$verification = in_array( $state, [ 'verified_resolved', 'promoted_learning' ], true ) ? 'verified' : 'unverified';
		}
		return [
			'backend'        => 'plugin-site',
			'origin'         => $source,
			'visibility'     => 'site-admin',
			'state'          => $state,
			'verification'   => $verification,
			'last_retrieved' => '' !== (string) ( $entry['last_retrieved_at'] ?? '' )
				? (string) $entry['last_retrieved_at']
				: 'never',
		];
	}

	private static function render_receipt_lookup(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only lookup.
		$id = isset( $_GET['receipt_id'] ) ? absint( $_GET['receipt_id'] ) : 0;
		if ( $id <= 0 ) {
			return;
		}
		$entry = Memory::get_by_id( $id );
		if ( null === $entry ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No plugin-site memory receipt exists for that ID. A Direct-local ID must be inspected or exported from the companion machine.', 'stonewright' ) . '</p></div>';
			return;
		}
		$meta = self::entry_meta( $entry );
		echo '<div class="notice notice-success inline"><p><strong>' . esc_html__( 'Receipt verified', 'stonewright' ) . ':</strong> ';
		echo '<code>' . esc_html( 'wp:stonewright_memory#' . (string) $id ) . '</code> · ';
		echo esc_html( $meta['backend'] . ' · ' . $meta['visibility'] . ' · ' . $meta['state'] );
		echo '</p></div>';
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

	public static function handle_approve_draft(): void {
		self::handle_draft_review( 'approve' );
	}

	public static function handle_discard_draft(): void {
		self::handle_draft_review( 'discard' );
	}

	/**
	 * Promote a draft lesson to active, or reject it. Only draft rows move.
	 */
	public static function apply_draft_review( int $id, string $decision ): bool {
		if ( $id <= 0 || ! in_array( $decision, [ 'approve', 'discard' ], true ) ) {
			return false;
		}
		$entry = Memory::get_by_id( $id );
		if ( null === $entry || 'draft' !== (string) ( $entry['status'] ?? '' ) ) {
			return false;
		}
		$status = 'approve' === $decision ? 'active' : 'rejected';
		return Memory::update_by_id( $id, [ 'status' => $status ] );
	}

	private static function handle_draft_review( string $decision ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stonewright' ) );
		}

		check_admin_referer( 'stonewright_memory_draft', '_stonewright_nonce' );

		$id = (int) ( $_POST['id'] ?? 0 );
		if ( $id > 0 ) {
			self::apply_draft_review( $id, $decision );
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
	 * Reclassify legacy automatic feedback in-place. No rows or audit history
	 * are deleted; the operator must confirm an export was taken first.
	 */
	public static function handle_migrate_feedback(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stonewright' ) );
		}
		check_admin_referer( 'stonewright_memory_migrate_feedback', '_stonewright_nonce' );
		if ( '1' !== (string) ( $_POST['export_confirmed'] ?? '' ) ) {
			wp_die( esc_html__( 'Export the current memory bundle before migration.', 'stonewright' ), '', [ 'response' => 400 ] );
		}

		$updated = 0;
		foreach ( Memory::list_by_type( 'feedback', 10000 ) as $entry ) {
			$value = is_array( $entry['value'] ?? null ) ? $entry['value'] : [];
			$key   = (string) ( $entry['memory_key'] ?? '' );
			$name  = strtolower( (string) ( $entry['name'] ?? '' ) );
			if ( str_contains( $name, 'post-deploy smoke' ) || str_contains( $key, 'post-deploy-smoke' ) ) {
				$value['state']  = 'historical_feedback';
				$value['source'] = (string) ( $value['source'] ?? 'audit-feedback' );
				Memory::update_by_id( (int) $entry['id'], [ 'value' => $value, 'status' => 'stale' ] );
				++$updated;
				continue;
			}
			if (
				'audit-error' !== (string) ( $value['source'] ?? '' )
				&& ! str_starts_with( $key, 'learning-audit-error-' )
			) {
				continue;
			}
			$value['state']        = (string) ( $value['state'] ?? 'unresolved_incident' );
			$value['verification'] = (string) ( $value['verification'] ?? 'unverified' );
			Memory::update_by_id( (int) $entry['id'], [ 'value' => $value, 'status' => 'stale' ] );
			++$updated;
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'page'     => self::SLUG,
					'migrated' => $updated,
				],
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
