export type SupportTier = 'certified' | 'compatible' | 'community' | 'unknown';

export type ClientConfigFormat = 'json-mcp' | 'json-servers' | 'toml-codex' | 'cli-only' | 'unknown';

export interface McpServerEntry {
	serverName: string;
	command: string;
	args: string[];
	env: Record<string, string>;
}

export interface ClientAdapterInfo {
	id: string;
	label: string;
	supportTier: SupportTier;
	configFormat: ClientConfigFormat;
	/** Default config path for the current OS (expanded ~). */
	defaultConfigPath: (homeDir: string) => string;
	officialCliAdd?: string | undefined;
}

export interface ApplyResult {
	configPath: string;
	backupPath: string | null;
	/** Redacted human-readable diff (no secrets). */
	diff: string;
	serverName: string;
	created: boolean;
}

export interface RemoveResult {
	configPath: string;
	backupPath: string | null;
	removed: boolean;
	serverName: string;
}

export interface VerifyConfigResult {
	ok: boolean;
	configPath: string;
	serverName: string;
	hasEntry: boolean;
	detail: string;
	/** Structural only when process spawn is too heavy. */
	structural: boolean;
}

export interface ClientAdapter extends ClientAdapterInfo {
	/**
	 * Upsert a named Stonewright server entry. Idempotent for same serverName+payload.
	 * Backs up the target file only; restores backup on validation failure.
	 */
	upsert(configPath: string, entry: McpServerEntry): ApplyResult;
	remove(configPath: string, serverName: string): RemoveResult;
	read(configPath: string, serverName: string): McpServerEntry | null;
	verify(configPath: string, serverName: string): VerifyConfigResult;
	listServerNames(configPath: string): string[];
}

export class ClientConfigError extends Error {
	readonly code: string;

	constructor(code: string, message: string) {
		super(message);
		this.name = 'ClientConfigError';
		this.code = code;
	}
}
