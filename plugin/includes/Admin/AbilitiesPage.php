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

			<div class="sw-card-grid" id="stonewright-ability-grid">
				<?php foreach ( $abilities as $ability ) : ?>
				<?php
				$is_disabled  = in_array( $ability['name'], $disabled_abilities, true );
				$row_category = $ability['category'];
				$row_style    = ( '' !== $active_category && $active_category !== $row_category ) ? ' style="display:none;"' : '';
				$card_class   = 'sw-card' . ( $is_disabled ? ' sw-card--disabled' : ' sw-card--enabled' );
				$card_id      = 'sw-ability-' . sanitize_html_class( str_replace( '/', '-', $ability['name'] ) );
				?>
				<div
					id="<?php echo esc_attr( $card_id ); ?>"
					class="<?php echo esc_attr( $card_class ); ?> stonewright-ability-row"
					data-name="<?php echo esc_attr( $ability['name'] ); ?>"
					data-label="<?php echo esc_attr( $ability['label'] ); ?>"
					data-category="<?php echo esc_attr( $row_category ); ?>"
					<?php echo $row_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constructed safely above. ?>
				>
					<div class="sw-card__header">
						<div class="sw-card__title-row">
							<span class="sw-card__title"><?php echo esc_html( $ability['label'] ); ?></span>
							<span class="sw-badge sw-badge--category"><?php echo esc_html( ucfirst( $row_category ) ); ?></span>
							<span class="sw-badge <?php echo $is_disabled ? 'sw-badge--disabled' : 'sw-badge--active'; ?>">
								<?php echo $is_disabled ? esc_html__( 'Disabled', 'stonewright' ) : esc_html__( 'Enabled', 'stonewright' ); ?>
							</span>
						</div>
						<code class="sw-card__slug"><?php echo esc_html( $ability['name'] ); ?></code>
					</div>
					<div class="sw-card__body">
						<p class="sw-card__description"><?php echo esc_html( $ability['description'] ); ?></p>
					</div>
					<div class="sw-card__footer">
						<?php if ( current_user_can( self::CAPABILITY ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
							<input type="hidden" name="action" value="stonewright_toggle_ability"/>
							<input type="hidden" name="ability_name" value="<?php echo esc_attr( $ability['name'] ); ?>"/>
							<?php wp_nonce_field( self::NONCE_ACTION ); ?>
							<label class="sw-toggle" title="<?php esc_attr_e( 'Enable / Disable', 'stonewright' ); ?>">
								<input
									type="checkbox"
									name="ability_enabled"
									value="1"
									onchange="this.form.submit()"
									<?php checked( ! $is_disabled ); ?>
								/>
								<span class="sw-toggle__track"></span>
							</label>
						</form>
						<?php endif; ?>
						<button
							type="button"
							class="button button-small"
							onclick="swToggleDetail('<?php echo esc_js( $card_id ); ?>')"
						><?php esc_html_e( 'Details ▾', 'stonewright' ); ?></button>
					</div>

					<?php // Detail panel — hidden until toggled. ?>
					<div class="sw-card__edit-panel" id="<?php echo esc_attr( $card_id ); ?>-detail" style="display:none;">
						<?php
						$schema = $ability['input_schema'] ?? [];
						$props  = $schema['properties'] ?? [];
						$req    = $schema['required'] ?? [];
						if ( ! empty( $props ) ) :
						?>
						<table class="widefat fixed" style="font-size:12px;margin-top:8px;">
							<thead><tr>
								<th><?php esc_html_e( 'Parameter', 'stonewright' ); ?></th>
								<th><?php esc_html_e( 'Type', 'stonewright' ); ?></th>
								<th><?php esc_html_e( 'Required', 'stonewright' ); ?></th>
								<th><?php esc_html_e( 'Description', 'stonewright' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $props as $param => $def ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) $param ); ?></code></td>
								<td><?php echo esc_html( is_array( $def['type'] ?? '' ) ? implode( '|', (array) $def['type'] ) : (string) ( $def['type'] ?? '?' ) ); ?></td>
								<td><?php echo in_array( $param, $req, true ) ? '✅' : ''; ?></td>
								<td><?php echo esc_html( (string) ( $def['description'] ?? '' ) ); ?></td>
							</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<?php else : ?>
						<p style="color:#6b7280;font-size:12px;margin:8px 0 0;"><?php esc_html_e( 'No input parameters.', 'stonewright' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>

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

			function swToggleDetail(cardId) {
				var panel = document.getElementById(cardId + '-detail');
				if (!panel) return;
				var btn = document.querySelector('#' + cardId + ' .sw-card__footer .button');
				if (panel.style.display === 'none') {
					panel.style.display = 'block';
					if (btn) btn.textContent = 'Details ▴';
				} else {
					panel.style.display = 'none';
					if (btn) btn.textContent = 'Details ▾';
				}
			}
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
