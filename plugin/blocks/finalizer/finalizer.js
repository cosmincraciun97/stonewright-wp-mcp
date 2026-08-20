/* global wp */
(function () {
	'use strict';

	var config = window.stonewrightBlockFinalizer || {};
	var token = config.token || '';
	var restBase = config.restBase || '/wp-json/stonewright/v1/block-finalizer/';

	function compactError(err) {
		if (!err) {
			return { message: 'unknown' };
		}
		if (typeof err === 'string') {
			return { message: err.slice(0, 200) };
		}
		return {
			code: err.code || err.name || '',
			message: String(err.message || err.msg || 'validation failed').slice(0, 200),
			block: err.block || err.blockName || '',
		};
	}

	function toBlock(spec) {
		spec = spec || {};
		var inner = (spec.innerBlocks || []).map(toBlock);
		return wp.blocks.createBlock(spec.name, spec.attributes || {}, inner);
	}

	function serializeSpec(spec) {
		var errors = [];
		var type = wp.blocks.getBlockType(spec.name);
		if (!type) {
			errors.push({
				code: 'unregistered_block',
				message: 'Block is not in the live editor registry.',
				block: spec.name,
			});
			return { html: '', errors: errors };
		}
		try {
			var block = toBlock(spec);
			var html = wp.blocks.serialize([block]);
			wp.blocks.parse(html);
			return { html: html, errors: [] };
		} catch (err) {
			errors.push(compactError(err));
			return { html: '', errors: errors };
		}
	}

	function headers() {
		var h = { Accept: 'application/json' };
		if (config.nonce) {
			h['X-WP-Nonce'] = config.nonce;
		}
		return h;
	}

	function poll() {
		var url = restBase + 'pending?token=' + encodeURIComponent(token);
		var request = window.wp && wp.apiFetch
			? wp.apiFetch({ path: '/stonewright/v1/block-finalizer/pending?token=' + encodeURIComponent(token) })
			: fetch(url, { credentials: 'same-origin', headers: headers() }).then(function (res) {
				if (res.status === 403) {
					throw new Error('forbidden');
				}
				return res.json();
			});

		return Promise.resolve(request).then(function (data) {
			var countEl = document.getElementById('stonewright-finalizer-queued-count');
			if (countEl && data && typeof data.queued_count !== 'undefined') {
				countEl.textContent = String(data.queued_count);
			}
			var items = (data && data.items) || [];
			return Promise.all(items.filter(function (item) {
				return item && item.status === 'queued' && item.block_spec;
			}).map(function (item) {
				var result = serializeSpec(item.block_spec);
				return postResult(item.id, result);
			}));
		});
	}

	function postResult(changeId, result) {
		var body = {
			token: token,
			change_id: changeId,
			html: result.html || '',
			html_hash: result.html ? sha256Fallback(result.html) : '',
			errors: (result.errors || []).slice(0, 20).map(compactError),
		};
		if (window.wp && wp.apiFetch) {
			return wp.apiFetch({
				path: '/stonewright/v1/block-finalizer/result',
				method: 'POST',
				data: body,
			});
		}
		return fetch(restBase + 'result', {
			method: 'POST',
			credentials: 'same-origin',
			headers: Object.assign({ 'Content-Type': 'application/json' }, headers()),
			body: JSON.stringify(body),
		});
	}

	function sha256Fallback(text) {
		if (window.crypto && crypto.subtle && window.TextEncoder) {
			return 'pending';
		}
		return '';
	}

	function digest(text) {
		if (!window.crypto || !crypto.subtle || !window.TextEncoder) {
			return Promise.resolve('');
		}
		return crypto.subtle.digest('SHA-256', new TextEncoder().encode(text)).then(function (buf) {
			return Array.from(new Uint8Array(buf)).map(function (b) {
				return b.toString(16).padStart(2, '0');
			}).join('');
		});
	}

	function tick() {
		poll().catch(function () {
			/* Keep polling; the operator was asked to leave this page open. */
		});
	}

	function boot() {
		if (!window.wp || !wp.blocks) {
			return;
		}
		var originalPost = postResult;
		postResult = function (changeId, result) {
			if (!result.html) {
				return originalPost(changeId, result);
			}
			return digest(result.html).then(function (hash) {
				result = Object.assign({}, result);
				return originalPost(changeId, {
					html: result.html,
					errors: result.errors,
					html_hash: hash,
				});
			});
		};
		tick();
		setInterval(tick, 2000);
	}

	if (window.wp && wp.domReady) {
		wp.domReady(boot);
	} else if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
