/**
 * Stonewright admin shell: notice drawer, shell offset, copy prompts, tooltips.
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

	/**
	 * Publish the height of the chrome that stays fixed above the content: the WordPress admin bar.
	 * The shell header scrolls with the page, so it is not part of the offset.
	 */
	function updateShellOffset() {
		var adminBar = document.getElementById('wpadminbar');
		var top = adminBar ? adminBar.offsetHeight || 0 : 0;
		if (top > 0) {
			document.documentElement.style.setProperty('--sw-shell-offset', top + 'px');
		}
	}

	/**
	 * True only for a notice another plugin or WordPress itself printed.
	 *
	 * Everything the plugin renders stays where it is: its page content, anything already in the drawer,
	 * and any element carrying a sw-* or stonewright-* class wherever that class sits in the list
	 * (the plugin prints its own admin notices with one).
	 */
	function isForeignNotice(node) {
		if (!(node instanceof HTMLElement)) {
			return false;
		}
		if (node.closest('.sw-shell__content, .sw-notice-drawer')) {
			return false;
		}
		var cls = typeof node.className === 'string' ? node.className : '';
		if (/(^|\s)(sw|stonewright)-/.test(cls)) {
			return false;
		}
		// Only the classes WordPress prints for its own notices count.
		return node.matches('.notice, .updated, .error, .update-nag');
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
			if (!isForeignNotice(node)) {
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

	function copyTextSilent(value) {
		// Prefer Clipboard API; fall back to execCommand. Never use alert/prompt.
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(value).then(function () {
				return true;
			}).catch(function () {
				return copyViaTextarea(value);
			});
		}
		return Promise.resolve(copyViaTextarea(value));
	}

	function copyViaTextarea(value) {
		try {
			var ta = document.createElement('textarea');
			ta.value = value;
			ta.setAttribute('readonly', '');
			ta.style.position = 'fixed';
			ta.style.top = '0';
			ta.style.left = '-9999px';
			ta.style.opacity = '0';
			document.body.appendChild(ta);
			ta.focus();
			ta.select();
			var ok = false;
			try {
				ok = document.execCommand('copy');
			} catch (e) {
				ok = false;
			}
			if (ta.parentNode) {
				ta.parentNode.removeChild(ta);
			}
			return ok;
		} catch (e2) {
			return false;
		}
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
			event.preventDefault();
			var text = btn.getAttribute('data-prompt') || '';
			if (!text) {
				return;
			}
			var original = btn.getAttribute('data-label-original') || btn.textContent;
			btn.setAttribute('data-label-original', original);

			copyTextSilent(text).then(function (ok) {
				btn.textContent = ok ? 'Copied ✓' : 'Copy failed';
				live.textContent = ok ? 'Copied to clipboard' : 'Could not copy';
				window.setTimeout(function () {
					btn.textContent = original;
					live.textContent = '';
				}, 2000);
			});
		});
	}

	function initTooltips() {
		var tipEl = null;
		var tipId = 0;

		function hide() {
			if (!tipEl) {
				return;
			}
			document.querySelectorAll('[aria-describedby="' + tipEl.id + '"]').forEach(function (el) {
				el.removeAttribute('aria-describedby');
			});
			tipEl.remove();
			tipEl = null;
		}

		function show(trigger) {
			hide();
			var text = trigger.getAttribute('data-sw-tooltip');
			if (!text) {
				return;
			}
			tipEl = document.createElement('div');
			tipEl.className = 'sw-tooltip';
			tipEl.id = 'sw-tooltip-' + (++tipId);
			tipEl.setAttribute('role', 'tooltip');
			tipEl.textContent = text;
			document.body.appendChild(tipEl);
			trigger.setAttribute('aria-describedby', tipEl.id);
			var r = trigger.getBoundingClientRect();
			var h = tipEl.offsetHeight;
			var left = r.left + r.width / 2 - tipEl.offsetWidth / 2;
			left = Math.max(8, Math.min(window.innerWidth - tipEl.offsetWidth - 8, left));
			// Flip below when the trigger sits too close to the viewport top.
			var above = r.top - h - 8 >= 0;
			var top = above ? r.top - h - 8 : r.bottom + 8;
			tipEl.style.left = left + 'px';
			tipEl.style.top = top + window.scrollY + 'px';
			tipEl.classList.add('is-visible');
		}

		document.addEventListener('mouseover', function (e) {
			var t = e.target && e.target.closest ? e.target.closest('[data-sw-tooltip]') : null;
			if (t) {
				show(t);
			}
		});
		document.addEventListener('mouseout', function (e) {
			if (e.target && e.target.closest && e.target.closest('[data-sw-tooltip]')) {
				hide();
			}
		});
		document.addEventListener('focusin', function (e) {
			var t = e.target && e.target.closest ? e.target.closest('[data-sw-tooltip]') : null;
			if (t) {
				show(t);
			}
		});
		document.addEventListener('focusout', hide);
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				hide();
			}
		});
	}

	ready(function () {
		var shell = document.querySelector('[data-sw-shell]');
		if (!shell) {
			return;
		}
		document.documentElement.classList.add('sw-has-shell');
		updateShellOffset();
		window.addEventListener('resize', updateShellOffset);
		collectForeignNotices(shell);
		watchNotices(shell);
		initCopyPrompts(shell);
		initTooltips();
	});
})();
