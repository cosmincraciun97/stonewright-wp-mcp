import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { appendDirectAudit, defaultStateDir } from '../audit.js';
import {
	assertWriteAllowed as integrityAssertWrite,
	encodeTreeOnce,
	normalizeToTree,
} from '../elementor-integrity.js';
import { assertWriteAllowed, resolveDirectWriteMode } from '../writes.js';
import { runWpCli, type WpCliCommandResult } from '../../wp-cli.js';

export type ElementorCli = typeof runWpCli;

/** Minimal REST client shape used for remote Elementor meta when WP-CLI is absent. */
export type ElementorRestClient = {
	get: <T>(
		path: string,
		opts?: { query?: Record<string, string | number | boolean | null | undefined> },
	) => Promise<T>;
	post: <T>(path: string, opts?: { body?: unknown }) => Promise<T>;
};

function resolveScope(site?: string): string {
	const s = (site ?? '_global').trim();
	return s.length > 0 ? s.replace(/[^a-zA-Z0-9_.-]/g, '_') : '_global';
}

function asFull(
	result: WpCliCommandResult,
): { ok: boolean; stdout: string; stderr: string; parsed_json?: unknown; error?: string; available?: boolean } {
	return {
		ok: Boolean(result.ok),
		available: result.available !== false,
		stdout: String((result as { stdout?: string }).stdout ?? ''),
		stderr: String((result as { stderr?: string }).stderr ?? ''),
		...(result.parsed_json !== undefined ? { parsed_json: result.parsed_json } : {}),
		...(typeof result.error === 'string' ? { error: result.error } : {}),
	};
}

function parseElementorTree(raw: unknown): unknown {
	if (typeof raw === 'string') {
		try {
			return JSON.parse(raw);
		} catch {
			return raw;
		}
	}
	return raw;
}

function collectionCandidates(type?: string): string[] {
	const t = (type ?? '').trim().toLowerCase();
	if (t === 'page' || t === 'pages') return ['pages'];
	if (t === 'post' || t === 'posts') return ['posts'];
	if (t) return [t, 'pages', 'posts'];
	return ['pages', 'posts'];
}

async function restFindCollection(
	client: ElementorRestClient,
	postId: number,
	type?: string,
): Promise<string | null> {
	for (const collection of collectionCandidates(type)) {
		try {
			await client.get(`/wp/v2/${collection}/${postId}`, {
				query: { context: 'edit', _fields: 'id' },
			});
			return collection;
		} catch {
			// try next
		}
	}
	return null;
}

async function restDataGet(
	client: ElementorRestClient,
	postId: number,
	type?: string,
): Promise<{
	ok: boolean;
	post_id: number;
	transport: 'rest';
	collection?: string;
	edit_mode?: string | null;
	template_type?: string | null;
	element_count?: number;
	data?: unknown;
	error?: string;
	hint?: string;
}> {
	const collection = await restFindCollection(client, postId, type);
	if (!collection) {
		return {
			ok: false,
			post_id: postId,
			transport: 'rest',
			error: 'Post not found via core REST (tried pages/posts).',
			hint: 'Pass type for CPTs, or install the Stonewright plugin for typed Elementor engines.',
		};
	}
	const post = await client.get<{
		id?: number;
		meta?: Record<string, unknown>;
	}>(`/wp/v2/${collection}/${postId}`, {
		query: { context: 'edit' },
	});
	const meta = post.meta && typeof post.meta === 'object' ? post.meta : {};
	if (!Object.prototype.hasOwnProperty.call(meta, '_elementor_data')) {
		return {
			ok: false,
			post_id: postId,
			transport: 'rest',
			collection,
			error: 'REST response has no meta._elementor_data (not registered for REST or not an Elementor document).',
			hint: 'On remote Direct without WP-CLI, Elementor meta must be REST-visible. Prefer the Stonewright plugin for batch-mutate engines; raw REST meta is limited and unvalidated.',
		};
	}
	const tree = parseElementorTree(meta['_elementor_data']);
	const elements = Array.isArray(tree) ? tree.length : tree && typeof tree === 'object' ? 1 : 0;
	const editMode = meta['_elementor_edit_mode'];
	const templateType = meta['_elementor_template_type'];
	return {
		ok: true,
		post_id: postId,
		transport: 'rest',
		collection,
		edit_mode: editMode != null ? String(editMode) : null,
		template_type: templateType != null ? String(templateType) : null,
		element_count: elements,
		data: tree,
	};
}

export async function elementorStatus(
	env: NodeJS.ProcessEnv,
	input: { site?: string; cwd?: string; path?: string } = {},
	cli: ElementorCli = runWpCli,
	rest?: ElementorRestClient,
) {
	const status = await cli(
		{
			command: ['cli', 'info'],
			...(input.cwd ? { cwd: input.cwd } : {}),
			...(input.path ? { path: input.path } : {}),
			responseMode: 'summary',
		},
		undefined,
		env,
	);
	const wpCli = Boolean(status.available && status.ok);
	if (!wpCli) {
		return {
			wp_cli: false,
			rest_fallback: Boolean(rest),
			elementor_active: null,
			version: null,
			// Provisional: REST may work when meta is registered; data-get proves it.
			can_edit_data: Boolean(rest),
			transport_preference: rest ? ['rest'] : [],
			guidance: rest
				? [
						'WP-CLI is not available (typical for remote/live Direct).',
						'Direct will try core REST meta for _elementor_data when it is REST-registered (context=edit).',
						'This is NOT plugin batch-mutate: no Elementor schema validation, no CSS rebuild engine.',
						'Prefer Stonewright plugin mode for production Elementor work on live sites.',
						'Do not invent WP-CLI or /abilities/run workarounds when REST meta is missing — install the plugin.',
					]
				: [
						'Elementor data editing without the Stonewright plugin requires local WP-CLI or REST meta access.',
						'On remote/live sites install the Stonewright plugin for Elementor engines.',
						'Do not attempt undocumented REST workarounds outside stonewright-elementor-data-* tools.',
					],
		};
	}

	const list = asFull(
		await cli(
			{
				command: ['plugin', 'list', '--format=json'],
				...(input.cwd ? { cwd: input.cwd } : {}),
				...(input.path ? { path: input.path } : {}),
				parseJson: true,
			},
			undefined,
			env,
		),
	);

	let elementorActive = false;
	let version: string | null = null;
	const plugins = Array.isArray(list.parsed_json) ? list.parsed_json : [];
	for (const row of plugins as Array<Record<string, unknown>>) {
		const name = String(row.name ?? row.file ?? '').toLowerCase();
		if (name.includes('elementor') && !name.includes('pro')) {
			const st = String(row.status ?? '').toLowerCase();
			elementorActive = st === 'active' || st === 'active-network';
			version = row.version != null ? String(row.version) : null;
			break;
		}
	}

	return {
		wp_cli: true,
		rest_fallback: Boolean(rest),
		elementor_active: elementorActive,
		version,
		can_edit_data: elementorActive,
		transport_preference: elementorActive ? ['wp-cli', ...(rest ? ['rest'] : [])] : rest ? ['rest'] : [],
		guidance: elementorActive
			? [
					'Use stonewright-elementor-data-get before any write.',
					'Copy structure from existing sibling widgets — never invent widgetType keys.',
					'stonewright-elementor-data-update backs up automatically under ~/.stonewright/backups/.',
					'Local WP-CLI path is preferred; REST is fallback when CLI is unavailable.',
				]
			: [
					'Elementor is not active. Activate it locally, or install the Stonewright plugin for full engines.',
				],
	};
}

export async function elementorDataGet(
	env: NodeJS.ProcessEnv,
	input: { post_id: number; site?: string; type?: string; cwd?: string; path?: string },
	cli: ElementorCli = runWpCli,
	rest?: ElementorRestClient,
) {
	const base = {
		...(input.cwd ? { cwd: input.cwd } : {}),
		...(input.path ? { path: input.path } : {}),
	};

	// Prefer WP-CLI when available.
	const probe = asFull(
		await cli(
			{
				command: ['cli', 'info'],
				...base,
				responseMode: 'summary',
			},
			undefined,
			env,
		),
	);

	if (probe.available !== false && probe.ok) {
		const meta = asFull(
			await cli(
				{
					command: ['post', 'meta', 'get', String(input.post_id), '_elementor_data', '--format=json'],
					...base,
					parseJson: true,
				},
				undefined,
				env,
			),
		);
		if (!meta.ok) {
			// Fall through to REST if CLI meta fails and REST exists.
			if (rest) {
				return restDataGet(rest, input.post_id, input.type);
			}
			return {
				ok: false,
				post_id: input.post_id,
				transport: 'wp-cli' as const,
				error: meta.stderr || meta.error || 'Failed to read _elementor_data',
				hint: 'Post not found or this post is not an Elementor page.',
			};
		}

		const tree = parseElementorTree(meta.parsed_json);
		const elements = Array.isArray(tree) ? tree.length : tree && typeof tree === 'object' ? 1 : 0;

		const mode = asFull(
			await cli(
				{
					command: ['post', 'meta', 'get', String(input.post_id), '_elementor_edit_mode'],
					...base,
				},
				undefined,
				env,
			),
		);
		const templateType = asFull(
			await cli(
				{
					command: ['post', 'meta', 'get', String(input.post_id), '_elementor_template_type'],
					...base,
				},
				undefined,
				env,
			),
		);

		return {
			ok: true,
			post_id: input.post_id,
			transport: 'wp-cli' as const,
			edit_mode: mode.stdout.trim() || null,
			template_type: templateType.stdout.trim() || null,
			element_count: elements,
			data: tree,
		};
	}

	if (rest) {
		return restDataGet(rest, input.post_id, input.type);
	}

	return {
		ok: false,
		post_id: input.post_id,
		transport: 'none' as const,
		error: 'Neither WP-CLI nor REST client is available for Elementor data.',
		hint: 'On remote live Direct, configure Application Password REST credentials, or install the Stonewright plugin.',
	};
}

export async function elementorDataUpdate(
	env: NodeJS.ProcessEnv,
	input: {
		post_id: number;
		data: string | unknown[] | Record<string, unknown>;
		site?: string;
		type?: string;
		confirm?: boolean;
		force_destructive?: boolean;
		allow_widget_type_remap?: boolean;
		cwd?: string;
		path?: string;
	},
	cli: ElementorCli = runWpCli,
	rest?: ElementorRestClient,
) {
	const writeMode = resolveDirectWriteMode(env);
	assertWriteAllowed({
		mode: writeMode,
		destructive: true,
		...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
		tool: 'stonewright-elementor-data-update',
		env,
	});

	const normalized = normalizeToTree(input.data);
	if (!normalized.ok || !normalized.tree) {
		const err = normalized as { error_code?: string; message?: string; data?: unknown };
		throw new Error(
			`[${err.error_code ?? 'integrity'}] ${err.message ?? 'Invalid Elementor document payload.'}`,
		);
	}
	const tree = normalized.tree;

	const base = {
		...(input.cwd ? { cwd: input.cwd } : {}),
		...(input.path ? { path: input.path } : {}),
	};

	// Mandatory backup before write (CLI or REST).
	const current = await elementorDataGet(env, input, cli, rest);
	if (!current.ok) {
		throw new Error(
			(current as { error?: string }).error
				|| 'Cannot backup current Elementor data before write (get failed).',
		);
	}

	const previous = Array.isArray((current as { data?: unknown }).data)
		? ((current as { data: unknown[] }).data)
		: [];

	const integrity = integrityAssertWrite(tree, previous, {
		...(input.force_destructive !== undefined ? { force_destructive: input.force_destructive } : {}),
		...(input.allow_widget_type_remap !== undefined
			? { allow_widget_type_remap: input.allow_widget_type_remap }
			: {}),
	});
	if (!integrity.ok) {
		throw new Error(
			`[${integrity.error_code}] ${integrity.message}${
				integrity.data ? ` ${JSON.stringify(integrity.data)}` : ''
			}`,
		);
	}

	// Encode once — never store an already-encoded JSON string as the meta value wrapper.
	const json = encodeTreeOnce(tree);

	const scope = resolveScope(input.site);
	const backupDir = join(defaultStateDir(env), 'backups', scope);
	mkdirSync(backupDir, { recursive: true, mode: 0o700 });
	const ts = new Date().toISOString().replace(/[:.]/g, '-');
	const backupPath = join(backupDir, `post-${input.post_id}-${ts}.json`);
	writeFileSync(
		backupPath,
		JSON.stringify(
			{
				post_id: input.post_id,
				backed_up_at: new Date().toISOString(),
				transport: (current as { transport?: string }).transport ?? null,
				edit_mode: (current as { edit_mode?: string | null }).edit_mode ?? null,
				template_type: (current as { template_type?: string | null }).template_type ?? null,
				data: (current as { data?: unknown }).data ?? null,
			},
			null,
			2,
		),
		{ encoding: 'utf8', mode: 0o600 },
	);

	const transport = (current as { transport?: string }).transport;
	const useCli = transport === 'wp-cli';

	if (useCli) {
		const updated = asFull(
			await cli(
				{
					command: ['post', 'meta', 'update', String(input.post_id), '_elementor_data'],
					...base,
					stdin: json,
				},
				undefined,
				env,
			),
		);
		if (!updated.ok) {
			appendDirectAudit({
				tool: 'stonewright-elementor-data-update',
				site: scope,
				resource: `post:${input.post_id}`,
				status: 'error',
				error: (updated.stderr || updated.error || 'meta update failed').slice(0, 200),
			});
			throw new Error(updated.stderr || updated.error || 'Failed to update _elementor_data');
		}

		let cssFlushed = false;
		const help = asFull(await cli({ command: ['help', 'elementor'], ...base }, undefined, env));
		const helpText = `${help.stdout}\n${help.stderr}`.toLowerCase();
		const flushCmd = helpText.includes('flush-css')
			? ['elementor', 'flush-css']
			: helpText.includes('flush_css')
				? ['elementor', 'flush_css']
				: null;
		if (flushCmd) {
			const flush = asFull(await cli({ command: flushCmd, ...base }, undefined, env));
			cssFlushed = flush.ok;
		}

		appendDirectAudit({
			tool: 'stonewright-elementor-data-update',
			site: scope,
			resource: `post:${input.post_id}`,
			status: 'ok',
		});

		return {
			ok: true,
			post_id: input.post_id,
			transport: 'wp-cli' as const,
			backup_path: backupPath,
			css_flushed: cssFlushed,
			verify: 'reload the page URL and confirm the change rendered',
			guidance: cssFlushed
				? []
				: [
						'CSS not regenerated — open the page in the Elementor editor once, or clear the site cache.',
					],
		};
	}

	// REST path for remote Direct (no WP-CLI).
	if (!rest) {
		throw new Error(
			'Elementor update requires local WP-CLI or a REST client. Configure Application Password credentials for remote Direct, or install the Stonewright plugin.',
		);
	}

	const collection =
		(current as { collection?: string }).collection
		?? (await restFindCollection(rest, input.post_id, input.type));
	if (!collection) {
		throw new Error('Could not resolve REST collection for this post_id (pages/posts/type).');
	}

	try {
		await rest.post(`/wp/v2/${collection}/${input.post_id}`, {
			body: {
				meta: {
					_elementor_data: json,
				},
			},
		});
	} catch (err) {
		const message = err instanceof Error ? err.message : String(err);
		appendDirectAudit({
			tool: 'stonewright-elementor-data-update',
			site: scope,
			resource: `post:${input.post_id}`,
			status: 'error',
			error: message.slice(0, 200),
		});
		throw new Error(
			`REST Elementor meta update failed: ${message}. Meta may not be REST-writable. Install the Stonewright plugin for typed batch-mutate instead of raw meta.`,
		);
	}

	appendDirectAudit({
		tool: 'stonewright-elementor-data-update',
		site: scope,
		resource: `post:${input.post_id}`,
		status: 'ok',
	});

	return {
		ok: true,
		post_id: input.post_id,
		transport: 'rest' as const,
		collection,
		backup_path: backupPath,
		css_flushed: false,
		verify: 'reload the frontend URL and confirm the change rendered',
		guidance: [
			'Updated via core REST meta (no WP-CLI). CSS flush is unavailable remotely — clear cache / open editor once if styles lag.',
			'This path has no Elementor schema validation. For production Loop Grid / complex widgets, use plugin stonewright-elementor-v3-batch-mutate.',
		],
	};
}
