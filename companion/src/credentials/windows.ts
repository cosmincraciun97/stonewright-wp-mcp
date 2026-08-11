import { execFileSync } from 'node:child_process';
import {
	chmodSync,
	existsSync,
	mkdirSync,
	readFileSync,
	unlinkSync,
	writeFileSync,
} from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import type { CredentialStore } from './types.js';
import { CredentialError, parseCredentialRef } from './types.js';

/**
 * Windows credential store.
 *
 * cmdkey can write Generic credentials but cannot read them back from userland.
 * Therefore every successful set() also writes a DPAPI-protected secret file
 * under ~/.stonewright/secrets/ (or STONEWRIGHT_SECRETS_DIR). get() reads that
 * file first, then falls back to env:// resolution for headless CI.
 *
 * cmdkey alone is insufficient for round-trip; the DPAPI sidecar is required.
 */
export class WindowsCredentialStore implements CredentialStore {
	readonly kind = 'windows' as const;

	constructor(
		private readonly options: {
			homeDir?: string | undefined;
			secretsDir?: string | undefined;
			/** Injected for tests; defaults to real PowerShell + cmdkey. */
			runPowerShell?: ((script: string) => string) | undefined;
			runCmdkey?: ((args: string[]) => void) | undefined;
			/** When true, skip platform checks (unit tests on non-Windows). */
			forceAvailable?: boolean | undefined;
		} = {},
	) {}

	isAvailable(): boolean {
		if (this.options.forceAvailable) {
			return true;
		}
		if (process.platform !== 'win32') {
			return false;
		}
		try {
			execFileSync('where', ['cmdkey'], { stdio: 'ignore', timeout: 3_000, shell: true });
			return true;
		} catch {
			return false;
		}
	}

	set(ref: string, secret: string): void {
		const target = this.targetFor(ref);
		if (!secret) {
			throw new CredentialError('empty_secret', 'Refusing to store an empty secret.');
		}

		// 1) Best-effort Credential Manager write (visible in Windows UI; not readable via cmdkey).
		try {
			const runCmdkey =
				this.options.runCmdkey ??
				((args: string[]) => {
					execFileSync('cmdkey', args, { stdio: 'ignore', timeout: 10_000, shell: true });
				});
			runCmdkey([`/generic:${target}`, '/user:stonewright', `/pass:${secret}`]);
		} catch {
			// Non-fatal: DPAPI file is the readable store.
		}

		// 2) DPAPI-protected local file — this is what get() reads.
		try {
			const encrypted = this.dpapiProtect(secret);
			const path = this.secretPath(target);
			mkdirSync(this.secretsDir(), { recursive: true });
			writeFileSync(path, encrypted, { encoding: 'utf8', mode: 0o600 });
			try {
				chmodSync(path, 0o600);
			} catch {
				// Windows may ignore POSIX modes.
			}
		} catch (err) {
			const detail = err instanceof Error ? err.message : String(err);
			throw new CredentialError(
				'credman_set_failed',
				`Windows DPAPI secret write failed: ${detail}. ` +
					'cmdkey alone cannot be read back; DPAPI file is required for get().',
			);
		}
	}

	get(ref: string): string | null {
		const target = this.targetFor(ref);
		const path = this.secretPath(target);
		if (existsSync(path)) {
			try {
				const encrypted = readFileSync(path, 'utf8').trim();
				if (!encrypted) return null;
				const plain = this.dpapiUnprotect(encrypted);
				return plain === '' ? null : plain;
			} catch {
				// Fall through
			}
		}
		// No readable vault entry — caller may try env:// fallback.
		return null;
	}

	delete(ref: string): void {
		const target = this.targetFor(ref);
		try {
			const runCmdkey =
				this.options.runCmdkey ??
				((args: string[]) => {
					execFileSync('cmdkey', args, { stdio: 'ignore', timeout: 10_000, shell: true });
				});
			runCmdkey([`/delete:${target}`]);
		} catch {
			// ignore
		}
		const path = this.secretPath(target);
		if (existsSync(path)) {
			try {
				// Overwrite then unlink (best-effort shred of the DPAPI blob).
				const size = readFileSync(path).length;
				writeFileSync(path, Buffer.alloc(Math.max(size, 1), 0));
				unlinkSync(path);
			} catch {
				try {
					unlinkSync(path);
				} catch {
					// ignore
				}
			}
		}
	}

	private targetFor(ref: string): string {
		const parsed = parseCredentialRef(ref);
		if (parsed.kind !== 'windows') {
			throw new CredentialError(
				'credman_ref_required',
				`Windows store expected credman:// ref, got: ${ref}`,
			);
		}
		return parsed.service;
	}

	private secretsDir(): string {
		if (this.options.secretsDir) return this.options.secretsDir;
		const fromEnv = (process.env['STONEWRIGHT_SECRETS_DIR'] ?? '').trim();
		if (fromEnv) return fromEnv;
		const home = this.options.homeDir ?? homedir();
		return join(home, '.stonewright', 'secrets');
	}

	/** Stable filename for a Credential Manager target name. */
	private secretPath(target: string): string {
		const safe = target
			.toLowerCase()
			.replace(/[^a-z0-9._-]+/g, '-')
			.replace(/^-+|-+$/g, '')
			.slice(0, 120);
		return join(this.secretsDir(), `${safe || 'secret'}.dpapi`);
	}

	private runPs(script: string): string {
		if (this.options.runPowerShell) {
			return this.options.runPowerShell(script);
		}
		return execFileSync(
			'powershell.exe',
			['-NoProfile', '-NonInteractive', '-Command', script],
			{ encoding: 'utf8', timeout: 15_000, stdio: ['ignore', 'pipe', 'pipe'] },
		);
	}

	/** DPAPI protect via ConvertFrom-SecureString (user-scoped). */
	private dpapiProtect(secret: string): string {
		const script = [
			`$ErrorActionPreference = 'Stop'`,
			`$plain = ${psQuote(secret)}`,
			`$sec = ConvertTo-SecureString $plain -AsPlainText -Force`,
			`ConvertFrom-SecureString $sec`,
		].join('; ');
		const out = this.runPs(script).trim();
		if (!out) {
			throw new Error('ConvertFrom-SecureString returned empty output');
		}
		return out;
	}

	/** DPAPI unprotect via ConvertTo-SecureString + Marshal. */
	private dpapiUnprotect(encrypted: string): string {
		const script = [
			`$ErrorActionPreference = 'Stop'`,
			`$enc = ${psQuote(encrypted)}`,
			`$sec = ConvertTo-SecureString $enc`,
			`$bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec)`,
			`try { [Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }`,
		].join('; ');
		return this.runPs(script).replace(/\r?\n$/, '');
	}
}

function psQuote(value: string): string {
	return `'${value.replace(/'/g, "''")}'`;
}
