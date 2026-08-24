import { createHmac, timingSafeEqual } from 'node:crypto';
import { APP_VERSION } from '../version.js';
import { stonewrightPackageVersion } from '../cli/clients/package-reference.js';
import {
	atomicWriteRegistry,
	findSiteByAlias,
	loadRegistry,
	withRegistryLock,
} from '../cli/connect/registry.js';
import type { RestartProof } from '../cli/connect/types.js';

const REQUIRED_ACTIVE_HOST_CALLS = [
	'stonewright-task-start',
	'stonewright-setup-profile',
	'stonewright-wordpress-mcp-status',
	'stonewright-client-surface-check',
] as const;

export type ActiveClientRestartAttestation =
	| { status: 'not-required' }
	| { status: 'incomplete'; missing_calls: string[] }
	| { status: 'blocked'; error_code: string; next_action: string }
	| { status: 'verified'; client: string; receipt_id: string; attestation_digest: string };

function canonicalAttestation(input: {
	receiptId: string;
	client: string;
	expectedPackage: string;
	expectedVersion: string;
	processStartId: string;
	catalogDigest: string;
	observedToolNames: string[];
	companionVersion: string;
}): string {
	return JSON.stringify({
		status: 'verified',
		attestation_scope: 'active-client',
		receipt_id: input.receiptId,
		client: input.client,
		expected_package: input.expectedPackage,
		expected_version: input.expectedVersion,
		companion_version: input.companionVersion,
		process_start_id: input.processStartId,
		catalog_digest: input.catalogDigest,
		observed_tool_names: [...input.observedToolNames].sort(),
	});
}

function safeSame(a: string, b: string): boolean {
	const left = Buffer.from(a);
	const right = Buffer.from(b);
	return left.length === right.length && timingSafeEqual(left, right);
}

export function verifyActiveClientRestartProof(value: unknown): boolean {
	if (!value || typeof value !== 'object') return false;
	const proof = value as Record<string, unknown>;
	if (proof['status'] !== 'verified' || proof['attestation_scope'] !== 'active-client') return false;
	const requiredStrings = [
		'receipt_id', 'client', 'expected_package', 'expected_version', 'companion_version',
		'process_start_id', 'catalog_digest', 'attestation_digest', 'attestation_challenge',
	] as const;
	if (requiredStrings.some((key) => typeof proof[key] !== 'string' || proof[key] === '')) return false;
	if (!Array.isArray(proof['observed_tool_names']) || proof['observed_tool_names'].some((name) => typeof name !== 'string')) return false;
	if (stonewrightPackageVersion(proof['expected_package'] as string) !== proof['expected_version']) return false;
	if (proof['expected_version'] !== proof['companion_version']) return false;
	const material = canonicalAttestation({
		receiptId: proof['receipt_id'] as string,
		client: proof['client'] as string,
		expectedPackage: proof['expected_package'] as string,
		expectedVersion: proof['expected_version'] as string,
		companionVersion: proof['companion_version'] as string,
		processStartId: proof['process_start_id'] as string,
		catalogDigest: proof['catalog_digest'] as string,
		observedToolNames: proof['observed_tool_names'] as string[],
	});
	const expected = `hmac-sha256:${createHmac('sha256', proof['attestation_challenge'] as string).update(material).digest('hex')}`;
	return safeSame(expected, proof['attestation_digest'] as string);
}

/**
 * Complete a restart receipt only from the live MCP process which has actually
 * served all required gateway calls. No CLI-provided attestation is accepted.
 */
export function attestPendingRestartFromActiveHost(input: {
	env: NodeJS.ProcessEnv;
	processStartId: string;
	catalogDigest: string;
	invokedToolNames: ReadonlySet<string>;
	observedToolNames: ReadonlySet<string>;
	registeredToolNames: readonly string[];
	refreshRequiredToolNames: readonly string[];
}): ActiveClientRestartAttestation {
	const alias = (input.env['STONEWRIGHT_SITE_ALIAS'] ?? '').trim();
	if (!alias) return { status: 'not-required' };

	const loaded = loadRegistry({ env: input.env });
	const site = findSiteByAlias(loaded.registry, alias);
	if (!site) return { status: 'not-required' };
	const pending = Object.entries(site.clients).filter(([, binding]) => binding.pending_restart);
	if (pending.length === 0) return { status: 'not-required' };
	if (pending.length !== 1) {
		return {
			status: 'blocked',
			error_code: 'restart_attestation_client_ambiguous',
			next_action: 'Complete one client update at a time, then repeat the active-host verification sequence.',
		};
	}

	const missing = REQUIRED_ACTIVE_HOST_CALLS.filter((name) => !input.invokedToolNames.has(name));
	if (missing.length > 0) return { status: 'incomplete', missing_calls: [...missing] };
	if (input.refreshRequiredToolNames.length > 0) {
		return {
			status: 'blocked',
			error_code: 'restart_attestation_refresh_required',
			next_action: 'Re-list or restart the active client until refresh_required_tool_names is empty, then repeat client-surface-check.',
		};
	}

	const [client, binding] = pending[0];
	const receipt = binding.pending_restart!;
	if (!receipt.attestation_challenge) {
		return {
			status: 'blocked',
			error_code: 'restart_attestation_challenge_missing',
			next_action: 'Run the safe companion update command again to mint a bound restart receipt.',
		};
	}
	if (receipt.client !== client || stonewrightPackageVersion(receipt.expected_package) !== receipt.expected_version) {
		return {
			status: 'blocked',
			error_code: 'restart_attestation_receipt_invalid',
			next_action: 'Run the safe companion update command again; the pending receipt is inconsistent.',
		};
	}
	if (receipt.expected_version !== APP_VERSION) {
		return {
			status: 'blocked',
			error_code: 'restart_attestation_version_mismatch',
			next_action: `Restart the client with Stonewright companion ${receipt.expected_version}.`,
		};
	}
	if (receipt.pre_restart_process_start_id && safeSame(receipt.pre_restart_process_start_id, input.processStartId)) {
		return {
			status: 'blocked',
			error_code: 'restart_attestation_process_stale',
			next_action: 'Fully restart the AI client, then repeat task-start, setup-profile, status, and client-surface-check.',
		};
	}
	if (!input.catalogDigest.startsWith('sha256:')) {
		return {
			status: 'blocked',
			error_code: 'restart_attestation_catalog_missing',
			next_action: 'Re-list the active MCP tools, then repeat client-surface-check.',
		};
	}
	if (receipt.pre_restart_catalog_digest && safeSame(receipt.pre_restart_catalog_digest, input.catalogDigest)) {
		return {
			status: 'blocked',
			error_code: 'restart_attestation_catalog_stale',
			next_action: 'Fully restart the AI client so the new package catalog is loaded, then repeat the verification sequence.',
		};
	}

	const registered = new Set(input.registeredToolNames);
	const observed = [...new Set([
		...input.invokedToolNames,
		...[...input.observedToolNames].filter((name) => registered.has(name)),
	])].sort();
	const material = canonicalAttestation({
		receiptId: receipt.receipt_id,
		client,
		expectedPackage: receipt.expected_package,
		expectedVersion: receipt.expected_version,
		processStartId: input.processStartId,
		catalogDigest: input.catalogDigest,
		observedToolNames: observed,
		companionVersion: APP_VERSION,
	});
	const digest = `hmac-sha256:${createHmac('sha256', receipt.attestation_challenge).update(material).digest('hex')}`;
	const now = new Date().toISOString();
	const proof: RestartProof = {
		verified_at: now,
		status: 'verified',
		attestation_scope: 'active-client',
		receipt_id: receipt.receipt_id,
		client,
		expected_package: receipt.expected_package,
		expected_version: receipt.expected_version,
		companion_version: APP_VERSION,
		process_start_id: input.processStartId,
		catalog_digest: input.catalogDigest,
		observed_tool_names: observed,
		attestation_digest: digest,
		attestation_challenge: receipt.attestation_challenge,
	};

	let persisted = false;
	withRegistryLock(loaded.path, () => {
		const current = loadRegistry({ sitesFile: loaded.path, env: input.env });
		const currentSite = findSiteByAlias(current.registry, alias);
		const currentBinding = currentSite?.clients[client];
		const currentReceipt = currentBinding?.pending_restart;
		if (
			!currentSite || !currentBinding || !currentReceipt
			|| currentReceipt.receipt_id !== receipt.receipt_id
			|| currentReceipt.attestation_challenge !== receipt.attestation_challenge
			|| currentReceipt.expected_package !== receipt.expected_package
			|| currentReceipt.expected_version !== receipt.expected_version
		) {
			return;
		}
		currentSite.clients[client] = {
			...currentBinding,
			pending_restart: undefined,
			last_restart_proof: proof,
		};
		currentSite.last_verification = {
			at: now,
			ok: true,
			client,
			detail: 'active-client restart attested by invoked permanent gateways',
			active_mode: currentSite.preferred_active_mode,
			active_alias: currentSite.alias,
			companion_version: APP_VERSION,
			remote_tool_count: observed.length,
			surface_digest: input.catalogDigest,
			task_start_available: true,
			status_available: true,
			refresh_required_tool_names: [],
			process_start_id: input.processStartId,
			catalog_digest: input.catalogDigest,
			client_observed_tool_names: observed,
			attestation_scope: 'active-client',
		};
		currentSite.updated_at = now;
		atomicWriteRegistry(loaded.path, current.registry, { persistBackup: false });
		persisted = true;
	});
	if (!persisted) {
		return {
			status: 'blocked',
			error_code: 'restart_attestation_receipt_changed',
			next_action: 'The pending receipt changed while it was being verified. Repeat client-surface-check.',
		};
	}

	return {
		status: 'verified',
		client,
		receipt_id: receipt.receipt_id,
		attestation_digest: digest,
	};
}
