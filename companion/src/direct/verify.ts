import { createHash, randomUUID } from 'node:crypto';
import { appendDirectAudit, defaultAuditPath } from './audit.js';

/**
 * Read-back check for every typed Direct write.
 *
 * After a write the resource is read again (`context=edit`) and the fields that were sent are compared
 * with what WordPress returns, normalized the way WordPress returns them (`{raw, rendered}` pairs reduce
 * to `raw`, line endings are unified, numbers and numeric strings match, arrays match as sets and objects
 * as subsets). The result is `verified`, `mismatch` or `not_checked`. Two audit rows are written: the write
 * row, which carries the before and after hashes, and a `direct_verifier` row with the outcome.
 *
 * The hashes are taken over a projection of the stored resource without the fields that change on every
 * save (modified dates, rendered output, links), so the same stored state always gives the same hash.
 */

export type VerificationStatus = 'verified' | 'mismatch' | 'not_checked';

export interface WriteVerification {
	status: VerificationStatus;
	checked_fields: string[];
	mismatched_fields: string[];
	skipped_fields: string[];
	before_sha256: string | null;
	after_sha256: string | null;
	change_set_id: string;
	reason?: string;
	/** Set when WordPress stored the slug with a numeric suffix because the one that was sent is taken. */
	slug_adjusted?: SlugAdjusted;
	/** False when the audit rows could not be written; the check itself still ran. */
	audit_written?: boolean;
}

export interface SlugAdjusted {
	requested: string;
	stored: string;
}

export interface VerifyClient {
	get: <T>(path: string, opts?: { query?: Record<string, string | number | boolean | null | undefined> }) => Promise<T>;
}

const VOLATILE_KEYS = new Set([
	'modified',
	'modified_gmt',
	'_links',
	'_embedded',
	'guid',
	'link',
	'class_list',
	'generated_slug',
	'permalink_template',
	'yoast_head',
	'yoast_head_json',
]);

/** Keys that are accepted by a write but never returned by WordPress. */
const WRITE_ONLY_KEYS = new Set(['password', 'user_pass', 'force', 'reassign', 'context', 'confirm']);

export function stableJson(value: unknown): string {
	if (Array.isArray(value)) return `[${value.map(stableJson).join(',')}]`;
	if (value && typeof value === 'object') {
		return `{${Object.entries(value as Record<string, unknown>)
			.filter(([, item]) => item !== undefined)
			.sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
			.map(([key, item]) => `${JSON.stringify(key)}:${stableJson(item)}`)
			.join(',')}}`;
	}
	return JSON.stringify(value) ?? 'null';
}

export function sha256Text(value: string | Uint8Array): string {
	return createHash('sha256').update(value).digest('hex');
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
	return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/**
 * Only the top level of a resource is reduced: a field that WordPress returns as `{raw, rendered}` becomes its
 * `raw`, and the fields that change on every save are left out. Everything deeper is kept as stored, because a
 * nested key such as `link` or `raw` in theme.json or a widget is content, not volatile.
 */
function project(value: unknown): unknown {
	if (!isPlainObject(value)) return value;
	const out: Record<string, unknown> = {};
	for (const [key, item] of Object.entries(value)) {
		if (VOLATILE_KEYS.has(key)) continue;
		out[key] = isPlainObject(item) && 'raw' in item ? item['raw'] : item;
	}
	return out;
}

/** Hash of the stored state of a resource, without the fields that change on every save. */
export function stateHash(body: unknown): string {
	return sha256Text(stableJson(project(body)));
}

// --- comparison -------------------------------------------------------------------------------

function normText(value: unknown): unknown {
	return typeof value === 'string' ? value.replace(/\r\n/g, '\n') : value;
}

function isScalar(value: unknown): boolean {
	return value === null || ['string', 'number', 'boolean'].includes(typeof value);
}

function isStructured(value: unknown): boolean {
	return value !== null && typeof value === 'object';
}

function emptyLike(value: unknown): boolean {
	return value === null || value === undefined || value === '';
}

function looseEqual(sent: unknown, actual: unknown): boolean {
	if (isScalar(sent) || sent === undefined) {
		if (emptyLike(sent) && emptyLike(actual)) return true;
		if (!isScalar(actual)) return false;
		return String(normText(sent)) === String(normText(actual));
	}
	if (Array.isArray(sent)) {
		if (!Array.isArray(actual) || sent.length !== actual.length) return false;
		if (sent.every(isScalar) && actual.every(isScalar)) {
			const a = sent.map((item) => String(item)).sort();
			const b = actual.map((item) => String(item)).sort();
			return a.every((item, index) => item === b[index]);
		}
		return sent.every((item, index) => looseEqual(item, actual[index]));
	}
	if (isPlainObject(sent)) {
		if (!isPlainObject(actual)) return false;
		return Object.entries(sent).every(([key, item]) => looseEqual(item, actual[key]));
	}
	return false;
}

const RAW_FIELDS = new Set(['title', 'content', 'excerpt', 'caption', 'description']);

/** WordPress turns a slug into lower case words joined by dashes; compare the slug the same way. */
function slugForm(value: unknown): unknown {
	if (typeof value !== 'string') return value;
	let text = value;
	try {
		text = decodeURIComponent(value);
	} catch {
		// keep the text as sent
	}
	return text.toLowerCase().replace(/[^\p{L}\p{N}_-]+/gu, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
}

/** Term names come back HTML-escaped. */
function nameForm(value: unknown): unknown {
	if (typeof value !== 'string') return value;
	return value
		.replace(/&amp;/g, '&')
		.replace(/&lt;/g, '<')
		.replace(/&gt;/g, '>')
		.replace(/&quot;/g, '"')
		.replace(/&#0?39;/g, "'");
}

export interface CompareOutcome {
	checked: string[];
	mismatched: string[];
	skipped: string[];
	/** The slug WordPress stored when it added a numeric suffix to the one that was sent (allowSlugSuffix only). */
	slugAdjusted?: SlugAdjusted;
}

export interface CompareOptions {
	/**
	 * Accept the slug WordPress stores when the one that was sent is taken: the sent slug with `-2`, `-3` and so on.
	 * A write check wants this; a comparison of a stored copy with the current resource does not.
	 */
	allowSlugSuffix?: boolean;
}

/** Whether `stored` is `requested` with the numeric suffix (from 2 up) that WordPress adds to a slug that is taken. */
function isUniqueSuffix(requested: unknown, stored: unknown): boolean {
	const base = slugForm(requested);
	const kept = slugForm(stored);
	if (typeof base !== 'string' || typeof kept !== 'string' || base === '') return false;
	const match = /^(.+)-([1-9]\d*)$/.exec(kept);
	return match !== null && match[1] === base && Number(match[2]) >= 2;
}

/** Compare the fields of a request body with the resource WordPress returns. */
export function compareSent(sent: Record<string, unknown>, actual: unknown, options: CompareOptions = {}): CompareOutcome {
	const out: CompareOutcome = { checked: [], mismatched: [], skipped: [] };
	const body = isPlainObject(actual) ? actual : {};
	for (const [field, value] of Object.entries(sent)) {
		if (value === undefined || WRITE_ONLY_KEYS.has(field)) continue;
		const stored = body[field];
		if (RAW_FIELDS.has(field) && isPlainObject(stored)) {
			if (!('raw' in stored)) {
				out.skipped.push(field);
				continue;
			}
			out.checked.push(field);
			if (!looseEqual(value, stored['raw'])) out.mismatched.push(field);
			continue;
		}
		if ((field === 'meta' || field === 'acf') && isPlainObject(value)) {
			const storedMeta = isPlainObject(stored) ? stored : {};
			let compared = 0;
			for (const [key, item] of Object.entries(value)) {
				// ACF returns structured fields (images, relationships, repeaters) in the format the field is
				// set to return, which need not be the form that was sent; only plain values are compared.
				if (field === 'acf' && (isStructured(item) || isStructured(storedMeta[key]))) {
					out.skipped.push(`acf.${key}`);
					continue;
				}
				compared += 1;
				if (!looseEqual(item, storedMeta[key])) out.mismatched.push(`${field}.${key}`);
			}
			if (compared > 0 || Object.keys(value).length === 0) out.checked.push(field);
			continue;
		}
		out.checked.push(field);
		const same =
			field === 'slug'
				? looseEqual(slugForm(value), slugForm(stored))
				: field === 'name'
					? looseEqual(nameForm(value), nameForm(stored))
					: looseEqual(value, stored);
		if (!same && field === 'slug' && options.allowSlugSuffix === true && isUniqueSuffix(value, stored)) {
			out.slugAdjusted = { requested: String(value), stored: String(stored) };
			continue;
		}
		if (!same) out.mismatched.push(field);
	}
	return out;
}

// --- verification -----------------------------------------------------------------------------

export interface VerifyWriteArgs {
	client: VerifyClient;
	site: { alias: string; url: string };
	env?: NodeJS.ProcessEnv | undefined;
	tool: string;
	/** Audit label of the resource, for example `pages/12`. */
	resource: string;
	resourceType: string;
	/** REST path that returns the resource after the write. */
	readPath: string;
	readQuery?: Record<string, string | number | boolean | null | undefined>;
	/** The request body that was sent, restricted to fields WordPress returns. */
	sent?: Record<string, unknown> | undefined;
	/**
	 * `fields` compares `sent` with the stored resource; `present` does the same and counts a resource that
	 * exists with nothing to compare as verified (a new upload); `absent` expects the resource to be gone.
	 */
	expect?: 'fields' | 'present' | 'absent';
	/** Hash of the stored state before the write, when a pre-image was taken. */
	beforeSha256?: string | undefined;
	/** Stored pre-image to point the agent at when the check fails. */
	backupId?: string | undefined;
	/** Short text added to the audit summary, for example where a restore came from. */
	note?: string | undefined;
}

function statusOf(error: unknown): number | undefined {
	const status = error && typeof error === 'object' ? (error as { status?: unknown }).status : undefined;
	return typeof status === 'number' ? status : undefined;
}

function reasonOf(error: unknown): string {
	const text = error instanceof Error ? error.message : String(error);
	return text.replace(/\s+/g, ' ').slice(0, 120);
}

/**
 * Writes the write row and the `direct_verifier` row for a finished check. A failure to write them does not
 * fail the write, which has already happened; it is reported as `audit_written:false` instead.
 */
export function recordVerification(
	args: Pick<VerifyWriteArgs, 'site' | 'env' | 'tool' | 'resource' | 'resourceType' | 'note'>,
	result: WriteVerification,
): void {
	const env = args.env ?? process.env;
	const changeSetId = result.change_set_id;
	const auditPath = defaultAuditPath(env);
	const verified = result.status === 'verified';
	const mismatch = result.status === 'mismatch';
	const adjusted = result.slug_adjusted ? `; slug adjusted by WordPress: ${result.slug_adjusted.requested} -> ${result.slug_adjusted.stored}` : '';
	const outcome = mismatch
		? `fields differ after the write: ${result.mismatched_fields.join(', ')}`
		: `${result.reason ?? `fields compared: ${result.checked_fields.join(', ')}`}${adjusted}`;
	const summary = (args.note ? `${args.note}; ${outcome}` : outcome).slice(0, 300);
	const common = {
		tool: args.tool,
		site: args.site.alias,
		targetIdentity: args.site.url,
		resource: args.resource,
		resourceType: args.resourceType,
		correlationId: changeSetId,
		verificationStatus: result.status,
		effectVerified: verified,
		...(result.before_sha256 !== null ? { beforeSha256: result.before_sha256 } : {}),
		...(result.after_sha256 !== null ? { afterSha256: result.after_sha256 } : {}),
	};
	try {
		const write = appendDirectAudit(
			{ ...common, status: 'ok', changeSetId, validatorSummary: summary },
			auditPath,
		);
		appendDirectAudit(
			{
				...common,
				status: mismatch ? 'error' : 'ok',
				eventType: 'direct_verifier',
				category: 'READ',
				parentRequestId: String(write['event_id']),
				// A mismatch opens an informational incident that ages out; a change set here would make it
				// wait for a repair receipt that nothing in a plain write can produce.
				...(mismatch ? { code: 'verification_mismatch', error: summary } : { changeSetId }),
				validatorSummary: summary,
			},
			auditPath,
		);
	} catch {
		result.audit_written = false;
	}
}

export async function verifyWrite(args: VerifyWriteArgs): Promise<WriteVerification> {
	const changeSetId = randomUUID();
	const result: WriteVerification = {
		status: 'not_checked',
		checked_fields: [],
		mismatched_fields: [],
		skipped_fields: [],
		before_sha256: args.beforeSha256 ?? null,
		after_sha256: null,
		change_set_id: changeSetId,
	};

	try {
		const actual = await args.client.get<unknown>(args.readPath, {
			query: args.readQuery ?? { context: 'edit' },
		});
		if (args.expect === 'absent') {
			result.status = 'mismatch';
			result.mismatched_fields = ['resource_still_present'];
			result.after_sha256 = stateHash(actual);
		} else {
			const compared = compareSent(args.sent ?? {}, actual, { allowSlugSuffix: true });
			result.checked_fields = compared.checked;
			result.mismatched_fields = compared.mismatched;
			result.skipped_fields = compared.skipped;
			if (compared.slugAdjusted) result.slug_adjusted = compared.slugAdjusted;
			result.after_sha256 = stateHash(actual);
			if (compared.mismatched.length > 0) {
				result.status = 'mismatch';
			} else if (compared.checked.length === 0) {
				if (args.expect === 'present') {
					result.checked_fields = ['exists'];
					result.status = 'verified';
				} else {
					result.reason = 'no_comparable_fields';
				}
			} else {
				result.status = 'verified';
			}
		}
	} catch (error) {
		if (args.expect === 'absent' && (statusOf(error) === 404 || statusOf(error) === 410)) {
			result.status = 'verified';
		} else {
			result.reason = `readback_failed: ${reasonOf(error)}`;
		}
	}

	recordVerification(args, result);
	return result;
}

/** What the agent sees: the readback outcome without internal detail. */
export function attachVerification<T extends object>(
	result: T,
	verification: WriteVerification,
	options: { backupId?: string | undefined } = {},
): T & { verification: Record<string, unknown> } {
	return attachUntyped(result, verification, options) as T & { verification: Record<string, unknown> };
}

function attachUntyped(
	result: object,
	verification: WriteVerification,
	options: { backupId?: string | undefined },
): Record<string, unknown> {
	const base: Record<string, unknown> = { ...result };
	const backupId = options.backupId ?? (typeof base['backup_id'] === 'string' ? base['backup_id'] : undefined);
	const shown: Record<string, unknown> = {
		status: verification.status,
		checked_fields: verification.checked_fields,
		mismatched_fields: verification.mismatched_fields,
		...(verification.skipped_fields.length > 0 ? { skipped_fields: verification.skipped_fields } : {}),
		before_sha256: verification.before_sha256,
		after_sha256: verification.after_sha256,
		change_set_id: verification.change_set_id,
		...(verification.reason !== undefined ? { reason: verification.reason } : {}),
		...(verification.slug_adjusted !== undefined ? { slug_adjusted: verification.slug_adjusted } : {}),
		...(verification.audit_written === false ? { audit_written: false } : {}),
	};
	if (verification.status !== 'mismatch') return { ...base, verification: shown };
	const fields = verification.mismatched_fields.join(', ');
	return {
		...base,
		ok: false,
		error: 'verification_mismatch',
		written: true,
		message: `The write was accepted, but reading it back shows the stored resource differs from what was sent (${fields}). The site changed or did not keep it. Do not report this as done: read the resource, then correct it${backupId ? ' or restore the saved copy' : ''}.`,
		verification: shown,
		...(backupId
			? { restore_with: { tool: 'stonewright-direct-backup-restore', backup_id: backupId, needs: 'confirmation' } }
			: {}),
	};
}
