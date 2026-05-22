<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

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
		<div class="wrap">
			<h1>Memory &amp; Instructions</h1>
			<p>Custom instructions are prepended to every connected agent&rsquo;s discovery response. Memory entries persist across conversations.</p>

			<!-- Section 1: Custom Instructions -->
			<div class="stonewright-card">
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
					<p class="description">When enabled, the text below is prepended to discover-abilities and to the MCP server description.</p>
					<p>
						<textarea
							name="stonewright_custom_instructions"
							rows="10"
							class="large-text code"
							maxlength="4000"
						><?php echo esc_textarea( $instructions ); ?></textarea>
					</p>
					<p class="description">Up to 4000 characters.</p>
					<?php submit_button( 'Save instructions' ); ?>
				</form>
			</div>

			<!-- Section 2: Memory entries -->
			<div class="stonewright-card">
				<h2>
					Memory
					<button
						class="button button-secondary"
						onclick="document.getElementById('stonewright-new-memory').style.display='block';return false;"
					>Add new</button>
				</h2>

				<form method="post" action="options.php" style="margin-bottom:12px;">
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
				<div id="stonewright-new-memory" style="display:none; margin: 16px 0; padding: 12px; background: #f6f7f7;">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="stonewright_memory_create">
						<?php wp_nonce_field( 'stonewright_memory', '_stonewright_nonce' ); ?>
						<p>
							<label>
								Name
								<input type="text" name="name" required class="regular-text">
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
							<td>
								<form
									method="post"
									action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
									style="display:inline;"
									onsubmit="return confirm('Delete this memory?');"
								>
									<input type="hidden" name="action" value="stonewright_memory_delete">
									<input type="hidden" name="id" value="<?php echo (int) $e['id']; ?>">
									<?php wp_nonce_field( 'stonewright_memory', '_stonewright_nonce' ); ?>
									<button class="button button-link-delete">Delete</button>
								</form>
							</td>
						</tr>
						<?php endforeach; ?>
						<?php if ( empty( $entries ) ) : ?>
						<tr>
							<td colspan="6" style="text-align:center;color:#646970;padding:24px;">No memory entries.</td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<style>
				.stonewright-card { background: #fff; padding: 16px 20px; margin: 16px 0; border-left: 4px solid #2271b1; }
				.stonewright-type-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; text-transform: uppercase; font-weight: 600; }
				.stonewright-type-user { background: #e0f2fe; color: #0369a1; }
				.stonewright-type-feedback { background: #fef3c7; color: #92400e; }
				.stonewright-type-project { background: #e0e7ff; color: #4338ca; }
				.stonewright-type-reference { background: #ecfdf5; color: #047857; }
				.stonewright-type-generic { background: #f3f4f6; color: #374151; }
			</style>
		</div>
		<?php
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
}
