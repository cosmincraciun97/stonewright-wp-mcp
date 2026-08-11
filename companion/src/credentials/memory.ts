import type { CredentialStore } from './types.js';
import { CredentialError, parseCredentialRef } from './types.js';

/** In-process store for tests and headless CI when no OS store is available. */
export class MemoryCredentialStore implements CredentialStore {
	readonly kind = 'memory' as const;
	private readonly secrets = new Map<string, string>();

	isAvailable(): boolean {
		return true;
	}

	set(ref: string, secret: string): void {
		const parsed = parseCredentialRef(ref);
		if (parsed.kind !== 'memory' && parsed.kind !== 'keychain' && parsed.kind !== 'env') {
			// Allow memory store to back any ref kind during tests.
		}
		if (!secret) {
			throw new CredentialError('empty_secret', 'Refusing to store an empty secret.');
		}
		this.secrets.set(ref, secret);
	}

	get(ref: string): string | null {
		return this.secrets.get(ref) ?? null;
	}

	delete(ref: string): void {
		this.secrets.delete(ref);
	}

	clear(): void {
		this.secrets.clear();
	}
}
