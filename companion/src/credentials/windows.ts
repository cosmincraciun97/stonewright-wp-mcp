import { execFileSync } from 'node:child_process';
import type { CredentialStore } from './types.js';
import { CredentialError, parseCredentialRef } from './types.js';

/**
 * Windows Credential Manager adapter via `cmdkey` / PowerShell.
 * Minimal interface implementation — stores Generic credentials under the service name.
 */
export class WindowsCredentialStore implements CredentialStore {
	readonly kind = 'windows' as const;

	isAvailable(): boolean {
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
		try {
			// PowerShell Credential Manager is more reliable for generic secrets than cmdkey alone.
			const script = [
				`$target = ${psQuote(target)}`,
				`$secret = ${psQuote(secret)}`,
				'$cred = New-Object System.Management.Automation.PSCredential("stonewright", (ConvertTo-SecureString $secret -AsPlainText -Force))',
				// Persist via cmdkey as a fallback-friendly generic password.
				`cmdkey /generic:$target /user:stonewright /pass:$secret | Out-Null`,
			].join('; ');
			execFileSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', script], {
				stdio: 'ignore',
				timeout: 15_000,
			});
		} catch (err) {
			const detail = err instanceof Error ? err.message : String(err);
			throw new CredentialError('credman_set_failed', `Windows Credential Manager write failed: ${detail}`);
		}
	}

	get(ref: string): string | null {
		// Reading generic secrets from cmdkey is not supported without vault APIs.
		// Prefer env:// fallback for headless Windows CI; this get() returns null.
		void ref;
		return null;
	}

	delete(ref: string): void {
		const target = this.targetFor(ref);
		try {
			execFileSync('cmdkey', [`/delete:${target}`], { stdio: 'ignore', timeout: 10_000, shell: true });
		} catch {
			// ignore
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
}

function psQuote(value: string): string {
	return `'${value.replace(/'/g, "''")}'`;
}
