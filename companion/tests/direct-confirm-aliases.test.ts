import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createMcpServer } from '../src/mcp-server.js';
import { resetConfirmationsForTests } from '../src/direct/confirm-token.js';
import { makeFakeWp, type Row } from './helpers/fake-wp.js';

type Registered = {
	handler?: (input: unknown) => Promise<{ isError?: boolean; content: Array<{ text: string }> }>;
};

const MENU = '/wp/v2/menus/3';
const menuRow: Row = { id: 3, name: 'Main', slug: 'main' };

describe('aliases, bound confirmation and readback together', () => {
	let stateDir: string;

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-confirm-alias-'));
		resetConfirmationsForTests();
		// The write tools read the task-start rule from the process environment.
		process.env['STONEWRIGHT_DIRECT_REQUIRE_TASK_START'] = 'off';
	});
	afterEach(() => {
		delete process.env['STONEWRIGHT_DIRECT_REQUIRE_TASK_START'];
		rmSync(stateDir, { recursive: true, force: true });
	});

	async function connect(wp: ReturnType<typeof makeFakeWp>) {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'full',
				STONEWRIGHT_STATE_DIR: stateDir,
				STONEWRIGHT_WP_URL: 'https://example.com',
				STONEWRIGHT_WP_USERNAME: 'editor-user',
				STONEWRIGHT_WP_APP_PASSWORD: 'fixture-app-pass-0001',
				STONEWRIGHT_DIRECT_WRITES: 'confirm',
				STONEWRIGHT_DIRECT_REQUIRE_TASK_START: 'off',
			},
			fetchImpl: wp.fetchImpl as never,
		});
		const tools = (server as unknown as { _registeredTools?: Record<string, Registered> })._registeredTools ?? {};
		return async (input: Record<string, unknown>) => {
			const result = await tools['stonewright-menu-delete']?.handler?.(input);
			return { isError: result?.isError, body: JSON.parse(result?.content[0]?.text ?? '{}') as Record<string, unknown> };
		};
	}

	it('binds the id to the arguments as Direct reads them: either spelling of the argument works', async () => {
		const wp = makeFakeWp({ [MENU]: menuRow });
		const call = await connect(wp);
		const sent = () => wp.calls.filter((c) => c.method === 'DELETE').length;

		// The plugin's spelling is refused for lack of a confirmation, and nothing is deleted.
		const refused = await call({ menu_id: 3 });
		expect(refused.isError).toBe(true);
		expect(refused.body).toMatchObject({ error: 'confirmation_required', blocked: true });
		// The summary names the argument by Direct's name, not the alias.
		expect(String(refused.body['summary'])).toContain('id=3');
		expect(String(refused.body['summary'])).not.toContain('menu_id');
		const id = String(refused.body['confirmation_id']);
		expect(sent()).toBe(0);

		// A different menu is not covered by the id.
		const other = await call({ id: 4, confirmation_id: id });
		expect(other.body).toMatchObject({ error: 'confirmation_invalid', reason: 'different_call' });
		expect(sent()).toBe(0);

		// Direct's spelling of the same call uses it, once, and the write is read back.
		const done = await call({ id: 3, confirmation_id: id });
		expect(done.isError).toBeUndefined();
		expect(done.body['verification']).toMatchObject({ status: 'verified' });
		expect(sent()).toBe(1);
		expect(wp.store.has(MENU)).toBe(false);

		// Single use.
		const again = await call({ id: 3, confirmation_id: id });
		expect(again.body).toMatchObject({ error: 'confirmation_invalid', reason: 'unknown' });
		expect(sent()).toBe(1);
	});

	it('an id issued for one spelling is accepted with the other', async () => {
		const wp = makeFakeWp({ [MENU]: menuRow });
		const call = await connect(wp);
		const refused = await call({ id: 3 });
		const id = String(refused.body['confirmation_id']);
		const done = await call({ menu_id: 3, confirmation_id: id });
		expect(done.isError).toBeUndefined();
		expect(done.body['verification']).toMatchObject({ status: 'verified' });
		expect(wp.store.has(MENU)).toBe(false);
	});

	it('conflicting spellings are refused before any confirmation is issued', async () => {
		const wp = makeFakeWp({ [MENU]: menuRow });
		const call = await connect(wp);
		const out = await call({ menu_id: 3, id: 4 });
		expect(out.isError).toBe(true);
		expect(out.body['error']).toBe('argument_alias_conflict');
		expect(out.body['confirmation_id']).toBeUndefined();
		expect(wp.calls.filter((c) => c.method === 'DELETE')).toHaveLength(0);
	});
});
