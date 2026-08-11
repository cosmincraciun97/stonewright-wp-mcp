import { execFileSync } from 'node:child_process';
import type { CredentialStore } from './types.js';
import { CredentialError, parseCredentialRef } from './types.js';

const ACCOUNT = 'stonewright';

/**
 * macOS Keychain adapter via the `security` CLI.
 * Service name is the path after keychain:// (e.g. stonewright/site-a/app-password).
 */
export class KeychainCredentialStore implements CredentialStore {
	readonly kind = 'keychain' as const;

	isAvailable(): boolean {
		if (process.platform !== 'darwin') {
			return false;
		}
		try {
			execFileSync('security', ['help'], { stdio: 'ignore', timeout: 3_000 });
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
			// -U updates if the item already exists.
			execFileSync(
				'security',
				['add-generic-password', '-a', ACCOUNT, '-s', service, '-w', secret, '-U'],
				{ stdio: ['ignore', 'pipe', 'pipe'], timeout: 10_000 },
			);
		} catch (err) {
			const detail = err instanceof Error ? err.message : String(err);
			throw new CredentialError('keychain_set_failed', `macOS Keychain write failed: ${detail}`);
		}
	}

	get(ref: string): string | null {
		const service = this.serviceFor(ref);
		try {
			const out = execFileSync(
				'security',
				['find-generic-password', '-a', ACCOUNT, '-s', service, '-w'],
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
				'security',
				['delete-generic-password', '-a', ACCOUNT, '-s', service],
				{ stdio: 'ignore', timeout: 10_000 },
			);
		} catch {
			// Missing item is fine.
		}
	}

	private serviceFor(ref: string): string {
		const parsed = parseCredentialRef(ref);
		if (parsed.kind !== 'keychain') {
			throw new CredentialError(
				'keychain_ref_required',
				`Keychain store expected keychain:// ref, got: ${ref}`,
			);
		}
		if (!parsed.service || parsed.service.includes('://')) {
			throw new CredentialError('invalid_credential_ref', `Invalid keychain service in ref: ${ref}`);
		}
		return parsed.service;
	}
}
