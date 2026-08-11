/**
 * Truthful connection status contract (schema_version: 2).
 *
 * Shared by setup/doctor/task-start/status/client-surface-check.
 * `connected` remains a backward-compatible derived field, not source of truth.
 */

import type { ConnectionStage } from './state-machine.js';
import { permanentGatewayMembership } from './permanent-gateways.js';

export const STATUS_SCHEMA_VERSION = 2 as const;

export type ConfiguredMode = 'direct-only' | 'plugin-only' | 'auto';
export type ActiveMode = 'direct' | 'plugin' | 'local-only' | 'none';

export type ClientVisibilityState = 'unverified' | 'attested' | 'invoked';

export interface ClientVisibility {
	state: ClientVisibilityState;
	reason: string;
}

export interface SurfaceStatus {
	profile: string;
	local_tool_count: number;
	remote_tool_count: number;
	registered_tool_count: number;
	revision: number;
	digest: string;
	relist_required: boolean;
}

export interface PluginStatus {
	reachable: boolean | null;
	enabled_requested: boolean;
	effective_state: string;
	registry_ready: boolean;
}

export interface ConnectionStatusV2 {
	schema_version: typeof STATUS_SCHEMA_VERSION;
	site_alias: string | null;
	configured_mode: ConfiguredMode;
	active_mode: ActiveMode;
	connection_stage: ConnectionStage;
	connection_generation: number;
	transport: {
		kind: 'stdio' | 'http' | 'unknown';
		mcp_url: string | null;
	};
	authentication: {
		configured: boolean;
		method: 'app-password' | 'authorization' | 'oauth' | 'none' | 'unknown';
	};
	wordpress_runtime: {
		reachable: boolean | null;
		site_url: string | null;
	};
	plugin: PluginStatus;
	surface: SurfaceStatus;
	client_visibility: ClientVisibility;
	error_code: string | null;
	next_action: string | null;
	/** Derived backward-compatible field — not source of truth. */
	connected: boolean;
	ok: boolean;
	startup_ready: boolean;
	refresh_required_tool_names: string[];
	client_task_catalog_stale?: boolean;
	relist_or_restart_action?: string | null;
}

export interface ClientHasToolContext {
	/** Tool names the client attested via observed_tool_names. */
	observedToolNames?: readonly string[] | null;
	/** Tool names successfully invoked this session. */
	invokedToolNames?: ReadonlySet<string> | null;
}

/**
 * NEVER infer client_has_tool from remote_tool_count, connected, or startup_ready.
 * Only permanent gateway membership, explicit attestation, or session invocation.
 */
export function clientHasTool(toolName: string, ctx: ClientHasToolContext = {}): boolean {
	const normalized = normalizeToolName(toolName);
	if (permanentGatewayMembership(normalized)) {
		return true;
	}
	if (ctx.observedToolNames?.some((name) => normalizeToolName(name) === normalized)) {
		return true;
	}
	if (ctx.invokedToolNames?.has(normalized)) {
		return true;
	}
	return false;
}

export function defaultClientVisibility(reason = 'Client tool list not yet attested or invoked this session.'): ClientVisibility {
	return { state: 'unverified', reason };
}

export function clientVisibilityFromEvidence(
	ctx: ClientHasToolContext,
	options: { attested?: boolean; invoked?: boolean } = {},
): ClientVisibility {
	if (options.invoked || (ctx.invokedToolNames && ctx.invokedToolNames.size > 0)) {
		return { state: 'invoked', reason: 'At least one tool was invoked this session.' };
	}
	if (options.attested || (ctx.observedToolNames && ctx.observedToolNames.length > 0)) {
		return { state: 'attested', reason: 'Client supplied observed_tool_names attestation.' };
	}
	return defaultClientVisibility();
}

/**
 * refresh_required_tool_names = requested profile tools not currently registered.
 * NEVER a static hardcoded list alone.
 */
export function computeRefreshRequiredToolNames(
	requestedToolNames: readonly string[],
	registeredToolNames: readonly string[],
): string[] {
	const registered = new Set(registeredToolNames);
	return requestedToolNames.filter((name) => !registered.has(name));
}

export function mapConfiguredMode(envMode: string | null | undefined): ConfiguredMode {
	const raw = (envMode ?? 'auto').trim().toLowerCase();
	if (raw === 'direct' || raw === 'direct-only') return 'direct-only';
	if (raw === 'plugin' || raw === 'plugin-only') return 'plugin-only';
	return 'auto';
}

export function buildConnectionStatusV2(input: {
	siteAlias?: string | null;
	configuredMode: ConfiguredMode;
	activeMode: ActiveMode;
	connectionStage: ConnectionStage;
	connectionGeneration: number;
	transportKind?: 'stdio' | 'http' | 'unknown';
	mcpUrl?: string | null;
	authConfigured: boolean;
	authMethod?: ConnectionStatusV2['authentication']['method'];
	wpReachable?: boolean | null;
	siteUrl?: string | null;
	plugin: PluginStatus;
	surface: SurfaceStatus;
	clientVisibility?: ClientVisibility;
	errorCode?: string | null;
	nextAction?: string | null;
	startupReady: boolean;
	refreshRequiredToolNames?: string[];
	clientTaskCatalogStale?: boolean;
	relistOrRestartAction?: string | null;
	/** Extra derived ok override. */
	ok?: boolean;
}): ConnectionStatusV2 {
	const connected = input.connectionStage === 'plugin-ready'
		|| input.connectionStage === 'direct-ready'
		|| input.connectionStage === 'plugin-registering'
		|| input.connectionStage === 'plugin-authenticated';
	const ok = input.ok ?? (input.startupReady && !input.errorCode);

	return {
		schema_version: STATUS_SCHEMA_VERSION,
		site_alias: input.siteAlias ?? null,
		configured_mode: input.configuredMode,
		active_mode: input.activeMode,
		connection_stage: input.connectionStage,
		connection_generation: input.connectionGeneration,
		transport: {
			kind: input.transportKind ?? 'stdio',
			mcp_url: input.mcpUrl ?? null,
		},
		authentication: {
			configured: input.authConfigured,
			method: input.authMethod ?? (input.authConfigured ? 'unknown' : 'none'),
		},
		wordpress_runtime: {
			reachable: input.wpReachable ?? null,
			site_url: input.siteUrl ?? null,
		},
		plugin: input.plugin,
		surface: input.surface,
		client_visibility: input.clientVisibility ?? defaultClientVisibility(),
		error_code: input.errorCode ?? null,
		next_action: input.nextAction ?? null,
		connected,
		ok,
		startup_ready: input.startupReady,
		refresh_required_tool_names: input.refreshRequiredToolNames ?? [],
		...(input.clientTaskCatalogStale !== undefined
			? { client_task_catalog_stale: input.clientTaskCatalogStale }
			: {}),
		...(input.relistOrRestartAction !== undefined
			? { relist_or_restart_action: input.relistOrRestartAction }
			: {}),
	};
}

export function normalizeToolName(name: string): string {
	const trimmed = name.trim().replaceAll('/', '-');
	if (!trimmed) return trimmed;
	return trimmed.startsWith('stonewright-') ? trimmed : `stonewright-${trimmed}`;
}

/** Capability comparison for stonewright-mode-capabilities. */
export interface ModeCapabilityRow {
	capability: string;
	direct: 'yes' | 'no' | 'limited';
	plugin: 'yes' | 'no' | 'limited';
	notes: string;
}

export function modeCapabilitiesComparison(): ModeCapabilityRow[] {
	return [
		{
			capability: 'read_content',
			direct: 'yes',
			plugin: 'yes',
			notes: 'Both modes can read posts/pages/media via REST or typed abilities.',
		},
		{
			capability: 'typed_updates',
			direct: 'limited',
			plugin: 'yes',
			notes: 'Direct uses core REST; plugin exposes full typed ability surface.',
		},
		{
			capability: 'elementor_writes',
			direct: 'limited',
			plugin: 'yes',
			notes: 'Direct can patch _elementor_data (WP-CLI/REST meta); schema-safe batch-mutate is plugin-only.',
		},
		{
			capability: 'custom_code_apply',
			direct: 'no',
			plugin: 'limited',
			notes: 'Custom code requires human approval grant; Pluginless Direct must not write custom CSS.',
		},
		{
			capability: 'backup',
			direct: 'limited',
			plugin: 'yes',
			notes: 'Direct file-level backups for Elementor meta; plugin Backup::snapshot_post for engine writes.',
		},
		{
			capability: 'confirmation_tokens',
			direct: 'no',
			plugin: 'yes',
			notes: 'Production-safe ConfirmationToken issue/verify is plugin-only.',
		},
		{
			capability: 'php_execute',
			direct: 'no',
			plugin: 'yes',
			notes: 'stonewright-php-execute requires the Stonewright plugin runtime.',
		},
	];
}
