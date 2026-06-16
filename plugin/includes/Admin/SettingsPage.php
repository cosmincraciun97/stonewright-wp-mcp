<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

/**
 * Stonewright admin settings page.
 *
 * Surfaces the production-safe mode toggle,
 * companion bridge URL/token, and the Elementor V4 atomic feature flag.
 * All option writes go through `register_setting()` so they participate
 * in normal WordPress capability + nonce checks.
 */
final class SettingsPage {

	private const SLUG       = 'stonewright';
	private const CAPABILITY = 'manage_options';
	private const OPTION_GROUP = 'stonewright_settings';

	public static function register(): void {
		add_menu_page(
			__( 'Stonewright', 'stonewright' ),
			__( 'Stonewright', 'stonewright' ),
			self::CAPABILITY,
			self::SLUG,
			[ self::class, 'render' ],
			'dashicons-hammer',
			76
		);

		add_action( 'admin_init', [ self::class, 'register_settings' ] );
	}

	public static function register_settings(): void {
		register_setting( self::OPTION_GROUP, 'stonewright_mode', [
			'type'              => 'string',
			'default'           => 'development',
			'sanitize_callback' => static function ( $value ): string {
				$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
				return in_array( $value, [ 'development', 'staging', 'production-safe' ], true ) ? $value : 'development';
			},
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_companion_url', [
			'type'              => 'string',
			'default'           => 'http://127.0.0.1:8765',
			'sanitize_callback' => 'esc_url_raw',
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_companion_token', [
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_essential_tools_mode', [
			'type'              => 'boolean',
			'default'           => true,
			'sanitize_callback' => static function ( $value ): bool {
				return (bool) $value;
			},
		] );

		register_setting( self::OPTION_GROUP, 'stonewright_elementor_v4_atomic', [
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => static function ( $value ): bool {
				return (bool) $value;
			},
		] );
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Stonewright', 'stonewright' ); ?></h1>
			<p><?php esc_html_e( 'Design-accurate WordPress building for Gutenberg and Elementor. Toggle production-safe mode before exposing the MCP server to remote clients.', 'stonewright' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="stonewright_mode"><?php esc_html_e( 'Mode', 'stonewright' ); ?></label></th>
							<td>
								<?php $mode = (string) get_option( 'stonewright_mode', 'development' ); ?>
								<select name="stonewright_mode" id="stonewright_mode">
									<option value="development" <?php selected( $mode, 'development' ); ?>><?php esc_html_e( 'Development', 'stonewright' ); ?></option>
									<option value="staging" <?php selected( $mode, 'staging' ); ?>><?php esc_html_e( 'Staging', 'stonewright' ); ?></option>
									<option value="production-safe" <?php selected( $mode, 'production-safe' ); ?>><?php esc_html_e( 'Production (safe)', 'stonewright' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Production mode disables destructive abilities and requires confirmation tokens.', 'stonewright' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="stonewright_companion_url"><?php esc_html_e( 'Companion bridge URL', 'stonewright' ); ?></label></th>
							<td>
								<input type="url" class="regular-text" name="stonewright_companion_url" id="stonewright_companion_url" value="<?php echo esc_attr( (string) get_option( 'stonewright_companion_url', 'http://127.0.0.1:8765' ) ); ?>" autocomplete="off"/>
								<p class="description"><?php esc_html_e( 'The Node companion endpoint used for local helper operations and optional MCP proxying.', 'stonewright' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="stonewright_companion_token"><?php esc_html_e( 'Companion bearer token', 'stonewright' ); ?></label></th>
							<td>
								<input type="password" class="regular-text" name="stonewright_companion_token" id="stonewright_companion_token" value="<?php echo esc_attr( (string) get_option( 'stonewright_companion_token', '' ) ); ?>" autocomplete="off"/>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="stonewright_essential_tools_mode"><?php esc_html_e( 'Essential tools mode', 'stonewright' ); ?></label></th>
							<td>
								<label>
									<input type="checkbox" name="stonewright_essential_tools_mode" id="stonewright_essential_tools_mode" value="1" <?php checked( (bool) get_option( 'stonewright_essential_tools_mode', true ) ); ?>/>
									<?php esc_html_e( 'Expose a compact fast-path toolset by default for lower token use and faster MCP startup.', 'stonewright' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="stonewright_elementor_v4_atomic"><?php esc_html_e( 'Elementor V4 atomic (experimental)', 'stonewright' ); ?></label></th>
							<td>
								<label>
									<input type="checkbox" name="stonewright_elementor_v4_atomic" id="stonewright_elementor_v4_atomic" value="1" <?php checked( (bool) get_option( 'stonewright_elementor_v4_atomic', false ) ); ?>/>
									<?php esc_html_e( 'Enable the experimental V4 atomic renderer and related abilities.', 'stonewright' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
