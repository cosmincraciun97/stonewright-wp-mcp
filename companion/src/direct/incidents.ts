import { createHash, randomUUID } from 'node:crypto';
import {
	chmodSync,
	existsSync,
	mkdirSync,
	readFileSync,
	renameSync,
	writeFileSync,
} from 'node:fs';
import { join, resolve } from 'node:path';

const FINGERPRINT_RE = /^[a-f0-9]{64}$/;
const MAX_INCIDENTS = 100;
const OPEN_THRESHOLD = 2;

export type DirectIncidentState = 'observing' | 'open' | 'resolved' | 'suppressed';
export type DirectLearningStatus = 'none' | 'promoted' | 'stale';

export type DirectIncidentFailure = {
	event_id: string;
	ability: string;
	error_code: string;
	cause_key: string;
	severity?: string;
	timestamp?: string;
};

export type DirectIncident = {
	incident_id: string;
	site_fingerprint: string;
	state: DirectIncidentState;
	ability: string;
	error_code: string;
	cause_key_hash: string;
	severity: string;
	occurrences: number;
	reopened_count: number;
	first_seen: string;
	last_seen: string;
	failure_event_id: string;
	repair_phase: 'diagnose' | 'verify' | 'complete';
	learning_status: DirectLearningStatus;
	learning_memory_key: string | null;
	repair_receipt_id: string | null;
	learned_at: string | null;
	resolved_at: string | null;
	resolution_event_id: string | null;
};

type DirectIncidentDocument = {
	version: 1;
	site_fingerprint: string;
	incidents: DirectIncident[];
};

function sha256(value: string): string {
	return createHash('sha256').update(value).digest('hex');
}

function classification(value: string, fallback: string): string {
	const first = value.trim().toLowerCase().match(/^[a-z0-9][a-z0-9/_-]*/)?.[0] ?? fallback;
	return first.slice(0, 96);
}

function severity(value?: string): string {
	const normalized = classification(value ?? 'error', 'error');
	return ['critical', 'high', 'error', 'warning', 'notice', 'info'].includes(normalized)
		? normalized
		: 'error';
}

function iso(value?: string): string {
	const parsed = value ? Date.parse(value) : Number.NaN;
	return Number.isFinite(parsed) ? new Date(parsed).toISOString() : new Date().toISOString();
}

function emptyDocument(siteFingerprint: string): DirectIncidentDocument {
	return { version: 1, site_fingerprint: siteFingerprint, incidents: [] };
}

export class DirectIncidentStore {
	private readonly file: string;

	constructor(
		private readonly baseDir: string,
		private readonly siteFingerprint: string,
	) {
		if (!FINGERPRINT_RE.test(siteFingerprint)) {
			throw new Error('Direct incident store requires a SHA-256 site fingerprint');
		}
		const dir = resolve(join(baseDir, 'incidents'));
		this.file = resolve(join(dir, `${siteFingerprint}.json`));
		if (!this.file.startsWith(`${dir}/`)) {
			throw new Error('Direct incident path escaped the private state directory');
		}
	}

	path(): string {
		return this.file;
	}

	list(): DirectIncident[] {
		return this.read().incidents.map((incident) => ({ ...incident }));
	}

	get(incidentId: string): DirectIncident | null {
		return this.list().find((incident) => incident.incident_id === incidentId) ?? null;
	}

	observeFailure(input: DirectIncidentFailure): DirectIncident {
		const document = this.read();
		const ability = classification(input.ability, 'unknown-ability');
		const errorCode = classification(input.error_code, 'unknown-error');
		const causeKeyHash = sha256(input.cause_key.trim().toLowerCase());
		const incidentId = sha256(`${this.siteFingerprint}|${ability}|${errorCode}|${causeKeyHash}`);
		const timestamp = iso(input.timestamp);
		const existing = document.incidents.find((incident) => incident.incident_id === incidentId);

		if (existing) {
			const wasResolved = existing.state === 'resolved';
			existing.occurrences += 1;
			existing.last_seen = timestamp;
			existing.failure_event_id = classification(input.event_id, 'unknown-event');
			existing.severity = severity(input.severity);
			existing.state = 'open';
			existing.repair_phase = 'diagnose';
			if (wasResolved) {
				existing.reopened_count += 1;
				if (existing.learning_status === 'promoted') {
					existing.learning_status = 'stale';
				}
				existing.repair_receipt_id = null;
				existing.resolved_at = null;
				existing.resolution_event_id = null;
			}
			this.write(document);
			return { ...existing };
		}

		const incident: DirectIncident = {
			incident_id: incidentId,
			site_fingerprint: this.siteFingerprint,
			state: OPEN_THRESHOLD <= 1 ? 'open' : 'observing',
			ability,
			error_code: errorCode,
			cause_key_hash: causeKeyHash,
			severity: severity(input.severity),
			occurrences: 1,
			reopened_count: 0,
			first_seen: timestamp,
			last_seen: timestamp,
			failure_event_id: classification(input.event_id, 'unknown-event'),
			repair_phase: 'diagnose',
			learning_status: 'none',
			learning_memory_key: null,
			repair_receipt_id: null,
			learned_at: null,
			resolved_at: null,
			resolution_event_id: null,
		};
		document.incidents.push(incident);
		document.incidents = document.incidents
			.sort((left, right) => right.last_seen.localeCompare(left.last_seen))
			.slice(0, MAX_INCIDENTS);
		this.write(document);
		return { ...incident };
	}

	markResolved(
		incidentId: string,
		input: { repair_receipt_id: string; resolution_event_id: string; resolved_at?: string },
	): DirectIncident | null {
		const document = this.read();
		const incident = document.incidents.find((row) => row.incident_id === incidentId);
		if (!incident) return null;
		incident.state = 'resolved';
		incident.repair_phase = 'complete';
		incident.repair_receipt_id = classification(input.repair_receipt_id, 'invalid-receipt');
		incident.resolution_event_id = classification(input.resolution_event_id, 'invalid-event');
		incident.resolved_at = iso(input.resolved_at);
		this.write(document);
		return { ...incident };
	}

	markLearningPromoted(incidentId: string, memoryKey: string, receiptId: string): boolean {
		const document = this.read();
		const incident = document.incidents.find((row) => row.incident_id === incidentId);
		if (!incident || incident.state !== 'resolved') return false;
		incident.learning_status = 'promoted';
		incident.learning_memory_key = classification(memoryKey, 'verified-repair');
		incident.repair_receipt_id = classification(receiptId, 'invalid-receipt');
		incident.learned_at = new Date().toISOString();
		this.write(document);
		return true;
	}

	private read(): DirectIncidentDocument {
		if (!existsSync(this.file)) return emptyDocument(this.siteFingerprint);
		try {
			const parsed = JSON.parse(readFileSync(this.file, 'utf8')) as DirectIncidentDocument;
			if (
				parsed.version !== 1 ||
				parsed.site_fingerprint !== this.siteFingerprint ||
				!Array.isArray(parsed.incidents)
			) {
				throw new Error('Invalid Direct incident document');
			}
			return parsed;
		} catch {
			const corrupt = `${this.file}.corrupt`;
			if (!existsSync(corrupt)) renameSync(this.file, corrupt);
			return emptyDocument(this.siteFingerprint);
		}
	}

	private write(document: DirectIncidentDocument): void {
		const dir = resolve(join(this.baseDir, 'incidents'));
		mkdirSync(dir, { recursive: true, mode: 0o700 });
		if (process.platform !== 'win32') chmodSync(dir, 0o700);
		const temp = `${this.file}.${randomUUID()}.tmp`;
		writeFileSync(temp, `${JSON.stringify(document, null, 2)}\n`, {
			encoding: 'utf8',
			mode: 0o600,
		});
		if (process.platform !== 'win32') chmodSync(temp, 0o600);
		renameSync(temp, this.file);
		if (process.platform !== 'win32') chmodSync(this.file, 0o600);
	}
}
