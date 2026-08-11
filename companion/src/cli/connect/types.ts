/**
 * Schema v2 site/environment registry types.
 * File: ~/.stonewright/sites.json (schema_version: 2)
 */

export type SiteEnvironment = 'local' | 'development' | 'staging' | 'production' | 'other';

/** Per-site mode policy (persisted). Maps to STONEWRIGHT_MODE for MCP env. */
export type ConfiguredMode = 'direct-only' | 'plugin-only' | 'auto';

export type PreferredActiveMode = 'plugin' | 'direct';

export type FallbackPolicy =
	| 'direct-when-plugin-unavailable'
	| 'never'
	| 'always-direct';

export type AuthMethod = 'application-password' | 'oauth' | 'none';

export interface SiteClientBinding {
	server_name: string;
	install_profile?: string | undefined;
	config_path?: string | undefined;
	last_applied_at?: string | undefined;
}

export interface LastVerification {
	at: string;
	ok: boolean;
	client?: string | undefined;
	detail?: string | undefined;
	active_mode?: PreferredActiveMode | 'unknown' | undefined;
}

export interface PluginExpectations {
	required?: boolean | undefined;
	min_version?: string | undefined;
	abilities?: string[] | undefined;
}

export interface SiteRecordV2 {
	id: string;
	alias: string;
	environment: SiteEnvironment;
	canonical_url: string;
	url_fingerprint: string;
	username_hint: string;
	/** keychain://… | credman://… | secretservice://… | env://VAR | memory://… */
	credential_ref: string;
	auth_method: AuthMethod;
	configured_mode: ConfiguredMode;
	preferred_active_mode: PreferredActiveMode;
	fallback_policy: FallbackPolicy;
	companion_profile: string;
	plugin_expectations?: PluginExpectations | undefined;
	clients: Record<string, SiteClientBinding>;
	last_verification?: LastVerification | undefined;
	/** Per-site tool denylist (carried from v1 disabledTools). */
	disabled_tools?: string[] | undefined;
	/** Set when credential_ref points at env:// or was migrated. */
	created_at?: string | undefined;
	updated_at?: string | undefined;
}

export interface SitesRegistryV2 {
	schema_version: 2;
	default_site_id: string | null;
	sites: SiteRecordV2[];
}

/** Legacy schema v1 (pre multi-site installer). */
export interface SitesRegistryV1 {
	default?: string;
	sites: Record<
		string,
		{
			url?: string;
			URL?: string;
			username?: string;
			user?: string;
			USER?: string;
			appPassword?: string;
			applicationPassword?: string;
			password?: string;
			PASS?: string;
			app_password?: string;
			disabledTools?: string[];
		}
	>;
}

export type SitesFileRoot = SitesRegistryV2 | SitesRegistryV1 | Record<string, unknown>;

export interface ConnectReceipt {
	site_id: string;
	site_alias: string;
	environment: SiteEnvironment;
	configured_mode: ConfiguredMode;
	active_mode?: PreferredActiveMode | 'unknown' | undefined;
	ok: boolean;
	message: string;
	details?: Record<string, unknown> | undefined;
}

export class ConnectError extends Error {
	readonly code: string;
	readonly details?: Record<string, unknown> | undefined;

	constructor(code: string, message: string, details?: Record<string, unknown>) {
		super(message);
		this.name = 'ConnectError';
		this.code = code;
		this.details = details;
	}
}
