import { execFileSync } from 'node:child_process';
import type { CredentialStore } from './types.js';
import { CredentialError, parseCredentialRef } from './types.js';

/**
 * Linux Secret Service adapter via `secret-tool` (libsecret).
 * Shares the same CredentialStore interface as Keychain / Credential Manager.
 */
export class LinuxSecretServiceStore implements CredentialStore {
	readonly kind = 'secret-service' as const;

	isAvailable(): boolean {
		if (process.platform !== 'linux') {
			return false;
		}
		try {
			execFileSync('secret-tool', ['--version'], { stdio: 'ignore', timeout: 3_000 });
			return true;
		} catch {
			return false;
		}
	}

	set(ref: string, secret: string): void {
		const service = this.serviceFor(ref);
		if (!secret) {
			throw new CredentialError('empty_secret', 'Refusing to store an empty secret.');
		}
		try {
			execFileSync(
				'secret-tool',
				['store', '--label', `Stonewright ${service}`, 'service', service, 'account', 'stonewright'],
				{
					input: secret,
					stdio: ['pipe', 'ignore', 'pipe'],
					timeout: 10_000,
				},
			);
		} catch (err) {
			const detail = err instanceof Error ? err.message : String(err);
			throw new CredentialError(
				'secret_service_set_failed',
				`Linux Secret Service write failed: ${detail}`,
			);
		}
	}

	get(ref: string): string | null {
		const service = this.serviceFor(ref);
		try {
			const out = execFileSync(
				'secret-tool',
				['lookup', 'service', service, 'account', 'stonewright'],
				{ encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 10_000 },
			);
			const value = out.replace(/\r?\n$/, '');
			return value === '' ? null : value;
		} catch {
			return null;
		}
	}

	delete(ref: string): void {
		const service = this.serviceFor(ref);
		try {
			execFileSync(
				'secret-tool',
				['clear', 'service', service, 'account', 'stonewright'],
				{ stdio: 'ignore', timeout: 10_000 },
			);
		} catch {
			// ignore
		}
	}

	private serviceFor(ref: string): string {
		const parsed = parseCredentialRef(ref);
		if (parsed.kind !== 'secret-service') {
			throw new CredentialError(
				'secret_service_ref_required',
				`Secret Service store expected secretservice:// ref, got: ${ref}`,
			);
		}
		return parsed.service;
	}
}
