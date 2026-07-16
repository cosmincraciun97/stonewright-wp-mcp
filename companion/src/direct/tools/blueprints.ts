import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { WpRestClient } from '../wp-rest-client.js';
import { assertWriteAllowed, resolveDirectWriteMode } from '../writes.js';
import { serializeDesignSpec, type DesignSpec } from '../gutenberg-serializer.js';

export type BlueprintSummary = {
	id: string;
	name: string;
	description: string;
	industry: string;
	section_ids: string[];
};

function blueprintsDir(): string {
	const here = dirname(fileURLToPath(import.meta.url));
	// dist/direct/tools → ../../blueprints or repo companion/blueprints
	const candidates = [
		join(here, '../../../blueprints'),
		join(here, '../../../../companion/blueprints'),
		join(process.cwd(), 'blueprints'),
		join(process.cwd(), 'companion/blueprints'),
	];
	for (const c of candidates) {
		if (existsSync(c)) return c;
	}
	return candidates[0];
}

function loadAll(): Array<Record<string, unknown>> {
	const dir = blueprintsDir();
	if (!existsSync(dir)) return [];
	return readdirSync(dir)
		.filter((f) => f.endsWith('.json'))
		.map((f) => JSON.parse(readFileSync(join(dir, f), 'utf8')) as Record<string, unknown>);
}

export function listBlueprints(): BlueprintSummary[] {
	return loadAll().map((bp) => ({
		id: String(bp.id ?? ''),
		name: String(bp.name ?? ''),
		description: String(bp.description ?? ''),
		industry: String(bp.industry ?? ''),
		section_ids: Array.isArray(bp.section_ids) ? bp.section_ids.map(String) : [],
	}));
}

export function getBlueprint(id: string): Record<string, unknown> | null {
	const key = id.trim().toLowerCase();
	return loadAll().find((bp) => String(bp.id ?? '').toLowerCase() === key) ?? null;
}

export async function applyBlueprint(
	client: WpRestClient,
	args: {
		id: string;
		title?: string;
		status?: 'draft' | 'publish';
		post_id?: number;
		confirm?: boolean;
		engine?: string;
	},
	env: NodeJS.ProcessEnv,
): Promise<Record<string, unknown>> {
	if (args.engine && args.engine !== 'gutenberg' && args.engine !== 'auto') {
		return {
			ok: false,
			error: 'elementor_requires_plugin',
			message:
				'Elementor engine requires the Stonewright plugin. Direct mode applies blueprints as Gutenberg drafts only.',
		};
	}

	const bp = getBlueprint(args.id);
	if (!bp) {
		return { ok: false, error: 'not_found', message: `Blueprint ${args.id} not found` };
	}

	const spec = (bp.spec ?? {}) as DesignSpec;
	const content = serializeDesignSpec(spec);
	const title =
		args.title ||
		String((spec.page as { title?: string } | undefined)?.title ?? bp.name ?? 'Blueprint page');

	const writeMode = resolveDirectWriteMode(env, client.restBase);

	if (args.post_id && args.post_id > 0) {
		assertWriteAllowed({
			mode: writeMode,
			destructive: true,
			confirm: args.confirm,
			tool: 'stonewright-blueprint-apply',
		});
		const updated = await client.post(`/wp/v2/pages/${args.post_id}`, {
			body: {
				title,
				content,
				status: args.status ?? 'draft',
			},
		});
		return {
			ok: true,
			engine: 'gutenberg',
			mode: 'direct',
			post_id: args.post_id,
			page_id: args.post_id,
			blueprint_id: bp.id,
			updated,
			note: 'Direct mode Gutenberg apply (Elementor requires plugin).',
		};
	}

	assertWriteAllowed({
		mode: writeMode,
		destructive: false,
		confirm: args.confirm,
		tool: 'stonewright-blueprint-apply',
	});
	const created = await client.post<{ id?: number }>('/wp/v2/pages', {
		body: {
			title,
			content,
			status: args.status ?? 'draft',
		},
	});

	return {
		ok: true,
		engine: 'gutenberg',
		mode: 'direct',
		post_id: created.id ?? 0,
		page_id: created.id ?? 0,
		blueprint_id: bp.id,
		created: true,
		note: 'Direct mode Gutenberg apply (Elementor requires plugin).',
	};
}
