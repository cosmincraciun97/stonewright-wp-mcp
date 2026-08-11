import { afterEach, describe, expect, it } from 'vitest';
import { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { WindowsCredentialStore } from '../src/credentials/windows.js';
import { makeCredentialRef } from '../src/credentials/types.js';

describe('WindowsCredentialStore DPAPI sidecar', () => {
	const dirs: string[] = [];
	afterEach(() => {
		for (const d of dirs.splice(0)) {
			rmSync(d, { recursive: true, force: true });
		}
	});

	it('set/get round-trips via DPAPI file (mocked PowerShell)', () => {
		const dir = mkdtempSync(join(tmpdir(), 'sw-win-cred-'));
		dirs.push(dir);
		const vault = new Map<string, string>();

		const store = new WindowsCredentialStore({
			forceAvailable: true,
			secretsDir: dir,
			runCmdkey: () => {
				// write-only; ignore
			},
			runPowerShell: (script: string) => {
				// Protect path: script contains ConvertFrom-SecureString
				if (script.includes('ConvertFrom-SecureString')) {
					const m = /\$plain = '([^']*)'/.exec(script);
					const plain = m?.[1] ?? '';
					const enc = `ENC:${Buffer.from(plain, 'utf8').toString('base64')}`;
					vault.set('last', enc);
					return `${enc}\n`;
				}
				// Unprotect path
				if (script.includes('SecureStringToBSTR')) {
					const m = /\$enc = '([^']*)'/.exec(script);
					const enc = m?.[1] ?? '';
					const b64 = enc.startsWith('ENC:') ? enc.slice(4) : enc;
					return `${Buffer.from(b64, 'base64').toString('utf8')}\n`;
				}
				return '';
			},
		});

		expect(store.isAvailable()).toBe(true);
		const ref = makeCredentialRef('site-a', 'app-password', 'windows');
		store.set(ref, 'example-win-app-password');
		expect(store.get(ref)).toBe('example-win-app-password');

		// DPAPI sidecar file must exist on disk
		const files = readdirSync(dir);
		expect(files.some((f) => f.endsWith('.dpapi'))).toBe(true);
		const blob = readFileSync(join(dir, files.find((f) => f.endsWith('.dpapi'))!), 'utf8');
		expect(blob).toContain('ENC:');
		expect(blob).not.toContain('example-win-app-password');

		store.delete(ref);
		expect(store.get(ref)).toBeNull();
	});

	it('get returns null when no DPAPI file exists', () => {
		const dir = mkdtempSync(join(tmpdir(), 'sw-win-cred-empty-'));
		dirs.push(dir);
		const store = new WindowsCredentialStore({
			forceAvailable: true,
			secretsDir: dir,
			runCmdkey: () => undefined,
			runPowerShell: () => {
				throw new Error('should not run');
			},
		});
		const ref = makeCredentialRef('missing', 'app-password', 'windows');
		expect(store.get(ref)).toBeNull();
		expect(existsSync(dir)).toBe(true);
	});
});
