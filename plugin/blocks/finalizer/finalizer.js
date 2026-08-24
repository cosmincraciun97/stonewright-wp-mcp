/* global wp */
(function () {
	'use strict';

	var config = window.stonewrightBlockFinalizer || {};
	var token = config.token || '';
	var restBase = config.restBase || '/wp-json/stonewright/v1/block-finalizer/';
	var leaseId = config.leaseId || (window.crypto && typeof window.crypto.randomUUID === 'function'
		? window.crypto.randomUUID()
		: 'browser-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2));
	var resultIds = {};

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
		var packed = {
			code: err.code || err.name || '',
			message: String(err.message || err.msg || 'validation failed').slice(0, 200),
			block: err.block || err.blockName || '',
		};
		if (err.parsed_name) {
			packed.parsed_name = String(err.parsed_name).slice(0, 80);
		}
		if (typeof err.html_len === 'number') {
			packed.html_len = err.html_len;
		}
		return packed;
	}

	function roundtripError(blockName, html, parsedName) {
		html = html || '';
		parsedName = parsedName || '';
		var err = new FinalizerError(
			'serialize_roundtrip_failed',
			blockName,
			'serialize_roundtrip_failed expected=' + (blockName || '') + ' parsed_name=' + parsedName + ' html_len=' + html.length
		);
		err.html = html;
		err.parsed_name = parsedName;
		err.html_len = html.length;
		err.retryable = html.length === 0;
		return err;
	}

	function serializeDirect(blocksApi, spec) {
		var block = toBlock(blocksApi, spec);
		var html = blocksApi.serialize([block]);
		var parsed = blocksApi.parse(html);
		var parsedName = parsed && parsed[0] ? parsed[0].name : '';
		if (!html || !parsed.length || parsedName !== spec.name || parsedName === 'core/freeform') {
			throw roundtripError(spec.name, html, parsedName);
		}
		return html;
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

	function lockEditorPersistence(win) {
		if (!win || !win.wp || !win.wp.data || typeof win.wp.data.dispatch !== 'function') {
			return;
		}
		try {
			var editorDispatch = win.wp.data.dispatch('core/editor');
			if (editorDispatch && typeof editorDispatch.lockPostAutosaving === 'function') {
				editorDispatch.lockPostAutosaving('stonewright-block-finalizer');
			}
			if (editorDispatch && typeof editorDispatch.lockPostSaving === 'function') {
				editorDispatch.lockPostSaving('stonewright-block-finalizer');
			}
		} catch (lockErr) {
			/* Older editors may lack save locks; serialization still proceeds. */
		}
		try {
			if (win.wp.apiFetch && typeof win.wp.apiFetch.use === 'function' && !win.__stonewrightFinalizerFetchLocked) {
				win.__stonewrightFinalizerFetchLocked = true;
				win.wp.apiFetch.use(function (options, next) {
					options = options || {};
					options.headers = options.headers || {};
					options.headers['X-Stonewright-Finalizer'] = '1';
					return next(options);
				});
			}
		} catch (fetchErr) {
			/* apiFetch middleware is a belt-and-braces guard. */
		}
	}

	async function mountAndSerialize(win, spec) {
		lockEditorPersistence(win);
		var created = toBlock(win.wp.blocks, spec);
		var dataApi = win.wp.data;
		var blockDispatch = dataApi.dispatch('core/block-editor');
		var blockSelect = dataApi.select('core/block-editor');
		var editorSelect = dataApi.select('core/editor');
		var editorDispatch = dataApi.dispatch('core/editor');

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
			if (editorDispatch && typeof editorDispatch.resetEditorBlocks === 'function') {
				editorDispatch.resetEditorBlocks([created]);
			} else if (blockDispatch && typeof blockDispatch.resetBlocks === 'function') {
				blockDispatch.resetBlocks([created]);
			}

			await settle(win, 500);
			var settled = blockSelect.getBlocks()[0];
			if (!settled) {
				throw roundtripError(spec.name, '', '');
			}
			var valid = win.wp.blocks.validateBlock
				? isValidBlock(win.wp.blocks.validateBlock(settled))
				: true;
			var html = win.wp.blocks.serialize([settled]);
			var parsed = win.wp.blocks.parse(html);
			var parsedName = parsed && parsed[0] ? parsed[0].name : '';
			if (!valid || !html || !parsed.length || parsedName !== spec.name || parsedName === 'core/freeform') {
				throw roundtripError(spec.name, html, parsedName);
			}
			return html;
		} finally {
			if (editorDispatch && typeof editorDispatch.resetEditorBlocks === 'function') {
				editorDispatch.resetEditorBlocks(originalBlocks);
			} else if (blockDispatch && typeof blockDispatch.resetBlocks === 'function') {
				blockDispatch.resetBlocks(originalBlocks);
			}
			lockEditorPersistence(win);
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
		lockEditorPersistence(win);
		await waitFor(function () {
			return win.wp.blocks.getBlockType(spec.name);
		}, 15000).catch(function () {
			throw new FinalizerError('block_not_registered', spec.name);
		});
		try {
			return serializeDirect(win.wp.blocks, spec);
		} catch (directErr) {
			if (directErr && directErr.html) {
				throw directErr;
			}
			return mountAndSerialize(win, spec);
		}
	}

	function isRetryableSerializeError(err) {
		if (!err) {
			return true;
		}
		if (err.retryable) {
			return true;
		}
		return err.code === 'editor_frame_timeout'
			|| err.code === 'editor_frame_unavailable'
			|| err.code === 'block_not_registered'
			|| (err.code === 'serialize_roundtrip_failed' && !err.html);
	}

	function serializeItem(item) {
		return serializeInEditor(item).then(function (html) {
			return { html: html, errors: [], retryable: false };
		}).catch(function (err) {
			var html = (err && err.html) || '';
			if (isRetryableSerializeError(err) && !html) {
				return { html: '', errors: [], retryable: true };
			}
			return { html: html, errors: [compactError(err)], retryable: false };
		});
	}

	function headers() {
		var h = { Accept: 'application/json' };
		if (config.nonce) {
			h['X-WP-Nonce'] = config.nonce;
		}
		return h;
	}

	function httpError(status) {
		var error = new Error('http_' + String(status || 0));
		error.status = Number(status || 0);
		return error;
	}

	function requireOk(response) {
		if (response && typeof response.ok === 'boolean' && !response.ok) {
			throw httpError(response.status);
		}
		return response;
	}

	function jsonResponse(response) {
		requireOk(response);
		return response && typeof response.json === 'function' ? response.json() : response;
	}

	function pendingReceipt(data) {
		if (!data || typeof data !== 'object' || !Array.isArray(data.items)) {
			throw new FinalizerError('malformed_pending_receipt', '', 'The finalizer pending response was malformed.');
		}
		return data;
	}

	function resultReceipt(data, result) {
		var failed = result && result.errors && result.errors.length;
		if (data && data.ok === false && data.status === 'queued' && data.retryable === true) {
			return { accepted: false, retryable: true };
		}
		if (failed && data && data.ok === false && data.status === 'failed') {
			return { accepted: true, retryable: false };
		}
		if (!failed && data && data.ok === true && data.status === 'serialized') {
			return { accepted: true, retryable: false };
		}
		throw new FinalizerError('malformed_result_receipt', '', 'The finalizer result response was malformed.');
	}

	function statusOfError(error) {
		if (!error) {
			return 0;
		}
		var status = Number(error.status || (error.data && error.data.status) || (error.response && error.response.status) || 0);
		return Number.isFinite(status) ? status : 0;
	}

	function isRetryableTransportError(error) {
		var status = statusOfError(error);
		if (status >= 500 && status < 600) {
			return true;
		}
		if (status > 0) {
			return false;
		}
		var name = String((error && error.name) || '');
		var code = String((error && error.code) || '');
		var message = String((error && error.message) || '');
		return name === 'AbortError'
			|| name === 'TimeoutError'
			|| /^(?:ETIMEDOUT|ECONNRESET|ENETUNREACH|EHOSTUNREACH|fetch_error|http_request_failed|network_error)$/i.test(code)
			|| /(?:failed to fetch|network(?: request)? failed|networkerror|timed? out|timeout)/i.test(message);
	}

	var sessionApplied = 0;
	var sessionFailed = 0;
	var itemMemory = {};

	function pad2(n) {
		return String(n).padStart(2, '0');
	}

	function formatClock(date) {
		return pad2(date.getHours()) + ':' + pad2(date.getMinutes()) + ':' + pad2(date.getSeconds());
	}

	function setOnline(isOnline) {
		var el = document.getElementById('stonewright-finalizer-online');
		if (!el) {
			return;
		}
		el.classList.toggle('is-offline', !isOnline);
		el.setAttribute('data-online', isOnline ? 'true' : 'false');
		el.textContent = isOnline ? 'Online' : 'Offline';
	}

	function setText(id, value) {
		var el = document.getElementById(id);
		if (el) {
			el.textContent = String(value);
		}
	}

	function errorCodeOf(item, result) {
		var err = result && Array.isArray(result.errors) && result.errors[0] ? result.errors[0] : (item && item.error);
		if (!err) {
			return '';
		}
		if (typeof err === 'string') {
			return err.slice(0, 80);
		}
		return String(err.code || err.error_code || err.message || '').slice(0, 80);
	}

	function rememberItem(item, extras) {
		if (!item || !item.id) {
			return;
		}
		var spec = specOf(item);
		var next = itemMemory[item.id] || {};
		next.id = item.id;
		next.block = item.block_name || spec.name || next.block || 'block';
		next.post = typeof item.post_id !== 'undefined' && item.post_id !== null ? item.post_id : next.post;
		next.status = (extras && extras.status) || item.status || next.status || 'queued';
		next.error = errorCodeOf(item, extras && extras.result) || next.error || '';
		itemMemory[item.id] = next;
	}

	function renderItems() {
		var list = document.getElementById('stonewright-finalizer-items');
		if (!list) {
			return;
		}
		list.replaceChildren();
		Object.keys(itemMemory).forEach(function (id) {
			var row = itemMemory[id];
			var li = document.createElement('li');
			li.className = 'sw-finalizer-item is-' + String(row.status || 'queued').replace(/[^a-z0-9_-]/gi, '');
			var block = document.createElement('code');
			block.textContent = row.block || 'block';
			var post = document.createElement('span');
			post.textContent = row.post === '' || typeof row.post === 'undefined' ? '—' : 'Post ' + row.post;
			var status = document.createElement('span');
			status.textContent = row.status || 'queued';
			li.appendChild(block);
			li.appendChild(post);
			li.appendChild(status);
			if (row.error) {
				var err = document.createElement('code');
				err.className = 'sw-finalizer-item__error';
				err.textContent = row.error;
				li.appendChild(err);
			}
			list.appendChild(li);
		});
	}

	function renderStrip(data) {
		setText('stonewright-finalizer-last-poll', formatClock(new Date()));
		var queued = data && typeof data.queued_count !== 'undefined' ? Number(data.queued_count) : 0;
		if (Number.isNaN(queued)) {
			queued = 0;
		}
		setText('stonewright-finalizer-queued-count', queued);
		var failed = sessionFailed;
		if (data && typeof data.failed_count !== 'undefined' && data.failed_count !== null) {
			var apiFailed = Number(data.failed_count);
			if (!Number.isNaN(apiFailed)) {
				failed = Math.max(failed, apiFailed);
			}
		}
		setText('stonewright-finalizer-applied-count', sessionApplied);
		setText('stonewright-finalizer-failed-count', failed);
		var statusEl = document.getElementById('stonewright-finalizer-status');
		if (statusEl) {
			var busy = queued > 0;
			statusEl.classList.toggle('is-busy', busy);
			statusEl.textContent = busy
				? 'Serializing queued block changes…'
				: 'Nothing to serialize. The queue is ready.';
		}
		renderItems();
	}

	function poll() {
		var query = 'token=' + encodeURIComponent(token) + '&lease_id=' + encodeURIComponent(leaseId);
		var url = restBase + 'pending?' + query;
		var request = window.wp && wp.apiFetch
			? wp.apiFetch({ path: '/stonewright/v1/block-finalizer/pending?' + query })
			: fetch(url, { credentials: 'same-origin', headers: headers() }).then(jsonResponse);

		return Promise.resolve(request).then(pendingReceipt).then(function (data) {
			setOnline(true);
			var items = (data && data.items) || [];
			items.forEach(function (item) {
				rememberItem(item, null);
			});
			renderStrip(data);
			var retryableItemSeen = false;
			return items.filter(function (item) {
				return item && item.status === 'queued' && (item.block_spec || item.spec);
			}).reduce(function (chain, item) {
				return chain.then(function () {
					return serializeItem(item).then(function (result) {
						if (result && result.retryable && !result.html) {
							retryableItemSeen = true;
							rememberItem(item, { status: 'queued', result: result });
							renderStrip(data);
							return;
						}
						return postResult(item.id, result).then(function (receipt) {
							if (receipt && receipt.retryable) {
								retryableItemSeen = true;
								rememberItem(item, { status: 'queued', result: result });
								renderStrip(data);
								return;
							}
							var failed = result && result.errors && result.errors.length;
							if (failed) {
								sessionFailed += 1;
								rememberItem(item, { status: 'failed', result: result });
							} else {
								sessionApplied += 1;
								rememberItem(item, { status: 'applied', result: result });
							}
							renderStrip(data);
						});
					});
				});
			}, Promise.resolve()).then(function () {
				return { retryable: retryableItemSeen };
			});
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
		if (!resultIds[changeId]) {
			resultIds[changeId] = window.crypto && typeof window.crypto.randomUUID === 'function'
				? window.crypto.randomUUID()
				: 'result-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
		}
		return hashPayload(result.html || '').then(function (hashing) {
			var body = {
				token: token,
				lease_id: leaseId,
				result_id: resultIds[changeId],
				change_id: changeId,
				html: result.html || '',
				html_hash: hashing.html_hash,
				errors: (result.errors || []).slice(0, 20).map(compactError),
			};
			if (hashing.hash_unavailable) {
				body.hash_unavailable = true;
			}
			if (window.wp && wp.apiFetch) {
				return Promise.resolve(wp.apiFetch({
					path: '/stonewright/v1/block-finalizer/result',
					method: 'POST',
					data: body,
				})).then(function (receipt) {
					return resultReceipt(receipt, result);
				});
			}
			return fetch(restBase + 'result', {
				method: 'POST',
				credentials: 'same-origin',
				headers: Object.assign({ 'Content-Type': 'application/json' }, headers()),
				body: JSON.stringify(body),
			}).then(jsonResponse).then(function (receipt) {
				return resultReceipt(receipt, result);
			});
		});
	}

	var pollInFlight = false;
	var heartbeatInFlight = false;
	var pollFailures = 0;
	var heartbeatFailures = 0;
	var pollTimer = 0;
	var heartbeatTimer = 0;
	var closed = false;

	function stop() {
		if (closed) {
			return;
		}
		closed = true;
		window.clearTimeout(pollTimer);
		window.clearTimeout(heartbeatTimer);
		pollTimer = 0;
		heartbeatTimer = 0;
		setOnline(false);
	}

	function retryDelay(failures, base) {
		return Math.min(30000, base * Math.pow(2, Math.max(0, failures - 1)));
	}

	function scheduleNextTick(delay) {
		if (closed) {
			return;
		}
		window.clearTimeout(pollTimer);
		pollTimer = window.setTimeout(tick, delay);
	}

	function scheduleHeartbeat(delay) {
		if (closed) {
			return;
		}
		window.clearTimeout(heartbeatTimer);
		heartbeatTimer = window.setTimeout(heartbeat, delay);
	}

	function tick() {
		if (closed || pollInFlight) {
			return;
		}
		pollInFlight = true;
		poll().then(function (result) {
			setOnline(true);
			if (result && result.retryable) {
				pollFailures += 1;
				scheduleNextTick(retryDelay(pollFailures, 2000));
			} else {
				pollFailures = 0;
				scheduleNextTick(2000);
			}
		}).catch(function (error) {
			if (!isRetryableTransportError(error)) {
				stop();
				return;
			}
			pollFailures += 1;
			setOnline(false);
			setText('stonewright-finalizer-last-poll', formatClock(new Date()));
			scheduleNextTick(retryDelay(pollFailures, 2000));
		}).finally(function () {
			pollInFlight = false;
		});
	}

	function heartbeat() {
		if (closed || !token || heartbeatInFlight) {
			return;
		}
		heartbeatInFlight = true;
		var body = { token: token, lease_id: leaseId };
		var request = window.wp && wp.apiFetch
			? wp.apiFetch({
				path: '/stonewright/v1/block-finalizer/heartbeat',
				method: 'POST',
				data: body,
			})
			: fetch(restBase + 'heartbeat', {
				method: 'POST',
				credentials: 'same-origin',
				headers: Object.assign({ 'Content-Type': 'application/json' }, headers()),
				body: JSON.stringify(body),
			}).then(requireOk);
		Promise.resolve(request).then(function () {
			heartbeatFailures = 0;
			setOnline(true);
			scheduleHeartbeat(15000);
		}).catch(function (error) {
			if (!isRetryableTransportError(error)) {
				stop();
				return;
			}
			heartbeatFailures += 1;
			setOnline(false);
			scheduleHeartbeat(retryDelay(heartbeatFailures, 15000));
		}).finally(function () {
			heartbeatInFlight = false;
		});
	}

	function boot() {
		if (closed) {
			return;
		}
		heartbeat();
		tick();
	}

	window.addEventListener('unload', stop);
	window.addEventListener('pagehide', stop);

	if (window.wp && wp.domReady) {
		wp.domReady(boot);
	} else if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
