import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createMcpServer } from '../src/mcp-server.js';
import {
	DIRECT_BOOTSTRAP_TOOL_NAMES,
	DIRECT_ESSENTIAL_TOOL_NAMES,
	DIRECT_TOOL_NAMES,
	DIRECT_WAVE6_TOOL_NAMES,
	shouldRegisterDirectTool,
	type DirectToolProfile,
} from '../src/direct/registry.js';
import { makeFakeWp, pageRow } from './helpers/fake-wp.js';

const repoRoot = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const LIST = 'stonewright-direct-backup-list';
const RESTORE = 'stonewright-direct-backup-restore';

type RegisteredTool = {
	inputSchema?: { shape?: Record<string, unknown> };
	description?: string;
	enabled?: boolean;
	handler?: (input: unknown) => Promise<{ isError?: boolean; content: Array<{ text: string }> }>;
};

describe('Direct backup tools: names and profiles', () => {
	it('uses names that cannot be mistaken for the Plugin abilities', () => {
		const matrix = readFileSync(join(repoRoot, 'docs', 'ability-truth-matrix.md'), 'utf8');
		// The plugin has theme-backup-restore, site-backup-page, change-restore and rescue-*, and its
		// receipts name stonewright/backup-restore. A plain stonewright-backup-* name would collide.
		expect(matrix).toMatch(/stonewright\/theme-backup-restore/);
		expect(matrix).toMatch(/stonewright\/site-backup-page/);
		expect(DIRECT_TOOL_NAMES as readonly string[]).not.toContain('stonewright-backup-list');
		expect(DIRECT_TOOL_NAMES as readonly string[]).not.toContain('stonewright-backup-restore');
		for (const name of DIRECT_WAVE6_TOOL_NAMES) {
			expect(matrix, name).not.toContain(name);
			expect(matrix, name).not.toContain(name.replace('stonewright-', 'stonewright/'));
		}
		expect([...DIRECT_WAVE6_TOOL_NAMES]).toEqual([LIST, RESTORE]);
		expect(DIRECT_TOOL_NAMES).toContain(LIST);
		expect(DIRECT_TOOL_NAMES).toContain(RESTORE);
	});

	it('keeps them out of the bootstrap and essential surfaces and puts them on the profiles that write', () => {
		for (const name of [LIST, RESTORE]) {
			expect(DIRECT_BOOTSTRAP_TOOL_NAMES as readonly string[]).not.toContain(name);
			expect(DIRECT_ESSENTIAL_TOOL_NAMES as readonly string[]).not.toContain(name);
			const profiles: DirectToolProfile[] = ['bootstrap', 'essential', 'essential-static', 'elementor-design', 'content-model', 'gutenberg', 'site-admin', 'full'];
			const on = profiles.filter((profile) => shouldRegisterDirectTool(name, profile));
			expect(on, name).toEqual(['elementor-design', 'content-model', 'gutenberg', 'site-admin', 'full']);
		}
	});

	it('is part of the frozen Direct contract', () => {
		const contract = JSON.parse(readFileSync(join(repoRoot, 'docs', 'contracts', 'direct-tools-v1.json'), 'utf8')) as { tools: Array<{ name: string }> };
		const names = contract.tools.map((tool) => tool.name);
		expect(names).toContain(LIST);
		expect(names).toContain(RESTORE);
	});
});

describe('Direct backup tools through the MCP server', () => {
	let stateDir: string;
	const saved = process.env['STONEWRIGHT_STATE_DIR'];

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-backup-tools-'));
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
		process.env['STONEWRIGHT_DIRECT_PROBE'] = 'off';
		// The write tools read the task-start rule from the process environment.
		process.env['STONEWRIGHT_DIRECT_REQUIRE_TASK_START'] = 'off';
	});

	afterEach(() => {
		if (saved === undefined) delete process.env['STONEWRIGHT_STATE_DIR'];
		else process.env['STONEWRIGHT_STATE_DIR'] = saved;
		delete process.env['STONEWRIGHT_DIRECT_PROBE'];
		delete process.env['STONEWRIGHT_DIRECT_REQUIRE_TASK_START'];
		rmSync(stateDir, { recursive: true, force: true });
	});

	async function connect(fetchImpl: typeof fetch) {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'full',
				STONEWRIGHT_STATE_DIR: stateDir,
				STONEWRIGHT_WP_URL: 'http://example.test',
				STONEWRIGHT_WP_USERNAME: 'editor-user',
				STONEWRIGHT_WP_APP_PASSWORD: 'fixture-app-pass-0001',
				STONEWRIGHT_DIRECT_WRITES: 'on',
				STONEWRIGHT_DIRECT_REQUIRE_TASK_START: 'off',
				STONEWRIGHT_DIRECT_PROBE: 'off',
			},
			fetchImpl,
		});
		return (server as { _registeredTools?: Record<string, RegisteredTool> })._registeredTools ?? {};
	}

	async function call(tools: Record<string, RegisteredTool>, name: string, input: unknown) {
		const result = await tools[name]?.handler?.(input);
		return { isError: result?.isError, body: JSON.parse(result?.content[0]?.text ?? '{}') as Record<string, unknown> };
	}

	it('registers both tools, enabled on the full surface, with the documented arguments', async () => {
		const wp = makeFakeWp({});
		const tools = await connect(wp.fetchImpl as never);
		expect(tools[LIST]?.enabled).toBe(true);
		expect(tools[RESTORE]?.enabled).toBe(true);
		expect(Object.keys(tools[RESTORE]?.inputSchema?.shape ?? {}).sort()).toEqual(['backup_id', 'confirm', 'dry_run', 'expected_sha256', 'site']);
		expect(Object.keys(tools[LIST]?.inputSchema?.shape ?? {}).sort()).toEqual(['limit', 'resource', 'site', 'tool']);
		expect(tools[RESTORE]?.description).toMatch(/confirm:true/);
		expect(tools['stonewright-content-update']?.description).toMatch(/stonewright-direct-backup-restore/);
		for (const name of ['stonewright-content-update', 'stonewright-template-update', 'stonewright-template-part-update', 'stonewright-blueprint-apply']) {
			expect(Object.keys(tools[name]?.inputSchema?.shape ?? {}), name).toEqual(expect.arrayContaining(['probe', 'rollback_on_probe_failure']));
		}
	});

	it('writes, lists and restores end to end, and refuses the restore without confirm', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
		const tools = await connect(wp.fetchImpl as never);
		const written = await call(tools, 'stonewright-content-update', { id: 4, content: '<p>new</p>' });
		expect(written.isError).toBeUndefined();
		expect((written.body['verification'] as Record<string, unknown>)['status']).toBe('verified');
		const id = String(written.body['backup_id']);

		const listed = await call(tools, LIST, { resource: 'pages-4' });
		expect((listed.body['items'] as Array<Record<string, unknown>>)[0]).toMatchObject({ backup_id: id, integrity: 'ok' });

		const refused = await call(tools, RESTORE, { backup_id: id });
		expect(refused.isError).toBe(true);
		expect(refused.body).toMatchObject({ error: 'confirmation_required', blocked: true });
		expect(wp.store.get('/wp/v2/pages/4')).toMatchObject({ content: { raw: '<p>new</p>' } });

		const restored = await call(tools, RESTORE, { backup_id: id, confirm: true });
		expect(restored.body).toMatchObject({ ok: true, restored: true });
		expect(wp.store.get('/wp/v2/pages/4')).toMatchObject({ content: { raw: '<p>old 4</p>' } });
	});

	it('returns a readback mismatch as ok:false through the tool, with the restore hint', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
		wp.sanitize = (_path, body) => ({ ...body, content: '<p>stripped</p>' });
		const tools = await connect(wp.fetchImpl as never);
		const written = await call(tools, 'stonewright-content-update', { id: 4, content: '<p>new</p>' });
		expect(written.body).toMatchObject({ ok: false, error: 'verification_mismatch', written: true });
		expect(written.body['restore_with']).toMatchObject({ tool: RESTORE, needs: 'confirm:true' });
		expect(JSON.stringify(written.body['restore_with'])).not.toContain('"confirm":true');
	});

	it('rejects an id that is not a backup before any request', async () => {
		const wp = makeFakeWp({});
		const tools = await connect(wp.fetchImpl as never);
		const out = await call(tools, RESTORE, { backup_id: '../../audit-direct.jsonl', confirm: true });
		expect(out.isError).toBe(true);
		expect(out.body).toMatchObject({ error: 'backup_not_found' });
		expect(wp.calls).toHaveLength(0);
	});
});
