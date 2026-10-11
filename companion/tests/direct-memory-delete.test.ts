import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { runWithDirectCallContext } from '../src/direct/plugin-presence.js';
import { DirectSafetyBlockedError } from '../src/direct/writes.js';
import { resetConfirmationsForTests } from '../src/direct/confirm-token.js';
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

/** A plain confirm:true counts here, as it does on a local site. */
const LEGACY = { STONEWRIGHT_DIRECT_CONFIRM_LEGACY: 'on' };

beforeEach(() => {
	resetConfirmationsForTests();
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
		const out = memoryDelete(ctx({ STONEWRIGHT_DIRECT_WRITES: 'on', ...LEGACY }), { id: lesson.id, confirm: true });
		expect(out).toMatchObject({ deleted: true, id: lesson.id, backend: 'direct', memory_scope: '_global' });
		expect(getMemory({ baseDir, scope: '_global', id: lesson.id })).toBeNull();
		expect(memoryList(ctx()).items.some((row) => row.id === lesson.id)).toBe(false);
	});

	it('refuses without confirm, in confirm mode and in on mode, and keeps the lesson', () => {
		const lesson = recordMemory({ baseDir, scope: '_global', text: 'keep me' });
		expect(() => memoryDelete(ctx(LEGACY), { id: lesson.id })).toThrow(/confirmation/i);
		expect(() => memoryDelete(ctx({ STONEWRIGHT_DIRECT_WRITES: 'on', ...LEGACY }), { id: lesson.id })).toThrow(/confirmation/i);
		expect(() => memoryDelete(ctx({ STONEWRIGHT_DIRECT_WRITES: 'on', ...LEGACY }), { id: lesson.id, confirm: false })).toThrow(/confirmation/i);
		expect(getMemory({ baseDir, scope: '_global', id: lesson.id })?.text).toBe('keep me');
	});

	it('does not take a plain confirm:true where that no longer counts, and hands out a confirmation_id', () => {
		const lesson = recordMemory({ baseDir, scope: '_global', text: 'needs a bound confirmation' });
		let refused: unknown;
		try {
			memoryDelete(ctx({ STONEWRIGHT_DIRECT_WRITES: 'on' }), { id: lesson.id, confirm: true });
		} catch (error) {
			refused = error;
		}
		expect(refused).toBeInstanceOf(DirectSafetyBlockedError);
		expect((refused as DirectSafetyBlockedError).code).toBe('confirmation_required');
		expect(String((refused as DirectSafetyBlockedError).details['confirmation_id'])).toMatch(/^[a-f0-9]{32}$/);
		expect(getMemory({ baseDir, scope: '_global', id: lesson.id })).not.toBeNull();
	});

	it('deletes on the repeated call with its confirmation_id, once, and for that lesson only', () => {
		const lesson = recordMemory({ baseDir, scope: '_global', text: 'delete me with an id' });
		const other = recordMemory({ baseDir, scope: '_global', text: 'a different lesson' });
		const env = { STONEWRIGHT_DIRECT_WRITES: 'confirm' };
		const call = (id: string, confirmationId?: string) =>
			runWithDirectCallContext(
				{ tool: TOOL, confirm: false, args: { id, ...(confirmationId ? { confirmation_id: confirmationId } : {}) }, confirmationId, env: ctx(env).env },
				() => memoryDelete(ctx(env), { id }),
			);
		let refused: DirectSafetyBlockedError | undefined;
		try {
			call(lesson.id);
		} catch (error) {
			refused = error as DirectSafetyBlockedError;
		}
		const confirmationId = String(refused?.details['confirmation_id']);
		// Another lesson is another call: the id does not fit it.
		expect(() => call(other.id, confirmationId)).toThrow(/different call|confirmation_id/i);
		expect(getMemory({ baseDir, scope: '_global', id: other.id })).not.toBeNull();
		expect(call(lesson.id, confirmationId)).toMatchObject({ deleted: true, id: lesson.id });
		expect(getMemory({ baseDir, scope: '_global', id: lesson.id })).toBeNull();
		// Used once.
		expect(() => call(lesson.id, confirmationId)).toThrow(/confirmation_id/i);
	});

	it('refuses when Direct writes are off', () => {
		const lesson = recordMemory({ baseDir, scope: '_global', text: 'keep me too' });
		expect(() => memoryDelete(ctx({ STONEWRIGHT_DIRECT_WRITES: 'off', ...LEGACY }), { id: lesson.id, confirm: true })).toThrow(/disabled/i);
		expect(getMemory({ baseDir, scope: '_global', id: lesson.id })).not.toBeNull();
	});

	it('reports an unknown id and deletes nothing else', () => {
		const lesson = recordMemory({ baseDir, scope: '_global', text: 'still here' });
		expect(() => memoryDelete(ctx({ STONEWRIGHT_DIRECT_WRITES: 'on', ...LEGACY }), { id: 'f'.repeat(16), confirm: true })).toThrow(/memory_not_found/);
		expect(getMemory({ baseDir, scope: '_global', id: lesson.id })).not.toBeNull();
	});

	it('writes an audit row that names the tool and the lesson, not its text', () => {
		const lesson = recordMemory({ baseDir, scope: '_global', text: 'a private sentence about the client' });
		memoryDelete(ctx({ STONEWRIGHT_DIRECT_WRITES: 'on', ...LEGACY }), { id: lesson.id, confirm: true });
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

	it('uses the Plugin ability name and its id argument, and takes a confirmation', async () => {
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
		expect(Object.keys(tool?.inputSchema?.shape ?? {}).sort()).toEqual(['confirm', 'confirmation_id', 'global', 'id', 'site']);
		expect(tool?.description).toMatch(/confirmation_id/);
		// The Plugin ability of the same name takes `id` too.
		const matrix = readFileSync(join(repoRoot, 'docs', 'ability-truth-matrix.md'), 'utf8');
		expect(matrix).toContain(`\`${TOOL}\``);
	});
});
