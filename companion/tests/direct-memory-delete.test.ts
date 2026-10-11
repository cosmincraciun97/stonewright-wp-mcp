import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import './helpers/task-start.js';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { getMemory, recordMemory } from '../src/direct/memory-store.js';
import { memoryDelete, memoryList, type SelfImproveContext } from '../src/direct/tools/self-improve.js';
import {
	DIRECT_BOOTSTRAP_TOOL_NAMES,
	DIRECT_ESSENTIAL_TOOL_NAMES,
	DIRECT_TOOL_NAMES,
	shouldRegisterDirectTool,
} from '../src/direct/registry.js';
import { createMcpServer } from '../src/mcp-server.js';

const repoRoot = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const TOOL = 'stonewright-memory-delete';

let baseDir: string;
let previousState: string | undefined;

function ctx(env: Record<string, string> = {}): SelfImproveContext {
	return {
		env: { STONEWRIGHT_SITES_FILE: '/tmp/does-not-exist-sw-sites-memdel.json', ...env } as NodeJS.ProcessEnv,
		baseDir,
		directToolCount: 0,
	};
}

beforeEach(() => {
	baseDir = mkdtempSync(join(tmpdir(), 'sw-memdel-'));
	previousState = process.env['STONEWRIGHT_STATE_DIR'];
	process.env['STONEWRIGHT_STATE_DIR'] = baseDir;
});

afterEach(() => {
	if (previousState === undefined) delete process.env['STONEWRIGHT_STATE_DIR'];
	else process.env['STONEWRIGHT_STATE_DIR'] = previousState;
	rmSync(baseDir, { recursive: true, force: true });
});

describe('memory-delete', () => {
	it('removes a stored lesson once confirm is given, and says what it removed', () => {
		const lesson = recordMemory({ baseDir, scope: '_global', text: 'a wrong lesson' });
		const out = memoryDelete(ctx({ STONEWRIGHT_DIRECT_WRITES: 'on' }), { id: lesson.id, confirm: true });
		expect(out).toMatchObject({ deleted: true, id: lesson.id, backend: 'direct', memory_scope: '_global' });
		expect(getMemory({ baseDir, scope: '_global', id: lesson.id })).toBeNull();
		expect(memoryList(ctx()).items.some((row) => row.id === lesson.id)).toBe(false);
	});

	it('refuses without confirm, in confirm mode and in on mode, and keeps the lesson', () => {
		const lesson = recordMemory({ baseDir, scope: '_global', text: 'keep me' });
		expect(() => memoryDelete(ctx(), { id: lesson.id })).toThrow(/confirm:true/i);
		expect(() => memoryDelete(ctx({ STONEWRIGHT_DIRECT_WRITES: 'on' }), { id: lesson.id })).toThrow(/confirm:true/i);
		expect(() => memoryDelete(ctx({ STONEWRIGHT_DIRECT_WRITES: 'on' }), { id: lesson.id, confirm: false })).toThrow(/confirm:true/i);
		expect(getMemory({ baseDir, scope: '_global', id: lesson.id })?.text).toBe('keep me');
	});

	it('refuses when Direct writes are off', () => {
		const lesson = recordMemory({ baseDir, scope: '_global', text: 'keep me too' });
		expect(() => memoryDelete(ctx({ STONEWRIGHT_DIRECT_WRITES: 'off' }), { id: lesson.id, confirm: true })).toThrow(/disabled/i);
		expect(getMemory({ baseDir, scope: '_global', id: lesson.id })).not.toBeNull();
	});

	it('reports an unknown id and deletes nothing else', () => {
		const lesson = recordMemory({ baseDir, scope: '_global', text: 'still here' });
		expect(() => memoryDelete(ctx({ STONEWRIGHT_DIRECT_WRITES: 'on' }), { id: 'f'.repeat(16), confirm: true })).toThrow(/memory_not_found/);
		expect(getMemory({ baseDir, scope: '_global', id: lesson.id })).not.toBeNull();
	});

	it('writes an audit row that names the tool and the lesson, not its text', () => {
		const lesson = recordMemory({ baseDir, scope: '_global', text: 'a private sentence about the client' });
		memoryDelete(ctx({ STONEWRIGHT_DIRECT_WRITES: 'on' }), { id: lesson.id, confirm: true });
		const rows = readFileSync(join(baseDir, 'audit-direct.jsonl'), 'utf8').split('\n').filter(Boolean).map((line) => JSON.parse(line) as Record<string, unknown>);
		const row = rows.find((candidate) => candidate['tool'] === TOOL);
		expect(row).toMatchObject({ status: 'ok' });
		expect(String(row?.['resource'])).toContain(lesson.id);
		expect(JSON.stringify(rows)).not.toContain('private sentence');
	});
});

describe('memory-delete registration', () => {
	it('is a Direct tool on the full surface, not on the bootstrap or essential one, and in the frozen contract', () => {
		expect(DIRECT_TOOL_NAMES as readonly string[]).toContain(TOOL);
		expect(DIRECT_BOOTSTRAP_TOOL_NAMES as readonly string[]).not.toContain(TOOL);
		expect(DIRECT_ESSENTIAL_TOOL_NAMES as readonly string[]).not.toContain(TOOL);
		expect(shouldRegisterDirectTool(TOOL, 'full')).toBe(true);
		const contract = JSON.parse(readFileSync(join(repoRoot, 'docs', 'contracts', 'direct-tools-v1.json'), 'utf8')) as { tools: Array<{ name: string }> };
		expect(contract.tools.map((tool) => tool.name)).toContain(TOOL);
	});

	it('uses the Plugin ability name and its id argument, and asks for confirm', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'full',
				STONEWRIGHT_STATE_DIR: baseDir,
				STONEWRIGHT_WP_URL: 'http://example.test',
				STONEWRIGHT_WP_USERNAME: 'editor-user',
				STONEWRIGHT_WP_APP_PASSWORD: 'fixture-app-pass-0001',
				STONEWRIGHT_DIRECT_WRITES: 'on',
			},
			fetchImpl: (() => Promise.resolve(new Response('{}', { status: 404 }))) as never,
		});
		const tools = (server as { _registeredTools?: Record<string, { inputSchema?: { shape?: Record<string, unknown> }; description?: string; enabled?: boolean }> })._registeredTools ?? {};
		const tool = tools[TOOL];
		expect(tool?.enabled).toBe(true);
		expect(Object.keys(tool?.inputSchema?.shape ?? {}).sort()).toEqual(['confirm', 'global', 'id', 'site']);
		expect(tool?.description).toMatch(/confirm:true/);
		// The Plugin ability of the same name takes `id` too.
		const matrix = readFileSync(join(repoRoot, 'docs', 'ability-truth-matrix.md'), 'utf8');
		expect(matrix).toContain(`\`${TOOL}\``);
	});
});
