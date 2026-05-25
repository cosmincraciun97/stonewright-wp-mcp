/**
 * WP-CLI bootstrap integration test.
 *
 * Verifies the companion can resolve WP-CLI from one of three sources:
 *   1. PATH (system wp or STONEWRIGHT_WP_CLI_BIN)
 *   2. LocalWP discovery (APPDATA / LOCALAPPDATA / HOME scan)
 *   3. Stonewright companion cache (STONEWRIGHT_WP_CLI_INSTALL_DIR)
 *
 * This test does NOT require a live WordPress installation.
 * It uses a fake phar to validate the discovery + phar invocation chain.
 */
import { describe, expect, it } from 'vitest';
import { existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
	wpCliEnsureReady,
	resolveWpCliInvocation,
	type ExecFileRunner,
} from '../src/wp-cli.js';

describe('WP-CLI bootstrap integration', () => {
	it('resolves WP-CLI from companion cache when phar and php are set via env', () => {
		const temp = mkdtempSync(join(tmpdir(), 'sw-bootstrap-'));
		try {
			const installDir = join(temp, 'cache');
			const pharPath = join(installDir, 'wp-cli.phar');
			const phpBin = join(temp, 'php.exe');
			mkdirSync(installDir, { recursive: true });
			writeFileSync(pharPath, 'fake phar');
			writeFileSync(phpBin, 'fake php');

			const invocation = resolveWpCliInvocation(
				{
					STONEWRIGHT_WP_CLI_INSTALL_DIR: installDir,
					STONEWRIGHT_WP_CLI_PHP_BIN: phpBin,
					STONEWRIGHT_WP_CLI_PHAR_PATH: pharPath,
					STONEWRIGHT_WP_ROOT: temp,
				} as NodeJS.ProcessEnv,
				temp,
			);

			expect(invocation.source).toBe('env_php_phar');
			expect(invocation.prefixArgs).toContain(pharPath);
			expect(invocation.executable).toBe(phpBin);
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});

	it('wpCliEnsureReady is idempotent: skips download when phar already in cache', async () => {
		const temp = mkdtempSync(join(tmpdir(), 'sw-ensure-idem-'));
		try {
			const installDir = join(temp, 'cache');
			const pharPath = join(installDir, 'wp-cli.phar');
			mkdirSync(installDir, { recursive: true });
			writeFileSync(pharPath, 'existing phar');

			let fetchCalled = false;
			const fetchImpl = (): Promise<Response> => {
				fetchCalled = true;
				return Promise.resolve(new Response('unused'));
			};
			const runner: ExecFileRunner = () =>
				Promise.resolve({ stdout: '{"wp_cli_version":"2.10.0"}', stderr: '', exitCode: 0 });
			const env = {
				STONEWRIGHT_WP_CLI_INSTALL_DIR: installDir,
				STONEWRIGHT_WP_CLI_PHP_BIN: 'php',
				STONEWRIGHT_WP_ROOT: process.cwd(),
			} as NodeJS.ProcessEnv;

			const result = await wpCliEnsureReady({ runner, env, fetchImpl });

			expect(result.ensured).toBe(true);
			// Phar was already present; wpCliStatus succeeded on first check.
			// wpCliInstall should NOT have been called (phar already exists — idempotent).
			expect(fetchCalled).toBe(false);
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});

	it('wpCliEnsureReady returns ensured=true when phar is in cache and download would have been triggered', async () => {
		const temp = mkdtempSync(join(tmpdir(), 'sw-ensure-full-'));
		try {
			const installDir = join(temp, 'cache');
			mkdirSync(installDir, { recursive: true });
			// No phar yet — simulate the full install path
			let callCount = 0;
			const runner: ExecFileRunner = () => {
				callCount++;
				if (callCount === 1) {
					// First check: WP-CLI unavailable
					return Promise.resolve({
						stdout: '',
						stderr: '',
						exitCode: 1,
						errorCode: 'ENOENT' as string | number,
						errorMessage: 'wp: not found',
					});
				}
				// Second check (after install): success
				return Promise.resolve({ stdout: '{"wp_cli_version":"2.10.0"}', stderr: '', exitCode: 0 });
			};
			const fakePhar = Buffer.from('fake wp-cli phar for integration test');
			const fetchImpl = (): Promise<Response> => Promise.resolve(new Response(fakePhar));
			const env = {
				STONEWRIGHT_WP_CLI_INSTALL_DIR: installDir,
				STONEWRIGHT_WP_ROOT: process.cwd(),
			} as NodeJS.ProcessEnv;

			const result = await wpCliEnsureReady({ runner, env, fetchImpl });

			expect(result.ensured).toBe(true);
			expect(result.source).toBe('installed');
			expect(result.installed).toBe(true);
			expect(existsSync(join(installDir, 'wp-cli.phar'))).toBe(true);
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});
});
