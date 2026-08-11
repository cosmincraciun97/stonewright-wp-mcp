/**
 * OS credential store abstraction for Stonewright site secrets.
 *
 * New registry writes never persist plaintext Application Passwords in sites.json.
 * Secrets live behind a CredentialRef (keychain://, credman://, secretservice://, env://).
 */

export type CredentialStoreKind = 'keychain' | 'windows' | 'secret-service' | 'env' | 'memory';

export interface CredentialStore {
	readonly kind: CredentialStoreKind;
	/** True when this store can persist secrets on the current machine. */
	isAvailable(): boolean;
	set(ref: string, secret: string): void;
	get(ref: string): string | null;
	delete(ref: string): void;
}

export type CredentialPurpose = 'app-password' | 'oauth-refresh';

export class CredentialError extends Error {
	readonly code: string;

	constructor(code: string, message: string) {
		super(message);
		this.name = 'CredentialError';
		this.code = code;
	}
}

/** Build a stable credential reference for a site alias + purpose. */
export function makeCredentialRef(
	alias: string,
	purpose: CredentialPurpose = 'app-password',
	kind: CredentialStoreKind = 'keychain',
): string {
	const safeAlias = alias
		.trim()
		.toLowerCase()
		.replace(/[^a-z0-9._-]+/g, '-')
		.replace(/^-+|-+$/g, '') || 'site';
	const service = `stonewright/${safeAlias}/${purpose}`;
	switch (kind) {
		case 'keychain':
			return `keychain://${service}`;
		case 'windows':
			return `credman://${service}`;
		case 'secret-service':
			return `secretservice://${service}`;
		case 'env':
			return `env://STONEWRIGHT_CRED_${safeAlias.replace(/[^a-z0-9]+/gi, '_').toUpperCase()}`;
		case 'memory':
			return `memory://${service}`;
		default:
			return `keychain://${service}`;
	}
}

export function parseCredentialRef(ref: string): {
	kind: CredentialStoreKind;
	service: string;
	envVar?: string;
} {
	const trimmed = ref.trim();
	if (trimmed.startsWith('keychain://')) {
		return { kind: 'keychain', service: trimmed.slice('keychain://'.length) };
	}
	if (trimmed.startsWith('credman://')) {
		return { kind: 'windows', service: trimmed.slice('credman://'.length) };
	}
	if (trimmed.startsWith('secretservice://')) {
		return { kind: 'secret-service', service: trimmed.slice('secretservice://'.length) };
	}
	if (trimmed.startsWith('memory://')) {
		return { kind: 'memory', service: trimmed.slice('memory://'.length) };
	}
	if (trimmed.startsWith('env://')) {
		const envVar = trimmed.slice('env://'.length).trim();
		return { kind: 'env', service: envVar, envVar };
	}
	if (trimmed.startsWith('env:')) {
		const envVar = trimmed.slice('env:'.length).trim();
		return { kind: 'env', service: envVar, envVar };
	}
	throw new CredentialError('invalid_credential_ref', `Unsupported credential_ref: ${ref}`);
}
