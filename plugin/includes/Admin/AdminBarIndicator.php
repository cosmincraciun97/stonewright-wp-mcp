<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Core\VendorGuard;

/**
 * Admin bar ON/OFF kill switch for Stonewright abilities.
 *
 * Toggle is nonce-protected and requires manage_options. Shows ERROR when
 * abilities are enabled but vendor/MCP dependencies failed to load.
 */
final class AdminBarIndicator {

	public const TOGGLE_ACTION = 'stonewright_toggle_abilities';
	public const OPTION        = 'stonewright_enabled';
	public const CAPABILITY    = 'manage_options';

	/**
	 * Register admin bar + toggle handler hooks.
	 */
	public static function register(): void {
		add_action( 'admin_bar_menu', [ self::class, 'add_node' ], 80 );
		add_action( 'admin_post_' . self::TOGGLE_ACTION, [ self::class, 'handle_toggle' ] );
		add_action( 'admin_head', [ self::class, 'output_styles' ] );
		add_action( 'wp_head', [ self::class, 'output_styles' ] );
	}

	/**
	 * Add the indicator / kill-switch node to the admin bar.
	 *
	 * @param \WP_Admin_Bar $bar The admin bar instance.
	 */
	public static function add_node( \WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$enabled = (bool) get_option( self::OPTION, false );
		$error   = VendorGuard::get_error();
		$state   = 'off';
		if ( $enabled && null !== $error ) {
			$state = 'error';
		} elseif ( $enabled ) {
			$state = 'on';
		}

		$title = match ( $state ) {
			'on'    => '<span class="stonewright-ab-badge stonewright-ab-badge--on">Stonewright ON</span>',
			'error' => '<span class="stonewright-ab-badge stonewright-ab-badge--error">Stonewright ERROR</span>',
			default => '<span class="stonewright-ab-badge stonewright-ab-badge--off">Stonewright OFF</span>',
		};

		$bar->add_node(
			[
				'id'    => 'stonewright-on',
				'title' => $title,
				'href'  => admin_url( 'admin.php?page=' . ConfigurationPage::SLUG ),
				'meta'  => [
					'class' => 'stonewright-ab-status stonewright-ab-status--' . $state,
				],
			]
		);

		$label = match ( $state ) {
			'on'    => __( 'AI Abilities: On', 'stonewright' ),
			'error' => __( 'AI Abilities: Error', 'stonewright' ),
			default => __( 'AI Abilities: Off', 'stonewright' ),
		};

		$bar->add_node(
			[
				'id'     => 'stonewright-status-label',
				'parent' => 'stonewright-on',
				'title'  => $label,
			]
		);

		$target     = $enabled ? 'off' : 'on';
		$toggle_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::TOGGLE_ACTION . '&target=' . $target ),
			self::TOGGLE_ACTION
		);

		$bar->add_node(
			[
				'id'     => 'stonewright-toggle',
				'parent' => 'stonewright-on',
				'title'  => $enabled
					? __( 'Turn Off AI Abilities', 'stonewright' )
					: __( 'Turn On AI Abilities', 'stonewright' ),
				'href'   => $toggle_url,
				'meta'   => [
					'class' => $enabled ? 'stonewright-ab-toggle-off' : 'stonewright-ab-toggle-on',
				],
			]
		);

		$bar->add_node(
			[
				'id'     => 'stonewright-config',
				'parent' => 'stonewright-on',
				'title'  => __( 'Configuration', 'stonewright' ),
				'href'   => admin_url( 'admin.php?page=' . ConfigurationPage::SLUG ),
			]
		);
	}

	/**
	 * Apply the kill-switch target after capability checks.
	 *
	 * @param string $target 'on' or 'off'.
	 */
	public static function apply_toggle( string $target ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to toggle Stonewright abilities.', 'stonewright' ) );
		}

		$target = sanitize_key( $target );
		if ( ! in_array( $target, [ 'on', 'off' ], true ) ) {
			wp_die( esc_html__( 'Invalid toggle target.', 'stonewright' ) );
		}

		update_option( self::OPTION, 'on' === $target );
	}

	/**
	 * Nonce-protected kill switch handler.
	 */
	public static function handle_toggle(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to toggle Stonewright abilities.', 'stonewright' ) );
		}

		check_admin_referer( self::TOGGLE_ACTION );

		$target = isset( $_GET['target'] ) ? sanitize_key( (string) wp_unslash( $_GET['target'] ) ) : '';
		self::apply_toggle( $target );

		$redirect = function_exists( 'wp_get_referer' ) ? wp_get_referer() : false;
		if ( ! is_string( $redirect ) || '' === $redirect ) {
			$redirect = admin_url( 'admin.php?page=' . ConfigurationPage::SLUG );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Inline styles for the admin-bar badge.
	 */
	public static function output_styles(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		if ( function_exists( 'is_admin_bar_showing' ) && ! is_admin_bar_showing() ) {
			return;
		}

		echo '<style>'
			. '#wpadminbar #wp-admin-bar-stonewright-on > .ab-item { color: #fff; }'
			. '#wpadminbar .stonewright-ab-badge { padding: 0 8px; border-radius: 3px; font-weight: 600; }'
			. '#wpadminbar .stonewright-ab-badge--on { background: #d63638; color: #fff; }'
			. '#wpadminbar .stonewright-ab-badge--off { background: #646970; color: #fff; }'
			. '#wpadminbar .stonewright-ab-badge--error { background: #b32d2e; color: #fff; }'
			. '</style>' . "\n";
	}
}
