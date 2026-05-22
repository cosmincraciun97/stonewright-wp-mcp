<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * Admin page: AI Abilities — lists and toggles registered abilities.
 *
 * Slug: stonewright-abilities (sub-page under Stonewright menu).
 * Writes to the `stonewright_disabled_abilities` option (array of ability names).
 */
final class AbilitiesPage {

	private const SLUG       = 'stonewright-abilities';
	private const CAPABILITY = 'manage_options';
	private const NONCE_ACTION = 'stonewright_toggle_ability';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_post_stonewright_toggle_ability', [ self::class, 'handle_toggle' ] );
	}

	public static function add_submenu(): void {
		add_submenu_page(
			'stonewright',
			__( 'AI Abilities', 'stonewright' ),
			__( 'AI Abilities', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ]
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'stonewright' ) );
		}

		$abilities         = AbilityRegistry::enabled_abilities();
		$master_enabled    = (bool) get_option( 'stonewright_enabled', false );
		$disabled_abilities = (array) get_option( 'stonewright_disabled_abilities', [] );

		// Group by category for chip display.
		$by_category = [];
		foreach ( $abilities as $ability ) {
			$cat = $ability['category'];
			if ( ! isset( $by_category[ $cat ] ) ) {
				$by_category[ $cat ] = 0;
			}
			++$by_category[ $cat ];
		}

		// Read query param for category filter.
		$active_category = isset( $_GET['cat'] ) ? sanitize_key( $_GET['cat'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Success notice.
		$notice = isset( $_GET['stonewright_toggled'] ) ? sanitize_key( $_GET['stonewright_toggled'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Abilities', 'stonewright' ); ?></h1>

			<?php if ( ! $master_enabled ) : ?>
			<div class="notice notice-warning">
				<p>
					<?php esc_html_e( 'Master toggle is OFF — these abilities are registered but the MCP server rejects calls. Enable from the Configuration page.', 'stonewright' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=stonewright' ) ); ?>">
						<?php esc_html_e( 'Go to Configuration', 'stonewright' ); ?>
					</a>
				</p>
			</div>
			<?php endif; ?>

			<?php if ( 'enabled' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Ability enabled.', 'stonewright' ); ?></p></div>
			<?php elseif ( 'disabled' === $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Ability disabled.', 'stonewright' ); ?></p></div>
			<?php endif; ?>

			<?php // Category chips. ?>
			<p class="stonewright-category-chips">
				<a
					href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>"
					class="button<?php echo '' === $active_category ? ' button-primary' : ''; ?>"
				><?php esc_html_e( 'All', 'stonewright' ); ?> (<?php echo esc_html( (string) count( $abilities ) ); ?>)</a>

				<?php foreach ( $by_category as $cat_slug => $cat_count ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( [ 'page' => self::SLUG, 'cat' => $cat_slug ], admin_url( 'admin.php' ) ) ); ?>"
					class="button<?php echo $active_category === $cat_slug ? ' button-primary' : ''; ?>"
				><?php echo esc_html( ucfirst( $cat_slug ) ); ?> (<?php echo esc_html( (string) $cat_count ); ?>)</a>
				<?php endforeach; ?>
			</p>

			<?php // Search input (client-side filter). ?>
			<input
				type="search"
				id="stonewright-ability-search"
				class="regular-text"
				placeholder="<?php esc_attr_e( 'Search abilities…', 'stonewright' ); ?>"
				style="margin-bottom:1em;"
			/>

			<table class="wp-list-table widefat fixed striped stonewright-abilities-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Name', 'stonewright' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Category', 'stonewright' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Description', 'stonewright' ); ?></th>
						<th scope="col" style="width:100px;"><?php esc_html_e( 'Status', 'stonewright' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $abilities as $ability ) : ?>
					<?php
					$is_disabled  = in_array( $ability['name'], $disabled_abilities, true );
					$row_category = $ability['category'];
					$row_style    = ( '' !== $active_category && $active_category !== $row_category ) ? ' style="display:none;"' : '';
					?>
					<tr
						class="stonewright-ability-row"
						data-name="<?php echo esc_attr( $ability['name'] ); ?>"
						data-label="<?php echo esc_attr( $ability['label'] ); ?>"
						data-category="<?php echo esc_attr( $row_category ); ?>"
						<?php echo $row_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constructed safely above. ?>
					>
						<td>
							<strong><?php echo esc_html( $ability['label'] ); ?></strong><br/>
							<code><?php echo esc_html( $ability['name'] ); ?></code>
						</td>
						<td><?php echo esc_html( ucfirst( $row_category ) ); ?></td>
						<td><?php echo esc_html( $ability['description'] ); ?></td>
						<td>
							<?php if ( current_user_can( self::CAPABILITY ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="stonewright_toggle_ability"/>
								<input type="hidden" name="ability_name" value="<?php echo esc_attr( $ability['name'] ); ?>"/>
								<?php wp_nonce_field( self::NONCE_ACTION ); ?>
								<label>
									<input
										type="checkbox"
										name="ability_enabled"
										value="1"
										onchange="this.form.submit()"
										<?php checked( ! $is_disabled ); ?>
									/>
									<?php echo $is_disabled ? esc_html__( 'Disabled', 'stonewright' ) : esc_html__( 'Enabled', 'stonewright' ); ?>
								</label>
							</form>
							<?php else : ?>
							<?php echo $is_disabled ? esc_html__( 'Disabled', 'stonewright' ) : esc_html__( 'Enabled', 'stonewright' ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<script>
			(function () {
				var searchInput = document.getElementById('stonewright-ability-search');
				if (!searchInput) return;
				searchInput.addEventListener('input', function () {
					var query = this.value.toLowerCase();
					var rows = document.querySelectorAll('.stonewright-ability-row');
					rows.forEach(function (row) {
						var name     = (row.dataset.name     || '').toLowerCase();
						var label    = (row.dataset.label    || '').toLowerCase();
						var category = (row.dataset.category || '').toLowerCase();
						var match = !query || name.indexOf(query) !== -1 || label.indexOf(query) !== -1 || category.indexOf(query) !== -1;
						row.style.display = match ? '' : 'none';
					});
				});
			})();
			</script>
		</div>
		<?php
	}

	public static function handle_toggle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stonewright' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$ability_name = isset( $_POST['ability_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ability_name'] ) ) : '';
		if ( '' === $ability_name ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
			exit;
		}

		$enable  = isset( $_POST['ability_enabled'] ) && '1' === $_POST['ability_enabled'];
		$disabled = (array) get_option( 'stonewright_disabled_abilities', [] );

		if ( $enable ) {
			// Remove from disabled list.
			$disabled = array_values( array_filter( $disabled, static fn( $n ) => $n !== $ability_name ) );
			$notice   = 'enabled';
		} else {
			// Add to disabled list (deduplicated).
			if ( ! in_array( $ability_name, $disabled, true ) ) {
				$disabled[] = $ability_name;
			}
			$notice = 'disabled';
		}

		update_option( 'stonewright_disabled_abilities', $disabled, false );

		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'stonewright_toggled' => $notice ], admin_url( 'admin.php' ) ) );
		exit;
	}
}
