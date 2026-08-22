<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/connect-page.php
 * Source SHA-256: cad3348707d451e431c1b0aa964ea53d31c502bd8b86b5ca6fb9f15a3bf217d0
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\OAuth\ConnectedApps;

defined( 'ABSPATH' ) || exit;

/**
 * Render OAuth client tabs and instructions.
 */
final class OAuthConnectPanel {

	public static function render( string $mcp_url, string $server_name ): void {
		$configs = OAuthClientConfig::configs( $mcp_url, $server_name );
		$labels  = OAuthClientConfig::client_labels();
		$first   = (string) array_key_first( $labels );

		echo '<div class="sw-oauth-connect" data-stonewright-oauth-connect>';
		echo '<div class="sw-oauth-tabs" role="tablist" aria-label="' . esc_attr( __( 'AI clients', 'stonewright' ) ) . '">';
		foreach ( $labels as $slug => $label ) {
			echo '<button type="button" role="tab" class="button sw-oauth-tab';
			echo $slug === $first ? ' is-active' : '';
			echo '" data-sw-oauth-tab="' . esc_attr( $slug ) . '" aria-selected="';
			echo $slug === $first ? 'true' : 'false';
			echo '">' . esc_html( $label ) . '</button>';
		}
		echo '</div>';

		echo '<p><button type="button" class="button-link" data-sw-oauth-name-toggle aria-expanded="false">';
		echo esc_html__( 'Change server name (optional)', 'stonewright' ) . '</button></p>';
		echo '<div class="sw-oauth-name" data-sw-oauth-name-field hidden>';
		echo '<label for="stonewright-oauth-server-name">' . esc_html__( 'Server name', 'stonewright' ) . '</label> ';
		echo '<input type="text" id="stonewright-oauth-server-name" value="' . esc_attr( $server_name ) . '" maxlength="64" ';
		echo 'data-sw-oauth-server-name data-original-name="' . esc_attr( $server_name ) . '">';
		echo '<p class="description">' . esc_html__( 'Use letters, numbers, hyphens, and underscores.', 'stonewright' ) . '</p>';
		echo '</div>';

		foreach ( $labels as $slug => $label ) {
			$config  = $configs[ $slug ] ?? [
				'kind'    => 'notice',
				'message' => sprintf(
					/* translators: %s: client label. */
					__( '%s cannot reach a site that is available only on this local machine.', 'stonewright' ),
					$label
				),
			];
			$visible = $slug === $first;
			echo '<section class="sw-oauth-client-panel' . ( $visible ? ' is-active' : '' ) . '" role="tabpanel" data-sw-oauth-panel="' . esc_attr( $slug ) . '"';
			echo $visible ? '' : ' hidden';
			echo '>';
			self::render_config( $slug, $label, $config, $server_name );
			echo '</section>';
		}

		echo '<p class="sw-oauth-connected-apps"><a href="';
		echo esc_url( admin_url( 'admin.php?page=' . ConnectedApps::PAGE_SLUG ) ) . '">';
		echo esc_html__( 'Manage connected apps', 'stonewright' ) . '</a></p>';
		echo '</div>';
		self::render_script();
	}

	/**
	 * Render a clickable Cursor (or other custom-scheme) install button.
	 */
	public static function render_deeplink_button( string $deeplink, string $label, string $server_name = '' ): void {
		if ( '' === $deeplink ) {
			return;
		}
		$id = 'stonewright-oauth-deeplink-' . md5( $deeplink );
		echo '<p class="sw-oauth-deeplink-row">';
		echo '<a class="button button-primary" href="' . esc_url( $deeplink, [ 'http', 'https', 'cursor' ] ) . '">';
		echo esc_html( sprintf( __( 'One-click install in %s', 'stonewright' ), $label ) ) . '</a> ';
		echo '<code id="' . esc_attr( $id ) . '" class="screen-reader-text" data-sw-oauth-template="';
		echo esc_attr( $deeplink ) . '" data-sw-oauth-original-name="' . esc_attr( $server_name ) . '">';
		echo esc_html( $deeplink ) . '</code>';
		echo '<button type="button" class="button" data-stonewright-copy="' . esc_attr( $id ) . '">';
		echo esc_html__( 'Copy install link', 'stonewright' ) . '</button>';
		echo '</p>';
	}

	/**
	 * @param array<string, mixed> $config Config entry.
	 */
	private static function render_config( string $slug, string $label, array $config, string $server_name ): void {
		$kind    = (string) ( $config['kind'] ?? 'code' );
		$message = (string) ( $config['message'] ?? '' );
		if ( '' !== $message ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html( $message ) . '</p></div>';
		}
		if ( 'notice' === $kind ) {
			return;
		}

		$connector = (string) ( $config['connector'] ?? '' );
		if ( '' !== $connector ) {
			echo '<p><a class="button button-primary" href="' . esc_url( $connector ) . '" target="_blank" rel="noopener noreferrer">';
			echo esc_html__( 'Add custom connector', 'stonewright' ) . '</a></p>';
		}
		$deeplink = (string) ( $config['deeplink'] ?? '' );
		if ( '' !== $deeplink ) {
			self::render_deeplink_button( $deeplink, $label, $server_name );
		}

		$steps = $config['steps'] ?? [];
		if ( is_array( $steps ) && [] !== $steps ) {
			echo '<ol class="sw-oauth-steps">';
			foreach ( $steps as $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}
				echo '<li><strong>' . esc_html( (string) ( $step['title'] ?? '' ) ) . '</strong>';
				echo '<p>' . esc_html( (string) ( $step['body'] ?? '' ) ) . '</p>';
				$copy = (string) ( $step['copy'] ?? '' );
				if ( '' !== $copy ) {
					self::render_copy_value( $slug . '-step-' . md5( $copy ), $copy, $server_name );
				}
				echo '</li>';
			}
			echo '</ol>';
		}

		$paths = $config['paths'] ?? [];
		if ( is_array( $paths ) && [] !== $paths ) {
			echo '<div class="sw-oauth-paths"><strong>' . esc_html__( 'Open your config', 'stonewright' ) . '</strong><ul>';
			foreach ( $paths as $platform => $path ) {
				echo '<li><span>' . esc_html( (string) $platform ) . ':</span> <code>';
				echo esc_html( (string) $path ) . '</code></li>';
			}
			echo '</ul></div>';
		}

		$hint = (string) ( $config['hint'] ?? '' );
		if ( '' !== $hint ) {
			echo '<p class="description">' . wp_kses_post( $hint ) . '</p>';
		}

		$code = (string) ( $config['code'] ?? '' );
		if ( '' !== $code ) {
			$id = 'stonewright-oauth-code-' . $slug;
			echo '<pre id="' . esc_attr( $id ) . '" data-sw-oauth-template="';
			echo esc_attr( $code ) . '" data-sw-oauth-original-name="' . esc_attr( $server_name ) . '"><code>';
			echo esc_html( $code ) . '</code></pre>';
			echo '<button type="button" class="button" data-stonewright-copy="' . esc_attr( $id ) . '">';
			echo esc_html__( 'Copy', 'stonewright' ) . '</button>';
		}

		$note = (string) ( $config['note'] ?? '' );
		if ( '' !== $note ) {
			echo '<pre class="sw-oauth-note">' . esc_html( $note ) . '</pre>';
		}
	}

	private static function render_copy_value( string $suffix, string $value, string $server_name ): void {
		$id = 'stonewright-oauth-copy-' . sanitize_key( $suffix );
		echo '<div class="sw-oauth-copy-row"><code id="' . esc_attr( $id ) . '" data-sw-oauth-template="';
		echo esc_attr( $value ) . '" data-sw-oauth-original-name="' . esc_attr( $server_name ) . '">';
		echo esc_html( $value ) . '</code> ';
		echo '<button type="button" class="button button-small" data-stonewright-copy="' . esc_attr( $id ) . '">';
		echo esc_html__( 'Copy', 'stonewright' ) . '</button></div>';
	}

	private static function render_script(): void {
		?>
		<script>
		(function () {
			var root = document.querySelector('[data-stonewright-oauth-connect]');
			if (!root) return;
			root.querySelectorAll('[data-sw-oauth-tab]').forEach(function (tab) {
				tab.addEventListener('click', function () {
					var slug = tab.getAttribute('data-sw-oauth-tab');
					root.querySelectorAll('[data-sw-oauth-tab]').forEach(function (item) {
						var active = item === tab;
						item.classList.toggle('is-active', active);
						item.setAttribute('aria-selected', active ? 'true' : 'false');
					});
					root.querySelectorAll('[data-sw-oauth-panel]').forEach(function (panel) {
						var match = panel.getAttribute('data-sw-oauth-panel') === slug;
						panel.hidden = !match;
						panel.classList.toggle('is-active', match);
					});
				});
			});
			var toggle = root.querySelector('[data-sw-oauth-name-toggle]');
			var field = root.querySelector('[data-sw-oauth-name-field]');
			if (toggle && field) {
				toggle.addEventListener('click', function () {
					field.hidden = !field.hidden;
					toggle.setAttribute('aria-expanded', field.hidden ? 'false' : 'true');
				});
			}
			var input = root.querySelector('[data-sw-oauth-server-name]');
			if (input) {
				input.addEventListener('input', function () {
					var value = input.value.replace(/[^a-zA-Z0-9_-]/g, '-').slice(0, 64);
					root.querySelectorAll('[data-sw-oauth-template]').forEach(function (node) {
						var original = node.getAttribute('data-sw-oauth-original-name') || '';
						var template = node.getAttribute('data-sw-oauth-template') || '';
						node.textContent = template.split(original).join(value || original);
					});
				});
			}
		}());
		</script>
		<?php
	}
}
