/*!
 * Stonewright motion runtime v1.0.0. SPDX-License-Identifier: AGPL-3.0-or-later.
 * Original bundled product code. Static-first and fail-open: if initialization
 * fails, every target remains visible. CSS transitions only; no remote code.
 */
(function () {
	'use strict';

	if (window.__stwMotionRuntime) {
		return;
	}
	window.__stwMotionRuntime = true;

	var doc = document;
	var root = doc.documentElement;
	var REDUCED = 'stw-motion-reduced';
	var PLAYED = 'stw-motion-played';
	var MAX_DURATION_MS = 3000;
	var MAX_DELAY_MS = 2000;
	var MAX_STAGGER_INTERVAL_MS = 250;
	var MAX_STAGGER_SPAN_MS = 2000;
	var TARGET_SELECTOR = '.stw-motion-fade-in, .stw-motion-fade-up, .stw-motion-slide-in-inline, .stw-motion-scale-in-subtle';
	var CONFIG_SELECTOR = TARGET_SELECTOR + ', .stw-motion-card-lift, .stw-motion-link-underline, .stw-motion-stagger-reveal';
	var media = null;
	var observer = null;

	function boundedClassValue(el, prefix, max) {
		for (var i = 0; i < el.classList.length; i++) {
			var token = el.classList.item(i) || '';
			if (token.indexOf(prefix) !== 0) {
				continue;
			}
			var raw = token.slice(prefix.length);
			if (!/^[0-9]+$/.test(raw)) {
				return 0;
			}
			return Math.min(parseInt(raw, 10), max);
		}
		return 0;
	}

	function applyConfig(el) {
		var duration = boundedClassValue(el, 'stw-motion-duration--', MAX_DURATION_MS);
		var delay = boundedClassValue(el, 'stw-motion-delay--', MAX_DELAY_MS);
		if (duration || el.classList.contains('stw-motion-duration--0')) {
			el.style.setProperty('--stw-motion-duration', duration + 'ms');
		}
		if (delay || el.classList.contains('stw-motion-delay--0')) {
			el.style.setProperty('--stw-motion-delay', delay + 'ms');
		}
	}

	function markPlayed(el) {
		el.classList.add(PLAYED);
	}

	function prepareStagger(container) {
		var interval = boundedClassValue(container, 'stw-motion-stagger-interval--', MAX_STAGGER_INTERVAL_MS);
		var children = container.querySelectorAll(':scope > *');
		for (var i = 0; i < children.length && i < 12; i++) {
			children[i].classList.add('stw-motion-fade-up', 'stw-motion-trigger--viewport-enter');
			children[i].style.setProperty('--stw-motion-stagger-delay', Math.min(i * interval, MAX_STAGGER_SPAN_MS) + 'ms');
		}
	}

	function allTargets() {
		return doc.querySelectorAll(TARGET_SELECTOR);
	}

	function teardown() {
		if (observer) {
			observer.disconnect();
			observer = null;
		}
	}

	function revealAll() {
		var targets = allTargets();
		for (var i = 0; i < targets.length; i++) {
			markPlayed(targets[i]);
		}
	}

	function failOpen() {
		teardown();
		revealAll();
		root.classList.remove('stw-motion-js');
	}

	function initializeTargets() {
		try {
			teardown();

			var configured = doc.querySelectorAll(CONFIG_SELECTOR);
			for (var c = 0; c < configured.length; c++) {
				applyConfig(configured[c]);
			}

			var staggers = doc.querySelectorAll('.stw-motion-stagger-reveal');
			for (var s = 0; s < staggers.length; s++) {
				prepareStagger(staggers[s]);
			}

			var targets = allTargets();
			if (media.matches || !('IntersectionObserver' in window)) {
				revealAll();
				return;
			}

			observer = new IntersectionObserver(
				function (entries) {
					for (var i = 0; i < entries.length; i++) {
						if (!entries[i].isIntersecting) {
							continue;
						}
						markPlayed(entries[i].target);
						observer.unobserve(entries[i].target);
					}
				},
				{ threshold: 0.15 }
			);

			for (var j = 0; j < targets.length; j++) {
				if (targets[j].classList.contains('stw-motion-trigger--load')) {
					markPlayed(targets[j]);
				} else if (!targets[j].classList.contains(PLAYED)) {
					observer.observe(targets[j]);
				}
			}
		} catch (error) {
			failOpen();
		}
	}

	function onPreferenceChange() {
		root.classList.toggle(REDUCED, media.matches);
		initializeTargets();
	}

	try {
		media = window.matchMedia('(prefers-reduced-motion: reduce)');
		root.classList.add('stw-motion-js');
		root.classList.toggle(REDUCED, media.matches);

		if (media.addEventListener) {
			media.addEventListener('change', onPreferenceChange);
		} else if (media.addListener) {
			media.addListener(onPreferenceChange);
		}
		window.addEventListener('pagehide', teardown);
		window.addEventListener('pageshow', initializeTargets);

		if (doc.readyState === 'loading') {
			doc.addEventListener('DOMContentLoaded', initializeTargets, { once: true });
		} else {
			initializeTargets();
		}
	} catch (error) {
		failOpen();
	}
})();
