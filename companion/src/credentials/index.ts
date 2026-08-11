import { EnvCredentialStore } from './env-store.js';
import { KeychainCredentialStore } from './keychain.js';
import { LinuxSecretServiceStore } from './linux.js';
import { MemoryCredentialStore } from './memory.js';
import {
	type CredentialStore,
	type CredentialStoreKind,
	makeCredentialRef,
	parseCredentialRef,
	CredentialError,
} from './types.js';
import { WindowsCredentialStore } from './windows.js';

export type { CredentialStore, CredentialStoreKind, CredentialPurpose } from './types.js';
export {
	CredentialError,
	makeCredentialRef,
	parseCredentialRef,
} from './types.js';
export { MemoryCredentialStore } from './memory.js';
export { EnvCredentialStore } from './env-store.js';
export { KeychainCredentialStore } from './keychain.js';
export { WindowsCredentialStore } from './windows.js';
export { LinuxSecretServiceStore } from './linux.js';

export interface CreateCredentialStoreOptions {
	/** Force a store kind (tests / migration). */
	prefer?: CredentialStoreKind;
	/** Env bag for env:// resolution. */
	env?: NodeJS.ProcessEnv;
	/** Injected store (tests). */
	store?: CredentialStore;
	/**
	 * When true, fall back to memory if the OS store is unavailable.
	 * Default false for production writes (caller must handle unavailability).
	 */
	allowMemoryFallback?: boolean;
}

/**
 * Pick the best available credential store for this platform.
 * Order: injected → prefer → keychain (darwin) → windows → secret-service → env → optional memory.
 */
export function createCredentialStore(options: CreateCredentialStoreOptions = {}): CredentialStore {
	if (options.store) {
		return options.store;
	}

	const env = options.env ?? process.env;
	const prefer = options.prefer;

	const candidates: CredentialStore[] = [];
	if (prefer === 'memory') {
		return new MemoryCredentialStore();
	}
	if (prefer === 'env') {
		return new EnvCredentialStore(env);
	}
	if (prefer === 'keychain' || (!prefer && process.platform === 'darwin')) {
		candidates.push(new KeychainCredentialStore());
	}
	if (prefer === 'windows' || (!prefer && process.platform === 'win32')) {
		candidates.push(new WindowsCredentialStore());
	}
	if (prefer === 'secret-service' || (!prefer && process.platform === 'linux')) {
		candidates.push(new LinuxSecretServiceStore());
	}

	for (const candidate of candidates) {
		if (candidate.isAvailable()) {
			return candidate;
		}
	}

	if (options.allowMemoryFallback) {
		return new MemoryCredentialStore();
	}

	// Default: env store (always available; secrets not written to registry).
	return new EnvCredentialStore(env);
}

/**
 * Resolve a secret for a credential_ref using the appropriate store.
 * env:// always uses EnvCredentialStore; other schemes use the platform store.
 */
export function resolveCredentialSecret(
	ref: string,
	options: CreateCredentialStoreOptions = {},
): string | null {
	const parsed = parseCredentialRef(ref);
	if (parsed.kind === 'env') {
		return new EnvCredentialStore(options.env ?? process.env).get(ref);
	}
	if (parsed.kind === 'memory' || options.store instanceof MemoryCredentialStore) {
		const store = options.store ?? createCredentialStore({ ...options, prefer: 'memory' });
		return store.get(ref);
	}
	const store = options.store ?? createCredentialStore(options);
	if (store.kind === 'env') {
		// OS store unavailable — cannot resolve keychain/credman/secret-service refs.
		return null;
	}
	try {
		return store.get(ref);
	} catch {
		return null;
	}
}

/**
 * Store a secret and return the credential_ref that should be written to the registry.
 * Throws CredentialError when the secure store is unavailable and no fallback is allowed.
 */
export function storeSiteSecret(args: {
	alias: string;
	secret: string;
	purpose?: 'app-password' | 'oauth-refresh' | undefined;
	options?: CreateCredentialStoreOptions | undefined;
	/** Prefer env:// ref when OS store unavailable (headless). */
	allowEnvRef?: boolean | undefined;
}): string {
	const options = args.options ?? {};
	const store = createCredentialStore(options);

	if (store.kind === 'env' && !args.allowEnvRef && !options.allowMemoryFallback) {
		throw new CredentialError(
			'secure_store_unavailable',
			'No OS credential store available. Install Keychain/Credential Manager/secret-tool, or pass --credential-env VAR to use an environment reference. Registry left unchanged.',
		);
	}

	const kind: CredentialStoreKind =
		store.kind === 'memory'
			? 'memory'
			: store.kind === 'env'
				? 'env'
				: store.kind === 'windows'
					? 'windows'
					: store.kind === 'secret-service'
						? 'secret-service'
						: 'keychain';

	const ref = makeCredentialRef(args.alias, args.purpose ?? 'app-password', kind);
	store.set(ref, args.secret);
	return ref;
}
