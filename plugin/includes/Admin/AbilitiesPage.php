<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * Admin page for enabling and reviewing Stonewright abilities.
 */
final class AbilitiesPage {

	private const SLUG              = 'stonewright-abilities';
	private const CAPABILITY        = 'manage_options';
	private const NONCE_ACTION      = 'stonewright_toggle_ability';
	private const BULK_NONCE_ACTION = 'stonewright_bulk_abilities';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
		add_action( 'admin_post_stonewright_toggle_ability', [ self::class, 'handle_toggle' ] );
		add_action( 'admin_post_stonewright_bulk_abilities', [ self::class, 'handle_bulk' ] );
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

		$abilities          = AbilityRegistry::all_abilities();
		$master_enabled     = (bool) get_option( 'stonewright_enabled', false );
		$disabled_abilities = (array) get_option( 'stonewright_disabled_abilities', [] );
		$groups             = self::group_by_category( $abilities );
		$stats              = self::compute_stats( $abilities, $disabled_abilities );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		$notice             = isset( $_GET['stonewright_toggled'] )
			? sanitize_key( wp_unslash( (string) $_GET['stonewright_toggled'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<?php AdminShell::open( 'stonewright-abilities' ); ?>
		<div class="sw-abilities-page stonewright-abilities-page">
			<header class="stonewright-page-header">
				<div>
					<h1><?php esc_html_e( 'AI Abilities', 'stonewright' ); ?></h1>
					<p><?php esc_html_e( 'Search, inspect, and toggle the MCP tool surface exposed by Stonewright.', 'stonewright' ); ?></p>
				</div>
			</header>

			<?php if ( ! $master_enabled ) : ?>
				<div class="notice notice-warning sw-notice">
					<p>
						<?php esc_html_e( 'Master toggle is off. Abilities remain configurable here but runtime calls are rejected except ping.', 'stonewright' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=stonewright' ) ); ?>">
							<?php esc_html_e( 'Open Configuration', 'stonewright' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( in_array( $notice, [ 'enabled', 'disabled', 'bulk-enabled', 'bulk-disabled' ], true ) ) : ?>
				<div class="notice notice-success is-dismissible sw-notice" aria-live="polite">
					<p><?php esc_html_e( 'Ability settings updated.', 'stonewright' ); ?></p>
				</div>
			<?php endif; ?>

			<form id="stonewright-bulk-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="stonewright_bulk_abilities"/>
				<?php wp_nonce_field( self::BULK_NONCE_ACTION ); ?>
			</form>

			<div class="sw-abilities-filters stonewright-abilities-toolbar" data-sw-abilities-filters>
				<label class="screen-reader-text" for="stonewright-ability-search">
					<?php esc_html_e( 'Search abilities', 'stonewright' ); ?>
				</label>
				<input
					type="search"
					id="stonewright-ability-search"
					class="regular-text sw-abilities-search"
					placeholder="<?php esc_attr_e( 'Search by label, tool, category, or kind', 'stonewright' ); ?>"
					autocomplete="off"
				/>

				<select name="stonewright_bulk_action" form="stonewright-bulk-form">
					<option value=""><?php esc_html_e( 'Bulk action', 'stonewright' ); ?></option>
					<option value="enable_selected"><?php esc_html_e( 'Enable selected', 'stonewright' ); ?></option>
					<option value="disable_selected"><?php esc_html_e( 'Disable selected', 'stonewright' ); ?></option>
					<option value="enable_category"><?php esc_html_e( 'Enable category', 'stonewright' ); ?></option>
					<option value="disable_category"><?php esc_html_e( 'Disable category', 'stonewright' ); ?></option>
				</select>

				<select name="stonewright_bulk_category" form="stonewright-bulk-form">
					<option value=""><?php esc_html_e( 'Category', 'stonewright' ); ?></option>
					<?php foreach ( array_keys( $groups ) as $category ) : ?>
						<option value="<?php echo esc_attr( $category ); ?>"><?php echo esc_html( self::category_label( $category ) ); ?></option>
					<?php endforeach; ?>
				</select>

				<button type="submit" form="stonewright-bulk-form" class="sw-btn sw-btn--secondary sw-btn--sm">
					<?php esc_html_e( 'Apply', 'stonewright' ); ?>
				</button>

				<label class="stonewright-select-all">
					<input type="checkbox" data-stonewright-select-all />
					<span><?php esc_html_e( 'Select visible', 'stonewright' ); ?></span>
				</label>

				<div class="sw-abilities-stats" aria-live="polite">
					<?php
					printf(
						/* translators: 1: enabled count, 2: write count, 3: read count */
						esc_html__( 'Enabled %1$d · Write %2$d · Read %3$d', 'stonewright' ),
						(int) $stats['enabled'],
						(int) $stats['write'],
						(int) $stats['read']
					);
					?>
				</div>
			</div>

			<div class="sw-abilities-empty" data-sw-abilities-empty hidden>
				<p><?php esc_html_e( 'No abilities match your search.', 'stonewright' ); ?></p>
			</div>

			<div class="stonewright-provider-group-list sw-abilities-list">
				<?php foreach ( $groups as $category => $category_abilities ) : ?>
					<?php
					$cat_enabled = 0;
					foreach ( $category_abilities as $ability ) {
						if ( ! in_array( (string) $ability['name'], $disabled_abilities, true ) ) {
							++$cat_enabled;
						}
					}
					?>
					<details
						class="sw-ability-category stonewright-provider-group"
						data-provider="stonewright"
						data-category="<?php echo esc_attr( $category ); ?>"
						open
					>
						<summary class="sw-ability-category__summary stonewright-provider-group__header">
							<div class="sw-ability-category__title">
								<span class="stonewright-provider-name"><?php esc_html_e( 'Stonewright', 'stonewright' ); ?></span>
								<h2><?php echo esc_html( self::category_label( $category ) ); ?></h2>
							</div>
							<span class="sw-badge sw-badge--neutral">
								<?php
								printf(
									/* translators: 1: enabled in category, 2: total in category */
									esc_html__( '%1$d / %2$d', 'stonewright' ),
									(int) $cat_enabled,
									(int) count( $category_abilities )
								);
								?>
							</span>
							<span class="sw-ability-category__actions sw-actions" onclick="event.preventDefault();">
								<button
									type="submit"
									form="stonewright-bulk-form"
									class="sw-btn sw-btn--ghost sw-btn--sm"
									data-sw-bulk-action="enable_category"
									data-sw-bulk-category="<?php echo esc_attr( $category ); ?>"
								><?php esc_html_e( 'Enable all', 'stonewright' ); ?></button>
								<button
									type="submit"
									form="stonewright-bulk-form"
									class="sw-btn sw-btn--ghost sw-btn--sm"
									data-sw-bulk-action="disable_category"
									data-sw-bulk-category="<?php echo esc_attr( $category ); ?>"
								><?php esc_html_e( 'Disable all', 'stonewright' ); ?></button>
							</span>
						</summary>

						<div class="stonewright-ability-table" role="table">
							<div class="stonewright-ability-table__head" role="row">
								<span></span>
								<span><?php esc_html_e( 'Ability', 'stonewright' ); ?></span>
								<span><?php esc_html_e( 'MCP tool', 'stonewright' ); ?></span>
								<span><?php esc_html_e( 'Kind', 'stonewright' ); ?></span>
								<span><?php esc_html_e( 'Enabled', 'stonewright' ); ?></span>
								<span><?php esc_html_e( 'Details', 'stonewright' ); ?></span>
							</div>
							<?php foreach ( $category_abilities as $index => $ability ) : ?>
								<?php self::render_ability_row( $ability, $disabled_abilities, $category, $index ); ?>
							<?php endforeach; ?>
						</div>
					</details>
				<?php endforeach; ?>
			</div>
		</div>
		<?php AdminShell::close(); ?>
		<?php
	}

	/**
	 * @param array<string, mixed> $ability
	 * @param array<int, string>   $disabled_abilities
	 */
	private static function render_ability_row(
		array $ability,
		array $disabled_abilities,
		string $category,
		int $index
	): void {
		$name       = (string) $ability['name'];
		$is_enabled = ! in_array( $name, $disabled_abilities, true );
		$kind       = self::kind_for( $name );
		$row_id     = 'stonewright-ability-' . sanitize_html_class( str_replace( '/', '-', $name ) );
		$form_id    = $row_id . '-form-' . $index;
		$tool_name  = AbilityRegistry::mcp_tool_name( $name );
		?>
		<form id="<?php echo esc_attr( $form_id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="stonewright_toggle_ability"/>
			<input type="hidden" name="ability_name" value="<?php echo esc_attr( $name ); ?>"/>
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
		</form>
		<div
			id="<?php echo esc_attr( $row_id ); ?>"
			class="stonewright-ability-row<?php echo $is_enabled ? '' : ' is-disabled'; ?>"
			role="row"
			data-name="<?php echo esc_attr( $name ); ?>"
			data-label="<?php echo esc_attr( (string) $ability['label'] ); ?>"
			data-tool="<?php echo esc_attr( $tool_name ); ?>"
			data-category="<?php echo esc_attr( $category ); ?>"
			data-kind="<?php echo esc_attr( $kind ); ?>"
		>
			<label class="stonewright-row-check">
				<input
					type="checkbox"
					name="stonewright_abilities[]"
					value="<?php echo esc_attr( $name ); ?>"
					form="stonewright-bulk-form"
				/>
				<span class="screen-reader-text"><?php esc_html_e( 'Select ability', 'stonewright' ); ?></span>
			</label>
			<div class="stonewright-ability-main">
				<strong class="sw-ability-label"><?php echo esc_html( (string) $ability['label'] ); ?></strong>
				<p><?php echo esc_html( (string) $ability['description'] ); ?></p>
			</div>
			<code class="stonewright-mcp-tool sw-ability-tool"><?php echo esc_html( $tool_name ); ?></code>
			<span class="stonewright-kind-badge stonewright-kind-badge--<?php echo esc_attr( $kind ); ?>">
				<?php echo esc_html( self::kind_label( $kind ) ); ?>
			</span>
			<label class="sw-switch" title="<?php esc_attr_e( 'Enable or disable ability', 'stonewright' ); ?>">
				<input
					type="checkbox"
					name="ability_enabled"
					value="1"
					form="<?php echo esc_attr( $form_id ); ?>"
					data-stonewright-submit-form="<?php echo esc_attr( $form_id ); ?>"
					<?php checked( $is_enabled ); ?>
				/>
				<span class="sw-switch__track" aria-hidden="true"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Enable or disable ability', 'stonewright' ); ?></span>
			</label>
			<details class="stonewright-ability-details">
				<summary><?php esc_html_e( 'Details', 'stonewright' ); ?></summary>
				<?php self::render_schema_table( $ability ); ?>
			</details>
		</div>
		<?php
	}

	/**
	 * @param array<string, mixed> $ability
	 */
	private static function render_schema_table( array $ability ): void {
		$schema = is_array( $ability['input_schema'] ?? null ) ? $ability['input_schema'] : [];
		$props  = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : [];
		$req    = is_array( $schema['required'] ?? null ) ? $schema['required'] : [];

		if ( [] === $props ) {
			echo '<p class="description">' . esc_html__( 'No input parameters.', 'stonewright' ) . '</p>';
			return;
		}
		?>
		<div class="stonewright-schema-table-wrap">
			<table class="widefat striped stonewright-schema-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Parameter', 'stonewright' ); ?></th>
						<th><?php esc_html_e( 'Type', 'stonewright' ); ?></th>
						<th><?php esc_html_e( 'Required', 'stonewright' ); ?></th>
						<th><?php esc_html_e( 'Description', 'stonewright' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $props as $param => $def ) : ?>
						<?php $type = is_array( $def['type'] ?? null ) ? implode( '|', $def['type'] ) : (string) ( $def['type'] ?? '?' ); ?>
						<tr>
							<td><code><?php echo esc_html( (string) $param ); ?></code></td>
							<td><?php echo esc_html( $type ); ?></td>
							<td><?php echo in_array( $param, $req, true ) ? esc_html__( 'Yes', 'stonewright' ) : ''; ?></td>
							<td><?php echo esc_html( (string) ( $def['description'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $abilities
	 * @param array<int, string>               $disabled_abilities
	 * @return array{enabled: int, write: int, read: int, total: int}
	 */
	private static function compute_stats( array $abilities, array $disabled_abilities ): array {
		$enabled = 0;
		$write   = 0;
		$read    = 0;

		foreach ( $abilities as $ability ) {
			$name = (string) $ability['name'];
			$kind = self::kind_for( $name );
			if ( ! in_array( $name, $disabled_abilities, true ) ) {
				++$enabled;
			}
			if ( 'read' === $kind ) {
				++$read;
			} else {
				++$write;
			}
		}

		return [
			'enabled' => $enabled,
			'write'   => $write,
			'read'    => $read,
			'total'   => count( $abilities ),
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $abilities
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function group_by_category( array $abilities ): array {
		$groups = [];
		foreach ( $abilities as $ability ) {
			$category            = (string) $ability['category'];
			$groups[ $category ] ??= [];
			$groups[ $category ][] = $ability;
		}
		ksort( $groups );
		return $groups;
	}

	private static function kind_for( string $name ): string {
		if ( 1 === preg_match( '/-(delete|remove|deactivate)\b/', $name ) ) {
			return 'destructive';
		}

		if ( 1 === preg_match(
			'/-(create|update|write|apply|insert|save|set|move|upload|optimize|activate|toggle|register|define|bulk|record|duplicate)\b/',
			$name
		) ) {
			return 'write';
		}

		return 'read';
	}

	private static function kind_label( string $kind ): string {
		return match ( $kind ) {
			'destructive' => __( 'Destructive', 'stonewright' ),
			'write'       => __( 'Write', 'stonewright' ),
			default       => __( 'Read', 'stonewright' ),
		};
	}

	private static function category_label( string $category ): string {
		return ucwords( str_replace( '-', ' ', $category ) );
	}

	public static function handle_toggle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stonewright' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$ability_name = isset( $_POST['ability_name'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['ability_name'] ) )
			: '';
		if ( '' === $ability_name ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
			exit;
		}

		$enable   = isset( $_POST['ability_enabled'] ) && '1' === $_POST['ability_enabled'];
		$disabled = (array) get_option( 'stonewright_disabled_abilities', [] );

		if ( $enable ) {
			$disabled = array_values( array_filter( $disabled, static fn( mixed $n ): bool => $n !== $ability_name ) );
			$notice   = 'enabled';
		} else {
			if ( ! in_array( $ability_name, $disabled, true ) ) {
				$disabled[] = $ability_name;
			}
			$notice = 'disabled';
		}

		update_option( 'stonewright_disabled_abilities', $disabled, false );

		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'stonewright_toggled' => $notice ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_bulk(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'stonewright' ) );
		}

		check_admin_referer( self::BULK_NONCE_ACTION );

		$action   = isset( $_POST['stonewright_bulk_action'] )
			? sanitize_key( wp_unslash( (string) $_POST['stonewright_bulk_action'] ) )
			: '';
		$category = isset( $_POST['stonewright_bulk_category'] )
			? sanitize_key( wp_unslash( (string) $_POST['stonewright_bulk_category'] ) )
			: '';
		$selected = isset( $_POST['stonewright_abilities'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['stonewright_abilities'] ) )
			: [];

		$all      = AbilityRegistry::all_abilities();
		$known    = array_column( $all, 'name' );
		$disabled = array_values( array_intersect( (array) get_option( 'stonewright_disabled_abilities', [] ), $known ) );
		$targets  = in_array( $action, [ 'enable_category', 'disable_category' ], true )
			? self::ability_names_for_category( $all, $category )
			: array_values( array_intersect( $selected, $known ) );

		if ( in_array( $action, [ 'enable_selected', 'enable_category' ], true ) ) {
			$disabled = array_values( array_diff( $disabled, $targets ) );
			$notice   = 'bulk-enabled';
		} elseif ( in_array( $action, [ 'disable_selected', 'disable_category' ], true ) ) {
			$disabled = array_values( array_unique( array_merge( $disabled, $targets ) ) );
			$notice   = 'bulk-disabled';
		} else {
			$notice = '';
		}

		update_option( 'stonewright_disabled_abilities', $disabled, false );
		wp_safe_redirect( add_query_arg( [ 'page' => self::SLUG, 'stonewright_toggled' => $notice ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * @param array<int, array<string, mixed>> $abilities
	 * @return array<int, string>
	 */
	private static function ability_names_for_category( array $abilities, string $category ): array {
		$names = [];
		foreach ( $abilities as $ability ) {
			if ( (string) $ability['category'] === $category ) {
				$names[] = (string) $ability['name'];
			}
		}
		return $names;
	}
}
