import type { CredentialStore } from './types.js';
import { CredentialError, parseCredentialRef } from './types.js';

/**
 * Resolves secrets from environment variables only.
 * Never writes secrets to disk. set() only validates that the env var name is legal;
 * callers must set the env var themselves (headless / CI).
 */
export class EnvCredentialStore implements CredentialStore {
	readonly kind = 'env' as const;

	constructor(private readonly env: NodeJS.ProcessEnv = process.env) {}

	isAvailable(): boolean {
		return true;
	}

	set(ref: string, secret: string): void {
		const parsed = parseCredentialRef(ref);
		if (parsed.kind !== 'env' || !parsed.envVar) {
			throw new CredentialError(
				'env_ref_required',
				'EnvCredentialStore only accepts env://VAR_NAME references.',
			);
		}
		if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(parsed.envVar)) {
			throw new CredentialError('invalid_env_var', `Invalid env var name in ref: ${ref}`);
		}
		// Persist into the process env for this session so get() works after set().
		this.env[parsed.envVar] = secret;
	}

	get(ref: string): string | null {
		const parsed = parseCredentialRef(ref);
		if (parsed.kind !== 'env' || !parsed.envVar) {
			return null;
		}
		const value = (this.env[parsed.envVar] ?? '').trim();
		return value === '' ? null : value;
	}

	delete(ref: string): void {
		const parsed = parseCredentialRef(ref);
		if (parsed.kind === 'env' && parsed.envVar) {
			delete this.env[parsed.envVar];
		}
	}
}
