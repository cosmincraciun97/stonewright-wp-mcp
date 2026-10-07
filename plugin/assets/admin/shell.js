/**
 * Stonewright admin shell: notice policy, shell offset, copy prompts, tooltips.
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
	 * Notices the page prints in its own content stay where the page printed them.
	 *
	 * WordPress moves every notice that is not marked `inline` to just after `hr.wp-header-end` when the page is
	 * ready. The plugin's own pages print notices inside the content region, so they are marked `inline` here,
	 * synchronously, before WordPress's handler runs.
	 */
	function pinOwnNotices(shell) {
		var content = shell.querySelector('#sw-main');
		if (!content) {
			return;
		}
		content.querySelectorAll('.notice, .updated, .error').forEach(function (node) {
			node.classList.add('inline');
		});
	}

	/**
	 * True only for a notice another plugin or WordPress itself printed.
	 *
	 * Everything the plugin renders stays where it is: its page content (#sw-main), anything already in the
	 * drawer, and any element carrying a sw-* or stonewright-* class wherever that class sits in the list
	 * (the plugin prints its own admin notices with one).
	 */
	function isForeignNotice(node) {
		if (!(node instanceof HTMLElement)) {
			return false;
		}
		if (node.closest('#sw-main, .sw-notice-drawer')) {
			return false;
		}
		var cls = typeof node.className === 'string' ? node.className : '';
		if (/(^|\s)(sw|stonewright)-/.test(cls)) {
			return false;
		}
		// Only the classes WordPress prints for its own notices count.
		return node.matches('.notice, .updated, .error, .update-nag');
	}

	/** error, warning, update or notice: the words the drawer title counts. */
	function severityOf(node) {
		if (node.matches('.notice-error, .error')) {
			return 'error';
		}
		if (node.matches('.notice-warning')) {
			return 'warning';
		}
		if (node.matches('.update-nag, .update-message')) {
			return 'update';
		}
		return 'notice';
	}

	function countLabel(labels, severity, count) {
		var forms = labels && labels[severity];
		var template = forms ? forms[count === 1 ? 0 : 1] : '%d';
		return String(template || '%d').replace('%d', String(count));
	}

	/**
	 * Keep up to three notices where WordPress put them. When more arrive, fold them into one disclosure titled
	 * with how many of each kind it holds. It starts open whenever it holds an error or a warning, so those are
	 * never hidden behind a click.
	 */
	var MAX_VISIBLE = 3;

	function foldNotices(shell) {
		var drawer = shell.querySelector('[data-sw-notice-drawer]');
		var body = shell.querySelector('[data-sw-notice-body]');
		var summary = shell.querySelector('[data-sw-notice-summary]');
		if (!drawer || !body || !summary) {
			return;
		}

		var root = document.getElementById('wpbody-content') || document.body;
		var visible = [];
		root.querySelectorAll('.notice, .update-nag, .error, .updated').forEach(function (node) {
			if (isForeignNotice(node)) {
				visible.push(node);
			}
		});
		var folded = Array.prototype.slice.call(body.children);

		if (folded.length + visible.length > MAX_VISIBLE) {
			visible.forEach(function (node) {
				body.appendChild(node);
			});
			folded = Array.prototype.slice.call(body.children);
		}

		if (folded.length === 0) {
			drawer.hidden = true;
			return;
		}

		var labels = {};
		try {
			labels = JSON.parse(drawer.getAttribute('data-sw-notice-labels') || '{}');
		} catch (e) {
			labels = {};
		}
		var counts = { error: 0, warning: 0, update: 0, notice: 0 };
		folded.forEach(function (node) {
			counts[severityOf(node)] += 1;
		});
		var parts = ['error', 'warning', 'update', 'notice']
			.filter(function (severity) {
				return counts[severity] > 0;
			})
			.map(function (severity) {
				return countLabel(labels, severity, counts[severity]);
			});
		var title = (labels.heading || '') + ': ' + parts.join(', ');
		if (summary.textContent !== title) {
			summary.textContent = title;
		}

		if (drawer.hidden && (counts.error > 0 || counts.warning > 0)) {
			drawer.open = true;
		}
		drawer.hidden = false;
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
				foldNotices(shell);
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

	// Runs now, not on ready: WordPress moves notices when the page is ready, and the plugin's own must be marked first.
	var printedShell = document.querySelector('[data-sw-shell]');
	if (printedShell) {
		pinOwnNotices(printedShell);
	}

	ready(function () {
		var shell = document.querySelector('[data-sw-shell]');
		if (!shell) {
			return;
		}
		document.documentElement.classList.add('sw-has-shell');
		updateShellOffset();
		window.addEventListener('resize', updateShellOffset);
		foldNotices(shell);
		watchNotices(shell);
		initCopyPrompts(shell);
		initTooltips();
	});
})();
