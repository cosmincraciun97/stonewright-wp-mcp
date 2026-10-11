import {
	lstatSync,
	readFileSync,
	readdirSync,
	type Dirent,
} from 'node:fs';
import { join, resolve, sep } from 'node:path';
import { appendDirectAudit, defaultAuditPath, defaultStateDir } from './audit.js';
import { safeSegment, storePreImage } from './preimage.js';
import {
	assertToolEnabled,
	assertWriteAllowed,
	DirectSafetyBlockedError,
} from './writes.js';
import type { DirectToolContext } from './tools/types.js';
import { elementorDataGet, elementorDataUpdate, type ElementorCli } from './tools/elementor-direct.js';
import {
	attachVerification,
	compareSent,
	sha256Text,
	stableJson,
	stateHash,
	verifyWrite,
} from './verify.js';

/**
 * List and restore the copies Direct tools save before they overwrite something.
 *
 * Two kinds of file live under `<state dir>/backups/<site alias>/`:
 *   - pre-images (`<tool>/<resource>/<timestamp>.json`) of pages, posts, templates, template parts and
 *     global styles, written by `preimage.ts`;
 *   - Elementor file backups (`post-<id>-<timestamp>.json`), written by `stonewright-elementor-data-update`.
 *
 * A restore checks the hash stored in the file, checks that the resource still exists, needs a
 * confirmation, saves the current state first (so the restore can be undone), writes the stored
 * fields back through the same REST route, reads the resource back and audits the result.
 */

export const BACKUP_LIST_TOOL = 'stonewright-direct-backup-list';
export const BACKUP_RESTORE_TOOL = 'stonewright-direct-backup-restore';

const STAMP = '\\d{4}-\\d{2}-\\d{2}T\\d{2}-\\d{2}-\\d{2}-\\d{3}Z';
const PREIMAGE_ID = new RegExp(`^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/(${STAMP})\\.json$`);
const ELEMENTOR_ID = new RegExp(`^post-(\\d+)-(${STAMP})\\.json$`);
const SAFE_REST_PATH = /^\/wp\/v2\/[A-Za-z0-9_.%-]+\/[A-Za-z0-9_.%-]+$/;
const ELEMENTOR_TOOL = 'stonewright-elementor-data-update';

export type BackupIntegrity = 'ok' | 'tampered' | 'unverifiable' | 'unreadable';

interface LocatedBackup {
	id: string;
	kind: 'preimage' | 'elementor';
	path: string;
	tool: string;
	resource: string;
	stamp: string;
}

interface InspectedBackup extends LocatedBackup {
	bytes: Buffer;
	fileSha256: string;
	integrity: BackupIntegrity;
	record: Record<string, unknown> | null;
}

function blocked(code: string, message: string, ctx: DirectToolContext): DirectSafetyBlockedError {
	return new DirectSafetyBlockedError(code, message, BACKUP_RESTORE_TOOL, ctx.site.alias);
}

function backupBase(ctx: DirectToolContext): string {
	return join(defaultStateDir(ctx.env ?? process.env), 'backups', safeSegment(ctx.site.alias));
}

function isRecord(value: unknown): value is Record<string, unknown> {
	return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function isRegularFile(path: string): boolean {
	try {
		const stats = lstatSync(path);
		return stats.isFile() && !stats.isSymbolicLink();
	} catch {
		return false;
	}
}

function dirEntries(dir: string): Dirent[] {
	try {
		return readdirSync(dir, { withFileTypes: true });
	} catch {
		return [];
	}
}

/** Resolves a backup id to a file under this site's backup folder; nothing else can be named. */
function locate(ctx: DirectToolContext, id: string): LocatedBackup {
	const notFound = (): DirectSafetyBlockedError =>
		blocked('backup_not_found', 'No stored backup has that id for this site. Use stonewright-direct-backup-list to see the ids.', ctx);
	const base = backupBase(ctx);
	const pre = PREIMAGE_ID.exec(id);
	const el = pre ? null : ELEMENTOR_ID.exec(id);
	if (!pre && !el) throw notFound();
	const segments = id.split('/');
	if (segments.some((segment) => segment === '.' || segment === '..')) throw notFound();
	const path = resolve(base, ...segments);
	if (!path.startsWith(resolve(base) + sep) || !isRegularFile(path)) throw notFound();
	if (pre) {
		return { id, kind: 'preimage', path, tool: pre[1] ?? '', resource: pre[2] ?? '', stamp: pre[3] ?? '' };
	}
	return { id, kind: 'elementor', path, tool: ELEMENTOR_TOOL, resource: `post-${el?.[1] ?? ''}`, stamp: el?.[2] ?? '' };
}

function inspect(located: LocatedBackup): InspectedBackup {
	let bytes: Buffer;
	try {
		bytes = readFileSync(located.path);
	} catch {
		return { ...located, bytes: Buffer.alloc(0), fileSha256: '', integrity: 'unreadable', record: null };
	}
	const fileSha256 = sha256Text(bytes);
	let record: Record<string, unknown> | null = null;
	try {
		const parsed = JSON.parse(bytes.toString('utf8')) as unknown;
		record = isRecord(parsed) ? parsed : null;
	} catch {
		record = null;
	}
	if (!record) return { ...located, bytes, fileSha256, integrity: 'unreadable', record: null };

	const payload = located.kind === 'preimage' ? record['body'] : record['data'];
	const shape = located.kind === 'preimage'
		? record['schema'] === 'stonewright-direct-preimage/1' && isRecord(payload)
		: payload !== null && typeof payload === 'object';
	if (!shape) return { ...located, bytes, fileSha256, integrity: 'unreadable', record };
	const stored = located.kind === 'preimage' ? record['body_sha256'] : record['data_sha256'];
	if (typeof stored !== 'string' || stored === '') return { ...located, bytes, fileSha256, integrity: 'unverifiable', record };
	const matches = stored === sha256Text(stableJson(payload));
	return { ...located, bytes, fileSha256, integrity: matches ? 'ok' : 'tampered', record };
}

// --- list -------------------------------------------------------------------------------------

function stampOf(name: string): string {
	return /(\d{4}-\d{2}-\d{2}T\d{2}-\d{2}-\d{2}-\d{3}Z)\.json$/.exec(name)?.[1] ?? '';
}

function candidates(ctx: DirectToolContext): LocatedBackup[] {
	const base = backupBase(ctx);
	const out: LocatedBackup[] = [];
	for (const entry of dirEntries(base)) {
		if (entry.isFile()) {
			const match = ELEMENTOR_ID.exec(entry.name);
			if (match) {
				out.push({ id: entry.name, kind: 'elementor', path: join(base, entry.name), tool: ELEMENTOR_TOOL, resource: `post-${match[1] ?? ''}`, stamp: match[2] ?? '' });
			}
			continue;
		}
		if (!entry.isDirectory()) continue;
		for (const resource of dirEntries(join(base, entry.name))) {
			if (!resource.isDirectory()) continue;
			for (const file of dirEntries(join(base, entry.name, resource.name))) {
				if (!file.isFile() || stampOf(file.name) === '' || !file.name.endsWith('.json')) continue;
				const id = `${entry.name}/${resource.name}/${file.name}`;
				if (!PREIMAGE_ID.test(id)) continue;
				out.push({ id, kind: 'preimage', path: join(base, entry.name, resource.name, file.name), tool: entry.name, resource: resource.name, stamp: stampOf(file.name) });
			}
		}
	}
	return out.sort((a, b) => (a.stamp < b.stamp ? 1 : a.stamp > b.stamp ? -1 : a.id < b.id ? 1 : -1));
}

export function backupList(
	ctx: DirectToolContext,
	input: { resource?: string | undefined; tool?: string | undefined; limit?: number | undefined } = {},
) {
	return Promise.resolve().then(() => listBackups(ctx, input));
}

function listBackups(
	ctx: DirectToolContext,
	input: { resource?: string | undefined; tool?: string | undefined; limit?: number | undefined },
) {
	assertToolEnabled(ctx.site, BACKUP_LIST_TOOL);
	const limit = Math.min(Math.max(Math.trunc(input.limit ?? 20), 1), 100);
	const resource = (input.resource ?? '').trim().toLowerCase();
	const tool = (input.tool ?? '').trim().toLowerCase();
	const matching = candidates(ctx).filter(
		(item) => (resource === '' || item.resource.toLowerCase().includes(resource)) && (tool === '' || item.tool.toLowerCase().includes(tool)),
	);
	const items = matching.slice(0, limit).map((item) => {
		const found = inspect(item);
		const when = found.record?.['captured_at'] ?? found.record?.['backed_up_at'];
		return {
			backup_id: item.id,
			kind: item.kind,
			tool: item.tool,
			resource: item.resource,
			...(item.kind === 'preimage' && typeof found.record?.['resource'] === 'string' ? { rest_path: found.record['resource'] } : {}),
			captured_at: typeof when === 'string' ? when : null,
			size_bytes: found.bytes.length,
			backup_sha256: found.fileSha256,
			integrity: found.integrity,
		};
	});
	return {
		items,
		total: matching.length,
		...(matching.length > items.length ? { truncated: true } : {}),
		restore_with: BACKUP_RESTORE_TOOL,
	};
}

// --- restore ----------------------------------------------------------------------------------

const POST_FIELDS = ['title', 'content', 'excerpt', 'status', 'slug', 'parent', 'template', 'meta'] as const;
const TEMPLATE_FIELDS = ['title', 'content', 'description'] as const;
const STYLE_FIELDS = ['settings', 'styles'] as const;

interface RestoreSpec {
	fields: readonly string[];
	method: 'put' | 'post';
	resourceType: string;
}

/** Collections a content or blueprint write never touches; a backup that names one is refused. */
const NOT_CONTENT = new Set([
	'users',
	'comments',
	'menus',
	'menu-items',
	'widgets',
	'plugins',
	'themes',
	'settings',
	'sidebars',
	'templates',
	'template-parts',
	'global-styles',
	'application-passwords',
]);

/** A backup restores only to the kind of resource the tool that wrote it works on. */
function pathMatchesTool(tool: string, restPath: string): boolean {
	const collection = /^\/wp\/v2\/([A-Za-z0-9_.-]+)\//.exec(restPath)?.[1] ?? '';
	switch (tool) {
		case 'stonewright-content-update':
		case 'stonewright-blueprint-apply':
			return !NOT_CONTENT.has(collection);
		case 'stonewright-template-update':
			return collection === 'templates';
		case 'stonewright-template-part-update':
			return collection === 'template-parts';
		case 'stonewright-global-styles-update':
			return collection === 'global-styles';
		case BACKUP_RESTORE_TOOL:
			// An undo copy of an earlier restore: any of the kinds above.
			return collection === 'templates' || collection === 'template-parts' || collection === 'global-styles' || !NOT_CONTENT.has(collection);
		default:
			return false;
	}
}

function specFor(restPath: string): RestoreSpec | null {
	if (/^\/wp\/v2\/(?:templates|template-parts)\//.test(restPath)) {
		return { fields: TEMPLATE_FIELDS, method: 'post', resourceType: restPath.startsWith('/wp/v2/templates/') ? 'templates' : 'template-parts' };
	}
	if (restPath.startsWith('/wp/v2/global-styles/')) return { fields: STYLE_FIELDS, method: 'post', resourceType: 'global-styles' };
	const collection = /^\/wp\/v2\/([A-Za-z0-9_.-]+)\/\d+$/.exec(restPath)?.[1];
	if (collection) return { fields: POST_FIELDS, method: 'put', resourceType: collection };
	return null;
}

function rawOf(value: unknown): unknown {
	return isRecord(value) && 'raw' in value ? value['raw'] : value;
}

/** The writable fields of a stored resource, in the form the REST route accepts. */
function restoreBody(spec: RestoreSpec, stored: Record<string, unknown>): Record<string, unknown> {
	const body: Record<string, unknown> = {};
	for (const field of spec.fields) {
		if (!(field in stored)) continue;
		const value = rawOf(stored[field]);
		if (value === undefined) continue;
		if (field === 'status' && (value === 'trash' || value === 'auto-draft')) continue;
		if (field === 'meta' && (!isRecord(value) || Object.keys(value).length === 0)) continue;
		if (field === 'parent' && typeof value !== 'number') continue;
		body[field] = value;
	}
	return body;
}

function resourceLabel(restPath: string): string {
	return decodeURIComponent(restPath.replace(/^\/wp\/v2\//, ''));
}

function statusOf(error: unknown): number | undefined {
	const status = error && typeof error === 'object' ? (error as { status?: unknown }).status : undefined;
	return typeof status === 'number' ? status : undefined;
}

export interface BackupRestoreInput {
	backup_id: string;
	confirm?: boolean | undefined;
	dry_run?: boolean | undefined;
	/** The `backup_sha256` the original write returned; checked against the stored file. */
	expected_sha256?: string | undefined;
}

export interface BackupRestoreDeps {
	/** Test seam for the Elementor WP-CLI path. */
	cli?: ElementorCli | undefined;
	/** Internal: the caller already holds the agent's confirmation (a rollback the agent asked for with rollback_on_probe_failure). */
	confirmed?: boolean | undefined;
}

function assertTrustworthy(ctx: DirectToolContext, found: InspectedBackup, expected: string | undefined): void {
	if (found.integrity === 'unreadable') {
		throw blocked('backup_integrity_failed', 'The stored backup cannot be read as a Direct backup, so it was not used.', ctx);
	}
	if (found.integrity === 'tampered') {
		throw blocked('backup_integrity_failed', 'The stored backup does not match the hash saved with it (the file was edited or damaged), so it was not used.', ctx);
	}
	const wanted = (expected ?? '').trim().toLowerCase();
	if (wanted !== '') {
		if (wanted !== found.fileSha256) {
			throw blocked('backup_hash_mismatch', 'The stored file does not match expected_sha256, so it was not used.', ctx);
		}
	} else if (found.integrity === 'unverifiable') {
		throw blocked(
			'backup_unverifiable',
			'This backup has no stored hash (it was written by an older version). Pass expected_sha256 (the backup_sha256 returned when it was written) to restore it.',
			ctx,
		);
	}
	const writtenBy = found.record?.['tool'];
	if (found.kind === 'preimage' && typeof writtenBy === 'string' && safeSegment(writtenBy) !== found.tool) {
		throw blocked('backup_integrity_failed', 'The stored backup names a different tool than the folder it sits in, so it was not used.', ctx);
	}
	const site = found.record?.['site'];
	if (typeof site === 'string' && site !== ctx.site.alias) {
		throw blocked('backup_site_mismatch', 'This backup was taken for a different site.', ctx);
	}
}

export async function backupRestore(
	ctx: DirectToolContext,
	input: BackupRestoreInput,
	deps: BackupRestoreDeps = {},
): Promise<Record<string, unknown>> {
	assertToolEnabled(ctx.site, BACKUP_RESTORE_TOOL);
	const env = ctx.env ?? process.env;
	const dryRun = input.dry_run === true;
	if (!dryRun) {
		assertWriteAllowed({
			site: ctx.site,
			mode: ctx.writeMode,
			destructive: true,
			...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
			tool: BACKUP_RESTORE_TOOL,
			env,
			// Restoring overwrites the current resource: a confirmation is asked for in every write mode (dry_run:true shows the change without one).
			alwaysConfirm: true,
			preConfirmed: deps.confirmed === true,
		});
	}

	const found = inspect(locate(ctx, input.backup_id));
	assertTrustworthy(ctx, found, input.expected_sha256);
	return found.kind === 'elementor'
		? restoreElementor(ctx, found, { dryRun, deps, env })
		: restorePreImage(ctx, found, { dryRun, env });
}

async function restorePreImage(
	ctx: DirectToolContext,
	found: InspectedBackup,
	opts: { dryRun: boolean; env: NodeJS.ProcessEnv },
): Promise<Record<string, unknown>> {
	const record = found.record ?? {};
	const restPath = typeof record['resource'] === 'string' ? record['resource'] : '';
	const stored = isRecord(record['body']) ? record['body'] : {};
	const spec = SAFE_REST_PATH.test(restPath) && pathMatchesTool(found.tool, restPath) ? specFor(restPath) : null;
	if (!spec) {
		throw blocked('restore_unsupported', 'This backup does not name a resource that Direct can restore.', ctx);
	}
	const body = restoreBody(spec, stored);
	if (Object.keys(body).length === 0) {
		throw blocked('restore_unsupported', 'This backup holds no field that Direct can write back.', ctx);
	}

	let current: unknown;
	try {
		current = await ctx.client.get<unknown>(restPath, { query: { context: 'edit' } });
	} catch (error) {
		if (statusOf(error) === 404 || statusOf(error) === 410) {
			throw blocked('resource_missing', 'The resource this backup belongs to no longer exists. Direct restores into an existing resource only; it does not recreate one.', ctx);
		}
		throw error;
	}
	const differing = compareSent(body, current).mismatched;
	const label = resourceLabel(restPath);

	if (opts.dryRun) {
		return {
			ok: true,
			dry_run: true,
			backup_id: found.id,
			resource: label,
			integrity: found.integrity,
			would_change: differing,
			fields_in_backup: Object.keys(body),
		};
	}
	if (differing.length === 0) {
		return { ok: true, restored: false, unchanged: true, restored_from: found.id, resource: label, message: 'The resource already matches this backup; nothing was written.' };
	}

	// Save the state as it is now, so this restore can itself be undone.
	const undo = await storePreImage({
		client: ctx.client,
		site: ctx.site,
		env: opts.env,
		tool: BACKUP_RESTORE_TOOL,
		restPath,
		resourceId: found.resource,
	});
	try {
		await ctx.client[spec.method]<unknown>(restPath, { body });
	} catch (error) {
		appendDirectAudit(
			{
				tool: BACKUP_RESTORE_TOOL,
				site: ctx.site.alias,
				targetIdentity: ctx.site.url,
				resource: label,
				resourceType: spec.resourceType,
				status: 'error',
				code: error instanceof Error ? error.name : 'error',
				beforeSha256: undo.before_sha256,
			},
			defaultAuditPath(opts.env),
		);
		throw error;
	}
	const verification = await verifyWrite({
		client: ctx.client,
		site: ctx.site,
		env: opts.env,
		tool: BACKUP_RESTORE_TOOL,
		resource: label,
		resourceType: spec.resourceType,
		readPath: restPath,
		sent: body,
		beforeSha256: undo.before_sha256,
		backupId: undo.backup_id,
		note: `restored from ${found.id}`,
	});
	const result = {
		ok: true,
		restored: true,
		restored_from: found.id,
		resource: label,
		restored_fields: Object.keys(body),
		matches_backup: verification.after_sha256 !== null && verification.after_sha256 === stateHash(stored),
		...undo,
	};
	return attachVerification(result, verification, { backupId: undo.backup_id });
}

async function restoreElementor(
	ctx: DirectToolContext,
	found: InspectedBackup,
	opts: { dryRun: boolean; deps: BackupRestoreDeps; env: NodeJS.ProcessEnv },
): Promise<Record<string, unknown>> {
	const record = found.record ?? {};
	const postId = Number(record['post_id']);
	if (!Number.isInteger(postId) || postId <= 0 || !Array.isArray(record['data'])) {
		throw blocked('restore_unsupported', 'This Elementor backup does not hold a document tree Direct can restore.', ctx);
	}
	const collection = typeof record['collection'] === 'string' ? record['collection'] : undefined;
	const cli = opts.deps.cli;

	const present = await elementorDataGet(
		opts.env,
		{ post_id: postId, ...(collection ? { type: collection } : {}), responseMode: 'summary', maxElements: 1 },
		cli,
		ctx.client,
	);
	if (!present.ok) {
		throw blocked(
			'resource_missing',
			`The Elementor document for post ${postId} could not be read (${String((present as { error?: unknown }).error ?? 'not found')}). Direct restores into an existing document only.`,
			ctx,
		);
	}
	if (opts.dryRun) {
		return {
			ok: true,
			dry_run: true,
			backup_id: found.id,
			resource: `post-${postId}`,
			integrity: found.integrity,
			element_count_in_backup: Array.isArray(record['data']) ? (record['data'] as unknown[]).length : 0,
			element_count_now: (present as { element_count?: number }).element_count ?? null,
		};
	}
	const written = await elementorDataUpdate(
		opts.env,
		{
			post_id: postId,
			data: record['data'] as unknown[],
			site: ctx.site.alias,
			...(collection ? { type: collection } : {}),
			confirm: true,
			// A stored earlier version can be smaller or use other widgets than the one on the site now.
			force_destructive: true,
			allow_widget_type_remap: true,
		},
		cli,
		ctx.client,
		{ restore: { tool: BACKUP_RESTORE_TOOL, from: found.id } },
	);
	return { ...(written as Record<string, unknown>), restored_from: found.id, restored: (written as { ok?: unknown }).ok === true };
}
