import { appendFileSync, existsSync, mkdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';

export interface DirectAuditEntry {
	tool: string;
	site: string;
	resource?: string;
	status: 'ok' | 'error' | 'blocked';
	timestamp?: string;
	code?: string;
}

export function defaultAuditPath(): string {
	return join(homedir(), '.stonewright', 'audit-direct.jsonl');
}

export function appendDirectAudit(entry: DirectAuditEntry, path = defaultAuditPath()): void {
	const dir = dirname(path);
	if (!existsSync(dir)) {
		mkdirSync(dir, { recursive: true, mode: 0o700 });
	}
	const row = {
		tool: entry.tool,
		site: entry.site,
		resource: entry.resource ?? null,
		status: entry.status,
		code: entry.code ?? null,
		timestamp: entry.timestamp ?? new Date().toISOString(),
	};
	appendFileSync(path, `${JSON.stringify(row)}\n`, { encoding: 'utf8', mode: 0o600 });
}
