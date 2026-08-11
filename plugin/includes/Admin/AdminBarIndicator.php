<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Core\VendorGuard;
use Stonewright\WpMcp\Security\DomainLock;
use Stonewright\WpMcp\Security\PluginEffectiveState;

/**
 * Admin bar ON/OFF/BLOCKED kill switch for Stonewright abilities.
 *
 * Toggle is nonce-protected and requires manage_options. Shows ERROR when
 * abilities are enabled but vendor/MCP dependencies failed to load, and
 * BLOCKED (not OFF) when operator intent is on but domain lock mismatches.
 */
final class AdminBarIndicator {

	public const TOGGLE_ACTION = 'stonewright_toggle_abilities';
	public const OPTION        = PluginEffectiveState::OPTION_REQUESTED;
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

		$requested = PluginEffectiveState::enabled_requested();
		$effective = PluginEffectiveState::effective_state();
		$error     = VendorGuard::get_error();

		$state = match ( $effective ) {
			PluginEffectiveState::STATE_ENABLED                 => 'on',
			PluginEffectiveState::STATE_BLOCKED_DOMAIN_MISMATCH => 'blocked',
			PluginEffectiveState::STATE_BLOCKED_DEPENDENCY      => 'error',
			PluginEffectiveState::STATE_BLOCKED_SECURITY_POLICY => 'blocked',
			default                                             => 'off',
		};

		// Prefer ERROR badge when vendor is broken even if domain also mismatches.
		if ( $requested && null !== $error && 'blocked' !== $state ) {
			$state = 'error';
		}

		$title = match ( $state ) {
			'on'      => '<span class="stonewright-ab-badge stonewright-ab-badge--on">Stonewright ON</span>',
			'error'   => '<span class="stonewright-ab-badge stonewright-ab-badge--error">Stonewright ERROR</span>',
			'blocked' => '<span class="stonewright-ab-badge stonewright-ab-badge--blocked">Stonewright BLOCKED</span>',
			default   => '<span class="stonewright-ab-badge stonewright-ab-badge--off">Stonewright OFF</span>',
		};

		$config_href = admin_url( 'admin.php?page=' . ConfigurationPage::SLUG );
		if ( 'blocked' === $state ) {
			$config_href = admin_url( 'admin.php?page=' . ConfigurationPage::SLUG . '#stonewright-domain-lock' );
		}

		$bar->add_node(
			[
				'id'    => 'stonewright-on',
				'title' => $title,
				'href'  => $config_href,
				'meta'  => [
					'class' => 'stonewright-ab-status stonewright-ab-status--' . $state,
				],
			]
		);

		$label = match ( $state ) {
			'on'      => __( 'AI Abilities: On', 'stonewright' ),
			'error'   => __( 'AI Abilities: Error', 'stonewright' ),
			'blocked' => __( 'AI Abilities: Blocked (domain or policy)', 'stonewright' ),
			default   => __( 'AI Abilities: Off', 'stonewright' ),
		};

		$bar->add_node(
			[
				'id'     => 'stonewright-status-label',
				'parent' => 'stonewright-on',
				'title'  => $label,
			]
		);

		if ( 'blocked' === $state && ! DomainLock::check() ) {
			$bar->add_node(
				[
					'id'     => 'stonewright-review-domain',
					'parent' => 'stonewright-on',
					'title'  => __( 'Review domain lock', 'stonewright' ),
					'href'   => $config_href,
				]
			);
		}

		// Toggle changes operator intent only (not effective domain state).
		$target     = $requested ? 'off' : 'on';
		$toggle_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::TOGGLE_ACTION . '&target=' . $target ),
			self::TOGGLE_ACTION
		);

		$bar->add_node(
			[
				'id'     => 'stonewright-toggle',
				'parent' => 'stonewright-on',
				'title'  => $requested
					? __( 'Turn Off AI Abilities', 'stonewright' )
					: __( 'Turn On AI Abilities', 'stonewright' ),
				'href'   => $toggle_url,
				'meta'   => [
					'class' => $requested ? 'stonewright-ab-toggle-off' : 'stonewright-ab-toggle-on',
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
	 * Writes operator intent only — never clears domain lock.
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

		PluginEffectiveState::set_enabled_requested( 'on' === $target );
		if ( 'on' === $target ) {
			DomainLock::lock();
		}
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
			. '#wpadminbar .stonewright-ab-badge--blocked { background: #996800; color: #fff; }'
			. '</style>' . "\n";
	}
}
