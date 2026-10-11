import { vi } from 'vitest';

/**
 * A small stateful WordPress REST fake for Direct tool tests: pages, templates, template parts and
 * global styles keep what is written to them, so a test can write, read back and restore.
 */

export type Row = Record<string, unknown>;

export interface FakeCall {
	method: string;
	path: string;
	query: URLSearchParams;
	body: Record<string, unknown> | undefined;
}

export interface FakeWp {
	fetchImpl: ReturnType<typeof vi.fn>;
	calls: FakeCall[];
	/** Stored resources by REST path, for example `/wp/v2/pages/4`. */
	store: Map<string, Row>;
	/** Called with each write body before it is stored; return the body the site will keep. */
	sanitize?: (path: string, body: Record<string, unknown>) => Record<string, unknown>;
	/** Pages for the front end, by path. Defaults to a healthy page. */
	front: Map<string, { status: number; body: string }>;
	/** Decides a front-end response from the current store; return undefined to fall back to `front`. */
	frontHandler?: (url: URL, store: Map<string, Row>) => { status: number; body: string } | undefined;
}

function json(body: unknown, status = 200): Response {
	return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
}

const TEXT_FIELDS = new Set(['title', 'content', 'excerpt']);

export function pageRow(id: number, overrides: Row = {}): Row {
	return {
		id,
		title: { raw: `Page ${id}`, rendered: `Page ${id}` },
		content: { raw: `<p>old ${id}</p>`, rendered: `<p>old ${id}</p>`, protected: false, block_version: 1 },
		excerpt: { raw: '', rendered: '' },
		status: 'publish',
		slug: `page-${id}`,
		parent: 0,
		template: '',
		meta: {},
		modified: '2026-10-01T00:00:00',
		link: `http://example.test/page-${id}/`,
		type: 'page',
		...overrides,
	};
}

export function templateRow(id: string, overrides: Row = {}): Row {
	return {
		id,
		slug: id.split('//')[1] ?? id,
		theme: id.split('//')[0] ?? 'theme',
		type: 'wp_template',
		source: 'custom',
		status: 'publish',
		title: { raw: 'Home', rendered: 'Home' },
		description: 'Home template',
		content: { raw: '<!-- wp:paragraph --><p>old</p><!-- /wp:paragraph -->', block_version: 1 },
		...overrides,
	};
}

export function makeFakeWp(initial: Record<string, Row> = {}): FakeWp {
	const store = new Map<string, Row>(Object.entries(initial));
	const calls: FakeCall[] = [];
	const front = new Map<string, { status: number; body: string }>();
	const fake: FakeWp = {
		fetchImpl: vi.fn(),
		calls,
		store,
		front,
	};
	fake.fetchImpl = vi.fn((url: Parameters<typeof fetch>[0], init?: RequestInit) => {
		const parsed = new URL(String(url));
		const method = (init?.method ?? 'GET').toUpperCase();
		const body = init?.body ? (JSON.parse(String(init.body)) as Record<string, unknown>) : undefined;
		if (!parsed.pathname.startsWith('/wp-json')) {
			calls.push({ method, path: parsed.pathname + parsed.search, query: parsed.searchParams, body });
			const page = fake.frontHandler?.(parsed, store) ?? front.get(parsed.pathname + parsed.search) ?? front.get(parsed.pathname);
			const healthy = { status: 200, body: '<!doctype html><html><body><h1>Hello</h1></body></html>' };
			const out = page ?? healthy;
			return Promise.resolve(new Response(out.body, { status: out.status, headers: { 'content-type': 'text/html' } }));
		}
		const path = decodeURIComponent(parsed.pathname.replace(/^\/wp-json/, ''));
		calls.push({ method, path, query: parsed.searchParams, body });
		const existing = store.get(path);
		if (method === 'GET') {
			if (!existing) return Promise.resolve(json({ code: 'rest_not_found', message: 'Not found' }, 404));
			return Promise.resolve(json(structuredClone(existing)));
		}
		if (!existing) return Promise.resolve(json({ code: 'rest_not_found', message: 'Not found' }, 404));
		if (method === 'DELETE') {
			store.delete(path);
			return Promise.resolve(json({ deleted: true, previous: existing }));
		}
		const kept = fake.sanitize ? fake.sanitize(path, body ?? {}) : (body ?? {});
		for (const [key, value] of Object.entries(kept)) {
			if (TEXT_FIELDS.has(key) && typeof value === 'string') {
				const current = (existing[key] ?? {}) as Row;
				existing[key] = { ...current, raw: value, rendered: value };
			} else if (key === 'meta' && value && typeof value === 'object') {
				existing['meta'] = { ...((existing['meta'] as Row) ?? {}), ...(value as Row) };
			} else {
				existing[key] = value;
			}
		}
		existing['modified'] = new Date().toISOString();
		return Promise.resolve(json(structuredClone(existing)));
	}) as never;
	return fake;
}
