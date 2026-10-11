import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createMcpServer } from '../src/mcp-server.js';
import {
	isWriteTool,
	observeCorrection,
	resetCorrectionTriggerForTests,
	resourceKeyFor,
} from '../src/direct/correction-trigger.js';
import { makeFakeWp, pageRow } from './helpers/fake-wp.js';

const text = (body: unknown, isError = false) => ({
	...(isError ? { isError: true as const } : {}),
	content: [{ type: 'text' as const, text: JSON.stringify(body) }],
});
const parse = (result: { content: Array<{ text: string }> }) => JSON.parse(result.content[0]?.text ?? '{}') as Record<string, unknown>;

describe('correction trigger: which calls count', () => {
	it('tells write tools from reads', () => {
		for (const name of [
			'stonewright-content-update',
			'stonewright-template-part-update',
			'stonewright-global-styles-update',
			'stonewright-blocks-batch-mutate',
			'stonewright-elementor-data-update',
			'stonewright-blueprint-apply',
			'stonewright-plugin-activate',
		]) expect(isWriteTool(name), name).toBe(true);
		for (const name of [
			'stonewright-content-get',
			'stonewright-content-list',
			'stonewright-gutenberg-validate',
			'stonewright-learning-record',
			'stonewright-memory-delete',
			'stonewright-task-start',
			'stonewright-direct-backup-list',
			'stonewright-rest-request',
		]) expect(isWriteTool(name), name).toBe(false);
	});

	it('names the resource a call acts on, the same way across tools', () => {
		expect(resourceKeyFor('stonewright-content-update', { id: 12, type: 'pages' })).toBe('post:12');
		expect(resourceKeyFor('stonewright-blocks-batch-mutate', { post_id: 12 })).toBe('post:12');
		expect(resourceKeyFor('stonewright-elementor-data-update', { post_id: 12 })).toBe('post:12');
		expect(resourceKeyFor('stonewright-blueprint-apply', { id: 'agency', post_id: 12 })).toBe('post:12');
		expect(resourceKeyFor('stonewright-template-update', { id: 'theme//home' })).toBe('template:theme//home');
		expect(resourceKeyFor('stonewright-template-part-update', { id: 'theme//header' })).toBe('template-part:theme//header');
		expect(resourceKeyFor('stonewright-global-styles-update', {})).toBe('global-styles');
		expect(resourceKeyFor('stonewright-plugin-activate', { plugin: 'akismet/akismet' })).toBe('plugin:akismet/akismet');
	});

	it('has no resource for a create or a call without an id', () => {
		expect(resourceKeyFor('stonewright-content-create-page', { title: 'x' })).toBeNull();
		expect(resourceKeyFor('stonewright-blueprint-apply', { id: 'agency' })).toBeNull();
		expect(resourceKeyFor('stonewright-content-update', {})).toBeNull();
	});
});

describe('correction trigger: the offer', () => {
	beforeEach(() => resetCorrectionTriggerForTests());

	const call = (tool: string, input: Record<string, unknown>, result: ReturnType<typeof text>, event?: string) =>
		observeCorrection({ tool, site: 'https://example.com', input, result, ...(event ? { failureEventId: event } : {}) });

	it('offers once, on the call that fixes a resource whose write failed', () => {
		const failed = call('stonewright-content-update', { id: 4 }, text({ code: 'rest_cannot_update', status: 403 }, true), 'evt-1');
		expect(parse(failed)).not.toHaveProperty('learning_offer');
		const fixed = parse(call('stonewright-blocks-batch-mutate', { post_id: 4 }, text({ ok: true, post_id: 4 })));
		expect(fixed['ok']).toBe(true);
		expect(fixed['learning_offer']).toMatchObject({
			trigger: 'write_failed_then_succeeded',
			resource: 'post:4',
			earlier_failure: { tool: 'stonewright-content-update', code: 'rest_cannot_update' },
			fixed_by: { tool: 'stonewright-blocks-batch-mutate' },
			record_with: { tool: 'stonewright-learning-record', source: 'write-recovery' },
		});
		expect(JSON.stringify(fixed['learning_offer'])).toContain('evt-1');
		expect(String((fixed['learning_offer'] as Record<string, string>)['next'])).toMatch(/ask the user/i);
		// The offer is made once.
		const again = parse(call('stonewright-content-update', { id: 4 }, text({ ok: true })));
		expect(again).not.toHaveProperty('learning_offer');
	});

	it('counts a readback mismatch as a failure', () => {
		call('stonewright-content-update', { id: 4 }, text({ ok: false, error: 'verification_mismatch', written: true }));
		const fixed = parse(call('stonewright-content-update', { id: 4 }, text({ id: 4, verification: { status: 'verified' } })));
		expect(fixed['learning_offer']).toMatchObject({ earlier_failure: { code: 'verification_mismatch' } });
	});

	it('does not offer for another resource, another site or a call that did not succeed', () => {
		call('stonewright-content-update', { id: 4 }, text({ code: 'rest_cannot_update' }, true));
		expect(parse(call('stonewright-content-update', { id: 5 }, text({ id: 5 })))).not.toHaveProperty('learning_offer');
		const otherSite = observeCorrection({ tool: 'stonewright-content-update', site: 'https://other.example.com', input: { id: 4 }, result: text({ id: 4 }) });
		expect(parse(otherSite)).not.toHaveProperty('learning_offer');
		expect(parse(call('stonewright-content-update', { id: 4 }, text({ ok: false, error: 'frontend_probe_failed' })))).not.toHaveProperty('learning_offer');
		// Still pending for the right call.
		expect(parse(call('stonewright-content-update', { id: 4 }, text({ id: 4 })))).toHaveProperty('learning_offer');
	});

	it('ignores refusals: a blocked call, a custom-code refusal, a missing argument', () => {
		call('stonewright-content-update', { id: 4 }, text({ ok: false, error: 'confirmation_required', blocked: true }, true));
		call('stonewright-content-update', { id: 4 }, text({ supported: false, error: 'custom_code_approval_required', approval_required: true, written: false }));
		call('stonewright-content-update', { id: 4 }, text({ ok: false, error: 'invalid_arguments' }, true));
		expect(parse(call('stonewright-content-update', { id: 4 }, text({ id: 4 })))).not.toHaveProperty('learning_offer');
	});

	it('a dry run or a read is not a fix', () => {
		call('stonewright-blocks-batch-mutate', { post_id: 4 }, text({ ok: false, error: 'content_conflict' }));
		expect(parse(call('stonewright-blocks-batch-mutate', { post_id: 4, dry_run: true }, text({ ok: true, dry_run: true })))).not.toHaveProperty('learning_offer');
		expect(parse(call('stonewright-content-get', { id: 4 }, text({ id: 4 })))).not.toHaveProperty('learning_offer');
	});

	it('a call that wrote nothing is not a fix', () => {
		call('stonewright-blocks-batch-mutate', { post_id: 4 }, text({ ok: false, error: 'content_conflict' }));
		expect(parse(call('stonewright-blocks-batch-mutate', { post_id: 4 }, text({ ok: true, written: false, unchanged: true })))).not.toHaveProperty('learning_offer');
		expect(parse(call('stonewright-blocks-batch-mutate', { post_id: 4 }, text({ ok: true, written: true })))).toHaveProperty('learning_offer');
	});

	it('keeps the answer as it was when there is nothing to offer', () => {
		const result = text({ id: 9, title: 'x' });
		expect(call('stonewright-content-update', { id: 9 }, result)).toBe(result);
	});

	it('forgets a failure after twelve hours', () => {
		vi.useFakeTimers();
		try {
			vi.setSystemTime(new Date('2026-10-11T08:00:00Z'));
			call('stonewright-content-update', { id: 4 }, text({ code: 'rest_cannot_update' }, true));
			vi.setSystemTime(new Date('2026-10-11T21:00:00Z'));
			expect(parse(call('stonewright-content-update', { id: 4 }, text({ id: 4 })))).not.toHaveProperty('learning_offer');
		} finally {
			vi.useRealTimers();
		}
	});
});

describe('correction trigger through the MCP server', () => {
	let stateDir: string;
	const saved = process.env['STONEWRIGHT_STATE_DIR'];

	beforeEach(() => {
		resetCorrectionTriggerForTests();
		stateDir = mkdtempSync(join(tmpdir(), 'sw-correction-'));
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
		// The write tools read these from the process environment.
		process.env['STONEWRIGHT_DIRECT_REQUIRE_TASK_START'] = 'off';
		process.env['STONEWRIGHT_DIRECT_PROBE'] = 'off';
	});

	afterEach(() => {
		if (saved === undefined) delete process.env['STONEWRIGHT_STATE_DIR'];
		else process.env['STONEWRIGHT_STATE_DIR'] = saved;
		delete process.env['STONEWRIGHT_DIRECT_REQUIRE_TASK_START'];
		delete process.env['STONEWRIGHT_DIRECT_PROBE'];
		rmSync(stateDir, { recursive: true, force: true });
	});

	type Tool = { handler?: (input: unknown) => Promise<{ isError?: boolean; content: Array<{ text: string }> }> };

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
		return (server as { _registeredTools?: Record<string, Tool> })._registeredTools ?? {};
	}

	async function call(tools: Record<string, Tool>, name: string, input: unknown) {
		const result = await tools[name]?.handler?.(input);
		return { isError: result?.isError, body: JSON.parse(result?.content[0]?.text ?? '{}') as Record<string, unknown> };
	}

	it('offers to record the lesson when a rejected write is fixed on the same page', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
		let reject = true;
		const fetchImpl = ((url: Parameters<typeof fetch>[0], init?: RequestInit) => {
			if (reject && init?.method === 'PUT') {
				reject = false;
				return Promise.resolve(new Response(JSON.stringify({ code: 'rest_cannot_update', message: 'Sorry', data: { status: 403 } }), { status: 403, headers: { 'content-type': 'application/json' } }));
			}
			return wp.fetchImpl(url, init) as Promise<Response>;
		}) as typeof fetch;
		const tools = await connect(fetchImpl);

		const failed = await call(tools, 'stonewright-content-update', { id: 4, content: '<p>a</p>' });
		expect(failed.isError).toBe(true);
		expect(failed.body).not.toHaveProperty('learning_offer');

		const fixed = await call(tools, 'stonewright-content-update', { id: 4, content: '<p>b</p>' });
		expect(fixed.isError, JSON.stringify(fixed.body)).toBeUndefined();
		expect(fixed.body['learning_offer']).toMatchObject({
			trigger: 'write_failed_then_succeeded',
			resource: 'post:4',
			earlier_failure: { tool: 'stonewright-content-update' },
			record_with: { tool: 'stonewright-learning-record' },
		});
		// The failure event is cited so the lesson can point at the audit row.
		expect(JSON.stringify(fixed.body['learning_offer'])).toMatch(/[0-9a-f]{8}-[0-9a-f]{4}-/);
	});

	it('offers after a readback mismatch that a later write corrects', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
		const tools = await connect(wp.fetchImpl as never);
		wp.sanitize = (_path, body) => ({ ...body, content: '<p>stripped</p>' });
		const first = await call(tools, 'stonewright-content-update', { id: 4, content: '<p>a</p>' });
		expect(first.body).toMatchObject({ ok: false, error: 'verification_mismatch' });
		delete wp.sanitize;
		const second = await call(tools, 'stonewright-content-update', { id: 4, content: '<p>b</p>' });
		expect(second.body['learning_offer']).toMatchObject({ earlier_failure: { code: 'verification_mismatch' } });
	});

	it('makes no offer for a write that never failed', async () => {
		const wp = makeFakeWp({ '/wp/v2/pages/4': pageRow(4) });
		const tools = await connect(wp.fetchImpl as never);
		const out = await call(tools, 'stonewright-content-update', { id: 4, content: '<p>a</p>' });
		expect(out.body).not.toHaveProperty('learning_offer');
	});
});
