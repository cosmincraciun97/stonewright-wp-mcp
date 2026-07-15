/**
 * Stonewright admin shell: theme on <html>, notice drawer, shell offset, copy prompts.
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

	function applyThemeClass(theme) {
		var root = document.documentElement;
		root.classList.remove('sw-theme-light', 'sw-theme-dark');
		if (theme === 'dark') {
			root.classList.add('sw-theme-dark');
		} else if (theme === 'light') {
			root.classList.add('sw-theme-light');
		}
	}

	function updateShellOffset(shell) {
		var header = shell.querySelector('.sw-shell__header');
		var adminBar = document.getElementById('wpadminbar');
		var top = 0;
		if (adminBar) {
			top += adminBar.offsetHeight || 0;
		}
		if (header) {
			top += header.offsetHeight || 0;
		}
		if (top > 0) {
			document.documentElement.style.setProperty('--sw-shell-offset', top + 'px');
		}
	}

	function isForeignNotice(node) {
		if (!(node instanceof HTMLElement)) {
			return false;
		}
		if (node.classList.contains('sw-notice')) {
			return false;
		}
		// Stonewright-owned UI must never be relocated.
		var cls = node.className || '';
		if (typeof cls === 'string' && cls.indexOf('sw-') === 0) {
			return false;
		}
		var matches =
			node.matches('.notice, .updated, .error, .update-nag') ||
			(typeof cls === 'string' && /notice/i.test(cls) && !/^sw-/.test(cls));
		return matches;
	}

	function collectForeignNotices(shell) {
		var drawer = shell.querySelector('[data-sw-notice-drawer]');
		var body = shell.querySelector('[data-sw-notice-body]');
		var countEl = shell.querySelector('[data-sw-notice-count]');
		if (!drawer || !body || !countEl) {
			return;
		}

		var root = document.getElementById('wpbody-content') || document.body;
		var candidates = root.querySelectorAll('.notice, .update-nag, .error, .updated, [class*="notice"]');
		var moved = 0;

		candidates.forEach(function (node) {
			if (!isForeignNotice(node)) {
				return;
			}
			if (body.contains(node)) {
				return;
			}
			if (shell.contains(node) && node.closest('[data-sw-notice-drawer]')) {
				return;
			}
			// Skip notices nested deep inside interactive widgets that are not top-level WP notices.
			if (node.closest('.sw-shell__content') && node.closest('form') && node.classList.contains('sw-notice')) {
				return;
			}
			body.appendChild(node);
			moved += 1;
		});

		var total = body.children.length;
		if (total > 0) {
			countEl.textContent = String(total);
			drawer.hidden = false;
			var summary = drawer.querySelector('.sw-notice-drawer__summary');
			if (summary) {
				var label = total === 1
					? 'Other WordPress notice'
					: 'Other WordPress notices';
				// Keep the count badge as a child; rewrite only leading text.
				var textNode = null;
				for (var i = 0; i < summary.childNodes.length; i++) {
					if (summary.childNodes[i].nodeType === 3) {
						textNode = summary.childNodes[i];
						break;
					}
				}
				if (textNode) {
					textNode.textContent = label + ' ';
				}
			}
		}

		return moved;
	}

	function watchNotices(shell) {
		var root = document.getElementById('wpbody-content') || document.body;
		if (!root || typeof MutationObserver === 'undefined') {
			return;
		}
		var timer = null;
		var observer = new MutationObserver(function () {
			if (timer) {
				window.clearTimeout(timer);
			}
			timer = window.setTimeout(function () {
				collectForeignNotices(shell);
			}, 80);
		});
		observer.observe(root, { childList: true, subtree: true });
		// Stop after 15s — late notices from other plugins usually inject within a few seconds.
		window.setTimeout(function () {
			observer.disconnect();
		}, 15000);
	}

	function initThemeToggle(shell) {
		var btn = shell.querySelector('[data-sw-theme-toggle]');
		var current = shell.getAttribute('data-sw-theme') || 'light';
		applyThemeClass(current);

		if (!btn) {
			return;
		}

		btn.addEventListener('click', function () {
			var isDark = shell.classList.contains('sw-theme-dark') ||
				document.documentElement.classList.contains('sw-theme-dark');
			var next = isDark ? 'light' : 'dark';
			shell.classList.toggle('sw-theme-dark', next === 'dark');
			shell.classList.toggle('sw-theme-light', next === 'light');
			shell.setAttribute('data-sw-theme', next);
			applyThemeClass(next);
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

	function initCopyPrompts(shell) {
		var live = shell.querySelector('[data-sw-copy-live]');
		if (!live) {
			live = document.createElement('div');
			live.setAttribute('data-sw-copy-live', '');
			live.setAttribute('aria-live', 'polite');
			live.className = 'screen-reader-text';
			shell.appendChild(live);
		}

		shell.addEventListener('click', function (event) {
			var btn = event.target.closest('.sw-copy-prompt');
			if (!btn || !shell.contains(btn)) {
				return;
			}
			var prompt = btn.getAttribute('data-prompt') || '';
			if (!prompt) {
				return;
			}
			var done = function () {
				var original = btn.getAttribute('data-label-original') || btn.textContent;
				btn.setAttribute('data-label-original', original);
				btn.textContent = 'Copied ✓';
				live.textContent = 'Copied to clipboard';
				window.setTimeout(function () {
					btn.textContent = original;
					live.textContent = '';
				}, 2000);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(prompt).then(done).catch(function () {
					// Fallback for older browsers / insecure contexts.
					window.prompt('Copy this prompt:', prompt);
				});
			} else {
				window.prompt('Copy this prompt:', prompt);
			}
		});
	}

	ready(function () {
		var shell = document.querySelector('[data-sw-shell]');
		if (!shell) {
			return;
		}
		// Ensure light class is explicit for media-query exclusion.
		if (!shell.classList.contains('sw-theme-dark')) {
			shell.classList.add('sw-theme-light');
		}
		applyThemeClass(shell.getAttribute('data-sw-theme') || 'light');
		updateShellOffset(shell);
		window.addEventListener('resize', function () {
			updateShellOffset(shell);
		});
		collectForeignNotices(shell);
		watchNotices(shell);
		initThemeToggle(shell);
		initCopyPrompts(shell);
	});
})();
