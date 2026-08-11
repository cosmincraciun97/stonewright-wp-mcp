/**
 * Connect CLI command implementations (add/list/use/verify/repair/remove/migrate/detect-client).
 */
import { homedir } from 'node:os';
import {
	buildStdioServerEntry,
	detectClients,
	getClientAdapter,
	listClientCatalog,
	type ClientAdapter,
	type McpServerEntry,
} from '../clients/index.js';
import {
	type CreateCredentialStoreOptions,
	MemoryCredentialStore,
	createCredentialStore,
	parseCredentialRef,
	resolveCredentialSecret,
	storeSiteSecret,
} from '../../credentials/index.js';
import { createHash } from 'node:crypto';
import { restoreFileSnapshot, snapshotFile } from '../clients/atomic-config.js';
import { WordPressMcpClient } from '../../wordpress-mcp.js';
import { Client as McpClient } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';
import { APP_VERSION } from '../../version.js';
import { configuredModeToEnv, mayProbePlugin, resolveActiveMode } from './mode-policy.js';
import {
	buildSiteRecord,
	findSiteByAlias,
	findSiteById,
	findDuplicateEndpoint,
	loadRegistry,
	makeEnvCredentialRef,
	migrateSitesFile,
	removeSite as registryRemoveSite,
	resolveSitePassword,
	saveRegistry,
	setDefaultSite,
	upsertSite,
	type LoadRegistryOptions,
} from './registry.js';
import { mcpServerName } from './server-name.js';
import {
	ConnectError,
	type ConnectReceipt,
	type BrowserPreferences,
	type ConsentState,
	type PluginExpectations,
	type SiteEnvironment,
	type SiteRecordV2,
	type SitesRegistryV2,
} from './types.js';

export interface ConnectContext {
	sitesFile?: string | undefined;
	homeDir?: string | undefined;
	env?: NodeJS.ProcessEnv | undefined;
	credentials?: CreateCredentialStoreOptions | undefined;
	/** Skip live HTTP auth (tests). */
	skipAuth?: boolean | undefined;
	fetchImpl?: typeof fetch | undefined;
	packageSpec?: string | undefined;
	/** Override client config path for add/repair (tests and advanced use). */
	clientConfigPath?: string | undefined;
	/** Injectable persistence boundary for transaction-failure tests. */
	saveRegistryImpl?: typeof saveRegistry | undefined;
	/** Injectable live runtime probe for certified-client tests. */
	runtimeVerifier?: ((site: SiteRecordV2, password: string | null, entry?: McpServerEntry) => Promise<RuntimeVerification>) | undefined;
}

export interface RuntimeVerification {
	ok: boolean;
	detail: string;
	companion_version?: string | undefined;
	active_alias?: string | undefined;
	remote_tool_names?: string[] | undefined;
	task_start_available?: boolean | undefined;
	status_available?: boolean | undefined;
}

function ctxPaths(ctx: ConnectContext): LoadRegistryOptions {
	const out: LoadRegistryOptions = {};
	if (ctx.sitesFile !== undefined) out.sitesFile = ctx.sitesFile;
	if (ctx.homeDir !== undefined) out.homeDir = ctx.homeDir;
	if (ctx.env !== undefined) out.env = ctx.env;
	if (ctx.credentials !== undefined) out.credentials = ctx.credentials;
	return out;
}

function companionPackageSpec(version = APP_VERSION): string {
	return `https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v${version}/stonewright-companion-${version}.tgz`;
}

function writeOut(msg: string): void {
	process.stdout.write(`${msg}\n`);
}

function writeErr(msg: string): void {
	process.stderr.write(`${msg}\n`);
}

function receiptLines(r: ConnectReceipt): string {
	const parts = [
		`ok=${r.ok}`,
		`site_id=${r.site_id}`,
		`site_alias=${r.site_alias}`,
		`environment=${r.environment}`,
		`configured_mode=${r.configured_mode}`,
	];
	if (r.active_mode) parts.push(`active_mode=${r.active_mode}`);
	parts.push(r.message);
	return parts.join(' | ');
}

async function verifyAuth(
	url: string,
	username: string,
	password: string,
	fetchImpl: typeof fetch,
): Promise<{ ok: boolean; detail: string }> {
	const base = url.replace(/\/+$/, '');
	const auth = Buffer.from(`${username}:${password.replace(/\s+/g, '')}`).toString('base64');
	try {
		const res = await fetchImpl(`${base}/wp-json/wp/v2/users/me`, {
			headers: { Authorization: `Basic ${auth}`, Accept: 'application/json' },
		});
		if (res.ok) {
			return { ok: true, detail: `Authenticated (HTTP ${res.status})` };
		}
		return { ok: false, detail: `Auth failed HTTP ${res.status}` };
	} catch (err) {
		return { ok: false, detail: err instanceof Error ? err.message : String(err) };
	}
}

function saveRegistryForContext(registry: SitesRegistryV2, ctx: ConnectContext) {
	return (ctx.saveRegistryImpl ?? saveRegistry)(registry, ctxPaths(ctx));
}

function credentialStoreForRef(ref: string, ctx: ConnectContext) {
	const parsed = parseCredentialRef(ref);
	if (parsed.kind === 'env') return null;
	if (ctx.credentials?.store) return ctx.credentials.store;
	return createCredentialStore(ctx.credentials ?? { allowMemoryFallback: false });
}

function restoreCredential(
	ref: string,
	previousSecret: string | null,
	ctx: ConnectContext,
): void {
	const parsed = parseCredentialRef(ref);
	if (parsed.kind === 'env') {
		const env = ctx.env ?? process.env;
		if (previousSecret === null) delete env[parsed.envVar ?? parsed.service];
		else env[parsed.envVar ?? parsed.service] = previousSecret;
		return;
	}
	const store = credentialStoreForRef(ref, ctx);
	if (!store) return;
	if (previousSecret === null) store.delete(ref);
	else store.set(ref, previousSecret);
}

function deleteCredentialIfUnreferenced(
	ref: string,
	registry: SitesRegistryV2,
	ctx: ConnectContext,
): void {
	if (registry.sites.some((site) => site.credential_ref === ref)) return;
	const parsed = parseCredentialRef(ref);
	// Environment variables are user-owned and must never be unset by registry removal.
	if (parsed.kind === 'env') return;
	credentialStoreForRef(ref, ctx)?.delete(ref);
}

function pluginExpectations(input: ConnectAddInput, prior?: PluginExpectations): PluginExpectations {
	if (input.wordpressMode && !['development', 'staging', 'production-safe'].includes(input.wordpressMode)) {
		throw new ConnectError('invalid_wordpress_mode', `Unsupported WordPress mode: ${input.wordpressMode}`);
	}
	if (input.wordpressToolSurface && !['bootstrap', 'essential', 'full'].includes(input.wordpressToolSurface)) {
		throw new ConnectError('invalid_wordpress_surface', `Unsupported WordPress tool surface: ${input.wordpressToolSurface}`);
	}
	const pluginRequested = input.pluginEnabled ?? prior?.enabled_requested ?? input.mode !== 'direct-only';
	return {
		required: input.mode === 'plugin-only',
		enabled_requested: pluginRequested,
		wordpress_mode: input.wordpressMode ?? prior?.wordpress_mode ?? 'development',
		wordpress_tool_surface: input.wordpressToolSurface ?? prior?.wordpress_tool_surface ?? 'essential',
		elementor_v4_atomic: input.elementorV4Atomic ?? prior?.elementor_v4_atomic ?? false,
		...(prior?.min_version ? { min_version: prior.min_version } : {}),
		...(prior?.abilities ? { abilities: prior.abilities } : {}),
	};
}

function browserPreferences(
	input: Pick<ConnectAddInput, 'browserProvider' | 'browserScanConsent' | 'browserInstallConsent'>,
	prior?: BrowserPreferences,
): BrowserPreferences {
	if (input.browserProvider && !['recommended', 'connected-browser', 'none', 'unset'].includes(input.browserProvider)) {
		throw new ConnectError('invalid_browser_provider', `Unsupported browser provider: ${input.browserProvider}`);
	}
	for (const [name, value] of [
		['browser scan consent', input.browserScanConsent],
		['browser install consent', input.browserInstallConsent],
	] as const) {
		if (value && !['granted', 'denied', 'unknown'].includes(value)) {
			throw new ConnectError('invalid_browser_consent', `Unsupported ${name}: ${value}`);
		}
	}
	return {
		provider: input.browserProvider ?? prior?.provider ?? 'unset',
		scan_consent: input.browserScanConsent ?? prior?.scan_consent ?? 'unknown',
		install_consent: input.browserInstallConsent ?? prior?.install_consent ?? 'unknown',
	};
}

function toolNameMatches(names: string[], expected: string): boolean {
	const canonical = expected.replace(/^stonewright[-/]/, '').replaceAll('/', '-');
	return names.some((name) => name.replace(/^stonewright[-/]/, '').replaceAll('/', '-') === canonical);
}

async function defaultRuntimeVerifier(
	site: SiteRecordV2,
	password: string | null,
	fetchImpl: typeof fetch,
	entry?: McpServerEntry,
): Promise<RuntimeVerification> {
	if (entry) {
		const env = Object.fromEntries(
			Object.entries({ ...process.env, ...entry.env }).filter((row): row is [string, string] => typeof row[1] === 'string'),
		);
		const transport = new StdioClientTransport({
			command: entry.command,
			args: entry.args,
			env,
			stderr: 'pipe',
		});
		const client = new McpClient({ name: 'stonewright-connect-verify', version: APP_VERSION });
		try {
			await client.connect(transport);
			const listed = await client.listTools({}, { timeout: 20_000 });
			const names = listed.tools.map((tool) => tool.name);
			const taskName = names.find((name) => toolNameMatches([name], 'stonewright-task-start'));
			const statusName = names.find((name) => toolNameMatches([name], 'stonewright-wordpress-mcp-status'));
			if (taskName) {
				await client.callTool({
					name: taskName,
					arguments: {
						task: 'Verify the saved Stonewright site connection.',
						intent: 'read-only connection verification',
						surface: site.plugin_expectations?.wordpress_tool_surface ?? 'essential',
					},
				}, undefined, { timeout: 20_000 });
			}
			const status = statusName
				? await client.callTool({ name: statusName, arguments: {} }, undefined, { timeout: 20_000 })
				: null;
			const statusText = JSON.stringify(status ?? {});
			const versionMatch = statusText.match(/"companion_version"\s*:\s*"([^"]+)"/);
			const required = site.plugin_expectations?.abilities ?? [];
			const missing = required.filter((name) => !toolNameMatches(names, name));
			return {
				ok: Boolean(taskName && statusName && missing.length === 0),
				detail: missing.length > 0
					? `Spawned client runtime missing required tools: ${missing.join(', ')}`
					: `Spawned client runtime exposed ${names.length} tools; task-start and status completed.`,
				companion_version: versionMatch?.[1] ?? APP_VERSION,
				active_alias: entry.env.STONEWRIGHT_SITE_ALIAS ?? site.alias,
				remote_tool_names: names,
				task_start_available: Boolean(taskName),
				status_available: Boolean(statusName),
			};
		} catch (err) {
			return { ok: false, detail: `Spawned client runtime failed: ${err instanceof Error ? err.message : String(err)}` };
		} finally {
			await transport.close().catch(() => undefined);
		}
	}
	if (site.configured_mode === 'direct-only') {
		return {
			ok: true,
			detail: 'Site credentials are valid; pass --client to spawn and prove the configured Direct companion runtime.',
			companion_version: APP_VERSION,
			active_alias: site.alias,
		};
	}
	if (!password) {
		return { ok: false, detail: 'Credential unavailable for live MCP runtime verification.' };
	}
	try {
		const client = new WordPressMcpClient(
			{
				url: `${site.canonical_url}/wp-json/mcp/stonewright`,
				username: site.username_hint,
				password,
				timeoutMs: 15_000,
			},
			fetchImpl,
		);
		const tools = await client.listTools();
		const names = tools.map((tool) => tool.name);
		const taskName = names.find((name) => toolNameMatches([name], 'stonewright-task-start'));
		const statusName = names.find((name) => toolNameMatches([name], 'stonewright-wordpress-mcp-status'));
		if (taskName) {
			await client.callTool(taskName, {
				task: 'Verify the saved Stonewright site connection.',
				intent: 'read-only connection verification',
				surface: site.plugin_expectations?.wordpress_tool_surface ?? 'essential',
			});
		}
		if (statusName) await client.callTool(statusName, {});
		const required = site.plugin_expectations?.abilities ?? [];
		const missing = required.filter((name) => !toolNameMatches(names, name));
		return {
			ok: Boolean(taskName && statusName && missing.length === 0),
			detail: missing.length > 0
				? `Live MCP missing required tools: ${missing.join(', ')}`
				: `Live MCP exposed ${names.length} tools; task-start and status completed.`,
			active_alias: site.alias,
			remote_tool_names: names,
			task_start_available: Boolean(taskName),
			status_available: Boolean(statusName),
		};
	} catch (err) {
		return { ok: false, detail: err instanceof Error ? err.message : String(err) };
	}
}

function takenServerNames(registry: SitesRegistryV2, excludeSiteId?: string): Set<string> {
	const taken = new Set<string>();
	for (const site of registry.sites) {
		if (excludeSiteId && site.id === excludeSiteId) continue;
		for (const binding of Object.values(site.clients)) {
			if (binding.server_name) taken.add(binding.server_name);
		}
	}
	return taken;
}

export function connectDetectClient(ctx: ConnectContext = {}): number {
	const home = ctx.homeDir ?? homedir();
	const detected = detectClients(home);
	const catalog = listClientCatalog();
	writeOut(JSON.stringify({ detected, catalog }, null, 2));
	return 0;
}

export interface ConnectAddInput {
	alias: string;
	url: string;
	username: string;
	/**
	 * Application Password value. Prefer `passwordEnv` or interactive prompt;
	 * argv `--password` is discouraged (shell history) and kept for tests.
	 */
	password?: string | undefined;
	/**
	 * Read the Application Password from process.env[passwordEnv] for this
	 * invocation (does not by itself create an env:// credential_ref).
	 */
	passwordEnv?: string | undefined;
	environment?: SiteEnvironment | undefined;
	mode?: string | undefined;
	client?: string | undefined;
	clientConfigPath?: string | undefined;
	replace?: boolean | undefined;
	makeDefault?: boolean | undefined;
	/** Use env://VAR instead of OS store. */
	credentialEnv?: string | undefined;
	companionProfile?: string | undefined;
	/** When set, skip interactive prompts. */
	yes?: boolean | undefined;
	/**
	 * Optional password prompt for TTY (injected in tests).
	 * When password is missing and stdin is a TTY, connectAdd uses this or a default hidden prompt.
	 */
	promptPassword?: (() => Promise<string>) | undefined;
	pluginEnabled?: boolean | undefined;
	wordpressMode?: 'development' | 'staging' | 'production-safe' | undefined;
	wordpressToolSurface?: 'bootstrap' | 'essential' | 'full' | undefined;
	elementorV4Atomic?: boolean | undefined;
	browserProvider?: BrowserPreferences['provider'] | undefined;
	browserScanConsent?: ConsentState | undefined;
	browserInstallConsent?: ConsentState | undefined;
}

/**
 * Resolve the Application Password for connect add without requiring argv.
 * Order: explicit password → --password-env → interactive prompt → credential-env only.
 */
export async function resolveConnectPassword(
	input: Pick<ConnectAddInput, 'password' | 'passwordEnv' | 'credentialEnv' | 'promptPassword' | 'yes'>,
	env: NodeJS.ProcessEnv = process.env,
): Promise<{ password: string; source: 'argv' | 'password-env' | 'prompt' | 'none' }> {
	const fromArgv = (input.password ?? '').trim();
	if (fromArgv) {
		return { password: fromArgv, source: 'argv' };
	}
	const envName = (input.passwordEnv ?? '').trim();
	if (envName) {
		const fromEnv = (env[envName] ?? '').trim();
		if (!fromEnv) {
			throw new ConnectError(
				'password_env_missing',
				`Environment variable ${envName} is empty or unset (from --password-env).`,
			);
		}
		return { password: fromEnv, source: 'password-env' };
	}
	if (input.credentialEnv && (env[input.credentialEnv] ?? '').trim()) {
		// credential-env already holds the secret; optional for store path.
		return { password: (env[input.credentialEnv] ?? '').trim(), source: 'password-env' };
	}
	if (input.yes) {
		return { password: '', source: 'none' };
	}
	const prompt = input.promptPassword;
	if (prompt) {
		const value = (await prompt()).trim();
		return { password: value, source: 'prompt' };
	}
	if (process.stdin.isTTY && process.stdout.isTTY) {
		const value = (await defaultHiddenPasswordPrompt()).trim();
		return { password: value, source: 'prompt' };
	}
	return { password: '', source: 'none' };
}

async function defaultHiddenPasswordPrompt(): Promise<string> {
	const { createInterface } = await import('node:readline');
	return new Promise((resolve, reject) => {
		const rl = createInterface({ input: process.stdin, output: process.stdout });
		const output = process.stdout;
		// Mute echo by writing prompt then reading raw if possible.
		output.write('Application Password (input hidden when supported): ');
		const stdin = process.stdin;
		if (typeof stdin.setRawMode === 'function') {
			const chars: string[] = [];
			const onData = (buf: Buffer) => {
				const s = buf.toString('utf8');
				for (const ch of s) {
					if (ch === '\n' || ch === '\r' || ch === '\u0004') {
						stdin.setRawMode?.(false);
						stdin.removeListener('data', onData);
						output.write('\n');
						rl.close();
						resolve(chars.join(''));
						return;
					}
					if (ch === '\u0003') {
						stdin.setRawMode?.(false);
						stdin.removeListener('data', onData);
						rl.close();
						reject(new Error('Interrupted'));
						return;
					}
					if (ch === '\u007f' || ch === '\b') {
						chars.pop();
						continue;
					}
					chars.push(ch);
				}
			};
			stdin.setRawMode(true);
			stdin.resume();
			stdin.on('data', onData);
			return;
		}
		rl.question('', (answer) => {
			rl.close();
			resolve(answer);
		});
	});
}

export async function connectAdd(input: ConnectAddInput, ctx: ConnectContext = {}): Promise<number> {
	const loaded = loadRegistry(ctxPaths(ctx));
	let registry = loaded.registry;

	// If file is still v1 on disk, migrate first (secure) so we don't mix schemas.
	if (loaded.schema_was === 1 && loaded.source === 'file') {
		try {
			migrateSitesFile({ ...ctxPaths(ctx), allowEnvRef: Boolean(input.credentialEnv) });
			registry = loadRegistry(ctxPaths(ctx)).registry;
		} catch (err) {
			if (err instanceof ConnectError && err.code === 'secure_migration_failed') {
				writeErr(err.message);
				return 1;
			}
			// If migration fails because secure store unavailable and user gave credentialEnv, retry
			if (input.credentialEnv) {
				migrateSitesFile({ ...ctxPaths(ctx), allowEnvRef: true });
				registry = loadRegistry(ctxPaths(ctx)).registry;
			} else {
				throw err;
			}
		}
	}

	const existing = findSiteByAlias(registry, input.alias);
	if (existing && !input.replace) {
		writeErr(
			`Alias "${input.alias}" already exists (id=${existing.id}). Clients: ${Object.keys(existing.clients).join(', ') || '(none)'}. Pass --replace to overwrite.`,
		);
		return 1;
	}
	if (existing && input.replace) {
		writeOut(
			`Replacing alias "${existing.alias}" (id=${existing.id}). Affected clients: ${Object.keys(existing.clients).join(', ') || '(none)'}.`,
		);
	}
	let requestedPluginExpectations: PluginExpectations;
	let requestedBrowserPreferences: BrowserPreferences;
	try {
		requestedPluginExpectations = pluginExpectations(input, existing?.plugin_expectations);
		requestedBrowserPreferences = browserPreferences(
			input,
			input.client ? existing?.clients[input.client]?.browser : undefined,
		);
	} catch (err) {
		writeErr(err instanceof Error ? err.message : String(err));
		return 1;
	}

	// Reject duplicate URL+environment before touching a credential store or client config.
	const requestedEnvironment = input.environment ?? 'other';
	const duplicate = findDuplicateEndpoint(
		registry,
		input.url,
		requestedEnvironment,
		existing?.id,
	);
	if (duplicate) {
		writeErr(
			`duplicate_site: Canonical URL + environment already registered as alias "${duplicate.alias}". Refusing to clone.`,
		);
		return 1;
	}

	let password: string;
	try {
		const resolved = await resolveConnectPassword(input, ctx.env ?? process.env);
		password = resolved.password;
	} catch (err) {
		if (err instanceof ConnectError) {
			writeErr(err.message);
			return 1;
		}
		throw err;
	}
	if (!password && !input.credentialEnv) {
		writeErr(
			'Application Password is required. Prefer interactive prompt, --password-env VAR, or --credential-env VAR. Avoid --password on argv (shell history).',
		);
		return 1;
	}

	if (!ctx.skipAuth && password) {
		const auth = await verifyAuth(input.url, input.username, password, ctx.fetchImpl ?? fetch);
		if (!auth.ok) {
			writeErr(`Auth failed: ${auth.detail}`);
			return 1;
		}
		writeOut(auth.detail);
	}

	const previousCredentialRef = existing?.credential_ref ?? null;
	const previousCredentialSecret = previousCredentialRef
		? resolveCredentialSecret(previousCredentialRef, ctx.credentials ?? { env: ctx.env ?? process.env })
		: null;
	let credential_ref: string;
	try {
		if (input.credentialEnv) {
			credential_ref = makeEnvCredentialRef(input.credentialEnv);
			if (password) {
				// Seed process env for this session / tests
				const env = ctx.env ?? process.env;
				env[input.credentialEnv] = password;
				if (ctx.credentials?.store) {
					ctx.credentials.store.set(credential_ref, password);
				}
			}
		} else {
			credential_ref = storeSiteSecret({
				alias: input.alias,
				secret: password,
				purpose: 'app-password',
				options: ctx.credentials ?? { allowMemoryFallback: false },
				allowEnvRef: false,
			});
		}
	} catch (err) {
		const msg = err instanceof Error ? err.message : String(err);
		writeErr(`Secure credential store failed: ${msg}`);
		writeErr('Registry left unchanged. Use --credential-env VAR for headless environments.');
		return 1;
	}

	const site = buildSiteRecord({
		alias: input.alias,
		url: input.url,
		username: input.username,
		credential_ref,
		environment: input.environment ?? 'other',
		configured_mode: input.mode ?? 'auto',
		companion_profile: input.companionProfile ?? 'essential-static',
		plugin_expectations: requestedPluginExpectations,
		...(existing?.id ? { id: existing.id } : {}),
		clients: existing?.clients ?? {},
	});

	try {
		registry = upsertSite(registry, site, {
			...(input.replace !== undefined ? { replace: input.replace } : {}),
			makeDefault: input.makeDefault ?? registry.sites.length === 0,
		});
	} catch (err) {
		if (err instanceof ConnectError) {
			restoreCredential(credential_ref, credential_ref === previousCredentialRef ? previousCredentialSecret : null, ctx);
			writeErr(`${err.code}: ${err.message}`);
			if (err.details) writeErr(JSON.stringify(err.details));
			return 1;
		}
		throw err;
	}

	// Bind client if requested
	let clientApply: Record<string, unknown> | undefined;
	let rollbackClient: (() => void) | undefined;
	if (input.client) {
		try {
			const bindCtx: ConnectContext = { ...ctx };
			const configPath = input.clientConfigPath ?? ctx.clientConfigPath;
			if (configPath !== undefined) bindCtx.clientConfigPath = configPath;
			const result = applyClientBinding(
				registry,
				site,
				input.client,
				bindCtx,
				requestedBrowserPreferences,
			);
			registry = result.registry;
			clientApply = result.receipt;
			rollbackClient = result.rollback;
			// refresh site from registry
			const updated = findSiteById(registry, site.id);
			if (updated) Object.assign(site, updated);
		} catch (err) {
			restoreCredential(
				credential_ref,
				credential_ref === previousCredentialRef ? previousCredentialSecret : null,
				ctx,
			);
			writeErr(`Client binding failed; credential was rolled back: ${err instanceof Error ? err.message : String(err)}`);
			return 1;
		}
	}

	let persisted: ReturnType<typeof saveRegistry>;
	try {
		persisted = saveRegistryForContext(registry, ctx);
	} catch (err) {
		rollbackClient?.();
		restoreCredential(
			credential_ref,
			credential_ref === previousCredentialRef ? previousCredentialSecret : null,
			ctx,
		);
		writeErr(`Registry write failed; client config and credential were rolled back: ${err instanceof Error ? err.message : String(err)}`);
		return 1;
	}
	const { path, backupPath } = persisted;
	if (previousCredentialRef && previousCredentialRef !== credential_ref) {
		deleteCredentialIfUnreferenced(previousCredentialRef, registry, ctx);
	}

	const receipt: ConnectReceipt = {
		ok: true,
		site_id: site.id,
		site_alias: site.alias,
		environment: site.environment,
		configured_mode: site.configured_mode,
		active_mode: site.preferred_active_mode,
		message: `Site registered in ${path}`,
		details: {
			credential_ref: site.credential_ref.startsWith('env://')
				? site.credential_ref
				: site.credential_ref.replace(/^(keychain|memory|credman|secretservice):\/\/.*$/, '$1://***'),
			registry_backup: backupPath,
			client: clientApply,
		},
	};
	writeOut(receiptLines(receipt));
	if (clientApply) {
		writeOut(JSON.stringify({ client: clientApply }, null, 2));
	}
	return 0;
}

function applyClientBinding(
	registry: SitesRegistryV2,
	site: SiteRecordV2,
	clientId: string,
	ctx: ConnectContext,
	browser?: BrowserPreferences,
): { registry: SitesRegistryV2; receipt: Record<string, unknown>; rollback: () => void } {
	const adapter = getClientAdapter(clientId);
	if (!adapter) {
		throw new ConnectError(
			'client_unsupported',
			`Client "${clientId}" has no implemented adapter (community/untested). Use detect-client to list support tiers.`,
		);
	}
	const home = ctx.homeDir ?? homedir();
	const configPath = ctx.clientConfigPath ?? adapter.defaultConfigPath(home);
	const taken = takenServerNames(registry, site.id);
	// Preserve existing server name for this client+site when present
	const existingName = site.clients[adapter.id]?.server_name;
	const serverName =
		existingName && (!taken.has(existingName) || site.clients[adapter.id]?.server_name === existingName)
			? existingName
			: mcpServerName(site.alias, site.id, taken);

	const entry = buildStdioServerEntry({
		serverName,
		packageSpec: ctx.packageSpec ?? companionPackageSpec(),
		siteAlias: site.alias,
		modeEnv: configuredModeToEnv(site.configured_mode),
		toolProfile: site.companion_profile,
	});

	const before = snapshotFile(configPath);
	const applied = adapter.upsert(configPath, entry);
	const now = new Date().toISOString();
	const nextSite: SiteRecordV2 = {
		...site,
		clients: {
			...site.clients,
			[adapter.id]: {
				server_name: serverName,
				install_profile: site.companion_profile,
				config_path: configPath,
				last_applied_at: now,
				browser: browser ?? site.clients[adapter.id]?.browser ?? {
					provider: 'unset',
					scan_consent: 'unknown',
					install_consent: 'unknown',
				},
			},
		},
		updated_at: now,
	};
	const nextRegistry = upsertSite(registry, nextSite, { replace: true });
	return {
		registry: nextRegistry,
			receipt: {
			client: adapter.id,
			server_name: serverName,
			config_path: configPath,
			backup_path: applied.backupPath,
			created: applied.created,
			diff: applied.diff,
				support_tier: adapter.supportTier,
				browser: nextSite.clients[adapter.id]?.browser,
			},
		rollback: () => restoreFileSnapshot(configPath, before),
	};
}

export function connectList(ctx: ConnectContext = {}): number {
	const { registry, path, schema_was } = loadRegistry(ctxPaths(ctx));
	const rows = registry.sites.map((s) => ({
		id: s.id,
		alias: s.alias,
		environment: s.environment,
		canonical_url: s.canonical_url,
		configured_mode: s.configured_mode,
		preferred_active_mode: s.preferred_active_mode,
		is_default: s.id === registry.default_site_id,
		clients: Object.keys(s.clients),
		username_hint: s.username_hint,
	}));
	writeOut(
		JSON.stringify(
			{
				path,
				schema_version: 2,
				schema_was,
				default_site_id: registry.default_site_id,
				sites: rows,
			},
			null,
			2,
		),
	);
	return 0;
}

export function connectUse(alias: string, ctx: ConnectContext = {}): number {
	const { registry } = loadRegistry(ctxPaths(ctx));
	const site = findSiteByAlias(registry, alias);
	if (!site) {
		writeErr(`Unknown alias "${alias}"`);
		return 1;
	}
	const next = setDefaultSite(registry, alias);
	const { path } = saveRegistry(next, ctxPaths(ctx));
	const receipt: ConnectReceipt = {
		ok: true,
		site_id: site.id,
		site_alias: site.alias,
		environment: site.environment,
		configured_mode: site.configured_mode,
		active_mode: site.preferred_active_mode,
		message: `Default site set to "${site.alias}" in ${path}`,
	};
	writeOut(receiptLines(receipt));
	return 0;
}

export async function connectVerify(
	alias: string,
	opts: { client?: string } = {},
	ctx: ConnectContext = {},
): Promise<number> {
	const { registry, path } = loadRegistry(ctxPaths(ctx));
	const site = findSiteByAlias(registry, alias);
	if (!site) {
		writeErr(`Unknown alias "${alias}"`);
		return 1;
	}

	let password: string | null = null;
	try {
		password = resolveSitePassword(site, {
			sitesFile: path,
			...(ctx.credentials ? { credentials: ctx.credentials } : {}),
		});
	} catch (err) {
		writeErr(err instanceof Error ? err.message : String(err));
		return 1;
	}

	const checks: Array<{ id: string; ok: boolean; detail: string }> = [];

	// Mode policy structural check
	const probeAllowed = mayProbePlugin(site.configured_mode);
	checks.push({
		id: 'mode_policy',
		ok: true,
		detail: `configured_mode=${site.configured_mode}; may_probe_plugin=${probeAllowed}`,
	});

	if (!ctx.skipAuth && password) {
		const auth = await verifyAuth(site.canonical_url, site.username_hint, password, ctx.fetchImpl ?? fetch);
		checks.push({ id: 'rest_auth', ok: auth.ok, detail: auth.detail });
	} else {
		checks.push({ id: 'rest_auth', ok: true, detail: 'skipped' });
	}

	// Optional plugin probe (never for direct-only). skipAuth only skips REST auth, not mode probe.
	let active = resolveActiveMode({
		configured: site.configured_mode,
		fallbackPolicy: site.fallback_policy,
		probePresent: null,
	});
	if (!probeAllowed) {
		checks.push({
			id: 'plugin_probe',
			ok: true,
			detail: 'skipped (direct-only never probes plugin)',
		});
	} else {
		try {
			const endpoint = `${site.canonical_url}/wp-json/mcp/stonewright`;
			const res = await (ctx.fetchImpl ?? fetch)(endpoint, {
				method: 'GET',
				headers: { Accept: 'application/json' },
			});
			const present = res.status !== 404;
			active = resolveActiveMode({
				configured: site.configured_mode,
				fallbackPolicy: site.fallback_policy,
				probePresent: present,
			});
			checks.push({
				id: 'plugin_probe',
				ok: site.configured_mode !== 'plugin-only' || present,
				detail: `HTTP ${res.status}; ${active.reason}`,
			});
		} catch (err) {
			checks.push({
				id: 'plugin_probe',
				ok: site.configured_mode !== 'plugin-only',
				detail: err instanceof Error ? err.message : String(err),
			});
		}
	}

	let configuredEntry: McpServerEntry | undefined;
	if (opts.client) {
		const adapter = getClientAdapter(opts.client);
		if (!adapter) {
			checks.push({
				id: 'client_config',
				ok: false,
				detail: `No adapter for client "${opts.client}"`,
			});
		} else {
			const binding = site.clients[adapter.id];
			const configPath = binding?.config_path ?? adapter.defaultConfigPath(ctx.homeDir ?? homedir());
			const serverName = binding?.server_name ?? mcpServerName(site.alias, site.id, new Set());
			const v = adapter.verify(configPath, serverName);
			configuredEntry = adapter.read(configPath, serverName) ?? undefined;
			checks.push({
				id: 'client_config',
				ok: v.ok,
				detail: v.detail,
			});
		}
	}

	const runtime = await (ctx.runtimeVerifier
		? ctx.runtimeVerifier(site, password, configuredEntry)
		: defaultRuntimeVerifier(site, password, ctx.fetchImpl ?? fetch, configuredEntry));
	checks.push({ id: 'runtime', ok: runtime.ok, detail: runtime.detail });
	const remoteNames = runtime.remote_tool_names ?? [];
	const surfaceDigest = remoteNames.length > 0
		? `sha256:${createHash('sha256').update([...remoteNames].sort().join('\n')).digest('hex')}`
		: undefined;

	const ok = checks.every((c) => c.ok);
	const now = new Date().toISOString();
	const nextSite: SiteRecordV2 = {
		...site,
		last_verification: {
			at: now,
			ok,
			...(opts.client ? { client: opts.client } : {}),
			detail: checks.map((c) => `${c.id}:${c.ok ? 'ok' : 'fail'}`).join(','),
			active_mode: active.active,
			active_alias: runtime.active_alias ?? site.alias,
			companion_version: runtime.companion_version,
			remote_tool_count: remoteNames.length || undefined,
			surface_digest: surfaceDigest,
			task_start_available: runtime.task_start_available,
			status_available: runtime.status_available,
		},
		updated_at: now,
	};
	const nextRegistry = upsertSite(registry, nextSite, { replace: true });
	saveRegistryForContext(nextRegistry, ctx);

	const receipt: ConnectReceipt = {
		ok,
		site_id: site.id,
		site_alias: site.alias,
		environment: site.environment,
		configured_mode: site.configured_mode,
		active_mode: active.active,
		message: ok ? 'Verify passed' : 'Verify failed',
		details: { checks, transition: active.transition, runtime, surface_digest: surfaceDigest },
	};
	writeOut(receiptLines(receipt));
	writeOut(JSON.stringify({ checks }, null, 2));
	return ok ? 0 : 1;
}

export function connectRepair(
	alias: string,
	opts: {
		client?: string;
		browserProvider?: BrowserPreferences['provider'];
		browserScanConsent?: ConsentState;
		browserInstallConsent?: ConsentState;
	} = {},
	ctx: ConnectContext = {},
): number {
	const { registry } = loadRegistry(ctxPaths(ctx));
	const site = findSiteByAlias(registry, alias);
	if (!site) {
		writeErr(`Unknown alias "${alias}"`);
		return 1;
	}
	const clientId = opts.client ?? Object.keys(site.clients)[0];
	if (!clientId) {
		writeErr('No client binding on this site. Pass --client <id>.');
		return 1;
	}
	try {
		const priorBrowser = site.clients[clientId]?.browser;
		const browser = browserPreferences({
			browserProvider: opts.browserProvider ?? priorBrowser?.provider ?? 'unset',
			browserScanConsent: opts.browserScanConsent ?? priorBrowser?.scan_consent ?? 'unknown',
			browserInstallConsent: opts.browserInstallConsent ?? priorBrowser?.install_consent ?? 'unknown',
		}, priorBrowser);
		const { registry: next, receipt, rollback } = applyClientBinding(registry, site, clientId, ctx, browser);
		try {
			saveRegistryForContext(next, ctx);
		} catch (err) {
			rollback();
			throw new ConnectError(
				'registry_write_failed',
				`Registry write failed; client config was rolled back: ${err instanceof Error ? err.message : String(err)}`,
			);
		}
		const r: ConnectReceipt = {
			ok: true,
			site_id: site.id,
			site_alias: site.alias,
			environment: site.environment,
			configured_mode: site.configured_mode,
			active_mode: site.preferred_active_mode,
			message: `Repaired client binding for ${clientId}`,
			details: receipt,
		};
		writeOut(receiptLines(r));
		writeOut(JSON.stringify(receipt, null, 2));
		return 0;
	} catch (err) {
		if (err instanceof ConnectError) {
			writeErr(`${err.code}: ${err.message}`);
			return 1;
		}
		throw err;
	}
}

export function connectRemove(
	alias: string,
	opts: { client?: string } = {},
	ctx: ConnectContext = {},
): number {
	const { registry } = loadRegistry(ctxPaths(ctx));
	const site = findSiteByAlias(registry, alias);
	if (!site) {
		writeErr(`Unknown alias "${alias}"`);
		return 1;
	}

	if (opts.client) {
		// Remove only one client binding + its named server entry
		const adapter = getClientAdapter(opts.client);
		const binding = site.clients[opts.client];
		let rollbackClient: (() => void) | undefined;
		if (adapter && binding) {
			const configPath = binding.config_path ?? adapter.defaultConfigPath(ctx.homeDir ?? homedir());
			const before = snapshotFile(configPath);
			adapter.remove(configPath, binding.server_name);
			rollbackClient = () => restoreFileSnapshot(configPath, before);
		}
		const rest = { ...site.clients };
		delete rest[opts.client];
		const nextSite: SiteRecordV2 = { ...site, clients: rest, updated_at: new Date().toISOString() };
		const next = upsertSite(registry, nextSite, { replace: true });
		try {
			saveRegistryForContext(next, ctx);
		} catch (err) {
			rollbackClient?.();
			writeErr(`Registry write failed; client config was rolled back: ${err instanceof Error ? err.message : String(err)}`);
			return 1;
		}
		writeOut(
			receiptLines({
				ok: true,
				site_id: site.id,
				site_alias: site.alias,
				environment: site.environment,
				configured_mode: site.configured_mode,
				message: `Removed client "${opts.client}" from site "${site.alias}"`,
			}),
		);
		return 0;
	}

	// Remove entire site; clean client entries we know about
	const rollbackClients: Array<() => void> = [];
	let removed: SiteRecordV2 = site;
	try {
		for (const [clientId, binding] of Object.entries(site.clients)) {
			const adapter = getClientAdapter(clientId);
			if (adapter && binding.config_path) {
				const configPath = binding.config_path;
				const before = snapshotFile(configPath);
				adapter.remove(configPath, binding.server_name);
				rollbackClients.push(() => restoreFileSnapshot(configPath, before));
			}
		}

		const removal = registryRemoveSite(registry, alias);
		const next = removal.registry;
		removed = removal.removed;
		saveRegistryForContext(next, ctx);
		try {
			deleteCredentialIfUnreferenced(removed.credential_ref, next, ctx);
		} catch (err) {
			writeErr(`Warning: site was removed, but credential cleanup failed: ${err instanceof Error ? err.message : String(err)}`);
		}
	} catch (err) {
		for (const rollback of rollbackClients.reverse()) rollback();
		writeErr(`Remove failed; client configs were rolled back: ${err instanceof Error ? err.message : String(err)}`);
		return 1;
	}
	writeOut(
		receiptLines({
			ok: true,
			site_id: removed.id,
			site_alias: removed.alias,
			environment: removed.environment,
			configured_mode: removed.configured_mode,
			message: `Removed site "${removed.alias}"`,
		}),
	);
	return 0;
}

export function connectMigrate(ctx: ConnectContext & { allowEnvRef?: boolean | undefined } = {}): number {
	try {
		const result = migrateSitesFile({
			...ctxPaths(ctx),
			...(ctx.allowEnvRef !== undefined ? { allowEnvRef: ctx.allowEnvRef } : {}),
		});
		writeOut(
			JSON.stringify(
				{
					ok: true,
					path: result.path,
					migrated: result.migrated,
					site_count: result.site_count,
					backup: result.backupPath,
				},
				null,
				2,
			),
		);
		return 0;
	} catch (err) {
		if (err instanceof ConnectError) {
			writeErr(`${err.code}: ${err.message}`);
			return 1;
		}
		throw err;
	}
}

/** Test helper: force memory credential store. */
export function testCredentialOptions(store = new MemoryCredentialStore()): CreateCredentialStoreOptions {
	return { store, prefer: 'memory', allowMemoryFallback: true };
}

export type { ClientAdapter };
