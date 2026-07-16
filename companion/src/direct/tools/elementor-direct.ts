import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { appendDirectAudit, defaultStateDir } from '../audit.js';
import { assertWriteAllowed, resolveDirectWriteMode } from '../writes.js';
import { runWpCli, type WpCliCommandResult } from '../../wp-cli.js';

export type ElementorCli = typeof runWpCli;

function resolveScope(site?: string): string {
	const s = (site ?? '_global').trim();
	return s.length > 0 ? s.replace(/[^a-zA-Z0-9_.-]/g, '_') : '_global';
}

function asFull(
	result: WpCliCommandResult,
): { ok: boolean; stdout: string; stderr: string; parsed_json?: unknown; error?: string } {
	return {
		ok: Boolean(result.ok),
		stdout: String((result as { stdout?: string }).stdout ?? ''),
		stderr: String((result as { stderr?: string }).stderr ?? ''),
		...(result.parsed_json !== undefined ? { parsed_json: result.parsed_json } : {}),
		...(typeof result.error === 'string' ? { error: result.error } : {}),
	};
}

export async function elementorStatus(
	env: NodeJS.ProcessEnv,
	input: { site?: string; cwd?: string; path?: string } = {},
	cli: ElementorCli = runWpCli,
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
			elementor_active: false,
			version: null,
			can_edit_data: false,
			guidance: [
				'Elementor data editing without the Stonewright plugin requires local WP-CLI.',
				'On remote/live sites install the Stonewright plugin for Elementor engines.',
				'Do not attempt REST workarounds for _elementor_data.',
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
		elementor_active: elementorActive,
		version,
		can_edit_data: elementorActive,
		guidance: elementorActive
			? [
					'Use stonewright-elementor-data-get before any write.',
					'Copy structure from existing sibling widgets — never invent widgetType keys.',
					'stonewright-elementor-data-update backs up automatically under ~/.stonewright/backups/.',
				]
			: [
					'Elementor is not active. Activate it locally, or install the Stonewright plugin for full engines.',
				],
	};
}

export async function elementorDataGet(
	env: NodeJS.ProcessEnv,
	input: { post_id: number; site?: string; cwd?: string; path?: string },
	cli: ElementorCli = runWpCli,
) {
	const base = {
		...(input.cwd ? { cwd: input.cwd } : {}),
		...(input.path ? { path: input.path } : {}),
	};
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
		return {
			ok: false,
			post_id: input.post_id,
			error: meta.stderr || meta.error || 'Failed to read _elementor_data',
			hint: 'Post not found or this post is not an Elementor page.',
		};
	}

	let tree: unknown = meta.parsed_json;
	if (typeof tree === 'string') {
		try {
			tree = JSON.parse(tree);
		} catch {
			// keep string
		}
	}
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
		edit_mode: mode.stdout.trim() || null,
		template_type: templateType.stdout.trim() || null,
		element_count: elements,
		data: tree,
	};
}

function serializeData(data: string | unknown[] | Record<string, unknown>): string {
	if (typeof data === 'string') {
		JSON.parse(data); // throws if invalid
		return data;
	}
	return JSON.stringify(data);
}

export async function elementorDataUpdate(
	env: NodeJS.ProcessEnv,
	input: {
		post_id: number;
		data: string | unknown[] | Record<string, unknown>;
		site?: string;
		confirm?: boolean;
		cwd?: string;
		path?: string;
	},
	cli: ElementorCli = runWpCli,
) {
	const writeMode = resolveDirectWriteMode(env);
	assertWriteAllowed({
		mode: writeMode,
		destructive: true,
		...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
		tool: 'stonewright-elementor-data-update',
		env,
	});

	let json: string;
	try {
		json = serializeData(input.data);
	} catch {
		throw new Error(
			'data must be valid JSON (array tree or string). Call stonewright-elementor-data-get first and mutate the real tree.',
		);
	}

	const base = {
		...(input.cwd ? { cwd: input.cwd } : {}),
		...(input.path ? { path: input.path } : {}),
	};

	// Mandatory backup before write.
	const current = await elementorDataGet(env, input, cli);
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
				edit_mode: current.edit_mode ?? null,
				template_type: current.template_type ?? null,
				data: current.data ?? null,
			},
			null,
			2,
		),
		{ encoding: 'utf8', mode: 0o600 },
	);

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

	// Best-effort CSS flush — never a write gate.
	let cssFlushed = false;
	const help = asFull(
		await cli({ command: ['help', 'elementor'], ...base }, undefined, env),
	);
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

