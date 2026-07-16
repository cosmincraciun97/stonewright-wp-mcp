import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { mkdtempSync, readFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
	elementorStatus,
	elementorDataGet,
	elementorDataUpdate,
} from '../src/direct/tools/elementor-direct.js';
import { markTaskStartSeen, resetTaskStartSeenForTests } from '../src/direct/writes.js';
import type { ExecFileResult } from '../src/wp-cli.js';

function mockCli(handlers: Record<string, () => ExecFileResult | { ok: boolean; available?: boolean; stdout?: string; stderr?: string; parsed_json?: unknown }>) {
	return vi.fn(async (input: { command: string[] }) => {
		const key = input.command.join(' ');
		for (const [pattern, fn] of Object.entries(handlers)) {
			if (key.includes(pattern) || key === pattern) {
				const r = fn();
				return {
					ok: r.ok !== false,
					available: r.available ?? true,
					command: input.command,
					cwd: '/tmp',
					stdout: r.stdout ?? '',
					stderr: r.stderr ?? '',
					exit_code: r.ok === false ? 1 : 0,
					duration_ms: 1,
					wp_cli_source: 'test',
					...(r.parsed_json !== undefined ? { parsed_json: r.parsed_json } : {}),
					...(r.error ? { error: r.error } : {}),
				};
			}
		}
		return {
			ok: true,
			available: true,
			command: input.command,
			cwd: '/tmp',
			stdout: '',
			stderr: '',
			exit_code: 0,
			duration_ms: 1,
			wp_cli_source: 'test',
		};
	});
}

describe('direct elementor tools', () => {
	let stateDir: string;

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-el-'));
		markTaskStartSeen();
	});
	afterEach(() => {
		resetTaskStartSeenForTests();
	});

	it('status reports no edit when wp-cli unavailable', async () => {
		const cli = mockCli({
			'cli info': () => ({ ok: false, available: false, stdout: '', stderr: 'missing' }),
		});
		const result = await elementorStatus({}, {}, cli as never);
		expect(result.can_edit_data).toBe(false);
		expect(result.wp_cli).toBe(false);
	});

	it('status detects active elementor', async () => {
		const cli = mockCli({
			'cli info': () => ({ ok: true, available: true }),
			'plugin list': () => ({
				ok: true,
				parsed_json: [{ name: 'elementor', status: 'active', version: '3.20.0' }],
			}),
		});
		const result = await elementorStatus({}, {}, cli as never);
		expect(result.elementor_active).toBe(true);
		expect(result.can_edit_data).toBe(true);
		expect(result.version).toBe('3.20.0');
	});

	it('data-get returns parsed tree', async () => {
		const tree = [{ id: 'abc', elType: 'container', elements: [] }];
		const cli = mockCli({
			'_elementor_data': () => ({ ok: true, parsed_json: tree }),
			'_elementor_edit_mode': () => ({ ok: true, stdout: 'builder' }),
			'_elementor_template_type': () => ({ ok: true, stdout: 'wp-page' }),
		});
		const result = await elementorDataGet({}, { post_id: 12 }, cli as never);
		expect(result.ok).toBe(true);
		expect(result.element_count).toBe(1);
	});

	it('data-update backs up then writes via stdin', async () => {
		const tree = [{ id: 'abc', elType: 'container', elements: [] }];
		const calls: Array<{ command: string[]; stdin?: string }> = [];
		const cli = vi.fn(async (input: { command: string[]; stdin?: string }) => {
			calls.push({ command: input.command, ...(input.stdin !== undefined ? { stdin: input.stdin } : {}) });
			const key = input.command.join(' ');
			if (key.includes('_elementor_data') && key.includes('get')) {
				return {
					ok: true,
					available: true,
					command: input.command,
					cwd: '/tmp',
					stdout: JSON.stringify(tree),
					stderr: '',
					exit_code: 0,
					duration_ms: 1,
					wp_cli_source: 'test',
					parsed_json: tree,
				};
			}
			if (key.includes('meta update')) {
				expect(input.stdin).toBeDefined();
				return {
					ok: true,
					available: true,
					command: input.command,
					cwd: '/tmp',
					stdout: 'Success',
					stderr: '',
					exit_code: 0,
					duration_ms: 1,
					wp_cli_source: 'test',
				};
			}
			if (key.includes('help elementor')) {
				return {
					ok: true,
					available: true,
					command: input.command,
					cwd: '/tmp',
					stdout: 'flush-css',
					stderr: '',
					exit_code: 0,
					duration_ms: 1,
					wp_cli_source: 'test',
				};
			}
			if (key.includes('flush-css')) {
				return {
					ok: true,
					available: true,
					command: input.command,
					cwd: '/tmp',
					stdout: 'ok',
					stderr: '',
					exit_code: 0,
					duration_ms: 1,
					wp_cli_source: 'test',
				};
			}
			return {
				ok: true,
				available: true,
				command: input.command,
				cwd: '/tmp',
				stdout: '',
				stderr: '',
				exit_code: 0,
				duration_ms: 1,
				wp_cli_source: 'test',
			};
		});

		const env = { STONEWRIGHT_STATE_DIR: stateDir, STONEWRIGHT_DIRECT_WRITES: 'on' };
		const result = await elementorDataUpdate(
			env,
			{ post_id: 9, data: tree, confirm: true },
			cli as never,
		);
		expect(result.ok).toBe(true);
		expect(result.backup_path).toContain('post-9-');
		expect(existsSync(result.backup_path)).toBe(true);
		const backup = JSON.parse(readFileSync(result.backup_path, 'utf8')) as { post_id: number };
		expect(backup.post_id).toBe(9);
		expect(calls.some((c) => c.command.includes('meta') && c.command.includes('update') && c.stdin)).toBe(true);
	});

	it('data-update rejects invalid json string', async () => {
		await expect(
			elementorDataUpdate(
				{ STONEWRIGHT_DIRECT_WRITES: 'on' },
				{ post_id: 1, data: 'not-json{', confirm: true },
				mockCli({}) as never,
			),
		).rejects.toThrow(/JSON/i);
	});
});
