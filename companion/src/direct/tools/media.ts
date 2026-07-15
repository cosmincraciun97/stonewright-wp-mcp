import { readFileSync } from 'node:fs';
import { basename } from 'node:path';
import type { WpRestClient } from '../wp-rest-client.js';
import { assertToolEnabled, assertWriteAllowed, type DirectWriteMode } from '../writes.js';
import { appendDirectAudit } from '../audit.js';
import type { ResolvedSite } from '../sites-config.js';

export interface MediaToolContext {
	client: WpRestClient;
	site: ResolvedSite;
	writeMode: DirectWriteMode;
	fetchImpl?: typeof fetch | undefined;
}

type WpMedia = {
	id: number;
	slug?: string | undefined;
	source_url?: string | undefined;
	mime_type?: string | undefined;
	alt_text?: string | undefined;
	title?: { rendered?: string; raw?: string } | string;
	caption?: { rendered?: string; raw?: string } | string;
	media_details?: Record<string, unknown> | undefined;
};

function titleOf(item: WpMedia): string {
	if (typeof item.title === 'string') return item.title;
	return item.title?.raw ?? item.title?.rendered ?? '';
}

function compactMedia(item: WpMedia) {
	return {
		id: item.id,
		title: titleOf(item),
		slug: item.slug ?? '',
		source_url: item.source_url ?? '',
		mime_type: item.mime_type ?? '',
		alt_text: item.alt_text ?? '',
	};
}

export async function mediaList(
	ctx: MediaToolContext,
	input: { search?: string | undefined; per_page?: number | undefined; page?: number | undefined },
) {
	assertToolEnabled(ctx.site, 'stonewright-media-list');
	const perPage = Math.min(Math.max(input.per_page ?? 20, 1), 50);
	const page = Math.max(input.page ?? 1, 1);
	const items = await ctx.client.get<WpMedia[]>('/wp/v2/media', {
		query: {
			search: input.search,
			per_page: perPage,
			page,
			_fields: 'id,title,slug,source_url,mime_type,alt_text',
		},
	});
	const list = Array.isArray(items) ? items : [];
	return {
		items: list.map(compactMedia),
		total: list.length,
		page,
		per_page: perPage,
		next_page: list.length === perPage ? page + 1 : undefined,
	};
}

export async function mediaGet(ctx: MediaToolContext, input: { id: number }) {
	assertToolEnabled(ctx.site, 'stonewright-media-get');
	const item = await ctx.client.get<WpMedia>(`/wp/v2/media/${input.id}`);
	return {
		...compactMedia(item),
		caption:
			typeof item.caption === 'string'
				? item.caption
				: (item.caption?.raw ?? item.caption?.rendered ?? ''),
		media_details: item.media_details ?? {},
	};
}

export async function mediaUpload(
	ctx: MediaToolContext,
	input: {
		path?: string | undefined;
		url?: string | undefined;
		filename?: string | undefined;
		title?: string | undefined;
		alt_text?: string | undefined;
		caption?: string | undefined;
	},
) {
	assertToolEnabled(ctx.site, 'stonewright-media-upload');
	assertWriteAllowed({ mode: ctx.writeMode, destructive: false, tool: 'stonewright-media-upload' });

	let bytes: Buffer;
	let filename = input.filename ?? 'upload.bin';

	if (input.path) {
		bytes = readFileSync(input.path);
		filename = input.filename ?? basename(input.path);
	} else if (input.url) {
		const fetchImpl = ctx.fetchImpl ?? fetch;
		const response = await fetchImpl(input.url);
		if (!response.ok) {
			throw new Error(`Failed to download media from URL (HTTP ${response.status})`);
		}
		bytes = Buffer.from(await response.arrayBuffer());
		const fromUrl = basename(new URL(input.url).pathname);
		filename = input.filename ?? (fromUrl || 'download.bin');
	} else {
		throw new Error('media-upload requires path or url');
	}

	const form = new FormData();
	form.append('file', new Blob([new Uint8Array(bytes)]), filename);
	if (input.title) form.append('title', input.title);
	if (input.alt_text) form.append('alt_text', input.alt_text);
	if (input.caption) form.append('caption', input.caption);

	try {
		const item = await ctx.client.post<WpMedia>('/wp/v2/media', {
			body: form,
			rawBody: true,
		});
		appendDirectAudit({
			tool: 'stonewright-media-upload',
			site: ctx.site.alias,
			resource: `media/${item.id}`,
			status: 'ok',
		});
		const compact = compactMedia(item);
		return {
			...compact,
			alt_text_recommended: !compact.alt_text,
		};
	} catch (err) {
		appendDirectAudit({
			tool: 'stonewright-media-upload',
			site: ctx.site.alias,
			resource: 'media',
			status: 'error',
		});
		throw err;
	}
}

export async function mediaUpdate(
	ctx: MediaToolContext,
	input: { id: number; title?: string | undefined; alt_text?: string | undefined; caption?: string | undefined },
) {
	assertToolEnabled(ctx.site, 'stonewright-media-update');
	assertWriteAllowed({ mode: ctx.writeMode, destructive: false, tool: 'stonewright-media-update' });
	const body: Record<string, unknown> = {};
	if (input.title !== undefined) body.title = input.title;
	if (input.alt_text !== undefined) body.alt_text = input.alt_text;
	if (input.caption !== undefined) body.caption = input.caption;

	try {
		const item = await ctx.client.put<WpMedia>(`/wp/v2/media/${input.id}`, { body });
		appendDirectAudit({
			tool: 'stonewright-media-update',
			site: ctx.site.alias,
			resource: `media/${input.id}`,
			status: 'ok',
		});
		return compactMedia(item);
	} catch (err) {
		appendDirectAudit({
			tool: 'stonewright-media-update',
			site: ctx.site.alias,
			resource: `media/${input.id}`,
			status: 'error',
		});
		throw err;
	}
}
