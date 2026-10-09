<?php
/**
 * The script that switches the authentication panels.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Setup;

/**
 * Shows the panels of the chosen authentication method and hides the others, without a request. The choice itself is saved by the admin script.
 */
final class AuthMethodScript {

	public static function render(): void {
		?>
				<script>
				(function () {
					var buttons = document.querySelectorAll('[data-stonewright-auth-method]');
					var panels = document.querySelectorAll('[data-stonewright-auth-panel]');
					buttons.forEach(function (button) {
						button.addEventListener('click', function () {
							if (button.disabled) return;
							var method = button.getAttribute('data-stonewright-auth-method');
							buttons.forEach(function (item) {
								var active = item === button;
								item.classList.toggle('is-active', active);
								item.setAttribute('aria-checked', active ? 'true' : 'false');
							});
							panels.forEach(function (panel) {
								panel.hidden = panel.getAttribute('data-stonewright-auth-panel') !== method;
							});
						});
					});
				}());
				</script>
		<?php
	}
}
