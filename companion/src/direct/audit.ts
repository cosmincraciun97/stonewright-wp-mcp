import { appendFileSync, chmodSync, existsSync, linkSync, mkdirSync, readFileSync, readdirSync, renameSync, statSync, unlinkSync, writeFileSync } from 'node:fs';
import { createHash, randomUUID } from 'node:crypto';
import { AsyncLocalStorage } from 'node:async_hooks';
import { homedir, uptime } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { DirectIncidentStore, directIncidentFingerprint } from './incidents.js';

export interface DirectAuditEntry {
	tool: string;
	site: string;
	/** Canonical URL or immutable target identity; aliases are display-only. */
	targetIdentity?: string;
	resource?: string;
	status: 'ok' | 'error' | 'blocked';
	timestamp?: string;
	code?: string;
	error?: string;
	eventType?: string;
	operationClass?: string;
	resourceType?: string;
	changeSetId?: string;
	requestId?: string;
	parentRequestId?: string;
	executionStatus?: string;
	verificationStatus?: string;
	rollbackStatus?: string;
	beforeSha256?: string;
	afterSha256?: string;
	changedBytes?: number;
	validatorSummary?: string;
	smokeSummary?: string;
	causeKey?: string;
	durationMs?: number;
	mode?: string;
	severity?: string;
	effectVerified?: boolean;
	eventId?: string;
	correlationId?: string;
	idempotencyKey?: string;
	lifecyclePhase?: 'started' | 'progress' | 'retry' | 'terminal';
	terminalOwner?: string;
	operationId?: string;
	parentEventId?: string;
	attempt?: number;
	payload?: unknown;
	category?: 'READ' | 'HEALTH' | 'RUNTIME' | 'WRITE' | 'SAFETY';
	outcome?: 'SUCCESS' | 'BLOCKED' | 'FAILED';
}

export type PersistedDirectAuditEntry = {
	request_id: string;
	site_fingerprint: string;
	[key: string]: unknown;
};

export type DirectAuditRotationPolicy = {
	maxBytes?: number;
	maxAgeMs?: number;
	maxFiles?: number;
	now?: Date;
};

export type DirectAuditRotationReceipt = {
	receipt_id: string;
	reason: 'size' | 'age';
	rotated_at: string;
	previous_bytes: number;
	archive: string;
};

const DEFAULT_ROTATION: Required<Omit<DirectAuditRotationPolicy, 'now'>> = {
	maxBytes: 5 * 1024 * 1024,
	maxAgeMs: 30 * 24 * 60 * 60 * 1000,
	maxFiles: 10,
};

const LOCK_STALE_MS = 30_000;
const LOCK_ATTEMPTS = 500;
const BOOT_IDENTITY = `boot:${Math.floor((Date.now() - uptime() * 1000) / 60_000)}`;
const PROCESS_STARTED_AT = Math.floor(Date.now() - process.uptime() * 1000);
const MARKER_RETENTION_MS = 30 * 24 * 60 * 60 * 1000;
const MARKER_INDEX_MAX_ENTRIES = 1000;

type DirectAuditReceiptContext = {
	terminalReceipt?: PersistedDirectAuditEntry;
	targetIdentity?: string;
};

const directAuditReceiptContext = new AsyncLocalStorage<DirectAuditReceiptContext>();

export function withDirectAuditReceiptContext<T>(operation: () => T): T {
	return directAuditReceiptContext.run({}, operation);
}

export function directTerminalAuditReceipt(): PersistedDirectAuditEntry | null {
	return directAuditReceiptContext.getStore()?.terminalReceipt ?? null;
}

export function setDirectAuditTargetIdentity(targetIdentity: string): void {
	const context = directAuditReceiptContext.getStore();
	if (context) context.targetIdentity = targetIdentity;
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

export function redactDirectAuditText(value: string): string {
	return value
		.replace(/\b(Basic|Bearer)\s+[A-Za-z0-9+/=_-]+/gi, '$1 [redacted]')
		.replace(
			/\b(password|user_pass|pass|app_?password|application_password|wp_app_password|api[_ -]?key|client_secret|access_token|refresh_token|authorization|token|secret|cookie)\b(\s*(?::|=|\bis\b|\bwas\b)\s*)(?:"[^"]*"|'[^']*'|[^\s,;&}]+)/gi,
			'$1$2[redacted]',
		)
		.replace(/(https?:\/\/[^/\s:@]+:)[^/\s@]+@/gi, '$1[redacted]@')
		.replace(/\b(?:[A-Za-z0-9]{4}\s+){5}[A-Za-z0-9]{4}\b/g, '[redacted-app-password]');
}

export function appendDirectAudit(
	entry: DirectAuditEntry,
	path = defaultAuditPath(),
	rotation: DirectAuditRotationPolicy = {},
): PersistedDirectAuditEntry {
	const dir = dirname(path);
	if (!existsSync(dir)) {
		mkdirSync(dir, { recursive: true, mode: 0o700 });
	}
	if (process.platform !== 'win32') {
		chmodSync(dir, 0o700);
	}
	const receipt = withAuditLock(path, () => appendDirectAuditUnlocked(entry, path, rotation));
	if (receipt['terminal'] === true) {
		const context = directAuditReceiptContext.getStore();
		if (context) context.terminalReceipt = receipt;
	}
	return receipt;
}

function appendDirectAuditUnlocked(
	entry: DirectAuditEntry,
	path: string,
	rotation: DirectAuditRotationPolicy,
): PersistedDirectAuditEntry {
	rotateDirectAuditUnlocked(path, rotation);
	const dir = dirname(path);
	const eventId = entry.eventId ?? randomUUID();
	const requestId = entry.requestId ?? eventId;
	const correlationId = entry.correlationId ?? requestId;
	const operationId = entry.operationId ?? correlationId;
	const parentEventId = entry.parentEventId ?? entry.parentRequestId ?? null;
	const attempt = Math.max(1, Math.min(1000, Math.trunc(entry.attempt ?? 1)));
	const lifecyclePhase = entry.lifecyclePhase ?? 'terminal';
	const terminal = lifecyclePhase === 'terminal';
	const terminalOwner = terminal ? (entry.terminalOwner ?? 'direct-registry') : null;
	const targetIdentity = entry.targetIdentity ?? directAuditReceiptContext.getStore()?.targetIdentity ?? entry.site;
	const siteFingerprint = directIncidentFingerprint(targetIdentity);
	const payloadHash = createHash('sha256').update(stableJson(entry.payload ?? {
		code: entry.code ?? null,
		error: entry.error ?? null,
		changeSetId: entry.changeSetId ?? null,
		verificationStatus: entry.verificationStatus ?? null,
		rollbackStatus: entry.rollbackStatus ?? null,
	})).digest('hex');
	const idempotencyKey = createHash('sha256')
		.update([
			entry.idempotencyKey ?? eventId,
			entry.tool,
			entry.resource ?? '',
			siteFingerprint,
			payloadHash,
			entry.status,
			operationId,
		].join('|'))
		.digest('hex');
	const markerDir = join(dir, '.audit-idempotency');
	let markerPath = '';
	if (terminal) {
		mkdirSync(markerDir, { recursive: true, mode: 0o700 });
		markerPath = join(markerDir, idempotencyKey);
		compactMarkerIndex(markerDir, existsSync(markerPath) ? MARKER_INDEX_MAX_ENTRIES : MARKER_INDEX_MAX_ENTRIES - 1);
		if (existsSync(markerPath)) {
			const existing = readDirectAuditByIdempotency(idempotencyKey, siteFingerprint, path);
			if (existing) return existing;
			try {
				const persisted = JSON.parse(readFileSync(markerPath, 'utf8')) as PersistedDirectAuditEntry;
				if (
					persisted['idempotency_key'] === idempotencyKey &&
					persisted.site_fingerprint === siteFingerprint
				) return persisted;
			} catch {
				// The global audit lock proves no writer still owns this marker.
			}
			unlinkSync(markerPath);
		}
		writeFileSync(markerPath, `${JSON.stringify({ state: 'pending', pid: process.pid, created_at: Date.now() })}\n`, { encoding: 'utf8', mode: 0o600, flag: 'wx' });
	}
	const executionStatus =
		entry.executionStatus ??
		(entry.status === 'ok' ? 'executed' : entry.status);
	const verificationStatus =
		entry.verificationStatus ??
		(entry.status === 'ok' ? 'response_returned' : 'not_verified');
	const resource = entry.resource
		? redactDirectAuditText(entry.resource).slice(0, 500)
		: null;
	const code = entry.code
		? redactDirectAuditText(entry.code).slice(0, 190)
		: null;
	const category = entry.category ?? directCategory(entry);
	const operationClass = entry.operationClass ?? directOperationClass(category);
	const row: PersistedDirectAuditEntry = {
		schema_version: '2.0',
		event_id: eventId,
		correlation_id: correlationId,
		operation_id: operationId,
		parent_event_id: parentEventId,
		attempt,
		idempotency_key: idempotencyKey,
		lifecycle_phase: lifecyclePhase,
		terminal,
		terminal_owner: terminalOwner,
		occurred_at: entry.timestamp ?? new Date().toISOString(),
		category,
		outcome: entry.outcome ?? (entry.status === 'ok' ? 'SUCCESS' : entry.status === 'blocked' ? 'BLOCKED' : 'FAILED'),
		severity_level: entry.severity ?? (entry.status === 'error' ? 'error' : entry.status === 'blocked' ? 'warning' : 'info'),
		ability: entry.tool,
		tool: entry.tool,
		resource,
		status: entry.status,
		code,
		error: entry.error ? redactDirectAuditText(entry.error).slice(0, 200) : null,
		timestamp: entry.timestamp ?? new Date().toISOString(),
		event_type: entry.eventType ?? 'direct_tool',
		operation_class: operationClass,
		resource_type: entry.resourceType ?? null,
		resource_ref: resource,
		change_set_id: entry.changeSetId ?? null,
		request_id: requestId,
		parent_request_id: parentEventId,
		execution_status: executionStatus,
		verification_status: verificationStatus,
		rollback_status: entry.rollbackStatus ?? 'not_required',
		before_sha256: entry.beforeSha256 ?? null,
		after_sha256: entry.afterSha256 ?? null,
		changed_bytes: entry.changedBytes ?? null,
		validator_summary: entry.validatorSummary
			? redactDirectAuditText(entry.validatorSummary).slice(0, 500)
			: null,
		smoke_summary: entry.smokeSummary
			? redactDirectAuditText(entry.smokeSummary).slice(0, 500)
			: null,
		error_code: code,
		cause_key: entry.causeKey
			? redactDirectAuditText(entry.causeKey).slice(0, 255)
			: null,
		duration_ms: entry.durationMs ?? null,
		backend: 'direct',
		site_fingerprint: siteFingerprint,
		mode: entry.mode ?? 'direct',
		severity:
			entry.severity ??
			(entry.status === 'error'
				? 'error'
				: entry.status === 'blocked'
					? 'notice'
					: 'info'),
		effect_verified: entry.effectVerified === true,
	};
	try {
		appendFileSync(path, `${JSON.stringify(row)}\n`, { encoding: 'utf8', mode: 0o600 });
	} catch (error) {
		if (markerPath && existsSync(markerPath)) unlinkSync(markerPath);
		throw error;
	}
	if (markerPath) {
		let markerTemp = '';
		try {
			markerTemp = `${markerPath}.${randomUUID()}.tmp`;
			writeFileSync(markerTemp, `${JSON.stringify(row)}\n`, { encoding: 'utf8', mode: 0o600 });
			renameSync(markerTemp, markerPath);
		} catch {
			// The append is authoritative. Keep the exclusive marker so a replay
			// cannot create a second terminal row after a receipt-write failure.
			if (markerTemp && existsSync(markerTemp)) {
				try { unlinkSync(markerTemp); } catch { /* bounded cleanup retries on the next append */ }
			}
		}
	}
	if (process.platform !== 'win32') {
		chmodSync(path, 0o600);
	}
	if (entry.status === 'error') {
		new DirectIncidentStore(dirname(path), siteFingerprint).observeFailure({
			event_id: eventId,
			correlation_id: correlationId,
			idempotency_key: idempotencyKey,
			ability: entry.tool,
			error_code: code ?? 'unknown_error',
			cause_key: entry.causeKey ?? `${entry.tool}|${code ?? 'unknown_error'}`,
			severity: entry.severity ?? 'error',
			timestamp: String(row['timestamp']),
		});
	}
	return row;
}

export function rotateDirectAudit(
	path = defaultAuditPath(),
	policy: DirectAuditRotationPolicy = {},
): DirectAuditRotationReceipt | null {
	const dir = dirname(path);
	if (!existsSync(dir)) mkdirSync(dir, { recursive: true, mode: 0o700 });
	return withAuditLock(path, () => rotateDirectAuditUnlocked(path, policy));
}

function rotateDirectAuditUnlocked(
	path: string,
	policy: DirectAuditRotationPolicy,
): DirectAuditRotationReceipt | null {
	const now = policy.now ?? new Date();
	const resolved = {
		maxBytes: Math.max(1, policy.maxBytes ?? DEFAULT_ROTATION.maxBytes),
		maxAgeMs: Math.max(1000, policy.maxAgeMs ?? DEFAULT_ROTATION.maxAgeMs),
		maxFiles: Math.max(1, Math.min(100, policy.maxFiles ?? DEFAULT_ROTATION.maxFiles)),
	};
	const recovered = recoverDirectAuditRotation(path, resolved.maxFiles);
	if (recovered || !existsSync(path)) return recovered;

	const stat = statSync(path);
	const reason = stat.size > resolved.maxBytes
		? 'size'
		: now.getTime() - stat.mtimeMs > resolved.maxAgeMs
			? 'age'
			: null;
	if (!reason) return null;

	const stamp = now.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}Z$/, 'Z');
	const suffix = createHash('sha256').update(`${stamp}|${stat.size}|${randomUUID()}`).digest('hex').slice(0, 8);
	const archive = `audit-direct.${stamp}.${suffix}.jsonl`;
	const rotatedAt = now.toISOString();
	const receipt: DirectAuditRotationReceipt = {
		receipt_id: createHash('sha256').update(`${archive}|${reason}|${rotatedAt}|${stat.size}`).digest('hex'),
		reason,
		rotated_at: rotatedAt,
		previous_bytes: stat.size,
		archive,
	};
	writeRotationJournal(path, receipt);
	return recoverDirectAuditRotation(path, resolved.maxFiles);
}

function writeRotationJournal(path: string, receipt: DirectAuditRotationReceipt): void {
	const journal = `${path}.rotation-journal.json`;
	const temp = `${journal}.${randomUUID()}.tmp`;
	const source = `.audit-direct.rotation-source.${receipt.receipt_id}.jsonl`;
	writeFileSync(temp, `${JSON.stringify({ version: 2, source, ...receipt })}\n`, { encoding: 'utf8', mode: 0o600 });
	renameSync(temp, journal);
	if (process.platform !== 'win32') chmodSync(journal, 0o600);
}

function recoverDirectAuditRotation(path: string, maxFiles: number): DirectAuditRotationReceipt | null {
	const journal = `${path}.rotation-journal.json`;
	if (!existsSync(journal)) return null;
	let receipt: DirectAuditRotationReceipt;
	let sourceName = '';
	try {
		const parsed = JSON.parse(readFileSync(journal, 'utf8')) as Partial<DirectAuditRotationReceipt> & { version?: number; source?: string };
		if (
			![1, 2].includes(Number(parsed.version)) ||
			!parsed.receipt_id?.match(/^[a-f0-9]{64}$/) ||
			!parsed.archive?.match(/^audit-direct\.\d{8}T\d{6}Z\.[a-f0-9]{8}\.jsonl$/) ||
			!['size', 'age'].includes(String(parsed.reason)) ||
			!Number.isFinite(parsed.previous_bytes)
		) throw new Error('invalid rotation journal');
			receipt = {
			receipt_id: parsed.receipt_id,
			reason: parsed.reason as 'size' | 'age',
			rotated_at: String(parsed.rotated_at),
			previous_bytes: Number(parsed.previous_bytes),
				archive: parsed.archive,
			};
			sourceName = parsed.version === 2 && /^\.audit-direct\.rotation-source\.[a-f0-9]{64}\.jsonl$/.test(String(parsed.source))
				? String(parsed.source)
				: `.audit-direct.rotation-source.${receipt.receipt_id}.jsonl`;
	} catch {
		renameSync(journal, `${journal}.corrupt-${Date.now()}`);
		return null;
	}

	const archivePath = join(dirname(path), receipt.archive);
	const sourcePath = join(dirname(path), sourceName);
	if (!existsSync(sourcePath) && existsSync(path) && !existsSync(archivePath)) renameSync(path, sourcePath);
	if (existsSync(sourcePath) && !existsSync(archivePath)) {
		const archiveTemp = `${archivePath}.${randomUUID()}.tmp`;
		sanitizeAuditArchive(sourcePath, archiveTemp);
		renameSync(archiveTemp, archivePath);
	}
	if (!existsSync(archivePath)) return null;
	if (existsSync(sourcePath)) unlinkSync(sourcePath);
	appendRotationReceipt(path, receipt);
	if (existsSync(journal)) unlinkSync(journal);
	pruneAuditArchives(path, maxFiles);
	return receipt;
}

function withAuditLock<T>(path: string, operation: () => T): T {
	const lockPath = `${path}.lock`;
	const token = randomUUID();
	const lockContents = `${JSON.stringify({
		pid: process.pid,
		created_at: Date.now(),
		token,
		boot_id: BOOT_IDENTITY,
		process_started_at: PROCESS_STARTED_AT,
	})}\n`;
	let acquired = false;
	for (let attempt = 0; attempt < LOCK_ATTEMPTS; attempt += 1) {
		try {
			writeFileSync(lockPath, lockContents, { encoding: 'utf8', mode: 0o600, flag: 'wx' });
			acquired = true;
			break;
		} catch (error) {
			const code = (error as NodeJS.ErrnoException).code;
			if (code !== 'EEXIST') throw error;
			const stale = staleLockSnapshot(lockPath);
			if (stale !== null) {
				quarantineStaleLock(lockPath, stale);
				continue;
			}
			Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 10);
		}
	}
	if (!acquired) throw new Error(`Timed out waiting for Direct audit lock: ${lockPath}`);
	try {
		return operation();
	} finally {
		quarantineOwnedLock(lockPath, lockContents);
	}
}

type LockSnapshot = {
	contents: string;
	modifiedAt: number;
	device: number;
	inode: number;
};

function staleLockSnapshot(lockPath: string): LockSnapshot | null {
	try {
		const before = statSync(lockPath);
		const contents = readFileSync(lockPath, 'utf8');
		const after = statSync(lockPath);
		if (before.dev !== after.dev || before.ino !== after.ino || before.mtimeMs !== after.mtimeMs) return null;
		const snapshot = { contents, modifiedAt: after.mtimeMs, device: after.dev, inode: after.ino };
		let parsed: { pid?: number; boot_id?: string; process_started_at?: number };
		try {
			parsed = JSON.parse(contents) as { pid?: number; boot_id?: string; process_started_at?: number };
		} catch {
			return Date.now() - after.mtimeMs > LOCK_STALE_MS ? snapshot : null;
		}
		if (Number.isInteger(parsed.pid) && Number(parsed.pid) > 0) {
			if (typeof parsed.boot_id === 'string' && parsed.boot_id !== BOOT_IDENTITY) return snapshot;
			if (
				Number(parsed.pid) === process.pid &&
				Number.isFinite(parsed.process_started_at) &&
				Number(parsed.process_started_at) !== PROCESS_STARTED_AT
			) return snapshot;
			try {
				process.kill(Number(parsed.pid), 0);
				return null;
			} catch (error) {
				return (error as NodeJS.ErrnoException).code === 'EPERM' ? null : snapshot;
			}
		}
		return Date.now() - after.mtimeMs > LOCK_STALE_MS ? snapshot : null;
	} catch {
		return null;
	}
}

function quarantineStaleLock(lockPath: string, observed: LockSnapshot): boolean {
	const quarantinePath = `${lockPath}.recovery-${randomUUID()}`;
	try {
		renameSync(lockPath, quarantinePath);
		const quarantined = statSync(quarantinePath);
		if (
			quarantined.dev !== observed.device ||
			quarantined.ino !== observed.inode ||
			readFileSync(quarantinePath, 'utf8') !== observed.contents
		) {
			restoreQuarantinedLock(quarantinePath, lockPath);
			return false;
		}
		unlinkSync(quarantinePath);
		return true;
	} catch {
		return false;
	}
}

function quarantineOwnedLock(lockPath: string, ownedContents: string): boolean {
	const quarantinePath = `${lockPath}.release-${randomUUID()}`;
	try {
		renameSync(lockPath, quarantinePath);
		if (readFileSync(quarantinePath, 'utf8') !== ownedContents) {
			restoreQuarantinedLock(quarantinePath, lockPath);
			return false;
		}
		unlinkSync(quarantinePath);
		return true;
	} catch {
		return false;
	}
}

function restoreQuarantinedLock(quarantinePath: string, lockPath: string): void {
	try {
		linkSync(quarantinePath, lockPath);
		unlinkSync(quarantinePath);
	} catch {
		// A new canonical owner wins. Preserve the quarantine for diagnosis.
	}
}

function compactMarkerIndex(markerDir: string, maxEntries: number): void {
	const now = Date.now();
	const entries = readdirSync(markerDir);
	for (const name of entries) {
		if (!/^[a-f0-9]{64}\.[a-f0-9-]+\.tmp$/.test(name)) continue;
		try { unlinkSync(join(markerDir, name)); } catch { /* another cleanup may remove it */ }
	}
	const markers = entries
		.filter((name) => /^[a-f0-9]{64}$/.test(name))
		.map((name) => {
			try {
				return { name, mtimeMs: statSync(join(markerDir, name)).mtimeMs };
			} catch {
				return null;
			}
		})
		.filter((marker): marker is { name: string; mtimeMs: number } => marker !== null);

	for (const marker of markers) {
		if (now - marker.mtimeMs <= MARKER_RETENTION_MS) continue;
		try { unlinkSync(join(markerDir, marker.name)); } catch { /* another recovery may remove it */ }
	}

	const retained = markers
		.filter((marker) => existsSync(join(markerDir, marker.name)))
		.sort((a, b) => b.mtimeMs - a.mtimeMs || b.name.localeCompare(a.name));
	for (const marker of retained.slice(Math.max(0, maxEntries))) {
		try { unlinkSync(join(markerDir, marker.name)); } catch { /* another recovery may remove it */ }
	}
}

function sanitizeAuditArchive(sourcePath: string, destinationPath: string): void {
	const output: string[] = [];
	for (const line of readFileSync(sourcePath, 'utf8').split('\n')) {
		if (!line) continue;
		try {
			output.push(JSON.stringify(redactLegacyValue(JSON.parse(line) as unknown)));
		} catch {
			output.push(JSON.stringify({ schema_version: '2.0', event_type: 'legacy_corrupt', redacted: true, bytes: Buffer.byteLength(line) }));
		}
	}
	writeFileSync(destinationPath, output.length > 0 ? `${output.join('\n')}\n` : '', { encoding: 'utf8', mode: 0o600 });
}

function redactLegacyValue(value: unknown, key = ''): unknown {
	if (/password|secret|token|authorization|cookie|api[_ -]?key/i.test(key)) return '[redacted]';
	if (typeof value === 'string') return redactDirectAuditText(value);
	if (Array.isArray(value)) return value.map((item) => redactLegacyValue(item));
	if (value && typeof value === 'object') {
		return Object.fromEntries(Object.entries(value as Record<string, unknown>).map(([childKey, child]) => [childKey, redactLegacyValue(child, childKey)]));
	}
	return value;
}

function stableJson(value: unknown): string {
	if (Array.isArray(value)) return `[${value.map(stableJson).join(',')}]`;
	if (value && typeof value === 'object') {
		return `{${Object.entries(value as Record<string, unknown>).sort(([a], [b]) => a.localeCompare(b)).map(([key, item]) => `${JSON.stringify(key)}:${stableJson(item)}`).join(',')}}`;
	}
	return JSON.stringify(value) ?? 'null';
}

function directCategory(entry: DirectAuditEntry): 'READ' | 'HEALTH' | 'RUNTIME' | 'WRITE' | 'SAFETY' {
	if (entry.status === 'blocked') return 'SAFETY';
	const hint = `${entry.tool}|${entry.operationClass ?? ''}`.toLowerCase();
	if (hint.includes('health')) return 'HEALTH';
	if (hint.includes('php-execute') || hint.includes('runtime') || hint.includes('execution')) return 'RUNTIME';
	if (/(?:-get|-list|-status|-search|-inspect|-describe|-preview|read)/.test(hint)) return 'READ';
	return 'WRITE';
}

function directOperationClass(category: 'READ' | 'HEALTH' | 'RUNTIME' | 'WRITE' | 'SAFETY'): string {
	return category === 'RUNTIME' ? 'EXECUTION' : category;
}

function appendRotationReceipt(path: string, receipt: DirectAuditRotationReceipt): void {
	const receiptPath = `${path}.rotation-receipts.jsonl`;
	if (existsSync(receiptPath) && readFileSync(receiptPath, 'utf8').includes(receipt.receipt_id)) return;
	appendFileSync(receiptPath, `${JSON.stringify(receipt)}\n`, { encoding: 'utf8', mode: 0o600 });
	if (process.platform !== 'win32') chmodSync(receiptPath, 0o600);
}

function pruneAuditArchives(path: string, maxFiles: number): void {
	const dir = dirname(path);
	const archives = readdirSync(dir)
		.filter((name) => /^audit-direct\.\d{8}T\d{6}Z\.[a-f0-9]{8}\.jsonl$/.test(name))
		.sort()
		.reverse();
	for (const name of archives.slice(maxFiles)) unlinkSync(join(dir, name));
}

function readDirectAuditByIdempotency(
	idempotencyKey: string,
	siteFingerprint: string,
	path: string,
): PersistedDirectAuditEntry | null {
	if (!existsSync(path)) return null;
	for (const line of readFileSync(path, 'utf8').split('\n')) {
		if (!line) continue;
		try {
			const row = JSON.parse(line) as PersistedDirectAuditEntry;
			if (
				row['idempotency_key'] === idempotencyKey &&
				row.site_fingerprint === siteFingerprint
			) return row;
		} catch {
			// Corrupt lines are never idempotency proof.
		}
	}
	return null;
}

export function readDirectAuditEvent(
	eventId: string,
	path = defaultAuditPath(),
): PersistedDirectAuditEntry | null {
	if (!existsSync(path)) return null;
	for (const line of readFileSync(path, 'utf8').split('\n')) {
		if (!line) continue;
		try {
			const row = JSON.parse(line) as PersistedDirectAuditEntry;
			if (row.request_id === eventId) return row;
		} catch {
			// A corrupt audit line is never proof.
		}
	}
	return null;
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
