import { appendFileSync, existsSync, mkdirSync, readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join, resolve } from 'node:path';

export interface DirectAuditEntry {
	tool: string;
	site: string;
	resource?: string;
	status: 'ok' | 'error' | 'blocked';
	timestamp?: string;
	code?: string;
	error?: string;
}

export function defaultStateDir(env: NodeJS.ProcessEnv = process.env): string {
	const override = (env['STONEWRIGHT_STATE_DIR'] ?? '').trim();
	if (override) {
		return resolve(override);
	}
	return join(homedir(), '.stonewright');
}

export function defaultAuditPath(env: NodeJS.ProcessEnv = process.env): string {
	return join(defaultStateDir(env), 'audit-direct.jsonl');
}

export function appendDirectAudit(
	entry: DirectAuditEntry,
	path = defaultAuditPath(),
): void {
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
		error: entry.error ? entry.error.slice(0, 200) : null,
		timestamp: entry.timestamp ?? new Date().toISOString(),
	};
	appendFileSync(path, `${JSON.stringify(row)}\n`, { encoding: 'utf8', mode: 0o600 });
}

export type DirectRecurringError = {
	tool: string;
	count: number;
	last_error: string;
	repair: string;
};

const DIRECT_REPAIR =
	'Re-read the error, verify the target exists (GET before write), and retry once with corrected input.';

/** In-process counts of identical Direct tool failures (tool|error|message). */
const directErrorOccurrences = new Map<string, number>();

export type DirectErrorPayload = {
	ok: false;
	error: string;
	message: string;
	occurrences?: number;
	repair?: string;
	[key: string]: unknown;
};

function directErrorSignature(tool: string, error: string, message: string): string {
	return `${tool}|${error}|${message}`.toLowerCase();
}

/**
 * Increment and return the in-process occurrence count for an identical failure.
 * Used by the registry dispatch choke point so escalateDirectError sees prior retries.
 */
export function noteDirectErrorOccurrence(tool: string, error: string, message: string): number {
	const key = directErrorSignature(tool, error, message);
	const next = (directErrorOccurrences.get(key) ?? 0) + 1;
	directErrorOccurrences.set(key, next);
	return next;
}

/** Test helper: clear process-local occurrence counters. */
export function resetDirectErrorOccurrencesForTests(): void {
	directErrorOccurrences.clear();
}

function directRepairHint(tool: string, errorCode: string): string {
	if (
		tool.includes('elementor') ||
		errorCode.includes('elementor') ||
		errorCode.includes('integrity')
	) {
		return 'Do not retry the same Elementor write. Re-read the document, fix the rejected cause, use surgical updates only — never raw full-tree rewrites or double-encoded JSON.';
	}
	return DIRECT_REPAIR;
}

/**
 * Escalate repeated identical Direct tool failures with hard-stop guidance.
 *
 * @param tool  MCP tool name (hyphen form)
 * @param result Structured failure payload
 * @param count Occurrence count for this exact tool+error+message (1 = first fail)
 */
export function escalateDirectError(
	tool: string,
	result: { ok: false; error?: string; message?: string; [key: string]: unknown },
	count: number,
): DirectErrorPayload {
	const error = String(result.error ?? 'error');
	const originalMessage = String(result.message ?? result.error ?? 'error');
	if (count < 2) {
		return {
			...result,
			ok: false,
			error,
			message: originalMessage,
			occurrences: count,
		};
	}
	const repair = directRepairHint(tool, error);
	return {
		...result,
		ok: false,
		error,
		message: `STOP: this exact error occurred ${count} times — do not retry the same call. ${originalMessage}. Next step: ${repair}`,
		occurrences: count,
		repair,
	};
}

export function recentRecurringErrors(
	baseDirOrPath?: string,
	limit = 3,
): DirectRecurringError[] {
	const path =
		baseDirOrPath && baseDirOrPath.endsWith('.jsonl')
			? baseDirOrPath
			: join(baseDirOrPath ?? defaultStateDir(), 'audit-direct.jsonl');
	if (!existsSync(path)) {
		return [];
	}
	const lines = readFileSync(path, 'utf8').split('\n').filter(Boolean);
	const byTool = new Map<string, { count: number; last_error: string; last_ts: string }>();
	for (const line of lines) {
		try {
			const row = JSON.parse(line) as {
				tool?: string;
				status?: string;
				error?: string | null;
				timestamp?: string;
			};
			if (row.status !== 'error' || !row.tool) {
				continue;
			}
			const prev = byTool.get(row.tool);
			const ts = row.timestamp ?? '';
			const err = String(row.error ?? 'error').slice(0, 200);
			if (!prev) {
				byTool.set(row.tool, { count: 1, last_error: err, last_ts: ts });
			} else {
				prev.count += 1;
				if (ts >= prev.last_ts) {
					prev.last_error = err;
					prev.last_ts = ts;
				}
			}
		} catch {
			// skip corrupt
		}
	}
	return [...byTool.entries()]
		.filter(([, v]) => v.count >= 2)
		.sort((a, b) => b[1].count - a[1].count || b[1].last_ts.localeCompare(a[1].last_ts))
		.slice(0, Math.max(1, limit))
		.map(([tool, v]) => ({
			tool,
			count: v.count,
			last_error: v.last_error,
			repair: DIRECT_REPAIR,
		}));
}
