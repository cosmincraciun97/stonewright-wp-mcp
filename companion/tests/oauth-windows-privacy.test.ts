import { describe, expect, it, vi } from 'vitest';
import type { ChildProcess } from 'node:child_process';
import { existsSync, readFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { performance } from 'node:perf_hooks';
import { createOAuthTestDirectory } from './helpers/windows-oauth-acl.js';

const captured = vi.hoisted(() => ({ child: null as ChildProcess | null, spawn: null as typeof import('node:child_process').spawn | null, blockNextRequest: false, ready: '', holdMs: 400, readyAt: 0 }));
vi.mock('node:child_process', async (importOriginal) => {
	const original = await importOriginal<typeof import('node:child_process')>();
	return {
		...original,
		spawn: (...args: Parameters<typeof original.spawn>) => {
			const child = original.spawn(...args);
			if (args[1]?.[0] === '--serve') captured.child = child;
			captured.spawn = original.spawn;
			return child;
		},
	};
});
vi.mock('node:fs', async (importOriginal) => {
	const original = await importOriginal<typeof import('node:fs')>();
	return {
		...original,
		renameSync: (from: string, to: string) => {
			if (captured.blockNextRequest && to.endsWith('request.json')) {
				captured.blockNextRequest = false;
				// Keep the real request open across rename, permitting DELETE but denying concurrent READ.
				const script = [
					"$data = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($env:STONEWRIGHT_SHARING_FIXTURE)) | ConvertFrom-Json",
					"$stream = New-Object IO.FileStream($data.path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Delete)",
					"try { [IO.File]::WriteAllText($data.ready, 'ready'); [Threading.Thread]::Sleep([int]$data.holdMs) } finally { $stream.Dispose() }",
				].join('\n');
				const child = captured.spawn!(join(process.env['SystemRoot'] ?? 'C:\\Windows', 'System32', 'WindowsPowerShell', 'v1.0', 'powershell.exe'), [
					'-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand', Buffer.from(script, 'utf16le').toString('base64'),
				], {
					windowsHide: true, stdio: 'ignore',
					env: { ...process.env, STONEWRIGHT_SHARING_FIXTURE: Buffer.from(JSON.stringify({ path: from, ready: captured.ready, holdMs: captured.holdMs })).toString('base64') },
				});
				child.unref();
				// Starting PowerShell can take several seconds on a loaded machine.
				const deadline = performance.now() + 10_000;
				const wait = new Int32Array(new SharedArrayBuffer(4));
				while (!original.existsSync(captured.ready)) {
					if (performance.now() >= deadline) throw new Error('Synthetic sharing fixture did not become ready.');
					Atomics.wait(wait, 0, 0, 1);
				}
				captured.readyAt = performance.now();
			}
			return original.renameSync(from, to);
		},
	};
});

describe.runIf(process.platform === 'win32')('Windows OAuth native helper failure', () => {
	it('recovers from a real transient request sharing violation with live privacy checks', async () => {
		vi.resetModules();
		const { OAuthTokenStore } = await import('../src/oauth-token-manager.js');
		const directory = createOAuthTestDirectory();
		try {
			const path = join(directory, 'oauth.json');
			const initial = { accessToken: 'synthetic-access', refreshToken: 'synthetic-refresh', expiresAt: 0 };
			const store = new OAuthTokenStore(path);
			store.save(initial);
			captured.ready = join(directory, 'sharing-ready');
			captured.holdMs = 400;
			captured.blockNextRequest = true;
			expect(store.load()).toEqual(initial);
			expect(existsSync(captured.ready)).toBe(true);
			// The fixture denies reads until it releases the file, so a result before then would mean no sharing conflict was met.
			expect(performance.now() - captured.readyAt).toBeGreaterThanOrEqual(captured.holdMs - 100);
			expect(store.load()).toEqual(initial);
		} finally { captured.blockNextRequest = false; rmSync(directory, { recursive: true, force: true }); }
	}, 30_000);

	it('fails closed after a sharing conflict exceeds the bounded retry budget', async () => {
		vi.resetModules();
		const { OAuthTokenStore } = await import('../src/oauth-token-manager.js');
		const directory = createOAuthTestDirectory();
		try {
			const path = join(directory, 'oauth.json');
			const store = new OAuthTokenStore(path);
			store.save({ accessToken: 'synthetic-access', refreshToken: 'synthetic-refresh', expiresAt: 0 });
			const previous = readFileSync(path, 'utf8');
			captured.ready = join(directory, 'sharing-ready');
			captured.holdMs = 3_000;
			captured.blockNextRequest = true;
			const started = performance.now();
			expect(() => store.load()).toThrow(/privacy/i);
			// The helper gives up after its one second budget and the caller stops waiting at its five second response deadline; the rest is slack for a loaded machine.
			expect(performance.now() - started).toBeLessThan(10_000);
			expect(() => store.clear()).toThrow(/privacy/i);
			expect(readFileSync(path, 'utf8')).toBe(previous);
		} finally { captured.blockNextRequest = false; rmSync(directory, { recursive: true, force: true }); }
	}, 30_000);

	it('preserves token bytes and requires a process restart after its helper exits', async () => {
		vi.resetModules();
		const { OAuthTokenStore } = await import('../src/oauth-token-manager.js');
		const directory = createOAuthTestDirectory();
		try {
			const path = join(directory, 'oauth.json');
			const store = new OAuthTokenStore(path);
			store.save({ accessToken: 'synthetic-access', refreshToken: 'synthetic-refresh', expiresAt: 0 });
			const previous = readFileSync(path, 'utf8');
			const child = captured.child;
			expect(child).not.toBeNull();
			await new Promise<void>((resolve) => { child!.once('exit', () => resolve()); child!.kill(); });
			expect(() => store.load()).toThrow(/privacy/i);
			expect(() => store.load()).toThrow(/privacy/i);
			expect(() => store.clear()).toThrow(/privacy/i);
			expect(readFileSync(path, 'utf8')).toBe(previous);
		} finally { rmSync(directory, { recursive: true, force: true }); }
	}, 30_000);
});
