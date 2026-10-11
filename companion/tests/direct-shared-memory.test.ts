import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { existsSync, mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { appendDirectAudit } from '../src/direct/audit.js';
import { resolveSiteMemoryScope, siteMemoryKey } from '../src/direct/memory-scope.js';
import { listMemory, recordMemory } from '../src/direct/memory-store.js';
import { forgetPluginPresence, rememberPluginPresence } from '../src/direct/plugin-presence.js';
import {
	LEARNING_RECORD_CORE_KEYS,
	TASK_START_CORE_KEYS,
	learningRecord,
	learningRecordAuthoritative,
	memoryList,
	taskStart,
	taskStartAuthoritative,
	type SelfImproveContext,
} from '../src/direct/tools/self-improve.js';
import type { SitesConfig } from '../src/direct/sites-config.js';

const SITE_A = 'https://a.example.test';
const SITE_B = 'https://b.example.test';

function json(body: unknown, status = 200): Response {
	return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
}

describe('shared state: memory is keyed by site identity', () => {
	let base: string;
	beforeEach(() => {
		base = mkdtempSync(join(tmpdir(), 'sw-shared-mem-'));
		forgetPluginPresence();
	});
	afterEach(() => {
		forgetPluginPresence();
		rmSync(base, { recursive: true, force: true });
	});

	const memoryFiles = () => (existsSync(join(base, 'memory')) ? readdirSync(join(base, 'memory')).sort() : []);

	it('the key follows the canonical URL, not the alias', () => {
		const key = siteMemoryKey('https://A.Example.test/');
		expect(key).toBe(siteMemoryKey('https://a.example.test'));
		expect(key).toMatch(/^a\.example\.test-[0-9a-f]{10}$/);
		expect(key).not.toBe(siteMemoryKey('https://b.example.test'));
		expect(key).not.toBe(siteMemoryKey('https://a.example.test/blog'));
		expect(key).toMatch(/^[a-z0-9_][a-z0-9_.-]{0,63}$/);
	});

	it('adopts an alias-keyed file once, copying its entries and leaving the old file alone', () => {
		const a = recordMemory({ baseDir: base, scope: 'client', text: 'Use the staging menu first' });
		const b = recordMemory({ baseDir: base, scope: 'client', text: 'Prices include tax', kind: 'fact' });
		const legacyBefore = readFileSync(join(base, 'memory', 'client.jsonl'), 'utf8');

		const first = resolveSiteMemoryScope({ baseDir: base, alias: 'client', siteUrl: SITE_A });
		expect(first.memoryScope).toBe(siteMemoryKey(SITE_A));
		expect(first.legacyScope).toBe('client');
		const adopted = listMemory({ baseDir: base, scope: first.memoryScope }).items;
		expect(adopted.map((e) => e.id).sort()).toEqual([a.id, b.id].sort());
		expect(adopted.find((e) => e.id === a.id)?.ts).toBe(a.ts);
		expect(readFileSync(join(base, 'memory', 'client.jsonl'), 'utf8')).toBe(legacyBefore);
		expect(memoryFiles()).toContain('_site-bindings.json');

		// Resolving again adds nothing.
		resolveSiteMemoryScope({ baseDir: base, alias: 'client', siteUrl: SITE_A });
		expect(listMemory({ baseDir: base, scope: first.memoryScope }).items).toHaveLength(2);
	});

	it('does not hand an old alias file to another site once the alias is pointed elsewhere', () => {
		recordMemory({ baseDir: base, scope: 'client', text: 'Belongs to site A' });
		resolveSiteMemoryScope({ baseDir: base, alias: 'client', siteUrl: SITE_A });

		const moved = resolveSiteMemoryScope({ baseDir: base, alias: 'client', siteUrl: SITE_B });
		expect(moved.memoryScope).toBe(siteMemoryKey(SITE_B));
		expect(moved.legacyScope).toBeNull();
		expect(listMemory({ baseDir: base, scope: moved.memoryScope }).items).toEqual([]);
		// Site A keeps what it was given, and the old file is still on disk.
		expect(listMemory({ baseDir: base, scope: siteMemoryKey(SITE_A) }).items).toHaveLength(1);
		expect(existsSync(join(base, 'memory', 'client.jsonl'))).toBe(true);
		// Pointing the alias back at A reads the old file again.
		expect(resolveSiteMemoryScope({ baseDir: base, alias: 'client', siteUrl: SITE_A }).legacyScope).toBe('client');
	});

	it('creates nothing for an alias that has no old file', () => {
		const result = resolveSiteMemoryScope({ baseDir: base, alias: 'fresh', siteUrl: SITE_A });
		expect(result.legacyScope).toBeNull();
		expect(memoryFiles()).toEqual([]);
	});

	describe('through the Direct tools', () => {
		function ctxFor(aliases: Record<string, string>): SelfImproveContext {
			const sites: SitesConfig['sites'] = {};
			for (const [alias, url] of Object.entries(aliases)) {
				sites[alias] = { url, username: 'admin', appPassword: 'fixture-pass-0001' };
			}
			return {
				env: { HOME: base, STONEWRIGHT_DIRECT_WRITES: 'on' },
				baseDir: base,
				sitesConfig: { default: Object.keys(aliases)[0] ?? 'one', source: 'env', sites },
			};
		}

		it('learning-record writes the site-keyed file and memory-list reads it back', () => {
			const ctx = ctxFor({ one: SITE_A });
			const saved = learningRecord(ctx, { topic: 'Menus', correction: 'Rebuild the menu after a theme switch' }) as Record<string, unknown>;
			const key = siteMemoryKey(SITE_A);
			expect(saved['storage_scope']).toBe(key);
			expect(String(saved['storage_ref'])).toContain(`memory/${key}.jsonl#`);
			expect(existsSync(join(base, 'memory', `${key}.jsonl`))).toBe(true);
			expect(existsSync(join(base, 'memory', 'one.jsonl'))).toBe(false);

			const listed = memoryList(ctx, {});
			expect(listed.items.map((e) => e.text)).toEqual(['Rebuild the menu after a theme switch']);
			expect(listed).toMatchObject({ backend: 'direct', memory_backend: 'direct-site-local', scope: 'one', memory_scope: key });
			expect(listed).not.toHaveProperty('plugin_detected');
		});

		it('two aliases for one site share its memory, and a re-pointed alias does not inherit', () => {
			learningRecord(ctxFor({ first: SITE_A }), { topic: 'T', correction: 'Shared lesson for site A' });
			expect(memoryList(ctxFor({ second: SITE_A }), {}).items.map((e) => e.text)).toEqual(['Shared lesson for site A']);
			// The alias "first" now points at site B: nothing carries over.
			expect(memoryList(ctxFor({ first: SITE_B }), {}).items).toEqual([]);
		});

		it('keeps reading an alias-keyed file while the alias still points at the same site', () => {
			recordMemory({ baseDir: base, scope: 'one', text: 'Written by an older companion' });
			const ctx = ctxFor({ one: SITE_A });
			expect(memoryList(ctx, {}).items.map((e) => e.text)).toEqual(['Written by an older companion']);
			// An older companion keeps writing to its file; the lesson is still listed, once.
			recordMemory({ baseDir: base, scope: 'one', text: 'Written later by the same older companion' });
			const texts = memoryList(ctx, {}).items.map((e) => e.text);
			expect(texts).toHaveLength(2);
			expect(new Set(texts).size).toBe(2);
		});

		it('memory-list says it read local files, and notes it when the plugin is active on the site', () => {
			const ctx = ctxFor({ one: SITE_A });
			rememberPluginPresence(SITE_A, true);
			const hybrid = memoryList(ctx, {}) as Record<string, unknown>;
			expect(hybrid['backend']).toBe('direct');
			expect(hybrid['memory_backend']).toBe('direct-site-local');
			expect(hybrid['plugin_detected']).toBe(true);
			expect(String(hybrid['note'])).toContain("companion's local memory only");
			rememberPluginPresence(SITE_A, false);
			expect(memoryList(ctx, {})).not.toHaveProperty('note');
		});

		it('memory-list pages with offset', () => {
			const ctx = ctxFor({ one: SITE_A });
			for (const n of [1, 2, 3]) learningRecord(ctx, { topic: `T${n}`, correction: `Lesson number ${n}` });
			expect(memoryList(ctx, { limit: 2, offset: 0 }).items).toHaveLength(2);
			expect(memoryList(ctx, { limit: 2, offset: 2 }).items).toHaveLength(1);
		});
	});
});

describe('shared state: task-start and learning-record share a core shape', () => {
	let base: string;
	beforeEach(() => {
		base = mkdtempSync(join(tmpdir(), 'sw-shared-core-'));
		forgetPluginPresence();
	});
	afterEach(() => {
		forgetPluginPresence();
		rmSync(base, { recursive: true, force: true });
	});

	function ctxFor(fetchImpl?: typeof fetch): SelfImproveContext {
		return {
			env: { HOME: base, STONEWRIGHT_DIRECT_WRITES: 'on' },
			baseDir: base,
			sitesConfig: {
				default: 'one',
				source: 'env',
				sites: { one: { url: SITE_A, username: 'admin', appPassword: 'fixture-pass-0001' } },
			},
			...(fetchImpl ? { fetchImpl } : {}),
		};
	}

	it('a local task-start carries every core key and says backend direct', () => {
		const result = taskStart(ctxFor(), { task: 'update the home page' }) as Record<string, unknown>;
		for (const key of TASK_START_CORE_KEYS) expect(result, key).toHaveProperty(key);
		expect(result['ok']).toBe(true);
		expect(result['backend']).toBe('direct');
		expect(result['mode']).toBe('direct');
		const target = result['target_context'] as Record<string, unknown>;
		expect(result['context_token']).toBe(target['context_token']);
		expect(result['expires_at']).toBe(target['expires_at']);
		// Backend-specific extras stay.
		for (const key of ['matched_skills', 'memory_highlights', 'recurring_errors', 'incident_actions', 'setup', 'capabilities']) {
			expect(result, key).toHaveProperty(key);
		}
	});

	it('a task-start answered by the plugin carries the same core keys and says backend plugin', async () => {
		const fetchImpl = (() =>
			Promise.resolve(
				json({
					ok: true,
					context_token: 'plugin-token',
					expires_at: '2030-01-01T00:00:00Z',
					mode: 'production-safe',
					fast_path: { tool_profile: 'full' },
					target_context: { site_fingerprint: 'abc', backend: 'plugin' },
				}),
			)) as unknown as typeof fetch;
		const result = (await taskStartAuthoritative(ctxFor(fetchImpl), { task: 'edit' })) as Record<string, unknown>;
		for (const key of TASK_START_CORE_KEYS) expect(result, key).toHaveProperty(key);
		expect(result['backend']).toBe('plugin');
		expect(result['mode']).toBe('direct');
		expect(result['plugin_mode']).toBe('production-safe');
		expect(result['ok']).toBe(true);
		expect(result['context_token']).toBe('plugin-token');
		expect(result['hard_rules']).toHaveProperty('digest');
		// The plugin's own extras are kept.
		expect(result).toHaveProperty('fast_path');
	});

	it('a local learning-record carries every core key and says backend direct', () => {
		const result = learningRecord(ctxFor(), { topic: 'T', correction: 'A lesson for the core shape' }) as Record<string, unknown>;
		for (const key of LEARNING_RECORD_CORE_KEYS) expect(result, key).toHaveProperty(key);
		expect(result['backend']).toBe('direct');
		expect(typeof result['memory_id']).toBe('string');
		expect(typeof result['memory_key']).toBe('string');
	});

	it('a learning-record stored by the plugin carries the same core keys', async () => {
		const fetchImpl = ((input: Parameters<typeof fetch>[0]) => {
			if (String(input).endsWith('/direct/task-start')) {
				return Promise.resolve(json({ ok: true, context_token: 'plugin-token', expires_at: '2099-01-01T00:00:00Z', mode: 'development' }));
			}
			// A bare answer: only what the plugin stored.
			return Promise.resolve(json({ memory_id: 41, verified: true }));
		}) as unknown as typeof fetch;
		const ctx = ctxFor(fetchImpl);
		await taskStartAuthoritative(ctx, { task: 'edit' });
		const result = (await learningRecordAuthoritative(ctx, { topic: 'T', correction: 'Stored by the plugin' })) as Record<string, unknown>;
		for (const key of LEARNING_RECORD_CORE_KEYS) expect(result, key).toHaveProperty(key);
		expect(result['backend']).toBe('plugin');
		expect(result['ok']).toBe(true);
		expect(result['stored']).toBe(true);
		expect(result['verified']).toBe(true);
		expect(result['memory_id']).toBe(41);
		expect(result['memory_key']).toBe('41');
		expect(result['memory_backend']).toBe('plugin-site');
	});

	it('task-start lists the recurring errors of its own site only', () => {
		const audit = join(base, 'audit-direct.jsonl');
		for (const [site, tool] of [
			[SITE_A, 'stonewright-content-update'],
			[SITE_A, 'stonewright-content-update'],
			[SITE_B, 'stonewright-menu-create'],
			[SITE_B, 'stonewright-menu-create'],
		] as const) {
			appendDirectAudit({ tool, site, targetIdentity: site, status: 'error', error: `${tool} failed` }, audit);
		}
		const result = taskStart(ctxFor(), { task: 'anything' }) as { recurring_errors: Array<{ tool: string }> };
		expect(result.recurring_errors.map((row) => row.tool)).toEqual(['stonewright-content-update']);
	});
});
