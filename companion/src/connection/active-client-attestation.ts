import { createHmac, timingSafeEqual } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { APP_VERSION } from '../version.js';
import { getClientAdapter } from '../cli/clients/index.js';
import {
	sha256Text,
	stonewrightPackageIdentity,
	validateOfficialStonewrightNpxEntry,
} from '../cli/clients/package-reference.js';
import {
	atomicWriteRegistry,
	findSiteByAlias,
	loadRegistry,
	withRegistryLock,
} from '../cli/connect/registry.js';
import type { RestartProof } from '../cli/connect/types.js';

export const REQUIRED_ACTIVE_HOST_CALLS = [
	'stonewright-task-start',
	'stonewright-setup-profile',
	'stonewright-wordpress-mcp-status',
	'stonewright-client-surface-check',
] as const;

export function requiredActiveHostCallSucceeded(name: string, result: unknown): boolean {
	if (!(REQUIRED_ACTIVE_HOST_CALLS as readonly string[]).includes(name)) return false;
	if (!result || typeof result !== 'object') return false;
	const value = result as Record<string, unknown>;
	if (value['schema_version'] !== 2 || value['isError'] === true) return false;
	const content = value['content'];
	if (!Array.isArray(content) || content.length === 0) return false;
	return content.every((block) => {
		if (!block || typeof block !== 'object') return false;
		const row = block as Record<string, unknown>;
		return row['type'] !== 'error' && row['isError'] !== true;
	});
}

export type ActiveClientRestartAttestation =
	| { status: 'not-required' }
	| { status: 'incomplete'; missing_calls: string[] }
	| { status: 'blocked'; error_code: string; next_action: string }
	| { status: 'verified'; client: string; receipt_id: string; attestation_digest: string };

type PreflightResult = {
	ok: false;
	error_code: 'restart_attestation_call_out_of_order';
	next_action: string;
};

function pendingReceiptId(env: NodeJS.ProcessEnv): string | null {
	const alias = (env['STONEWRIGHT_SITE_ALIAS'] ?? '').trim();
	if (!alias) return null;
	const site = findSiteByAlias(loadRegistry({ env }).registry, alias);
	if (!site) return null;
	const pending = Object.values(site.clients).filter((binding) => binding.pending_restart);
	return pending.length === 1 ? pending[0].pending_restart!.receipt_id : null;
}

/** Process-local state for the exact successful restart verification sequence. */
export class ActiveClientCallSequence {
	private receiptId: string | null = null;
	private readonly successful: string[] = [];

	preflight(env: NodeJS.ProcessEnv, name: string): PreflightResult | null {
		const receiptId = pendingReceiptId(env);
		if (!receiptId) {
			this.receiptId = null;
			this.successful.length = 0;
			return null;
		}
		if (this.receiptId !== receiptId) {
			this.receiptId = receiptId;
			this.successful.length = 0;
		}
		if (!(REQUIRED_ACTIVE_HOST_CALLS as readonly string[]).includes(name)) return null;
		const expected = REQUIRED_ACTIVE_HOST_CALLS[this.successful.length];
		if (name === expected) return null;
		return {
			ok: false,
			error_code: 'restart_attestation_call_out_of_order',
			next_action: expected
				? `Call ${expected} successfully next; restart attestation requires the documented order.`
				: 'The restart verification sequence is already complete for this process.',
		};
	}

	recordSuccess(env: NodeJS.ProcessEnv, name: string): void {
		const receiptId = pendingReceiptId(env);
		if (!receiptId || receiptId !== this.receiptId) return;
		if (REQUIRED_ACTIVE_HOST_CALLS[this.successful.length] === name) this.successful.push(name);
	}

	successfulCalls(): readonly string[] {
		return [...this.successful];
	}
}

function canonicalAttestation(input: {
	receiptId: string;
	client: string;
	mcpClientName: string;
	expectedPackage: string;
	expectedPackageProvenance: string;
	expectedVersion: string;
	companionVersion: string;
	configBeforeSha256: string;
	configAfterSha256: string;
	processStartId: string;
	catalogDigest: string;
	catalogObservationDigest: string;
	expiresAt: string;
}): string {
	return JSON.stringify({
		status: 'verified',
		attestation_scope: 'active-client',
		receipt_id: input.receiptId,
		client: input.client,
		mcp_client_name: input.mcpClientName,
		expected_package: input.expectedPackage,
		expected_package_provenance: input.expectedPackageProvenance,
		expected_version: input.expectedVersion,
		companion_version: input.companionVersion,
		config_before_sha256: input.configBeforeSha256,
		config_after_sha256: input.configAfterSha256,
		process_start_id: input.processStartId,
		catalog_digest: input.catalogDigest,
		catalog_observation_digest: input.catalogObservationDigest,
		expires_at: input.expiresAt,
	});
}

function safeSame(a: string, b: string): boolean {
	const left = Buffer.from(a);
	const right = Buffer.from(b);
	return left.length === right.length && timingSafeEqual(left, right);
}

export function verifyActiveClientRestartProof(
	value: unknown,
	registryKey?: string,
	consumedReceiptId?: string,
): boolean {
	if (!registryKey || !consumedReceiptId || !value || typeof value !== 'object') return false;
	const proof = value as Record<string, unknown>;
	if (proof['status'] !== 'verified' || proof['attestation_scope'] !== 'active-client') return false;
	const requiredStrings = [
		'receipt_id', 'client', 'mcp_client_name', 'expected_package', 'expected_package_provenance',
		'expected_version', 'companion_version', 'config_before_sha256', 'config_after_sha256',
		'process_start_id', 'catalog_digest', 'catalog_observation_digest', 'expires_at',
		'verified_at', 'attestation_digest',
	] as const;
	if (requiredStrings.some((key) => typeof proof[key] !== 'string' || proof[key] === '')) return false;
	if (!safeSame(consumedReceiptId, proof['receipt_id'] as string)) return false;
	const identity = stonewrightPackageIdentity(proof['expected_package'] as string);
	if (!identity || identity.version !== proof['expected_version'] || identity.provenance !== proof['expected_package_provenance']) return false;
	if (proof['expected_version'] !== proof['companion_version']) return false;
	const verifiedAt = Date.parse(proof['verified_at'] as string);
	const expiresAt = Date.parse(proof['expires_at'] as string);
	if (!Number.isFinite(verifiedAt) || !Number.isFinite(expiresAt) || verifiedAt > expiresAt || Date.now() > expiresAt) return false;
	const material = canonicalAttestation({
		receiptId: proof['receipt_id'] as string,
		client: proof['client'] as string,
		mcpClientName: proof['mcp_client_name'] as string,
		expectedPackage: proof['expected_package'] as string,
		expectedPackageProvenance: proof['expected_package_provenance'] as string,
		expectedVersion: proof['expected_version'],
		companionVersion: proof['companion_version'],
		configBeforeSha256: proof['config_before_sha256'] as string,
		configAfterSha256: proof['config_after_sha256'] as string,
		processStartId: proof['process_start_id'] as string,
		catalogDigest: proof['catalog_digest'] as string,
		catalogObservationDigest: proof['catalog_observation_digest'] as string,
		expiresAt: proof['expires_at'] as string,
	});
	const expected = `hmac-sha256:${createHmac('sha256', registryKey).update(material).digest('hex')}`;
	return safeSame(expected, proof['attestation_digest'] as string);
}

function activeClientMatches(client: string, mcpClientName: string): boolean {
	const normalized = mcpClientName.trim().toLowerCase();
	if (!normalized) return false;
	if (client === 'codex') return normalized.includes('codex') || normalized.includes('chatgpt desktop');
	if (client === 'claude-desktop') return normalized.includes('claude');
	if (client === 'vscode-copilot') return normalized.includes('visual studio code') || normalized.includes('vscode');
	return normalized.includes(client.replaceAll('-', ' ')) || normalized.includes(client);
}

function blocked(errorCode: string, nextAction: string): ActiveClientRestartAttestation {
	return { status: 'blocked', error_code: errorCode, next_action: nextAction };
}

function boundConfigState(input: {
	client: string;
	configPath: string;
	serverName: string;
	expectedPackage: string;
	expectedConfigHash: string;
}): 'ok' | 'source-changed' | 'config-changed' {
	const adapter = getClientAdapter(input.client);
	if (!adapter) return 'source-changed';
	try {
		const entry = adapter.read(input.configPath, input.serverName);
		if (!entry) return 'source-changed';
		validateOfficialStonewrightNpxEntry(entry.command, entry.args);
		if (entry.args.filter((arg) => arg === input.expectedPackage).length !== 1) return 'source-changed';
		return safeSame(sha256Text(readFileSync(input.configPath, 'utf8')), input.expectedConfigHash)
			? 'ok'
			: 'config-changed';
	} catch {
		return 'config-changed';
	}
}

/** Complete a receipt only from the live host after the exact successful sequence. */
export function attestPendingRestartFromActiveHost(input: {
	env: NodeJS.ProcessEnv;
	processStartId: string;
	catalogDigest: string;
	catalogObservation: string;
	expectedCatalogObservation: string;
	successfulToolNames: readonly string[];
	registeredToolNames: readonly string[];
	refreshRequiredToolNames: readonly string[];
	mcpClientName: string;
}): ActiveClientRestartAttestation {
	const alias = (input.env['STONEWRIGHT_SITE_ALIAS'] ?? '').trim();
	if (!alias) return { status: 'not-required' };

	const loaded = loadRegistry({ env: input.env });
	const site = findSiteByAlias(loaded.registry, alias);
	if (!site) return { status: 'not-required' };
	const pending = Object.entries(site.clients).filter(([, binding]) => binding.pending_restart);
	if (pending.length === 0) return { status: 'not-required' };
	if (pending.length !== 1) {
		return blocked('restart_attestation_client_ambiguous', 'Complete one client update at a time, then repeat the active-host verification sequence.');
	}

	const missing = REQUIRED_ACTIVE_HOST_CALLS.filter((name, index) => input.successfulToolNames[index] !== name);
	if (missing.length > 0) return { status: 'incomplete', missing_calls: [...missing] };
	if (input.refreshRequiredToolNames.length > 0) {
		return blocked('restart_attestation_refresh_required', 'Re-list or restart the active client until refresh_required_tool_names is empty, then repeat client-surface-check.');
	}
	if (!safeSame(input.catalogObservation, input.expectedCatalogObservation)) {
		return blocked('restart_attestation_catalog_unobserved', 'Re-list the active MCP tools and pass the process-bound catalog observation from the current client-surface-check schema.');
	}

	const [client, binding] = pending[0];
	const receipt = binding.pending_restart!;
	if (binding.last_consumed_restart_receipt_id === receipt.receipt_id) {
		return blocked('restart_attestation_replayed', 'Run the safe companion update command again to mint a new one-time restart receipt.');
	}
	if (!binding.restart_attestation_key) {
		return blocked('restart_attestation_key_missing', 'Run the safe companion update command again to mint a registry-bound receipt.');
	}
	const expiresAt = Date.parse(receipt.expires_at);
	if (!Number.isFinite(expiresAt) || Date.now() > expiresAt) {
		return blocked('restart_attestation_expired', 'Run the safe companion update command again, then complete the verification sequence before it expires.');
	}
	const identity = stonewrightPackageIdentity(receipt.expected_package);
	if (
		receipt.client !== client || !identity || identity.version !== receipt.expected_version
		|| identity.provenance !== receipt.expected_package_provenance
	) {
		return blocked('restart_attestation_receipt_invalid', 'Run the safe companion update command again; the pending receipt is inconsistent.');
	}
	if (!activeClientMatches(client, input.mcpClientName)) {
		return blocked('restart_attestation_client_mismatch', `Complete verification inside the ${client} MCP client that owns this receipt.`);
	}
	if (receipt.expected_version !== APP_VERSION) {
		return blocked('restart_attestation_version_mismatch', `Restart the client with Stonewright companion ${receipt.expected_version}.`);
	}
	if (receipt.pre_restart_process_start_id && safeSame(receipt.pre_restart_process_start_id, input.processStartId)) {
		return blocked('restart_attestation_process_stale', 'Fully restart the AI client, then repeat task-start, setup-profile, status, and client-surface-check.');
	}
	if (!input.catalogDigest.startsWith('sha256:')) {
		return blocked('restart_attestation_catalog_missing', 'Re-list the active MCP tools, then repeat client-surface-check.');
	}
	if (receipt.pre_restart_catalog_digest && safeSame(receipt.pre_restart_catalog_digest, input.catalogDigest)) {
		return blocked('restart_attestation_catalog_stale', 'Fully restart the AI client so the new package catalog is loaded, then repeat the verification sequence.');
	}
	if (!binding.config_path) {
		return blocked('restart_attestation_config_changed', 'The bound client config path is missing; run the safe companion update command again.');
	}
	const configState = boundConfigState({
		client,
		configPath: binding.config_path,
		serverName: binding.server_name,
		expectedPackage: receipt.expected_package,
		expectedConfigHash: receipt.config_after_sha256,
	});
	if (configState === 'source-changed') {
		return blocked('restart_attestation_source_changed', 'Restore the exact expected Stonewright package source, then run the safe update again.');
	}
	if (configState === 'config-changed') {
		return blocked('restart_attestation_config_changed', 'The client config changed after update; review it and run the safe update again.');
	}

	const catalogObservationDigest = sha256Text(input.catalogObservation);
	const material = canonicalAttestation({
		receiptId: receipt.receipt_id,
		client,
		mcpClientName: input.mcpClientName,
		expectedPackage: receipt.expected_package,
		expectedPackageProvenance: receipt.expected_package_provenance,
		expectedVersion: receipt.expected_version,
		companionVersion: APP_VERSION,
		configBeforeSha256: receipt.config_before_sha256,
		configAfterSha256: receipt.config_after_sha256,
		processStartId: input.processStartId,
		catalogDigest: input.catalogDigest,
		catalogObservationDigest,
		expiresAt: receipt.expires_at,
	});
	const digest = `hmac-sha256:${createHmac('sha256', binding.restart_attestation_key).update(material).digest('hex')}`;
	const now = new Date().toISOString();
	const proof: RestartProof = {
		verified_at: now,
		status: 'verified',
		attestation_scope: 'active-client',
		receipt_id: receipt.receipt_id,
		client,
		mcp_client_name: input.mcpClientName,
		expected_package: receipt.expected_package,
		expected_package_provenance: receipt.expected_package_provenance,
		expected_version: receipt.expected_version,
		companion_version: APP_VERSION,
		config_before_sha256: receipt.config_before_sha256,
		config_after_sha256: receipt.config_after_sha256,
		process_start_id: input.processStartId,
		catalog_digest: input.catalogDigest,
		catalog_observation_digest: catalogObservationDigest,
		expires_at: receipt.expires_at,
		attestation_digest: digest,
	};

	let persisted = false;
	withRegistryLock(loaded.path, () => {
		const current = loadRegistry({ sitesFile: loaded.path, env: input.env });
		const currentSite = findSiteByAlias(current.registry, alias);
		const currentBinding = currentSite?.clients[client];
		const currentReceipt = currentBinding?.pending_restart;
		if (
			!currentSite || !currentBinding || !currentReceipt || !currentBinding.config_path
			|| currentBinding.last_consumed_restart_receipt_id === receipt.receipt_id
			|| currentReceipt.receipt_id !== receipt.receipt_id
			|| currentBinding.restart_attestation_key !== binding.restart_attestation_key
			|| JSON.stringify(currentReceipt) !== JSON.stringify(receipt)
			|| boundConfigState({
				client,
				configPath: currentBinding.config_path,
				serverName: currentBinding.server_name,
				expectedPackage: receipt.expected_package,
				expectedConfigHash: receipt.config_after_sha256,
			}) !== 'ok'
		) return;
		currentSite.clients[client] = {
			...currentBinding,
			pending_restart: undefined,
			last_restart_proof: proof,
			last_consumed_restart_receipt_id: receipt.receipt_id,
		};
		currentSite.last_verification = {
			at: now,
			ok: true,
			client,
			detail: 'active-client restart attested by ordered successful permanent gateways',
			active_mode: currentSite.preferred_active_mode,
			active_alias: currentSite.alias,
			companion_version: APP_VERSION,
			remote_tool_count: input.registeredToolNames.length,
			surface_digest: input.catalogDigest,
			task_start_available: true,
			status_available: true,
			refresh_required_tool_names: [],
			process_start_id: input.processStartId,
			catalog_digest: input.catalogDigest,
			client_observed_tool_names: [...REQUIRED_ACTIVE_HOST_CALLS],
			attestation_scope: 'active-client',
		};
		currentSite.updated_at = now;
		atomicWriteRegistry(loaded.path, current.registry, { persistBackup: false });
		persisted = true;
	});
	if (!persisted) {
		return blocked('restart_attestation_receipt_changed', 'The receipt or bound config changed while it was being verified. Repeat client-surface-check.');
	}

	return { status: 'verified', client, receipt_id: receipt.receipt_id, attestation_digest: digest };
}
