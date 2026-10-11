import { createHash } from 'node:crypto';
import { chmodSync, existsSync, mkdirSync, readdirSync, readFileSync, unlinkSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { defaultStateDir } from './audit.js';
import { DirectSafetyBlockedError } from './writes.js';

/**
 * Pre-image of a whole-document Direct write.
 *
 * Before a write that replaces a template, a template part, global styles or a page, the current raw
 * resource (`context=edit`) is read and stored under
 * `<state dir>/backups/<site alias>/<tool>/<resource id>/<timestamp>.json`. When it cannot be read or
 * stored the write is refused. At most `MAX_PREIMAGES_PER_RESOURCE` files are kept per resource.
 */

export const MAX_PREIMAGES_PER_RESOURCE = 20;

export interface PreImageResult {
	backup_path: string;
	backup_sha256: string;
}

export interface PreImageClient {
	get: <T>(path: string, opts?: { query?: Record<string, string | number | boolean | null | undefined> }) => Promise<T>;
}

export interface PreImageRequest {
	client: PreImageClient;
	site: { alias: string };
	env?: NodeJS.ProcessEnv | undefined;
	tool: string;
	/** REST path of the resource, for example `/wp/v2/templates/theme%2F%2Fhome`. */
	restPath: string;
	/** Stable name of the resource, for example `pages-12`; made safe for use as a folder name. */
	resourceId: string;
}

/** A folder name that cannot leave its parent; a changed name gets a short hash so two ids never share one. */
function safeSegment(raw: string): string {
	const cleaned = raw
		.replace(/[^A-Za-z0-9_.-]/g, '_')
		.replace(/\.{2,}/g, '_')
		.replace(/^\.+/, '_')
		.slice(0, 80);
	const name = cleaned === '' ? '_' : cleaned;
	if (name === raw) return name;
	return `${name}-${createHash('sha256').update(raw).digest('hex').slice(0, 8)}`;
}

function blocked(request: PreImageRequest, reason: string): DirectSafetyBlockedError {
	return new DirectSafetyBlockedError(
		'preimage_unavailable',
		`Cannot write ${request.tool}: the current resource could not be backed up first (${reason}). Nothing was written.`,
		request.tool,
		request.site.alias,
	);
}

function reasonOf(error: unknown): string {
	const text = error instanceof Error ? error.message : String(error);
	return text.replace(/\s+/g, ' ').slice(0, 200);
}

function rotate(dir: string): void {
	try {
		const files = readdirSync(dir)
			.filter((name) => name.endsWith('.json'))
			.sort();
		for (const name of files.slice(0, Math.max(0, files.length - MAX_PREIMAGES_PER_RESOURCE))) {
			unlinkSync(join(dir, name));
		}
	} catch {
		// Rotation is best effort; the new backup is already stored.
	}
}

/** A file name that sorts after every file already in the folder, so oldest-first rotation stays correct. */
function newBackupPath(dir: string, now: Date): string {
	let stamp = now.getTime();
	const names = existsSync(dir) ? readdirSync(dir).filter((name) => name.endsWith('.json')).sort() : [];
	const newest = names.at(-1);
	if (newest !== undefined) {
		const parsed = Date.parse(newest.replace(/^(\d{4}-\d{2}-\d{2}T\d{2})-(\d{2})-(\d{2})-(\d{3})Z\.json$/, '$1:$2:$3.$4Z'));
		if (Number.isFinite(parsed) && parsed >= stamp) stamp = parsed + 1;
	}
	return join(dir, `${new Date(stamp).toISOString().replace(/[:.]/g, '-')}.json`);
}

/**
 * Read the current resource and store it. Throws `DirectSafetyBlockedError` (`preimage_unavailable`)
 * when the read or the store fails, so the caller sends nothing.
 */
export async function storePreImage(request: PreImageRequest): Promise<PreImageResult> {
	let body: unknown;
	try {
		body = await request.client.get<unknown>(request.restPath, { query: { context: 'edit' } });
	} catch (error) {
		throw blocked(request, `read failed: ${reasonOf(error)}`);
	}
	if (body === null || body === undefined || typeof body !== 'object' || Array.isArray(body)) {
		throw blocked(request, 'read returned no resource');
	}

	const env = request.env ?? process.env;
	const dir = join(
		defaultStateDir(env),
		'backups',
		safeSegment(request.site.alias),
		safeSegment(request.tool),
		safeSegment(request.resourceId),
	);
	const record = {
		schema: 'stonewright-direct-preimage/1',
		tool: request.tool,
		site: request.site.alias,
		resource: request.restPath,
		captured_at: new Date().toISOString(),
		body,
	};
	const bytes = Buffer.from(`${JSON.stringify(record, null, 2)}\n`, 'utf8');
	const sha256 = createHash('sha256').update(bytes).digest('hex');

	let path = '';
	try {
		mkdirSync(dir, { recursive: true, mode: 0o700 });
		path = newBackupPath(dir, new Date());
		writeFileSync(path, bytes, { mode: 0o600, flag: 'wx' });
		if (process.platform !== 'win32') chmodSync(path, 0o600);
		if (createHash('sha256').update(readFileSync(path)).digest('hex') !== sha256) {
			throw new Error('stored file does not match the read');
		}
	} catch (error) {
		if (path !== '') {
			try {
				unlinkSync(path);
			} catch {
				// nothing to clean up
			}
		}
		throw blocked(request, `store failed: ${reasonOf(error)}`);
	}

	rotate(dir);
	return { backup_path: path, backup_sha256: sha256 };
}
