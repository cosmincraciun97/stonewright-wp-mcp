/* global wp */
(function () {
	'use strict';

	var config = window.stonewrightBlockFinalizer || {};
	var token = config.token || '';
	var restBase = config.restBase || '/wp-json/stonewright/v1/block-finalizer/';

	function FinalizerError(code, blockName, message) {
		this.name = 'FinalizerError';
		this.code = code || 'finalizer_error';
		this.block = blockName || '';
		this.message = message || code || 'finalizer error';
	}
	FinalizerError.prototype = Object.create(Error.prototype);
	FinalizerError.prototype.constructor = FinalizerError;

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

	function specOf(item) {
		return (item && (item.block_spec || item.spec)) || {};
	}

	function toBlock(blocksApi, spec) {
		spec = spec || {};
		var inner = (spec.innerBlocks || []).map(function (child) {
			return toBlock(blocksApi, child);
		});
		return blocksApi.createBlock(spec.name, spec.attributes || {}, inner);
	}

	function waitFor(predicate, timeoutMs) {
		return new Promise(function (resolve, reject) {
			var started = Date.now();
			function tick() {
				try {
					if (predicate()) {
						resolve(true);
						return;
					}
				} catch (err) {
					/* Not ready yet. */
				}
				if (Date.now() - started >= timeoutMs) {
					reject(new Error('timeout'));
					return;
				}
				window.setTimeout(tick, 100);
			}
			tick();
		});
	}

	function rafTwice() {
		return new Promise(function (resolve) {
			window.requestAnimationFrame(function () {
				window.requestAnimationFrame(function () {
					resolve();
				});
			});
		});
	}

	function sleep(ms) {
		return new Promise(function (resolve) {
			window.setTimeout(resolve, ms);
		});
	}

	function isValidBlock(result) {
		if (true === result) {
			return true;
		}
		if (false === result) {
			return false;
		}
		if (Array.isArray(result)) {
			return result[0] === true;
		}
		return true;
	}

	function navigateFrame(frame, url) {
		return new Promise(function (resolve, reject) {
			var settled = false;
			function cleanup() {
				frame.removeEventListener('load', onLoad);
				window.clearTimeout(timeoutId);
			}
			function onLoad() {
				if (settled) {
					return;
				}
				settled = true;
				cleanup();
				resolve();
			}
			var timeoutId = window.setTimeout(function () {
				if (settled) {
					return;
				}
				settled = true;
				cleanup();
				reject(new FinalizerError('editor_frame_timeout', '', 'The editor iframe did not finish loading.'));
			}, 30000);
			frame.addEventListener('load', onLoad);
			frame.src = url;
		});
	}

	function frameWindow(frame) {
		try {
			return frame && frame.contentWindow ? frame.contentWindow : null;
		} catch (err) {
			return null;
		}
	}

	async function settle(win, ms) {
		await rafTwice();
		await sleep(ms);
		var select = win.wp.data.select('core/block-editor');
		if (!select || typeof select.getBlocks !== 'function') {
			return;
		}
		var previous = '';
		var deadline = Date.now() + Math.max(ms, 500);
		while (Date.now() < deadline) {
			var snapshot = win.wp.blocks.serialize(select.getBlocks() || []);
			if (snapshot !== '' && snapshot === previous) {
				return;
			}
			previous = snapshot;
			await sleep(100);
		}
	}

	async function mountAndSerialize(win, spec) {
		var created = toBlock(win.wp.blocks, spec);
		var dataApi = win.wp.data;
		var blockDispatch = dataApi.dispatch('core/block-editor');
		var blockSelect = dataApi.select('core/block-editor');
		var editorSelect = dataApi.select('core/editor');
		var editorDispatch = dataApi.dispatch('core/editor');
		var lockKey = 'stonewright-block-finalizer';
		var autosavingLocked = false;
		var savingLocked = false;

		var readyDeadline = Date.now() + 30000;
		while (Date.now() < readyDeadline) {
			var ready = editorSelect && typeof editorSelect.__unstableIsEditorReady === 'function'
				? editorSelect.__unstableIsEditorReady()
				: blockSelect && typeof blockSelect.getBlocks === 'function' && blockSelect.getBlocks().length > 0;
			if (ready) {
				break;
			}
			await sleep(200);
		}

		var originalBlocks = blockSelect.getBlocks();
		try {
			if (editorDispatch && typeof editorDispatch.lockPostAutosaving === 'function') {
				editorDispatch.lockPostAutosaving(lockKey);
				autosavingLocked = true;
			}
			if (editorDispatch && typeof editorDispatch.lockPostSaving === 'function') {
				editorDispatch.lockPostSaving(lockKey);
				savingLocked = true;
			}
		} catch (lockErr) {
			/* Older editors may lack save locks; serialization still proceeds. */
		}

		try {
			if (editorDispatch && typeof editorDispatch.resetEditorBlocks === 'function') {
				editorDispatch.resetEditorBlocks([created]);
			} else if (blockDispatch && typeof blockDispatch.resetBlocks === 'function') {
				blockDispatch.resetBlocks([created]);
			}

			await settle(win, 500);
			var settled = blockSelect.getBlocks()[0];
			if (!settled) {
				throw new FinalizerError('serialize_roundtrip_failed', spec.name);
			}
			var valid = win.wp.blocks.validateBlock
				? isValidBlock(win.wp.blocks.validateBlock(settled))
				: true;
			var html = win.wp.blocks.serialize(settled);
			var parsed = win.wp.blocks.parse(html);
			var parsedName = parsed && parsed[0] ? parsed[0].name : '';
			if (!valid || !parsed.length || parsedName !== spec.name || parsedName === 'core/freeform') {
				throw new FinalizerError('serialize_roundtrip_failed', spec.name);
			}
			return html;
		} finally {
			try {
				if (editorDispatch && typeof editorDispatch.resetEditorBlocks === 'function') {
					editorDispatch.resetEditorBlocks(originalBlocks);
				} else if (blockDispatch && typeof blockDispatch.resetBlocks === 'function') {
					blockDispatch.resetBlocks(originalBlocks);
				}
			} finally {
				try {
					if (savingLocked && typeof editorDispatch.unlockPostSaving === 'function') {
						editorDispatch.unlockPostSaving(lockKey);
					}
				} finally {
					if (autosavingLocked && typeof editorDispatch.unlockPostAutosaving === 'function') {
						editorDispatch.unlockPostAutosaving(lockKey);
					}
				}
			}
		}
	}

	async function serializeInEditor(item) {
		var spec = specOf(item);
		var frame = document.getElementById('stonewright-finalizer-frame');
		if (!frame || !item.editor_url) {
			throw new FinalizerError('editor_frame_unavailable', spec.name);
		}
		if (frame.dataset.post !== String(item.post_id)) {
			await navigateFrame(frame, item.editor_url);
			frame.dataset.post = String(item.post_id);
		}
		var win = frameWindow(frame);
		if (!win) {
			throw new FinalizerError('editor_frame_unavailable', spec.name);
		}
		await waitFor(function () {
			return win.wp && win.wp.blocks && win.wp.data
				&& typeof win.wp.blocks.createBlock === 'function'
				&& typeof win.wp.blocks.serialize === 'function'
				&& typeof win.wp.blocks.parse === 'function'
				&& typeof win.wp.blocks.getBlockType === 'function';
		}, 30000).catch(function () {
			throw new FinalizerError('editor_frame_unavailable', spec.name);
		});
		await waitFor(function () {
			return win.wp.blocks.getBlockType(spec.name);
		}, 15000).catch(function () {
			throw new FinalizerError('block_not_registered', spec.name);
		});
		return mountAndSerialize(win, spec);
	}

	function serializeFallback(spec) {
		if (window.wp && wp.blockLibrary && typeof wp.blockLibrary.registerCoreBlocks === 'function') {
			wp.blockLibrary.registerCoreBlocks();
		}
		if (!spec.name || spec.name.indexOf('core/') !== 0) {
			throw new FinalizerError('block_not_registered', spec.name);
		}
		var blocksApi = window.wp && wp.blocks;
		if (!blocksApi || typeof blocksApi.getBlockType !== 'function') {
			throw new FinalizerError('block_not_registered', spec.name);
		}
		if (!blocksApi.getBlockType(spec.name)) {
			throw new FinalizerError('block_not_registered', spec.name);
		}
		var block = toBlock(blocksApi, spec);
		var html = blocksApi.serialize([block]);
		var parsed = blocksApi.parse(html);
		if (!parsed.length || parsed[0].name !== spec.name) {
			throw new FinalizerError('serialize_roundtrip_failed', spec.name);
		}
		return html;
	}

	function serializeItem(item) {
		return serializeInEditor(item).then(function (html) {
			return { html: html, errors: [] };
		}).catch(function (err) {
			if (err && err.code === 'block_not_registered') {
				return { html: '', errors: [compactError(err)] };
			}
			if (err && (err.code === 'editor_frame_timeout' || err.code === 'editor_frame_unavailable')) {
				try {
					return { html: serializeFallback(specOf(item)), errors: [] };
				} catch (fallbackErr) {
					return { html: '', errors: [compactError(fallbackErr)] };
				}
			}
			return { html: '', errors: [compactError(err)] };
		});
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
			return items.filter(function (item) {
				return item && item.status === 'queued' && (item.block_spec || item.spec);
			}).reduce(function (chain, item) {
				return chain.then(function () {
					return serializeItem(item).then(function (result) {
						return postResult(item.id, result);
					});
				});
			}, Promise.resolve());
		});
	}

	function sha256Fallback(text) {
		void text;
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

	function hashPayload(html) {
		if (!html) {
			return Promise.resolve({ html_hash: '', hash_unavailable: false });
		}
		if (!window.crypto || !crypto.subtle || !window.TextEncoder) {
			sha256Fallback(html);
			return Promise.resolve({ html_hash: '', hash_unavailable: true });
		}
		return digest(html).then(function (hash) {
			if (hash) {
				return { html_hash: hash, hash_unavailable: false };
			}
			sha256Fallback(html);
			return { html_hash: '', hash_unavailable: true };
		}).catch(function () {
			sha256Fallback(html);
			return { html_hash: '', hash_unavailable: true };
		});
	}

	function postResult(changeId, result) {
		return hashPayload(result.html || '').then(function (hashing) {
			var body = {
				token: token,
				change_id: changeId,
				html: result.html || '',
				html_hash: hashing.html_hash,
				errors: (result.errors || []).slice(0, 20).map(compactError),
			};
			if (hashing.hash_unavailable) {
				body.hash_unavailable = true;
			}
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
		});
	}

	function tick() {
		poll().catch(function () {
			/* Keep polling; the operator was asked to leave this page open. */
		});
	}

	function heartbeat() {
		if (!token) {
			return;
		}
		var body = { token: token };
		if (window.wp && wp.apiFetch) {
			wp.apiFetch({
				path: '/stonewright/v1/block-finalizer/heartbeat',
				method: 'POST',
				data: body,
			}).catch(function () {
				/* Keep the queue page open; the next beat will retry. */
			});
			return;
		}
		fetch(restBase + 'heartbeat', {
			method: 'POST',
			credentials: 'same-origin',
			headers: Object.assign({ 'Content-Type': 'application/json' }, headers()),
			body: JSON.stringify(body),
		}).catch(function () {
			/* Keep the queue page open; the next beat will retry. */
		});
	}

	function boot() {
		heartbeat();
		setInterval(heartbeat, 15000);
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
