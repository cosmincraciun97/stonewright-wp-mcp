import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
	elementorDataGet,
	elementorDataUpdate,
	type ElementorRestClient,
} from '../src/direct/tools/elementor-direct.js';
import { markTaskStartSeen, resetTaskStartSeenForTests } from '../src/direct/writes.js';
import { resetConfirmationsForTests } from '../src/direct/confirm-token.js';
import { runWithDirectCallContext } from '../src/direct/plugin-presence.js';
import { sha256Text, stableJson } from '../src/direct/verify.js';
import type { WpCliCommandResult } from '../src/wp-cli.js';

type Tree = Array<Record<string, unknown>>;

const widget = (id: string, widgetType = 'heading', settings: Record<string, unknown> = { title: 'Hi' }) => ({
	id,
	elType: 'widget',
	widgetType,
	settings,
	elements: [],
});
const container = (id: string, elements: unknown[] = [], settings: Record<string, unknown> = {}) => ({
	id,
	elType: 'container',
	settings,
	elements,
});

const V3: Tree = [container('c1', [widget('w1')])];
const V4: Tree = [
	{
		id: 'd1',
		elType: 'e-div-block',
		settings: {},
		elements: [widget('h1', 'e-heading', { title: { $$type: 'string', value: 'Hi' } })],
	},
];

const hashOf = (tree: unknown): string => sha256Text(stableJson(tree));

function unavailableCli() {
	return vi.fn(
		(input: { command: string[] }): Promise<WpCliCommandResult> =>
			Promise.resolve({
				ok: false,
				available: false,
				command: input.command,
				cwd: '/tmp',
				stdout: '',
				stderr: '',
				exit_code: 1,
				duration_ms: 1,
				wp_cli_source: 'test',
			}),
	);
}

function restFor(url: string, alias: string, stored: unknown) {
	const meta: Record<string, unknown> = { _elementor_data: JSON.stringify(stored), _elementor_edit_mode: 'builder' };
	const post = vi.fn((_path: string, opts?: { body?: { meta?: { _elementor_data?: string } } }) => {
		if (opts?.body?.meta?._elementor_data) meta['_elementor_data'] = opts.body.meta._elementor_data;
		return Promise.resolve({ id: 7, meta });
	});
	const rest: ElementorRestClient = {
		target: { alias, url },
		get: vi.fn((path: string) => (path.includes('/pages/7') ? Promise.resolve({ id: 7, meta }) : Promise.reject(new Error('not found')))) as never,
		post: post as never,
	};
	return { rest, post, meta };
}

const LOCAL = { alias: 'local', url: 'http://example.test' };
const REMOTE = { alias: 'remote', url: 'https://example.com' };

describe('elementor-data-update: structural checks', () => {
	let stateDir: string;
	let env: NodeJS.ProcessEnv;

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-el-guard-'));
		env = { STONEWRIGHT_STATE_DIR: stateDir };
		markTaskStartSeen(LOCAL);
		resetConfirmationsForTests();
	});
	afterEach(() => {
		resetTaskStartSeenForTests();
		resetConfirmationsForTests();
		rmSync(stateDir, { recursive: true, force: true });
	});

	const run = (data: unknown, stored: unknown = V3) => {
		const fixture = restFor(LOCAL.url, LOCAL.alias, stored);
		const result = elementorDataUpdate(env, { post_id: 7, data: data as never }, unavailableCli() as never, fixture.rest);
		return { ...fixture, result };
	};

	const invalid: Array<[string, unknown, string]> = [
		['duplicate id at the same level', [container('c1'), container('c1')], 'stonewright_elementor_integrity_duplicate_id'],
		['duplicate id in a nested element', [container('c1', [widget('w1'), widget('w1')])], 'stonewright_elementor_integrity_duplicate_id'],
		['duplicate id between root and a child', [container('c1', [widget('c1')])], 'stonewright_elementor_integrity_duplicate_id'],
		['a widget at the document root', [widget('w9')], 'stonewright_elementor_integrity_widget_at_root'],
		['a widget directly inside a widget', [container('c1', [{ ...widget('w1'), elements: [widget('w2')] }])], 'stonewright_elementor_integrity_widget_in_widget'],
		['an inner-section element type', [container('c1', [{ id: 'i1', elType: 'inner-section', settings: {}, elements: [] }])], 'stonewright_elementor_integrity_invalid_element_type'],
		['a missing elType', [{ id: 'x1', settings: {}, elements: [] }], 'stonewright_elementor_integrity_missing_eltype'],
		['a widget without widgetType', [container('c1', [{ id: 'w1', elType: 'widget', settings: {}, elements: [] }])], 'stonewright_elementor_integrity_missing_widget_type'],
		['settings that are a list with items', [container('c1', [widget('w1', 'heading', ['a'] as never)])], 'stonewright_elementor_integrity_invalid_settings'],
		['settings that are a string', [container('c1', [{ ...widget('w1'), settings: 'title=Hi' }])], 'stonewright_elementor_integrity_invalid_settings'],
		['settings that are null', [container('c1', [{ ...widget('w1'), settings: null }])], 'stonewright_elementor_integrity_invalid_settings'],
		[
			'a colour value that carries CSS',
			[container('c1', [widget('w1', 'heading', { title: 'Hi', title_color: 'red;} body{display:none' })])],
			'stonewright_elementor_integrity_unsafe_value',
		],
		[
			'a nested colour value that carries CSS',
			[container('c1', [widget('w1', 'heading', { title: 'Hi', typography: { text_shadow_color: '#000;}</style>' } })])],
			'stonewright_elementor_integrity_unsafe_value',
		],
	];

	it.each(invalid)('refuses %s and sends nothing', async (_name, tree, code) => {
		const { result, post } = run(tree);
		await expect(result).rejects.toThrow(new RegExp(code));
		expect(post).not.toHaveBeenCalled();
	});

	it('accepts the shapes real Elementor documents use', async () => {
		const tree = [
			{ id: 'a1', elType: 'section', settings: [], elements: [{ id: 'col1', elType: 'column', settings: [], elements: [widget('w1', 'heading', { title: 'x', title_color: '#112233' })] }] },
			container('c2', [widget('w2', 'nested-tabs')]),
		];
		const { result, post } = run(tree);
		await expect(result).resolves.toMatchObject({ ok: true });
		expect(post).toHaveBeenCalledTimes(1);
	});

	it('numeric ids count as ids, so 5 and "5" collide', async () => {
		const { result } = run([container('5', [{ ...widget('w1'), id: 5 }])]);
		await expect(result).rejects.toThrow(/duplicate_id/);
	});

	it('keeps a widget the stored document already holds at its root', async () => {
		const legacy: Tree = [widget('w1'), container('c1')];
		const { result } = run([widget('w1', 'heading', { title: 'Edited' }), container('c1')], legacy);
		await expect(result).resolves.toMatchObject({ ok: true });
		const { result: added } = run([widget('w1'), widget('w2'), container('c1')], legacy);
		await expect(added).rejects.toThrow(/widget_at_root/);
	});

	it('refuses a tree over the size cap', async () => {
		const big = [container('c1', [widget('w1', 'heading', { title: 'x'.repeat(4 * 1024 * 1024 + 10) })])];
		const { result, post } = run(big);
		await expect(result).rejects.toThrow(/stonewright_elementor_integrity_too_large/);
		expect(post).not.toHaveBeenCalled();
	});

	it('refuses a tree with too many elements or too deep', async () => {
		const many = [container('c1', Array.from({ length: 5001 }, (_v, i) => widget(`w${i}`)))];
		await expect(run(many).result).rejects.toThrow(/stonewright_elementor_integrity_too_large/);
		let deep: Record<string, unknown> = container('d0');
		for (let i = 1; i < 40; i += 1) deep = container(`d${i}`, [deep]);
		await expect(run([deep]).result).rejects.toThrow(/stonewright_elementor_integrity_too_deep/);
	});
});

describe('elementor-data: tree hash', () => {
	let stateDir: string;
	let env: NodeJS.ProcessEnv;

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-el-hash-'));
		env = { STONEWRIGHT_STATE_DIR: stateDir };
		markTaskStartSeen(LOCAL);
		resetConfirmationsForTests();
	});
	afterEach(() => {
		resetTaskStartSeenForTests();
		resetConfirmationsForTests();
		rmSync(stateDir, { recursive: true, force: true });
	});

	it('data-get returns the canonical hash in summary and full mode; key order does not change it', async () => {
		const reordered = [{ elements: [widget('w1')], settings: {}, elType: 'container', id: 'c1' }];
		const a = restFor(LOCAL.url, LOCAL.alias, V3);
		const b = restFor(LOCAL.url, LOCAL.alias, reordered);
		const summary = await elementorDataGet(env, { post_id: 7 }, unavailableCli() as never, a.rest);
		const full = await elementorDataGet(env, { post_id: 7, responseMode: 'full' }, unavailableCli() as never, b.rest);
		expect((summary as { hash?: string }).hash).toBe(hashOf(V3));
		expect((full as { hash?: string }).hash).toBe(hashOf(V3));
		expect((summary as { hash?: string }).hash).toMatch(/^[0-9a-f]{64}$/);
	});

	it('a different tree has a different hash', async () => {
		const a = restFor(LOCAL.url, LOCAL.alias, V3);
		const b = restFor(LOCAL.url, LOCAL.alias, [container('c1', [widget('w1', 'heading', { title: 'Other' })])]);
		const one = (await elementorDataGet(env, { post_id: 7 }, unavailableCli() as never, a.rest)) as { hash: string };
		const two = (await elementorDataGet(env, { post_id: 7 }, unavailableCli() as never, b.rest)) as { hash: string };
		expect(one.hash).not.toBe(two.hash);
	});

	it('knownHash that still matches answers unchanged, without an outline', async () => {
		const { rest } = restFor(LOCAL.url, LOCAL.alias, V3);
		const same = (await elementorDataGet(env, { post_id: 7, knownHash: hashOf(V3) } as never, unavailableCli() as never, rest)) as Record<string, unknown>;
		expect(same).toMatchObject({ ok: true, unchanged: true, hash: hashOf(V3) });
		expect(same['outline']).toBeUndefined();
		const stale = (await elementorDataGet(env, { post_id: 7, knownHash: 'f'.repeat(64) } as never, unavailableCli() as never, rest)) as Record<string, unknown>;
		expect(stale['unchanged']).toBe(false);
		expect(stale['outline']).toBeDefined();
	});

	it('update refuses a stale expected_hash with a conflict and sends nothing', async () => {
		const { rest, post } = restFor(LOCAL.url, LOCAL.alias, V3);
		const next = [container('c1', [widget('w1', 'heading', { title: 'New' })])];
		const result = (await elementorDataUpdate(
			env,
			{ post_id: 7, data: next, expected_hash: 'a'.repeat(64) } as never,
			unavailableCli() as never,
			rest,
		)) as Record<string, unknown>;
		expect(result).toMatchObject({ ok: false, error: 'revision_conflict', expected_hash: 'a'.repeat(64), current_hash: hashOf(V3) });
		expect(String(result['message'])).toMatch(/elementor-data-get/);
		expect(post).not.toHaveBeenCalled();
	});

	it('update with the current hash writes and returns the previous and the new hash', async () => {
		const { rest, post } = restFor(LOCAL.url, LOCAL.alias, V3);
		const next = [container('c1', [widget('w1', 'heading', { title: 'New' })])];
		const result = (await elementorDataUpdate(
			env,
			{ post_id: 7, data: next, expected_hash: hashOf(V3) } as never,
			unavailableCli() as never,
			rest,
		)) as Record<string, unknown>;
		expect(result).toMatchObject({ ok: true, previous_hash: hashOf(V3), hash: hashOf(next) });
		expect(post).toHaveBeenCalledTimes(1);
	});

	it('expected_hash is optional: an update without it still works and still reports the hashes', async () => {
		const { rest } = restFor(LOCAL.url, LOCAL.alias, V3);
		const next = [container('c1', [widget('w1', 'heading', { title: 'New' })])];
		const result = (await elementorDataUpdate(env, { post_id: 7, data: next }, unavailableCli() as never, rest)) as Record<string, unknown>;
		expect(result).toMatchObject({ ok: true, previous_hash: hashOf(V3), hash: hashOf(next) });
	});

	it('a restore is not held to expected_hash or to the structure rules', async () => {
		const stored = [container('c1', [widget('w1')])];
		const old = [widget('w9'), widget('w9')];
		const { rest } = restFor(LOCAL.url, LOCAL.alias, stored);
		const result = (await elementorDataUpdate(
			env,
			{ post_id: 7, data: old as never, confirm: true, force_destructive: true, allow_widget_type_remap: true },
			unavailableCli() as never,
			rest,
			{ restore: { tool: 'stonewright-direct-backup-restore', from: 'post-7-x.json' } },
		)) as Record<string, unknown>;
		expect(result['ok']).toBe(true);
	});
});

describe('elementor-data-get: arguments and content', () => {
	let stateDir: string;
	let env: NodeJS.ProcessEnv;
	const tree = [
		container('c1', [widget('w1', 'heading', { title: 'First', link: { url: 'https://example.com' } }), widget('w2', 'text-editor', { editor: '<p>Body</p>' })]),
		container('c2', [widget('w3', 'image', { image: { url: 'data:image/png;base64,' + 'A'.repeat(1000), data: 'AAAA', id: 5 } })]),
	];

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-el-get-'));
		env = { STONEWRIGHT_STATE_DIR: stateDir };
	});
	afterEach(() => {
		rmSync(stateDir, { recursive: true, force: true });
	});

	it('max_nodes is an alias of maxElements', async () => {
		const { rest } = restFor(LOCAL.url, LOCAL.alias, tree);
		const result = (await elementorDataGet(env, { post_id: 7, max_nodes: 2 } as never, unavailableCli() as never, rest)) as { returned_count: number; truncated: boolean; count: number };
		expect(result.returned_count).toBe(2);
		expect(result.truncated).toBe(true);
		expect(result.count).toBe(5);
	});

	it('response_mode is an alias of responseMode', async () => {
		const { rest } = restFor(LOCAL.url, LOCAL.alias, tree);
		const result = (await elementorDataGet(env, { post_id: 7, response_mode: 'full' } as never, unavailableCli() as never, rest)) as { response_mode: string; data: unknown };
		expect(result.response_mode).toBe('full');
		expect(result.data).toEqual(tree);
	});

	it('root_id limits the outline to that subtree; the hash stays the whole document', async () => {
		const { rest } = restFor(LOCAL.url, LOCAL.alias, tree);
		const result = (await elementorDataGet(env, { post_id: 7, root_id: 'c2' } as never, unavailableCli() as never, rest)) as { outline: Array<{ id: string; parent_id: string | null }>; count: number; hash: string; root_id: string };
		expect(result.outline.map((row) => row.id)).toEqual(['c2', 'w3']);
		expect(result.count).toBe(2);
		expect(result.root_id).toBe('c2');
		expect(result.hash).toBe(hashOf(tree));
		const alias = (await elementorDataGet(env, { post_id: 7, element_id: 'w1' } as never, unavailableCli() as never, rest)) as { outline: Array<{ id: string }> };
		expect(alias.outline.map((row) => row.id)).toEqual(['w1']);
	});

	it('root_id in full mode returns that element only', async () => {
		const { rest } = restFor(LOCAL.url, LOCAL.alias, tree);
		const result = (await elementorDataGet(env, { post_id: 7, root_id: 'c2', responseMode: 'full' } as never, unavailableCli() as never, rest)) as { data: unknown };
		expect(result.data).toEqual([tree[1]]);
	});

	it('an unknown root_id is a clear error', async () => {
		const { rest } = restFor(LOCAL.url, LOCAL.alias, tree);
		const result = (await elementorDataGet(env, { post_id: 7, root_id: 'nope' } as never, unavailableCli() as never, rest)) as { ok: boolean; error: string };
		expect(result).toMatchObject({ ok: false });
		expect(result.error).toMatch(/nope/);
	});

	it('include_content returns bounded settings per node and never a binary body', async () => {
		const { rest } = restFor(LOCAL.url, LOCAL.alias, tree);
		const result = (await elementorDataGet(env, { post_id: 7, include_content: true } as never, unavailableCli() as never, rest)) as {
			nodes: Array<{ id: string; elType: string; widgetType: string; settings: Record<string, unknown> }>;
			tree_omitted: boolean;
		};
		expect(result.tree_omitted).toBe(true);
		expect(result.nodes.map((n) => n.id)).toEqual(['c1', 'w1', 'w2', 'c2', 'w3']);
		expect(result.nodes[1]?.settings).toMatchObject({ title: 'First' });
		const image = result.nodes[4]?.settings['image'] as { url: string; data?: unknown; id: number };
		expect(image.url.length).toBeLessThanOrEqual(256);
		expect(image.data).toBeUndefined();
		expect(image.id).toBe(5);
	});

	it('reports the architecture of the stored document', async () => {
		const v3 = (await elementorDataGet(env, { post_id: 7 }, unavailableCli() as never, restFor(LOCAL.url, LOCAL.alias, V3).rest)) as { architecture: string };
		const v4 = (await elementorDataGet(env, { post_id: 7 }, unavailableCli() as never, restFor(LOCAL.url, LOCAL.alias, V4).rest)) as { architecture: string };
		const mixed = (await elementorDataGet(env, { post_id: 7 }, unavailableCli() as never, restFor(LOCAL.url, LOCAL.alias, [...V3, ...V4]).rest)) as { architecture: string };
		expect(v3.architecture).toBe('v3');
		expect(v4.architecture).toBe('v4');
		expect(mixed.architecture).toBe('mixed');
	});
});

describe('elementor-data-update: Atomic (V4) documents', () => {
	let stateDir: string;
	let env: NodeJS.ProcessEnv;

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-el-v4-'));
		env = { STONEWRIGHT_STATE_DIR: stateDir };
		markTaskStartSeen(LOCAL);
		resetConfirmationsForTests();
	});
	afterEach(() => {
		resetTaskStartSeenForTests();
		resetConfirmationsForTests();
		rmSync(stateDir, { recursive: true, force: true });
	});

	it('refuses a whole-tree write onto a V4 document and names Plugin mode', async () => {
		const { rest, post } = restFor(LOCAL.url, LOCAL.alias, V4);
		const edited = [{ ...V4[0], settings: { classes: { $$type: 'classes', value: ['a'] } } }];
		await expect(elementorDataUpdate(env, { post_id: 7, data: edited as never }, unavailableCli() as never, rest)).rejects.toThrow(
			/stonewright_elementor_atomic_raw_write_blocked.*Plugin mode/s,
		);
		expect(post).not.toHaveBeenCalled();
	});

	it('refuses a mixed document', async () => {
		const { rest, post } = restFor(LOCAL.url, LOCAL.alias, [...V3, ...V4]);
		await expect(elementorDataUpdate(env, { post_id: 7, data: [...V3, ...V4] as never }, unavailableCli() as never, rest)).rejects.toThrow(/atomic_raw_write_blocked/);
		expect(post).not.toHaveBeenCalled();
	});

	it('refuses a V3 page that is being given Atomic content', async () => {
		const { rest, post } = restFor(LOCAL.url, LOCAL.alias, V3);
		await expect(elementorDataUpdate(env, { post_id: 7, data: V4 as never }, unavailableCli() as never, rest)).rejects.toThrow(/atomic_raw_write_blocked/);
		expect(post).not.toHaveBeenCalled();
	});

	it('refuses a $$type envelope inside a V3 element', async () => {
		const { rest } = restFor(LOCAL.url, LOCAL.alias, V3);
		const data = [container('c1', [widget('w1', 'heading', { title: { $$type: 'string', value: 'Hi' } })])];
		await expect(elementorDataUpdate(env, { post_id: 7, data: data as never }, unavailableCli() as never, rest)).rejects.toThrow(/atomic_raw_write_blocked/);
	});

	it('allow_atomic_raw needs confirm, then writes and lists the override', async () => {
		const { rest, post } = restFor(LOCAL.url, LOCAL.alias, V4);
		const edited = [{ ...V4[0], settings: { classes: { $$type: 'classes', value: ['a'] } } }];
		await expect(
			elementorDataUpdate(env, { post_id: 7, data: edited as never, allow_atomic_raw: true } as never, unavailableCli() as never, rest),
		).rejects.toMatchObject({ code: 'confirmation_required' });
		expect(post).not.toHaveBeenCalled();
		const result = (await elementorDataUpdate(
			env,
			{ post_id: 7, data: edited as never, allow_atomic_raw: true, confirm: true } as never,
			unavailableCli() as never,
			rest,
		)) as Record<string, unknown>;
		expect(result).toMatchObject({ ok: true, overrides_used: ['allow_atomic_raw'] });
		expect(post).toHaveBeenCalledTimes(1);
	});

	it('allow_atomic_raw does not switch off the structure checks', async () => {
		const { rest } = restFor(LOCAL.url, LOCAL.alias, V4);
		const broken = [V4[0], V4[0]];
		await expect(
			elementorDataUpdate(env, { post_id: 7, data: broken as never, allow_atomic_raw: true, confirm: true } as never, unavailableCli() as never, rest),
		).rejects.toThrow(/duplicate_id/);
	});

	it('atomic root elements are allowed when the override is used', async () => {
		const { rest } = restFor(LOCAL.url, LOCAL.alias, V4);
		const result = await elementorDataUpdate(env, { post_id: 7, data: V4 as never, allow_atomic_raw: true, confirm: true } as never, unavailableCli() as never, rest);
		expect(result).toMatchObject({ ok: true });
	});
});

describe('elementor-data-update: overrides are confirmed, audited and listed', () => {
	let stateDir: string;
	let env: NodeJS.ProcessEnv;
	const bigPrevious: Tree = [container('c1', Array.from({ length: 40 }, (_v, i) => widget(`w${i}`, 'heading', { title: `Title number ${i} with some text to take space` })))];
	const small: Tree = [container('c1', [widget('w0')])];

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-el-ovr-'));
		env = { STONEWRIGHT_STATE_DIR: stateDir };
		markTaskStartSeen(LOCAL);
		markTaskStartSeen(REMOTE);
		resetConfirmationsForTests();
	});
	afterEach(() => {
		resetTaskStartSeenForTests();
		resetConfirmationsForTests();
		rmSync(stateDir, { recursive: true, force: true });
	});

	const auditRows = (): Array<Record<string, unknown>> => {
		const path = join(stateDir, 'audit-direct.jsonl');
		if (!existsSync(path)) return [];
		return readFileSync(path, 'utf8')
			.split('\n')
			.filter((line) => line.trim() !== '')
			.map((line) => JSON.parse(line) as Record<string, unknown>);
	};

	it('without the override the size collapse is still refused (gate unchanged)', async () => {
		const { rest, post } = restFor(LOCAL.url, LOCAL.alias, bigPrevious);
		await expect(elementorDataUpdate(env, { post_id: 7, data: small }, unavailableCli() as never, rest)).rejects.toThrow(/size_collapse/);
		expect(post).not.toHaveBeenCalled();
	});

	it('force_destructive without confirm is refused even where writes need no confirmation', async () => {
		const { rest, post } = restFor(LOCAL.url, LOCAL.alias, bigPrevious);
		await expect(
			elementorDataUpdate(env, { post_id: 7, data: small, force_destructive: true }, unavailableCli() as never, rest),
		).rejects.toMatchObject({ code: 'confirmation_required' });
		expect(post).not.toHaveBeenCalled();
	});

	it('allow_widget_type_remap without confirm is refused', async () => {
		const { rest, post } = restFor(LOCAL.url, LOCAL.alias, V3);
		const remapped = [container('c1', [widget('w1', 'text-editor', { editor: 'Hi' })])];
		await expect(
			elementorDataUpdate(env, { post_id: 7, data: remapped, allow_widget_type_remap: true }, unavailableCli() as never, rest),
		).rejects.toMatchObject({ code: 'confirmation_required' });
		expect(post).not.toHaveBeenCalled();
	});

	it('with confirm the write goes through, lists the overrides used, and is audited', async () => {
		const { rest, post } = restFor(LOCAL.url, LOCAL.alias, bigPrevious);
		const result = (await elementorDataUpdate(
			env,
			{ post_id: 7, data: small, force_destructive: true, confirm: true },
			unavailableCli() as never,
			rest,
		)) as Record<string, unknown>;
		expect(result).toMatchObject({ ok: true, overrides_used: ['force_destructive'] });
		expect(String(JSON.stringify(result['guidance']))).toMatch(/force_destructive/);
		expect(post).toHaveBeenCalledTimes(1);
		const rows = auditRows().filter((row) => row['event_type'] === 'direct_elementor_override_used' || row['eventType'] === 'direct_elementor_override_used');
		expect(rows).toHaveLength(1);
		expect(JSON.stringify(rows[0])).toContain('force_destructive');
		expect(JSON.stringify(rows[0])).toContain('post:7');
	});

	it('both overrides are listed together, in a stable order', async () => {
		const { rest } = restFor(LOCAL.url, LOCAL.alias, [container('c1', [widget('w1'), ...Array.from({ length: 40 }, (_v, i) => widget(`x${i}`, 'heading', { title: `Padding text number ${i} for size` }))])]);
		const result = (await elementorDataUpdate(
			env,
			{ post_id: 7, data: [container('c1', [widget('w1', 'text-editor', { editor: 'Hi' })])], force_destructive: true, allow_widget_type_remap: true, confirm: true },
			unavailableCli() as never,
			rest,
		)) as Record<string, unknown>;
		expect(result['overrides_used']).toEqual(['force_destructive', 'allow_widget_type_remap']);
	});

	it('a call that sets an override flag but does not need it lists nothing', async () => {
		const { rest } = restFor(LOCAL.url, LOCAL.alias, V3);
		const next = [container('c1', [widget('w1', 'heading', { title: 'Changed' })])];
		const result = (await elementorDataUpdate(env, { post_id: 7, data: next, force_destructive: true, confirm: true }, unavailableCli() as never, rest)) as Record<string, unknown>;
		expect(result['ok']).toBe(true);
		// The flag was asked for, so it is confirmed and audited; the result says it was asked for and was not needed.
		expect(result['overrides_used']).toEqual([]);
		expect(result['overrides_requested']).toEqual(['force_destructive']);
	});

	it('on a remote site the override needs an id bound to the same arguments', async () => {
		const { rest, post } = restFor(REMOTE.url, REMOTE.alias, bigPrevious);
		const args = { post_id: 7, data: small, force_destructive: true };
		let issued: string | undefined;
		try {
			await runWithDirectCallContext({ tool: 'stonewright-elementor-data-update', confirm: false, args }, () =>
				elementorDataUpdate(env, args, unavailableCli() as never, rest),
			);
		} catch (error) {
			expect((error as { code?: string }).code).toBe('confirmation_required');
			issued = (error as { details?: Record<string, unknown> }).details?.['confirmation_id'] as string;
		}
		expect(issued).toMatch(/^[0-9a-f]{32}$/);
		expect(post).not.toHaveBeenCalled();

		// Plain confirm:true is not enough on a remote site.
		await expect(
			runWithDirectCallContext({ tool: 'stonewright-elementor-data-update', confirm: true, args }, () =>
				elementorDataUpdate(env, { ...args, confirm: true }, unavailableCli() as never, rest),
			),
		).rejects.toMatchObject({ code: 'confirmation_required' });

		// The id works for the same arguments only.
		const changed = { ...args, data: [container('c1', [widget('w0', 'heading', { title: 'Other' })])] };
		await expect(
			runWithDirectCallContext({ tool: 'stonewright-elementor-data-update', confirm: false, args: changed, confirmationId: issued as string }, () =>
				elementorDataUpdate(env, changed, unavailableCli() as never, rest),
			),
		).rejects.toMatchObject({ code: 'confirmation_invalid' });
		const ok = await runWithDirectCallContext({ tool: 'stonewright-elementor-data-update', confirm: false, args, confirmationId: issued as string }, () =>
			elementorDataUpdate(env, args, unavailableCli() as never, rest),
		);
		expect(ok).toMatchObject({ ok: true, overrides_used: ['force_destructive'] });
		expect(post).toHaveBeenCalledTimes(1);
	});
});
