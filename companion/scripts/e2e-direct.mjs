#!/usr/bin/env node
/**
 * Repeatable Direct-mode smoke against a WordPress site WITHOUT the Stonewright plugin.
 *
 * Usage:
 *   STONEWRIGHT_WP_URL=http://site.local \
 *   STONEWRIGHT_WP_USERNAME=admin \
 *   STONEWRIGHT_WP_APP_PASSWORD='xxxx xxxx xxxx xxxx' \
 *   STONEWRIGHT_MODE=direct \
 *   node scripts/e2e-direct.mjs
 *
 * Optional: STONEWRIGHT_E2E_CLEANUP=0 to leave created resources.
 */
import { createDirectClient } from '../dist/direct/wp-rest-client.js';
import { resolveRequestedMode, probePluginEndpoint, pluginMcpEndpoint, siteBaseFromEnv } from '../dist/direct/mode.js';

const url = process.env.STONEWRIGHT_WP_URL || process.env.WP_API_URL;
const user = process.env.STONEWRIGHT_WP_USERNAME || process.env.WP_USERNAME;
const pass = process.env.STONEWRIGHT_WP_APP_PASSWORD || process.env.WP_APP_PASSWORD;
const cleanup = process.env.STONEWRIGHT_E2E_CLEANUP !== '0';

const results = [];

function record(name, ok, detail = '') {
	results.push({ name, ok, detail: String(detail).slice(0, 200) });
	const mark = ok ? 'PASS' : 'FAIL';
	console.log(`${mark}  ${name}${detail ? ` — ${String(detail).slice(0, 120)}` : ''}`);
}

function failExit(msg) {
	console.error(msg);
	process.exit(2);
}

if (!url || !user || !pass) {
	failExit('Set STONEWRIGHT_WP_URL, STONEWRIGHT_WP_USERNAME, STONEWRIGHT_WP_APP_PASSWORD');
}

const siteBase = siteBaseFromEnv(process.env) || url.replace(/\/+$/, '');
const mode = resolveRequestedMode(process.env);

console.log(`Target: ${siteBase}`);
console.log(`Mode request: ${mode}`);

// 1) Plugin probe
const mcpEp = pluginMcpEndpoint(siteBase);
try {
	const probe = await probePluginEndpoint(mcpEp);
	if (probe.present === true) {
		record('plugin-probe', true, `plugin endpoint present (status ${probe.status}) — expect plugin proxy path`);
	} else if (probe.present === false) {
		record('plugin-probe', true, 'plugin endpoint 404 — Direct mode eligible');
	} else {
		record('plugin-probe', false, `unknown probe status ${probe.status}`);
	}
} catch (err) {
	record('plugin-probe', false, err instanceof Error ? err.message : String(err));
}

// Build REST client (from dist after npm run build)
let client;
try {
	// Prefer exported factory if present; else minimal inline client.
	const mod = await import('../dist/direct/wp-rest-client.js').catch(() => null);
	if (mod?.createWpRestClient) {
		client = mod.createWpRestClient({ baseUrl: siteBase, username: user, applicationPassword: pass });
	} else if (mod?.WpRestClient) {
		client = new mod.WpRestClient({ baseUrl: siteBase, username: user, applicationPassword: pass });
	} else {
		// Fallback Basic-auth REST helper
		const auth = Buffer.from(`${user}:${pass.replace(/\s+/g, '')}`).toString('base64');
		client = {
			async request(method, path, body) {
				const restPath = path.startsWith('/') ? path : `/${path}`;
				const candidates = [
					`${siteBase.replace(/\/+$/, '')}/wp-json${restPath}`,
					`${siteBase.replace(/\/+$/, '')}/?rest_route=${encodeURIComponent(restPath)}`,
				];
				let lastErr;
				for (const endpoint of candidates) {
					try {
						const res = await fetch(endpoint, {
							method,
							headers: {
								Authorization: `Basic ${auth}`,
								'Content-Type': 'application/json',
								Accept: 'application/json',
							},
							body: body ? JSON.stringify(body) : undefined,
						});
						const text = await res.text();
						let json;
						try {
							json = text ? JSON.parse(text) : null;
						} catch {
							json = { raw: text };
						}
						if (!res.ok) {
							const err = new Error(`HTTP ${res.status}`);
							err.status = res.status;
							err.body = json;
							throw err;
						}
						return json;
					} catch (e) {
						lastErr = e;
					}
				}
				throw lastErr;
			},
		};
	}
	record('auth-client', true, 'REST client ready');
} catch (err) {
	record('auth-client', false, err instanceof Error ? err.message : String(err));
	printSummary();
	process.exit(1);
}

async function rest(method, path, body) {
	if (typeof client.request === 'function') {
		return client.request(method, path, body);
	}
	if (method === 'GET' && client.get) return client.get(path);
	if (method === 'POST' && client.post) return client.post(path, body);
	if (method === 'PUT' && client.put) return client.put(path, body);
	if (method === 'DELETE' && client.delete) return client.delete(path);
	throw new Error('client has no request method');
}

// 2) site-discover-ish: read types
try {
	const types = await rest('GET', '/wp/v2/types');
	record('content-types', !!types && typeof types === 'object', `keys=${Object.keys(types || {}).slice(0, 6).join(',')}`);
} catch (err) {
	record('content-types', false, err instanceof Error ? err.message : String(err));
}

// 3) pages list/create/update/delete
let pageId = null;
try {
	const pages = await rest('GET', '/wp/v2/pages?per_page=1');
	record('pages-list', Array.isArray(pages), `count=${Array.isArray(pages) ? pages.length : 0}`);
} catch (err) {
	record('pages-list', false, err instanceof Error ? err.message : String(err));
}

try {
	const created = await rest('POST', '/wp/v2/pages', {
		title: `Stonewright Direct E2E ${Date.now()}`,
		status: 'draft',
		content: '<!-- e2e direct -->',
	});
	pageId = created?.id ?? null;
	record('pages-create', Number.isInteger(pageId), `id=${pageId}`);
} catch (err) {
	record('pages-create', false, err instanceof Error ? err.message : String(err));
}

if (pageId) {
	try {
		const updated = await rest('POST', `/wp/v2/pages/${pageId}`, {
			content: '<!-- e2e direct updated -->',
		});
		record('pages-update', updated?.id === pageId, `id=${updated?.id}`);
	} catch (err) {
		record('pages-update', false, err instanceof Error ? err.message : String(err));
	}
}

// 4) settings read
try {
	const settings = await rest('GET', '/wp/v2/settings');
	record('settings-read', !!settings && typeof settings === 'object', `title=${settings?.title ?? ''}`);
} catch (err) {
	record('settings-read', false, err instanceof Error ? err.message : String(err));
}

// 5) global styles (FSE) — may 404 without block theme
try {
	const themes = await rest('GET', '/wp/v2/global-styles/themes');
	record('global-styles', true, `ok themes=${Array.isArray(themes) ? themes.length : 'obj'}`);
} catch (err) {
	const status = err?.status;
	record('global-styles', status === 404, `expected optional: ${err instanceof Error ? err.message : String(err)}`);
}

// 6) taxonomy create category
let termId = null;
try {
	const term = await rest('POST', '/wp/v2/categories', {
		name: `sw-e2e-${Date.now()}`,
	});
	termId = term?.id ?? null;
	record('taxonomy-create', Number.isInteger(termId), `id=${termId}`);
} catch (err) {
	record('taxonomy-create', false, err instanceof Error ? err.message : String(err));
}

// 7) menus — may need menus endpoint plugin; core nav menus REST is limited
try {
	const menus = await rest('GET', '/wp/v2/menus').catch(() => rest('GET', '/wp/v2/menu-locations'));
	record('menus-read', true, 'endpoint reachable');
	void menus;
} catch (err) {
	record('menus-read', false, err instanceof Error ? err.message : String(err));
}

// Cleanup
if (cleanup) {
	if (pageId) {
		try {
			await rest('DELETE', `/wp/v2/pages/${pageId}?force=true`);
			record('pages-delete', true, `id=${pageId}`);
		} catch (err) {
			record('pages-delete', false, err instanceof Error ? err.message : String(err));
		}
	}
	if (termId) {
		try {
			await rest('DELETE', `/wp/v2/categories/${termId}?force=true`);
			record('taxonomy-delete', true, `id=${termId}`);
		} catch (err) {
			record('taxonomy-delete', false, err instanceof Error ? err.message : String(err));
		}
	}
}

printSummary();
const failed = results.filter((r) => !r.ok && !r.name.startsWith('global-styles') && !r.name.startsWith('menus-'));
process.exit(failed.length ? 1 : 0);

function printSummary() {
	const pass = results.filter((r) => r.ok).length;
	const fail = results.filter((r) => !r.ok).length;
	console.log('\n--- Summary ---');
	console.log(`PASS ${pass} / FAIL ${fail} / TOTAL ${results.length}`);
	for (const r of results) {
		console.log(`${r.ok ? '✓' : '✗'} ${r.name}${r.detail ? ` — ${r.detail}` : ''}`);
	}
}
