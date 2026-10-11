import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { elementorDataUpdate, type ElementorRestClient } from '../src/direct/tools/elementor-direct.js';
import { DirectSafetyBlockedError, markTaskStartSeen, resetTaskStartSeenForTests, resolveDirectWriteMode } from '../src/direct/writes.js';
import { runWithDirectCallContext } from '../src/direct/plugin-presence.js';
import type { WpCliCommandResult } from '../src/wp-cli.js';

const TREE = [{ id: 'abc', elType: 'container', elements: [] }];
const NEXT = [
	{ id: 'abc', elType: 'container', elements: [{ id: 'w1', elType: 'widget', widgetType: 'heading', settings: { title: 'Hi' }, elements: [] }] },
];

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

function restFor(url: string, alias: string) {
	const meta: Record<string, unknown> = { _elementor_data: JSON.stringify(TREE), _elementor_edit_mode: 'builder' };
	const post = vi.fn((_path: string, opts?: { body?: { meta?: { _elementor_data?: string } } }) => {
		if (opts?.body?.meta?._elementor_data) meta['_elementor_data'] = opts.body.meta._elementor_data;
		return Promise.resolve({ id: 7, meta });
	});
	const rest: ElementorRestClient = {
		target: { alias, url },
		get: vi.fn((path: string) => (path.includes('/pages/7') ? Promise.resolve({ id: 7, meta }) : Promise.reject(new Error('not found')))) as never,
		post: post as never,
	};
	return { rest, post };
}

describe('elementor-data-update resolves its write mode from the target site', () => {
	let stateDir: string;

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-el-mode-'));
	});
	afterEach(() => {
		resetTaskStartSeenForTests();
		rmSync(stateDir, { recursive: true, force: true });
	});

	it('uses the same mode as the other Direct tools for a local .test site: no confirm needed', async () => {
		const target = { alias: 'local', url: 'http://example.test' };
		expect(resolveDirectWriteMode({}, target.url)).toBe('on');
		markTaskStartSeen(target);
		const { rest, post } = restFor(target.url, target.alias);
		const result = await elementorDataUpdate({ STONEWRIGHT_STATE_DIR: stateDir }, { post_id: 7, data: NEXT }, unavailableCli() as never, rest);
		expect(result).toMatchObject({ ok: true, transport: 'rest' });
		expect(post).toHaveBeenCalled();
	});

	it('uses confirm mode for a remote site: the write needs confirm:true and sends nothing without it', async () => {
		const target = { alias: 'remote', url: 'https://example.com' };
		expect(resolveDirectWriteMode({}, target.url)).toBe('confirm');
		markTaskStartSeen(target);
		const { rest, post } = restFor(target.url, target.alias);
		await expect(
			elementorDataUpdate({ STONEWRIGHT_STATE_DIR: stateDir }, { post_id: 7, data: NEXT }, unavailableCli() as never, rest),
		).rejects.toMatchObject({ code: 'confirmation_required' });
		expect(post).not.toHaveBeenCalled();
		// A plain confirm:true is not a confirmation on a remote site any more; the refusal returns an id.
		await expect(
			elementorDataUpdate({ STONEWRIGHT_STATE_DIR: stateDir }, { post_id: 7, data: NEXT, confirm: true }, unavailableCli() as never, rest),
		).rejects.toMatchObject({ code: 'confirmation_required' });
		expect(post).not.toHaveBeenCalled();
		const args = { post_id: 7, data: NEXT };
		const refused = await runWithDirectCallContext({ tool: 'stonewright-elementor-data-update', confirm: false, args }, () =>
			elementorDataUpdate({ STONEWRIGHT_STATE_DIR: stateDir }, args, unavailableCli() as never, rest),
		).catch((error: unknown) => error as DirectSafetyBlockedError);
		const id = (refused as DirectSafetyBlockedError).details['confirmation_id'] as string;
		const confirmed = await runWithDirectCallContext({ tool: 'stonewright-elementor-data-update', confirm: false, args, confirmationId: id }, () =>
			elementorDataUpdate({ STONEWRIGHT_STATE_DIR: stateDir }, args, unavailableCli() as never, rest),
		);
		expect(confirmed).toMatchObject({ ok: true });
	});

	it('the legacy switch keeps a plain confirm:true working on a remote site', async () => {
		const target = { alias: 'remote', url: 'https://example.com' };
		markTaskStartSeen(target);
		const { rest } = restFor(target.url, target.alias);
		const confirmed = await elementorDataUpdate(
			{ STONEWRIGHT_STATE_DIR: stateDir, STONEWRIGHT_DIRECT_CONFIRM_LEGACY: 'on' },
			{ post_id: 7, data: NEXT, confirm: true },
			unavailableCli() as never,
			rest,
		);
		expect(confirmed).toMatchObject({ ok: true });
	});

	it('an explicit STONEWRIGHT_DIRECT_WRITES still wins over the site', async () => {
		const target = { alias: 'local', url: 'http://example.test' };
		markTaskStartSeen(target);
		const { rest, post } = restFor(target.url, target.alias);
		await expect(
			elementorDataUpdate(
				{ STONEWRIGHT_STATE_DIR: stateDir, STONEWRIGHT_DIRECT_WRITES: 'off' },
				{ post_id: 7, data: NEXT },
				unavailableCli() as never,
				rest,
			),
		).rejects.toMatchObject({ code: 'direct_writes_disabled' });
		expect(post).not.toHaveBeenCalled();
	});
});
