/**
 * Stonewright admin shell: notice drawer + dark theme toggle.
 */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	function collectForeignNotices(shell) {
		var drawer = shell.querySelector('[data-sw-notice-drawer]');
		var body = shell.querySelector('[data-sw-notice-body]');
		var countEl = shell.querySelector('[data-sw-notice-count]');
		if (!drawer || !body || !countEl) {
			return;
		}

		var root = document.getElementById('wpbody-content') || document.body;
		var candidates = root.querySelectorAll('.notice, .update-nag, .error, .updated');
		var moved = 0;

		candidates.forEach(function (node) {
			if (!(node instanceof HTMLElement)) {
				return;
			}
			if (node.classList.contains('sw-notice')) {
				return;
			}
			if (shell.contains(node) && node.closest('[data-sw-notice-drawer]')) {
				return;
			}
			// Keep notices that live inside forms as field-level feedback if marked.
			if (node.closest('.sw-shell__content') && node.classList.contains('sw-notice')) {
				return;
			}
			body.appendChild(node);
			moved += 1;
		});

		if (moved > 0) {
			countEl.textContent = String(moved);
			drawer.hidden = false;
			var summary = drawer.querySelector('.sw-notice-drawer__summary');
			if (summary) {
				var label = moved === 1
					? 'Other WordPress notice'
					: 'Other WordPress notices';
				summary.childNodes[0].textContent = label + ' ';
			}
		}
	}

	function initThemeToggle(shell) {
		var btn = shell.querySelector('[data-sw-theme-toggle]');
		if (!btn) {
			return;
		}

		btn.addEventListener('click', function () {
			var isDark = shell.classList.contains('sw-theme-dark');
			var next = isDark ? 'light' : 'dark';
			shell.classList.toggle('sw-theme-dark', next === 'dark');
			shell.setAttribute('data-sw-theme', next);
			btn.setAttribute('aria-pressed', next === 'dark' ? 'true' : 'false');
			var icon = btn.querySelector('.sw-theme-toggle__icon');
			if (icon) {
				icon.textContent = next === 'dark' ? '☀' : '☾';
			}

			var cfg = window.stonewrightShell || {};
			if (!cfg.ajaxUrl || !cfg.nonce) {
				return;
			}

			var body = new window.URLSearchParams();
			body.set('action', 'stonewright_set_admin_theme');
			body.set('nonce', cfg.nonce);
			body.set('theme', next);

			window.fetch(cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString(),
			}).catch(function () {
				// Preference still applied for the session; persistence is best-effort.
			});
		});
	}

	ready(function () {
		var shell = document.querySelector('[data-sw-shell]');
		if (!shell) {
			return;
		}
		collectForeignNotices(shell);
		initThemeToggle(shell);
	});
})();
