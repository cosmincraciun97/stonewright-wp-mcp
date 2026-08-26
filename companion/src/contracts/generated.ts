/* eslint-disable @typescript-eslint/no-explicit-any, @typescript-eslint/no-redundant-type-constituents */
// GENERATED FILE — do not edit manually.
// Regenerate with: npm run build:contracts
//
// Sources: companion/src/contracts/*.schema.json
// Tool: json-schema-to-typescript

/**
 * GET /health has no request body.
 */

export interface HealthRequest {}

export interface HealthResponse {
  status: "ok";
  /**
   * Semantic version of this contract set. PHP checks major-version compatibility.
   */
  contract_version: string;
  /**
   * Version of the running companion artifact.
   */
  version: string;
  /**
   * Official package for the running artifact version.
   */
  expected_companion_package: string;
  /**
   * Exact configured package, included only for a valid bearer and a validated configuration source.
   */
  configured_package?: string;
  configured_package_version?: string;
  configured_package_provenance?: "npm-registry" | "github-release";
  configured_package_source?: "authenticated-environment";
}

export type AuthenticationState = "authenticated" | "refreshing" | "transient_failure" | "reauth_required" | "unknown";

export interface AuthenticationStatusV3 {
  configured: boolean;
  method: "app-password" | "authorization" | "oauth" | "none" | "unknown";
  state: AuthenticationState;
  reason_code: null | string;
  last_success_at: null | string;
  refresh_expires_at: null | string;
  continuity_target_seconds: 604800;
  agent_notice_required: boolean;
  user_action: null | string;
}

export interface RecoveryStatus {
  catalog_preserved: boolean;
  remote_calls_available: boolean;
  last_success_at: null | string;
  reconnect_attempted: boolean;
  reconnect_coalesced: boolean;
}

export interface ConnectionTransport {
  kind: "stdio" | "http" | "unknown";
  mcp_url: null | string;
}

export interface WordPressRuntimeStatus {
  reachable: boolean | null;
  site_url: null | string;
}

export interface PluginStatus {
  reachable: boolean | null;
  enabled_requested: boolean;
  effective_state: string;
  registry_ready: boolean;
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

export interface ClientVisibility {
  state: "unverified" | "attested" | "invoked";
  reason: string;
}

export interface ConnectionReconciliation {
  client_expected_wordpress_mode: null | ("development" | "staging" | "production-safe");
  client_expected_wp_surface: null | ("bootstrap" | "essential" | "full");
  saved_wordpress_mode: null | ("development" | "staging" | "production-safe");
  effective_wordpress_mode: null | ("development" | "staging" | "production-safe");
  saved_wp_surface: null | ("bootstrap" | "essential" | "full");
  effective_companion_profile: string;
  profile_source: "site" | "client-lock" | "task" | "default";
  mismatch_reason: null | string;
  mismatch_action: null | string;
}

export interface ConnectionStatusV3 {
  schema_version: 3;
  site_alias: null | string;
  configured_mode: "direct-only" | "plugin-only" | "auto";
  active_mode: "direct" | "plugin" | "local-only" | "none";
  connection_stage:
    | "local-ready"
    | "probing"
    | "direct-ready"
    | "plugin-authenticated"
    | "plugin-registering"
    | "plugin-ready"
    | "degraded";
  connection_generation: number;
  transport: ConnectionTransport;
  authentication: AuthenticationStatusV3;
  recovery: RecoveryStatus;
  wordpress_runtime: WordPressRuntimeStatus;
  plugin: PluginStatus;
  surface: SurfaceStatus;
  client_visibility: ClientVisibility;
  process_start_id: null | string;
  catalog_digest: string;
  observed_tool_names: string[];
  reconciliation: ConnectionReconciliation;
  error_code: null | string;
  next_action: null | string;
  connected: boolean;
  ok: boolean;
  startup_ready: boolean;
  refresh_required_tool_names: string[];
  client_task_catalog_stale?: boolean;
  relist_or_restart_action?: null | string;
}
